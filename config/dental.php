<?php
/**
 * Dental module — shared helpers.
 *
 * Everything dental-specific that more than one page needs: FDI tooth
 * validation, the DA/DP number series, the account balance, the voids, the
 * consent gate, and the doctor's earned share on package work.
 *
 * WHAT IS NOT HERE: anything that already exists. Consultation fees, the
 * P-series procedure bill, patient discount categories, the day lock and the
 * doctor/clinic split all come from config/billing.php unchanged. Dental adds
 * a ledger for multi-visit work; it does not fork the billing engine.
 *
 * Requires config/billing.php (require_day_open, doctor_split_sql).
 */

require_once __DIR__ . '/billing.php';


// The dental taxonomy, mirroring the ENUM in add_dental_procedure_fields.sql.
// Ordered roughly by clinical workflow (diagnose -> restore -> ... -> surgery)
// rather than alphabetically, because that is how a dentist scans a list.
// Shared here so the catalogue admin, the treatment picker and the account
// picker cannot drift apart on wording.
const DENTAL_CAT_LABELS = [
    'DIAGNOSTIC'  => 'Diagnostic',
    'RESTORATIVE' => 'Restorative',
    'ENDODONTIC'  => 'Endodontic',
    'EXTRACTION'  => 'Extraction',
    'PROSTHETICS' => 'Prosthetics',
    'ORTHO'       => 'Orthodontic',
    'SURGERY'     => 'Surgery',
];


// ============================================================================
// FDI TOOTH NOTATION
//
// Tooth numbers are NOT universal. FDI (taught in Pakistan and most of the
// world), Universal (US, 1-32) and Palmer all collide: "36" is a lower-left
// first molar in FDI and does not exist in Universal, while Universal "19" is
// the same tooth. A mis-read tooth number is a medico-legal error, not a
// cosmetic one — the wrong tooth gets drilled.
//
// So the module standardises on FDI two-digit, validates every entry against
// the legal set, and labels the field "Tooth (FDI)" everywhere so nobody has
// to guess which system a number is in.
// ============================================================================

// The 52 legal FDI codes, in mouth order. Quadrant 1-4 permanent, 5-8 primary.
//
// This is an explicit allow-list, NOT a regex, on purpose: every plausible
// pattern (/^[1-8][1-8]$/ and friends) also admits 19, 29, 56 and 86, none of
// which are teeth. Enumerating 52 values is cheaper than being subtly wrong.
function fdi_teeth(): array {
    static $teeth = null;
    if ($teeth !== null) {
        return $teeth;
    }
    $teeth = [];
    // Permanent: quadrants 1-4, teeth 1-8 (central incisor -> third molar).
    foreach ([1, 2, 3, 4] as $q) {
        for ($t = 1; $t <= 8; $t++) { $teeth[] = $q . $t; }
    }
    // Primary (deciduous): quadrants 5-8, teeth 1-5. Children have no premolars
    // or third molars, which is why these quadrants stop at 5.
    foreach ([5, 6, 7, 8] as $q) {
        for ($t = 1; $t <= 5; $t++) { $teeth[] = $q . $t; }
    }
    return $teeth;
}

// Is this a real tooth? Empty is NOT passed here — callers allow an empty tooth
// for whole-mouth work (a scaling, a denture) and only validate non-empty input.
function is_valid_fdi(string $tooth): bool {
    return in_array($tooth, fdi_teeth(), true);
}

// The four quadrants, for grouping the picker. Returned as the <optgroup> label.
function fdi_quadrant_label(string $tooth): string {
    static $labels = [
        '1' => 'Upper right (permanent)', '2' => 'Upper left (permanent)',
        '3' => 'Lower left (permanent)',  '4' => 'Lower right (permanent)',
        '5' => 'Upper right (primary)',   '6' => 'Upper left (primary)',
        '7' => 'Lower left (primary)',    '8' => 'Lower right (primary)',
    ];
    return $labels[substr($tooth, 0, 1)] ?? '';
}

