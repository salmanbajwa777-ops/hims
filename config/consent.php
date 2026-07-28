<?php
/**
 * Procedure consent — the generic half of the consent machinery.
 *
 * config/dental.php already owns a consent implementation, but it is scoped to
 * dentistry: its gate accepts a package account, its upload directory is
 * uploads/dental_consents/, and its print partial is worded for dental
 * treatment. A circumcision consent shares the STORAGE (the dental_consents
 * table is generic in every column that matters) but none of that wording.
 *
 * So this file owns what is procedure-generic:
 *   - rendering a per-procedure template into a printable body
 *   - creating the consent row that a procedure bill prints
 *   - answering "does this procedure have a consent form at all?"
 *
 * It deliberately does NOT re-implement the scan upload. That contract
 * (generated filename, extension allow-list, size cap, runtime .htaccess) is
 * already correct in config/dental.php and is reused as-is — see
 * dental_consent_validate() / dental_consent_store(). Two upload paths writing
 * to two directories with two subtly different guards is exactly how a private
 * document ends up world-readable.
 *
 * Requires sql/add_procedure_consent.sql.
 */

require_once __DIR__ . '/permissions.php';   // audit_log()


// ============================================================================
// TEMPLATE RENDERING
// ============================================================================

/**
 * The placeholders a consent template may use, in the order they are shown to
 * the admin on the catalogue page. Keeping the list here rather than in the
 * page means the editor's help text can never drift from what actually
 * substitutes.
 */
const CONSENT_PLACEHOLDERS = [
    '{{patient_name}}'    => "The patient's full name, in capitals.",
    '{{father_name}}'     => "The patient's S/D/W of name, in capitals.",
    '{{mrn}}'             => 'The MR number.',
    '{{procedure_name}}'  => 'The procedure being consented to.',
    '{{doctor_name}}'     => 'The doctor performing it, in capitals.',
    '{{signer_name}}'     => 'Who is signing. Prints a blank rule when not entered.',
    '{{signer_relation}}' => 'Their relation to the patient. Prints a blank rule when not entered.',
    '{{signer_cnic}}'     => 'Their CNIC. Prints a blank rule when not entered.',
];

/**
 * Substitute a consent template into its final wording.
 *
 * TWO OUTPUT MODES, and the difference matters:
 *
 *   $forPrint = false — plain text, every placeholder replaced with its value
 *     or with '' when unknown. This is what gets FROZEN onto the consent row.
 *
 *   $forPrint = true — HTML. Values are escaped and emphasised, and an unfilled
 *     signer placeholder becomes a ruled blank so the printed sheet has a line
 *     to write on. This is never what gets stored.
 *
 * AN UNFILLED SIGNER PLACEHOLDER SURVIVES THE FREEZE. This is the subtle part.
 * The patient's name, the doctor, the procedure are all known when the bill is
 * raised, so they are substituted and frozen. The signer often is NOT: the
 * cashier leaves those fields empty and the parent writes their name on the
 * paper. Substituting an empty signer to '' at freeze time would destroy the
 * only marker of WHERE on the sheet that rule belongs, and the sheet would
 * print "I, ,  of the child ..." with nothing to sign on. So an empty signer
 * placeholder is left standing in the frozen text, and the print pass turns it
 * into the rule. A signer that WAS entered is frozen like any other fact.
 *
 * This applies to all three signer fields — name, relation and CNIC. The CNIC
 * is the one most often absent at billing time, since the cashier rarely has
 * the card in hand when the bill is raised.
 *
 * The frozen text otherwise carries no print markup on purpose: the row has to
 * stay readable in a database client, in an export, and in an audit trail, and
 * a stored blob of <span class="blank"> would be none of those.
 */
function consent_render(string $template, array $vars, bool $forPrint = false): string
{
    // The only fields that can legitimately still be empty at print time.
    $signerKeys = ['{{signer_name}}', '{{signer_relation}}', '{{signer_cnic}}'];

    $out = $forPrint ? htmlspecialchars($template) : $template;

    foreach (CONSENT_PLACEHOLDERS as $key => $_desc) {
        $raw = trim((string) ($vars[trim($key, '{}')] ?? ''));
        $isSigner = in_array($key, $signerKeys, true);

        if (!$forPrint) {
            // Leave an unfilled signer placeholder standing (see above); every
            // other unknown collapses to '' so no braces reach the stored text.
            if ($isSigner && $raw === '') {
                continue;
            }
            $out = str_replace($key, $raw, $out);
            continue;
        }

        if ($raw === '') {
            // An unfilled signer becomes a rule to write on; any other unfilled
            // field collapses rather than printing a stray line mid-sentence.
            $replacement = $isSigner ? '<span class="blank">&nbsp;</span>' : '';
        } else {
            $replacement = '<b>' . htmlspecialchars($raw) . '</b>';
        }

        // htmlspecialchars() ran over the template above, so the placeholder
        // braces survive untouched and this match is still exact.
        $out = str_replace($key, $replacement, $out);
    }

    return $out;
}


/**
 * Is procedure_master.consent_template present?
 *
 * Same shape as procedure_disposables_flag() in config/billing.php: cached for
 * the request, so a page that asks per-row does not run a probe query per row.
 * Everything consent-related is conditional on this, so a database where
 * sql/add_procedure_consent.sql has not run bills exactly as it did before
 * instead of fataling on a missing column.
 */
function consent_column_live(PDO $pdo): bool
{
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    try {
        $pdo->query('SELECT consent_template FROM procedure_master LIMIT 1')->fetchAll();
        $has = true;
    } catch (PDOException $e) {
        $has = false;
    }
    return $has;
}


/**
 * Is dental_consents.signed_cnic present?
 *
 * Same request-cached probe as consent_column_live(), for the same reason: this
 * is asked once per consent row on the register and once per INSERT, and a
 * probe query per row would be a needless cost.
 *
 * Requires sql/add_consent_signer_cnic.sql. Until that runs, every consent path
 * behaves exactly as it did before CNIC existed.
 */
function consent_cnic_column_live(PDO $pdo): bool
{
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    try {
        $pdo->query('SELECT signed_cnic FROM dental_consents LIMIT 1')->fetchAll();
        $has = true;
    } catch (PDOException $e) {
        $has = false;
    }
    return $has;
}


/**
 * Normalise a CNIC to the conventional 00000-0000000-0 form.
 *
 * The cashier types it however the card reads or however they are used to —
 * with dashes, without, with spaces. Storing it as typed would put four
 * spellings of the same identifier in one column, and this value goes on a
 * document the clinic keeps.
 *
 * A 13-digit input is punctuated. ANYTHING ELSE IS RETURNED UNTOUCHED rather
 * than rejected or padded: a half-typed CNIC is the cashier's problem to fix,
 * and silently mangling it into a well-formed but wrong number would be worse
 * than storing what they actually typed. Validation belongs at the input, not
 * in a formatter.
 */
function consent_format_cnic(string $raw): string
{
    $digits = preg_replace('/\D/', '', $raw);
    if (strlen($digits) !== 13) {
        return trim($raw);
    }
    return substr($digits, 0, 5) . '-' . substr($digits, 5, 7) . '-' . substr($digits, 12, 1);
}


/**
 * Does this procedure carry a consent form?
 *
 * The template is the switch, NOT mandatory_consent. A procedure can be flagged
 * as requiring consent while its wording is still being written, and printing a
 * blank sheet under a "CONSENT FOR ..." heading is worse than printing none.
 *
 * Returns the trimmed template, or null.
 */
function consent_template_for(PDO $pdo, int $procedureMasterId): ?string
{
    // Column absent = sql/add_procedure_consent.sql not run yet. Behave exactly
    // as before it existed: no procedure has a consent form.
    if (!consent_column_live($pdo)) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT consent_template FROM procedure_master WHERE id = ?');
    $stmt->execute([$procedureMasterId]);
    $tpl = trim((string) $stmt->fetchColumn());
    return $tpl !== '' ? $tpl : null;
}


// ============================================================================
// CREATING THE CONSENT A BILL PRINTS
// ============================================================================

/**
 * Create the consent rows for a procedure bill that was just raised.
 *
 * Called INSIDE the billing transaction, so a consent can never exist for a
 * bill that rolled back, and a bill can never commit having silently failed to
 * record the consent it is about to print.
 *
 * One row per consent-bearing line. A bill carrying a circumcision and a
 * dressing produces one consent, not two — the dressing has no template.
 *
 * Status is PENDING. It becomes SIGNED only when the signed paper is scanned
 * back in, which is the same rule the dental module already uses; nothing here
 * marks a consent signed just because it printed.
 *
 * Returns the number of consents created.
 */