// Human name for a tooth, e.g. '11' -> "Upper right central incisor (11)".
// Shown in the picker so a mis-selection is obvious before it is saved.
function fdi_label(string $tooth): string {
    if (!is_valid_fdi($tooth)) {
        return $tooth;
    }
    static $permanent = [
        1 => 'central incisor', 2 => 'lateral incisor', 3 => 'canine',
        4 => 'first premolar',  5 => 'second premolar', 6 => 'first molar',
        7 => 'second molar',    8 => 'third molar',
    ];
    // Primary teeth have no premolars: positions 4 and 5 are molars.
    static $primary = [
        1 => 'central incisor', 2 => 'lateral incisor', 3 => 'canine',
        4 => 'first molar',     5 => 'second molar',
    ];
    $q = (int) substr($tooth, 0, 1);
    $t = (int) substr($tooth, 1, 1);
    $name = $q <= 4 ? ($permanent[$t] ?? '') : ($primary[$t] ?? '');
    $side = in_array($q, [1, 5], true) ? 'Upper right'
          : (in_array($q, [2, 6], true) ? 'Upper left'
          : (in_array($q, [3, 7], true) ? 'Lower left' : 'Lower right'));
    return "$side $name ($tooth)";
}

// Renders the shared "Tooth (FDI)" picker. A <select> rather than a text input
// because it makes an invalid entry unreachable in the normal path — the server
// still validates, since a POST is not obliged to come from this form.
//
// $name is the field name, $selected the current value, $required whether a
// tooth must be chosen (false for whole-mouth work).
function fdi_select_html(string $name, string $selected = '', bool $required = false, string $extraAttrs = ''): string {
    $h = '<select name="' . htmlspecialchars($name) . '"' . ($required ? ' required' : '') . ' ' . $extraAttrs . '>';
    $h .= '<option value="">' . ($required ? '— select a tooth —' : '— not tooth-specific —') . '</option>';
    $currentGroup = '';
    foreach (fdi_teeth() as $t) {
        $group = fdi_quadrant_label($t);
        if ($group !== $currentGroup) {
            if ($currentGroup !== '') { $h .= '</optgroup>'; }
            $h .= '<optgroup label="' . htmlspecialchars($group) . '">';
            $currentGroup = $group;
        }
        $h .= '<option value="' . $t . '"' . ($selected === $t ? ' selected' : '') . '>'
            . htmlspecialchars(fdi_label($t)) . '</option>';
    }
    if ($currentGroup !== '') { $h .= '</optgroup>'; }
    return $h . '</select>';
}


// ============================================================================
// NUMBER SERIES
//
// Two series, both "{PREFIX}{seq}{YY}{MM}" with the sequence resetting monthly,
// matching every other series in the app:
//
//   DA  the account   — one per package          e.g. DA1 2607
//   DP  the receipt   — many per account         e.g. DP1 2607, DP2 2607
//
// DP is its own series precisely BECAUSE one account produces N receipts.
// Reusing P would collide: generate_procedure_invoice_number() derives its next
// value from MAX() over procedure_bills, which knows nothing about these.
//
// !! THE PREFIX IS TWO CHARACTERS !!
// The one-character series (P/A/E/I) parse their sequence with
// SUBSTRING(n, 2, CHAR_LENGTH(n) - 5). Copying that here would strip only "D"
// and leave "A1" — CAST('A1' AS UNSIGNED) is 0, so the counter would restart at
// 1 every call and the UNIQUE key would throw on the month's second account.
// Two-character prefixes need SUBSTRING(n, 3, CHAR_LENGTH(n) - 6).
//
// Both generators keep the GREATEST(stored counter, real MAX) guard from
// generate_procedure_invoice_number(): the counter table alone is not trusted,
// because a restored backup or a hand-inserted row can leave it behind the
// actual data, and a re-issued invoice number is the one thing a series must
// never do.
// ============================================================================