function consent_create_for_bill(
    PDO $pdo,
    int $procedureBillId,
    int $patientId,
    int $doctorId,
    array $lines,
    array $patient,
    string $doctorName,
    int $capturedById,
    string $signerName = '',
    string $signerRelation = '',
    string $signerCnic = ''
): int {
    $created = 0;

    // The CNIC column arrived after the consent table did (see
    // sql/add_consent_signer_cnic.sql). On a database where that migration has
    // not run, fall back to the pre-CNIC INSERT rather than fataling — the same
    // fail-soft contract consent_column_live() gives the rest of this file. The
    // sheet then prints its CNIC rule for the parent to fill by hand, which is
    // exactly what it does when the value is simply unknown.
    $hasCnic = consent_cnic_column_live($pdo);

    $ins = $hasCnic
        ? $pdo->prepare('
            INSERT INTO dental_consents
                (patient_id, doctor_id, procedure_master_id, procedure_bill_id,
                 procedure_ref, consent_text, status, signed_name, signed_relation,
                 signed_cnic, captured_by_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ')
        : $pdo->prepare('
            INSERT INTO dental_consents
                (patient_id, doctor_id, procedure_master_id, procedure_bill_id,
                 procedure_ref, consent_text, status, signed_name, signed_relation, captured_by_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');

    foreach ($lines as $ln) {
        $template = consent_template_for($pdo, (int) $ln['master_id']);
        if ($template === null) {
            continue;
        }

        // FROZEN AT CREATION. The catalogue template can be edited tomorrow;
        // what this patient signed must not change with it. Every reprint of
        // this consent renders from consent_text, never from the catalogue.
        $consentText = consent_render($template, [
            'patient_name'    => mb_strtoupper((string) ($patient['name'] ?? ''), 'UTF-8'),
            'father_name'     => mb_strtoupper((string) ($patient['father_name'] ?? ''), 'UTF-8'),
            'mrn'             => (string) ($patient['mrn'] ?? ''),
            'procedure_name'  => (string) $ln['name'],
            'doctor_name'     => mb_strtoupper($doctorName, 'UTF-8'),
            'signer_name'     => mb_strtoupper($signerName, 'UTF-8'),
            'signer_relation' => mb_strtoupper($signerRelation, 'UTF-8'),
            // Not uppercased: a CNIC is digits and dashes, so case is a no-op
            // and mb_strtoupper would only obscure that.
            'signer_cnic'     => $signerCnic,
        ], false);

        $params = [
            $patientId,
            $doctorId,
            (int) $ln['master_id'],
            $procedureBillId,
            (string) $ln['name'],
            $consentText,
            'PENDING',
            $signerName !== '' ? mb_strtoupper($signerName, 'UTF-8') : null,
            $signerRelation !== '' ? mb_strtoupper($signerRelation, 'UTF-8') : null,
        ];
        if ($hasCnic) {
            $params[] = $signerCnic !== '' ? $signerCnic : null;
        }
        $params[] = $capturedById;

        $ins->execute($params);
        $created++;
    }

    return $created;
}


/**
 * The consents printed with a given procedure bill, for the print branch and
 * the register.
 *
 * Fails soft: on a database where the consent migration has not run, a
 * procedure bill still prints — just without any consent pages attached.
 */
function consent_for_bill(PDO $pdo, int $procedureBillId): array
{
    try {
        $stmt = $pdo->prepare('
            SELECT c.id, c.procedure_ref, c.consent_text, c.status,
                   c.signed_name, c.signed_relation, c.created_at'
            . (consent_cnic_column_live($pdo) ? ', c.signed_cnic' : '') . '
              FROM dental_consents c
             WHERE c.procedure_bill_id = ? AND c.voided_at IS NULL
             ORDER BY c.id
        ');
        $stmt->execute([$procedureBillId]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}


/**
 * The relations offered at billing time. Free text would give us "father",
 * "Father", "FATHER" and "walid" in the same column within a week, and this
 * value is printed on a document the clinic keeps for its records.
 */
function consent_relations(): array
{
    return ['Father', 'Mother', 'Guardian', 'Spouse', 'Son', 'Daughter', 'Brother', 'Sister', 'Self', 'Other'];
}