// Shared body for both series. $prefix is the 2-char series, $table/$col the
// place real numbers live, $counterTable the (yr, mo, next_seq) high-water mark.
function dental_next_number(PDO $pdo, string $prefix, string $table, string $col, string $counterTable): string {
    $year  = (int) date('Y');
    $month = (int) date('n');
    $yymm  = substr((string) $year, 2, 2) . str_pad((string) $month, 2, '0', STR_PAD_LEFT);

    // $table/$col/$counterTable are literals from this file, never user input.
    $stmt = $pdo->prepare("
        SELECT GREATEST(
            COALESCE((SELECT next_seq - 1 FROM $counterTable WHERE yr = :y AND mo = :m), 0),
            COALESCE((SELECT MAX(CAST(SUBSTRING($col, 3, CHAR_LENGTH($col) - 6) AS UNSIGNED))
                      FROM $table WHERE $col LIKE :pfx), 0)
        ) + 1
    ");
    $stmt->execute([':y' => $year, ':m' => $month, ':pfx' => $prefix . '%' . $yymm]);
    $seq = (int) $stmt->fetchColumn();
    if ($seq < 1) {
        $seq = 1;
    }

    $pdo->prepare("
        INSERT INTO $counterTable (yr, mo, next_seq)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE next_seq = GREATEST(next_seq, VALUES(next_seq))
    ")->execute([$year, $month, $seq + 1]);

    return $prefix . $seq . $yymm;
}

function generate_dental_account_number(PDO $pdo): string {
    return dental_next_number($pdo, 'DA', 'dental_procedure_accounts', 'account_number', 'dental_account_counters');
}

function generate_dental_payment_number(PDO $pdo): string {
    return dental_next_number($pdo, 'DP', 'dental_procedure_payments', 'receipt_number', 'dental_payment_counters');
}


// ============================================================================
// THE BALANCE
// ============================================================================

// An account's money position, computed from live rows every time.
//
// There is no stored total anywhere — see the migration header. This function
// is the ONLY definition of what an account is worth, so no page can forget the
// `voided_at IS NULL` filter and quietly show a total that includes a dropped
// crown.
//
// Returns:
//   charged   SUM of live item amounts (procedure fees, post-discount)
//   lab       SUM of live item lab charges (added on top, always visible)
//   total     charged + lab — what the patient owes in all
//   paid      SUM of live payments
//   balance   total - paid. NEGATIVE means the patient has overpaid.
//   overpaid  max(0, paid - total), surfaced as REFUND DUE
//   items / payments   live row counts, for "3 items · 2 payments" summaries
function dental_account_totals(PDO $pdo, int $accountId): array {
    $stmt = $pdo->prepare('
        SELECT
          COALESCE((SELECT SUM(i.amount) FROM dental_procedure_account_items i
                     WHERE i.account_id = :a1 AND i.voided_at IS NULL), 0)     AS charged,
          COALESCE((SELECT SUM(i.lab_charge) FROM dental_procedure_account_items i
                     WHERE i.account_id = :a2 AND i.voided_at IS NULL), 0)     AS lab,
          COALESCE((SELECT COUNT(*) FROM dental_procedure_account_items i
                     WHERE i.account_id = :a3 AND i.voided_at IS NULL), 0)     AS items,
          COALESCE((SELECT SUM(p.amount) FROM dental_procedure_payments p
                     WHERE p.account_id = :a4 AND p.status = \'paid\'
                       AND p.voided_at IS NULL), 0)                            AS paid,
          COALESCE((SELECT COUNT(*) FROM dental_procedure_payments p
                     WHERE p.account_id = :a5 AND p.status = \'paid\'
                       AND p.voided_at IS NULL), 0)                            AS payments
    ');
    $stmt->execute([':a1' => $accountId, ':a2' => $accountId, ':a3' => $accountId,
                    ':a4' => $accountId, ':a5' => $accountId]);
    $r = $stmt->fetch() ?: [];

    $charged = round((float) ($r['charged'] ?? 0), 2);
    $lab     = round((float) ($r['lab'] ?? 0), 2);
    $paid    = round((float) ($r['paid'] ?? 0), 2);
    $total   = round($charged + $lab, 2);

    return [
        'charged'  => $charged,
        'lab'      => $lab,
        'total'    => $total,
        'paid'     => $paid,
        'balance'  => round($total - $paid, 2),
        'overpaid' => max(0.0, round($paid - $total, 2)),
        'items'    => (int) ($r['items'] ?? 0),
        'payments' => (int) ($r['payments'] ?? 0),
    ];
}

// Is the dental module migrated? Pages that merely REFERENCE dental (the doctor
// dashboard card, the share statement row) ask this so an un-run migration
// degrades to "nothing to show" instead of a fatal. The dental pages themselves
// don't bother — they cannot function at all without their tables.
function dental_tables_live(PDO $pdo): bool {
    static $live = null;
    if ($live !== null) {
        return $live;
    }
    try {
        $pdo->query('SELECT 1 FROM dental_procedure_accounts LIMIT 1')->fetchAll();
        $live = true;
    } catch (PDOException $e) {
        $live = false;
    }
    return $live;
}


// ============================================================================
// VOIDS
//
// Both follow void_procedure_bill()'s contract exactly: a reason is mandatory,
// the row is stamped rather than deleted, one transaction, an audit_logs entry,
// and [bool $ok, string $message] back.
// ============================================================================

// Void an account item — a planned crown the patient declined, a line entered
// on the wrong tooth. The item stays visible, struck through with its reason.
//
// NO day lock here, deliberately: an item is a quote line, not cash. Voiding
// one lowers what is owed; it does not touch any signed day's tally. (Voiding a
// PAYMENT does, and that one is locked — see below.)
function void_dental_account_item(PDO $pdo, int $itemId, int $userId, string $reason): array {
    $reason = trim($reason);
    if ($reason === '') {
        return [false, 'A void needs a reason.'];
    }
    $stmt = $pdo->prepare('
        SELECT i.id, i.description, i.amount, i.voided_at, i.account_id, a.account_number, a.status
          FROM dental_procedure_account_items i
          JOIN dental_procedure_accounts a ON a.id = i.account_id
         WHERE i.id = ?
    ');
    $stmt->execute([$itemId]);
    $it = $stmt->fetch();
    if (!$it) {
        return [false, 'Item not found.'];
    }
    if ($it['voided_at'] !== null) {
        return [false, 'This item is already voided.'];
    }
    if ($it['status'] === 'CANCELLED') {
        return [false, 'This account is cancelled — its balance is frozen and its items can no longer change.'];
    }

    $pdo->beginTransaction();
    try {
        $upd = $pdo->prepare('UPDATE dental_procedure_account_items
                                 SET voided_at = NOW(), voided_by_id = ?, void_reason = ?
                               WHERE id = ? AND voided_at IS NULL');
        $upd->execute([$userId, $reason, $itemId]);
        if ($upd->rowCount() !== 1) {
            $pdo->rollBack();
            return [false, 'Could not void the item (already voided?).'];
        }
        $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
            ->execute([$userId, 'dental_account_item_voided',
                       "Voided \"{$it['description']}\" (Rs {$it['amount']}) on account {$it['account_number']} — $reason"]);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('[void_dental_account_item] ' . $e->getMessage());
        return [false, 'Could not void the item. Please try again.'];
    }
    return [true, 'Item voided. The account total has been reduced.'];
}

// Void a payment — a receipt raised in error, or money returned at the desk.
//
// This one DOES take the day lock: the money was counted in a cashier's shift,
// so reversing it after that shift is signed off would silently change a closed
// day's cash position. Locked against the ORIGINAL drawer and date, not today's.
function void_dental_payment(PDO $pdo, int $paymentId, int $userId, string $reason): array {
    $reason = trim($reason);
    if ($reason === '') {
        return [false, 'A void needs a reason.'];
    }
    $stmt = $pdo->prepare('
        SELECT p.id, p.receipt_number, p.amount, p.paid_at, p.paid_by_id, p.created_by_id,
               p.status, p.voided_at, a.account_number
          FROM dental_procedure_payments p
          JOIN dental_procedure_accounts a ON a.id = p.account_id
         WHERE p.id = ?
    ');
    $stmt->execute([$paymentId]);
    $pay = $stmt->fetch();
    if (!$pay) {
        return [false, 'Payment not found.'];
    }
    if ($pay['voided_at'] !== null) {
        return [false, 'This payment is already voided.'];
    }

    $lockDate = date('Y-m-d', strtotime($pay['paid_at']));
    $lockUser = (int) ($pay['paid_by_id'] ?: $pay['created_by_id']);
    $dayLock = require_day_open($pdo, $lockDate, $lockUser);
    if ($dayLock) {
        return [false, $dayLock . ' Voiding this receipt would change that day\'s signed tally.'];
    }

    $pdo->beginTransaction();
    try {
        $upd = $pdo->prepare("UPDATE dental_procedure_payments
                                 SET status = 'voided', voided_at = NOW(), voided_by_id = ?, void_reason = ?
                               WHERE id = ? AND voided_at IS NULL");
        $upd->execute([$userId, $reason, $paymentId]);
        if ($upd->rowCount() !== 1) {
            $pdo->rollBack();
            return [false, 'Could not void the payment (already voided?).'];
        }
        $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
            ->execute([$userId, 'dental_payment_voided',
                       "Voided receipt {$pay['receipt_number']} (Rs {$pay['amount']}) on account {$pay['account_number']} — $reason"]);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('[void_dental_payment] ' . $e->getMessage());
        return [false, 'Could not void the payment. Please try again.'];
    }
    return [true, "Receipt {$pay['receipt_number']} voided. The number is kept for the record."];
}


// ============================================================================
// CONSENT GATE
// ============================================================================

// Has this patient signed consent for this procedure?
//
// procedure_master.mandatory_consent has existed since the procedure catalogue
// was built, but nothing enforced it — the biller only WARNED, because the
// consent form itself was a later phase. This is that phase: a flagged
// procedure cannot be billed until a SIGNED, non-voided consent exists.
//
// Matching is by (patient, procedure) rather than per-visit: a patient who
// signed for a root canal last week does not re-sign to finish it this week.
// $accountId, when given, also accepts the account's own contract consent —
// signing the itemised package covers the procedures inside it.
function dental_consent_satisfied(PDO $pdo, int $patientId, int $procedureMasterId, ?int $accountId = null): bool {
    $sql = "SELECT 1 FROM dental_consents
             WHERE patient_id = :p AND status = 'SIGNED' AND voided_at IS NULL
               AND (procedure_master_id = :m";
    $args = [':p' => $patientId, ':m' => $procedureMasterId];
    if ($accountId !== null && $accountId > 0) {
        $sql .= ' OR account_id = :a';
        $args[':a'] = $accountId;
    }
    $sql .= ') LIMIT 1';

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        return (bool) $stmt->fetchColumn();
    } catch (PDOException $e) {
        // Table absent = module not migrated. Fail OPEN rather than closed: a
        // half-installed dental module must not block the ordinary procedure
        // biller that was working fine before it arrived.
        return true;
    }
}


// ============================================================================
// CONSENT SCAN STORAGE
//
// Same contract as config/staff_documents.php, which is the app's established
// upload pattern: a generated filename (so a stored path can never be
// caller-supplied), a fixed directory, an extension allow-list and a size cap
// checked BEFORE anything is written, and a deny-all .htaccess created by the
// directory helper itself.
//
// The .htaccess matters: uploads/ is gitignored, so the guard can never arrive
// by deploy — it has to be written at runtime. Without it a signed consent
// bearing a patient's name and treatment plan is fetchable at
// /uploads/dental_consents/<name>, protected only by an unguessable filename.
// ============================================================================

const DENTAL_CONSENT_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];
const DENTAL_CONSENT_MAX_BYTES = 10 * 1024 * 1024;

function dental_consent_dir(): string {
    $dir = dirname(__DIR__) . '/uploads/dental_consents/';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $guard = $dir . '.htaccess';
    if (!is_file($guard)) {
        // Apache 2.4 syntax first, 2.2 fallback — hPanel has run both.
        file_put_contents($guard, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
    return $dir;
}

// Validate one uploaded scan. Returns [$pending, $error]; nothing touches disk
// or the database here, so a rejected file cannot leave a half-written row.
// A missing file is NOT an error — the scan can be attached later.
function dental_consent_validate(?array $file): array {
    if (!$file || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return [null, ''];
    }
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        return [null, 'That file is larger than the server allows (10MB max).'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [null, 'The scan failed to upload. Please try again.'];
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, DENTAL_CONSENT_EXTENSIONS, true)) {
        return [null, 'The scan must be a PDF, JPG or PNG file.'];
    }
    if ($file['size'] > DENTAL_CONSENT_MAX_BYTES) {
        return [null, 'The scan is larger than 10MB.'];
    }
    return [['tmp' => $file['tmp_name'], 'name' => $file['name'],
             'size' => (int) $file['size'], 'ext' => $ext], ''];
}

// Move a validated scan into place and stamp it onto the consent row, which is
// then marked SIGNED — an uploaded scan IS the signature in this workflow
// (print, sign on paper, scan back in; there is no signature pad in v1).
// Returns true when the file landed.
function dental_consent_store(PDO $pdo, array $pending, int $consentId, int $uploaderId): bool {
    $dir = dental_consent_dir();
    $storedName = $consentId . '_' . bin2hex(random_bytes(8)) . '.' . $pending['ext'];
    if (!move_uploaded_file($pending['tmp'], $dir . $storedName)) {
        return false;
    }
    $pdo->prepare('UPDATE dental_consents
                      SET scan_path = ?, scan_original_name = ?, scan_size = ?, uploaded_by_id = ?,
                          status = \'SIGNED\', signed_at = COALESCE(signed_at, NOW())
                    WHERE id = ?')
        ->execute([$storedName, $pending['name'], $pending['size'], $uploaderId, $consentId]);
    return true;
}


// ============================================================================
// DOCTOR EARNINGS ON PACKAGE WORK
// ============================================================================

// What a dentist EARNED from package accounts in a date range.
//
// CASH BASIS, PROPORTIONAL RECOGNITION — the subtle part of this module.
//
// A package is quoted once and paid over months, so "when was it earned?" has
// no single answer. Every other money figure in this app is recognised when the
// cash arrives (b.paid_at), and a doctor share paid out on money the clinic has
// not collected is a real cash-flow problem. So:
//
//   each PAYMENT is apportioned across the account's live items in proportion
//   to their amount, and each apportioned slice is split with THAT item's own
//   snapshot (share %, tax) at the moment the payment landed.
//
// Worked example — a Rs 100,000 account, two items:
//   endo   Rs 40,000 @ 70% share      prosthetic Rs 60,000 @ 50% share
//   patient pays Rs 25,000 (25% of the account)
//   -> endo recognises 25% x 40,000 = 10,000 -> doctor 7,000
//   -> pros recognises 25% x 60,000 = 15,000 -> doctor 7,500
//   A single blended rate would be wrong here, which is the same reason
//   procedure_bill_items snapshots the share per LINE rather than per doctor.
//
// WHY THE LAB IS **NOT** PASSED AS `disposables`:
// The disposables mechanism deducts a cost from the fee BEFORE splitting, for
// supplies the clinic bought out of a fee the patient already paid — Rs 3,000
// fee with Rs 500 of sutures leaves Rs 2,500 divisible.
//
// Dental lab is the opposite shape. The lab charge is billed to the patient ON
// TOP of the fee (its own visible line, never buried in the quote), so the fee
// is already whole and nothing needs recovering out of it. Routing it through
// $disposables would deduct the lab from the procedure fee as well as charging
// it to the patient — double-counting it, and underpaying the dentist by 60% of
// the lab on every crown. (A test caught exactly that: Rs 3,000 to the dentist
// where Rs 6,000 was owed.)
//
// So the split runs on the fee alone, and the lab is simply added to the
// clinic's side as the pass-through it is: clinic = clinic_net + lab.
//
// Returns the same shape as doctor_procedure_earnings(), including 'live',
// which is false when the tables are absent so callers can show "not billed
// yet" instead of a misleading zero.
function doctor_dental_earnings(PDO $pdo, int $doctorId, string $from, string $toExcl): array {
    $zero = ['gross' => 0.0, 'fees' => 0.0, 'lab' => 0.0, 'tax' => 0.0, 'doctor' => 0.0,
             'clinic' => 0.0, 'clinic_net' => 0.0, 'accounts' => 0, 'live' => false];
    if ($doctorId <= 0) {
        return $zero;
    }

    // ratio = what fraction of the account's live total this payment settles.
    // Guarded with NULLIF so an account whose items were all voided after a
    // payment landed divides by NULL (-> row contributes nothing) rather than
    // erroring. That state is real: it is the CANCELLED-with-overpayment case.
    $ratio = 'p.amount / NULLIF(t.live_total, 0)';
    $amt   = "(i.amount * $ratio)";
    $lab   = "(i.lab_charge * $ratio)";

    // Split the FEE only — no disposables argument (see the note above).
    $doc  = doctor_split_sql($amt, 'i.doctor_share_pct', 'i.has_tax', 'i.tax_percent', 'doctor');
    $tax  = doctor_split_sql($amt, 'i.doctor_share_pct', 'i.has_tax', 'i.tax_percent', 'tax');
    $cnet = doctor_split_sql($amt, 'i.doctor_share_pct', 'i.has_tax', 'i.tax_percent', 'clinic_net');
    // The clinic keeps its share of the fee PLUS the whole lab pass-through, so
    // tax + doctor + clinic still re-sums to everything the patient paid.
    $clinic = "($cnet + $lab)";

    try {
        $q = $pdo->prepare("
            SELECT COUNT(DISTINCT a.id)          AS accounts,
                   -- gross = everything the patient paid, fee AND lab, so that
                   -- tax + doctor + clinic re-sums to it exactly.
                   COALESCE(SUM($amt + $lab), 0)  AS gross,
                   COALESCE(SUM($amt), 0)        AS fees,
                   COALESCE(SUM($lab), 0)        AS lab,
                   COALESCE(SUM($tax), 0)        AS tax,
                   COALESCE(SUM($doc), 0)        AS doctor,
                   COALESCE(SUM($clinic), 0)     AS clinic,
                   COALESCE(SUM($cnet), 0)       AS clinic_net
              FROM dental_procedure_payments p
              JOIN dental_procedure_accounts a ON a.id = p.account_id
              JOIN dental_procedure_account_items i
                    ON i.account_id = a.id AND i.voided_at IS NULL
              JOIN (
                    SELECT account_id, SUM(amount + lab_charge) AS live_total
                      FROM dental_procedure_account_items
                     WHERE voided_at IS NULL
                     GROUP BY account_id
                   ) t ON t.account_id = a.id
             WHERE a.doctor_id = ?
               AND p.status = 'paid'
               AND p.voided_at IS NULL
               AND p.paid_at >= ? AND p.paid_at < ?
        ");
        $q->execute([$doctorId, $from, $toExcl]);
        $r = $q->fetch();
    } catch (PDOException $e) {
        // Tables absent = migration not run. Not worth failing a whole
        // statement page over.
        return $zero;
    }

    return [
        // gross = fees + lab (everything collected); fees = the shareable part.
        'gross'      => (float) ($r['gross'] ?? 0),
        'fees'       => (float) ($r['fees'] ?? 0),
        'lab'        => (float) ($r['lab'] ?? 0),
        'tax'        => (float) ($r['tax'] ?? 0),
        'doctor'     => (float) ($r['doctor'] ?? 0),
        'clinic'     => (float) ($r['clinic'] ?? 0),
        'clinic_net' => (float) ($r['clinic_net'] ?? 0),
        'accounts'   => (int) ($r['accounts'] ?? 0),
        'live'       => true,
    ];
}
