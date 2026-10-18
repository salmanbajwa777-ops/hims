<?php
/**
 * Shared "admit a patient" handler — one code path for all three entry points:
 * reception queue (receptionist.php), doctor console (doctor.php), and the
 * all-patients list (patients.php).
 *
 * Two admit contexts are supported:
 *   1. A patient with a visit today  -> admit against that visit_id.
 *   2. A patient with NO visit today -> a lightweight visit SHELL is auto-created in
 *      the same transaction, so admissions.visit_id stays NOT NULL / UNIQUE (never
 *      made nullable — that would ripple through ~6 JOINs). The shell is a real visit
 *      row with no consultation bill; it just anchors the admission.
 *
 * Gated on ADMISSION_ADMIT_PATIENT (doctor + reception + admin + manager) — matching
 * the check the three UI entry points (patients / receptionist / doctor) use.
 * Requires an open PDO and an authenticated session.
 *
 * Returns ['ok' => bool, 'error' => string, 'admission_id' => int|null]. The caller
 * decides how to redirect / surface the result.
 */

// Required here rather than left to the callers: admits arrive from three pages
// (receptionist.php, doctor.php, patients.php) and only one of them loaded this
// library, so a sheet row silently went missing from the other two.

require_once __DIR__ . '/permissions.php';   // audit_log(), has_permission()
require_once __DIR__ . '/sheets.php';
// Same reasoning: the shell-visit token must be issued the same way from all three.
require_once __DIR__ . '/tokens.php';
// Primary nurse is mandatory at admit — the roster/validator is shared with the
// IPD handler and the stay pages so they can never disagree on who qualifies.
require_once __DIR__ . '/nurses.php';
// Admitting doctor is mandatory too — shared resolver/validator.
require_once __DIR__ . '/doctors.php';
// notify_patient_admitted() — exactly the same reasoning as sheets.php above, and
// it had exactly the same outcome: my_queue.php handled an admit without loading
// this, so the function_exists() guard at the call site swallowed the alert and
// that admit notified nobody. require_once is a no-op where it is already loaded.
require_once __DIR__ . '/notify.php';
// Draft admission bill header, raised inside the admit transaction (see below).
require_once __DIR__ . '/admission_bill.php';
// rx_pre_admit_evidence() / RX_LATE_GAP_MIN: the live admit-gap uses the SAME
// signals as the late-admission detector on the exceptions page.
require_once __DIR__ . '/revenue_exceptions.php';

/** Fixed reasons reception must pick from when it (not a doctor) marks an admit. */
const ADMIT_RECEPTION_REASONS = ['Doctor did not mark it', 'Emergency - doctor busy', 'Walk-in direct admit', 'Other'];

/**
 * Validate the reception reason. Returns [value, error]; value is what is stored
 * ('Other: <text>' for Other). Picking a reason is the ONLY requirement - it never
 * blocks an emergency beyond that one click.
 */
function resolve_reception_admit_reason($pick, $other): array {
    $pick = trim((string) $pick);
    if (!in_array($pick, ADMIT_RECEPTION_REASONS, true)) {
        return [null, 'Pick why reception is marking this admission (the doctor did not).'];
    }
    if ($pick !== 'Other') { return [$pick, '']; }
    $txt = trim((string) preg_replace('/\s+/', ' ', (string) $other));
    $len = function_exists('mb_strlen') ? mb_strlen($txt) : strlen($txt);
    if ($len < 3) { return [null, 'Type the reason when you pick "Other".']; }
    $txt = function_exists('mb_substr') ? mb_substr($txt, 0, 150) : substr($txt, 0, 150);
    return ['Other: ' . $txt, ''];
}

function handle_admit_patient(PDO $pdo): array {
    $out = ['ok' => false, 'error' => '', 'admission_id' => null, 'notice' => ''];

    // Single admit key now. The legacy RECEPTION_ADMIT_PATIENTS was unified into
    // ADMISSION_ADMIT_PATIENT and back-granted to every prior holder in
    // sql/rbac_overhaul_2_grants.sql, so the OR on the old key is gone.
    if (!has_permission('ADMISSION_ADMIT_PATIENT')) {
        $out['error'] = 'You do not have permission to admit patients.';
        return $out;
    }

    $visitId   = (int) ($_POST['visit_id'] ?? 0);
    $patientId = (int) ($_POST['patient_id'] ?? 0);   // used only when there's no visit
    $admType   = $_POST['admission_type'] ?? '';
    // A system doctor, or a typed name when "Other" was picked. Exactly one of
    // the pair comes back set; both null means nothing usable was submitted.
    [$docId, $docManual] = resolve_doctor_pick($pdo, $_POST['admitting_doctor_id'] ?? 0, $_POST['admitting_doctor_manual'] ?? '');
    $nurseId   = (int) ($_POST['assigned_nurse_id'] ?? 0);

    // Admitting doctor is REQUIRED. The admission bill and the clinical record
    // both hang off it, and a stay showing "—" for doctor is unauditable later.
    if (!$docId && !$docManual) {
        $out['error'] = 'Select the admitting doctor — pick "Other" to type a name.';
        return $out;
    }

    // Primary nurse is REQUIRED — no stay may exist without someone accountable
    // for it. This only fixes ownership: any staff member with the relevant
    // nursing permission can still record vitals and log services on this stay.
    // Validated against the live roster because a POST can name any user id.
    if (!is_valid_nurse($pdo, 'NURSING_ATTEND_SHORT_STAY', $nurseId)) {
        $out['error'] = $nurseId > 0
            ? 'The selected primary nurse is not an active nursing staff member.'
            : 'Assign a primary nurse to admit this patient.';
        return $out;
    }

    // Type must be a currently-enabled admission type.
    $rateOk = $pdo->prepare('SELECT 1 FROM admission_rates WHERE admission_type = ? AND is_enabled = 1');
    $rateOk->execute([$admType]);
    if (!$rateOk->fetchColumn()) {
        $out['error'] = 'Pick a valid, enabled admission type.';
        return $out;
    }

    $baseRole = $_SESSION['base_role'] ?? '';
    $uid = (int) $_SESSION['user_id'];
    // admitted_by_role is a fixed enum — map anything unexpected to a safe default.
    // 3-role world: base_role is ADMIN/DOCTOR/STAFF, all valid in the widened
    // admitted_by_role audit enum. Fall back to STAFF for any legacy value.
    $admitRole = in_array($baseRole, ['ADMIN','DOCTOR','STAFF'], true) ? $baseRole : 'STAFF';

    // Reception (anyone who is not a DOCTOR) must say why the doctor did not mark
    // this admit themselves. Doctors' own admits carry no reason. Pre-migration
    // (column absent) nothing is asked or stored, exactly as before.
    $recReason = null;
    $isReceptionAdmit = ($baseRole !== 'DOCTOR');
    $hasReasonCol = adm_has($pdo, 'admissions', 'reception_admit_reason');
    if ($isReceptionAdmit && $hasReasonCol) {
        [$recReason, $reasonErr] = resolve_reception_admit_reason($_POST['reception_admit_reason'] ?? '', $_POST['reception_admit_reason_other'] ?? '');
        if ($recReason === null) {
            $out['error'] = $reasonErr;
            return $out;
        }
    }

    $pdo->beginTransaction();
    try {
        // Resolve the visit to admit against. If none was passed, this is an admit from
        // the all-patients list: reuse today's visit for this patient if one exists,
        // else create a visit shell.
        if ($visitId <= 0) {
            if ($patientId <= 0) {
                throw new RuntimeException('no_target');
            }
            // Prefer an existing not-yet-admitted visit for this patient today.
            $todays = $pdo->prepare("
                SELECT v.id FROM visits v
                LEFT JOIN admissions a ON a.visit_id = v.id
                WHERE v.patient_id = ? AND v.visit_date = CURDATE() AND a.id IS NULL
                ORDER BY v.id DESC LIMIT 1
            ");
            $todays->execute([$patientId]);
            $visitId = (int) ($todays->fetchColumn() ?: 0);

            if ($visitId <= 0) {
                // No usable visit today — create a shell. It needs a doctor + consult
                // type (visits columns are NOT NULL). Use the admitting doctor if given;
                // otherwise the patient's most recent doctor; the consult type is that
                // doctor's first available type. This shell carries NO consultation bill.
                // The admitting doctor when they're a system user; otherwise the
                // doctor who last saw this patient (same definition the admit
                // picker defaults to). A typed "Other" name can't anchor a visit.
                $shellDoctorId = $docId ?: last_seen_doctor_id($pdo, $patientId);
                if (!$shellDoctorId) {
                    throw new RuntimeException('no_doctor');
                }
                $ctStmt = $pdo->prepare('SELECT id, fee FROM doctor_consult_types WHERE doctor_id = ? ORDER BY id LIMIT 1');
                $ctStmt->execute([$shellDoctorId]);
                $ct = $ctStmt->fetch();
                if (!$ct) {
                    throw new RuntimeException('no_consult_type');
                }

                // Race-safe, session-aware token — same helper as registration.
                $token = issue_token($pdo, $shellDoctorId);
                $tokenNo = $token['no'];
                $tokenSession = $token['session'];

                // Shell visit: anchors the admission only. It carries NO consultation
                // bill, so its fee never reaches any tally. consult_status = 'DONE' keeps
                // it out of the doctor's waiting queue (the patient is being admitted, not
                // waiting for an OPD consult). payment_mode is NOT NULL in the schema, so a
                // neutral 'CASH' is stored even though nothing is billed here.
                $pdo->prepare('
                    INSERT INTO visits (token_no, token_session, patient_id, doctor_id, doctor_consult_type_id, fee, discount_pct, payment_mode, visit_date, created_by_id, consultation_fee_type, consult_status, disposition)
                    VALUES (?, ?, ?, ?, ?, ?, 0, ?, CURDATE(), ?, ?, ?, ?)
                ')->execute([
                    $tokenNo, $tokenSession, $patientId, $shellDoctorId, (int) $ct['id'], (float) $ct['fee'],
                    'CASH', $uid, 'FULL', 'DONE', 'SHORT_STAY',
                ]);
                $visitId = (int) $pdo->lastInsertId();
            }
        }

        // One admission per visit (admissions.visit_id is UNIQUE).
        $exists = $pdo->prepare('SELECT 1 FROM admissions WHERE visit_id = ?');
        $exists->execute([$visitId]);
        if ($exists->fetchColumn()) {
            throw new RuntimeException('already_admitted');
        }

        // Opens ACTIVE, not PENDING_ASSIGNMENT: the primary nurse is captured in
        // this same INSERT, so the "awaiting nurse" state is unreachable for new
        // admits. The enum value is kept for stays created before this change,
        // which admission.php can still assign a nurse to.
        // Reception admits: minutes between NOW and the earliest earlier ER /
        // doctor-visit event for this patient (previous 24h), kept only when it
        // exceeds RX_LATE_GAP_MIN. Best-effort - a failure here never blocks an admit.
        $gapMin = null;
        if ($isReceptionAdmit && $hasReasonCol && adm_has($pdo, 'admissions', 'admit_gap_minutes')) {
            try {
                $pq = $pdo->prepare('SELECT patient_id FROM visits WHERE id = ?');
                $pq->execute([$visitId]);
                $ev = rx_pre_admit_evidence($pdo, (int) $pq->fetchColumn(), time(), ['er', 'visit'], $visitId);
                if ($ev && $ev['gap_min'] > RX_LATE_GAP_MIN) {
                    $gapMin = (int) $ev['gap_min'];
                    $out['notice'] = 'Noted: this patient had an earlier ' . strtolower($ev['source']) . ' about '
                        . $gapMin . ' minutes before this admission. The admission is open and unaffected; the gap is recorded for review.';
                }
            } catch (Throwable $e) { $gapMin = null; }
        }
        $admCols = ['visit_id', 'admission_type', 'admitted_by_id', 'admitted_by_role', 'admitted_at',
                    'assigned_nurse_id', 'assigned_at', 'admitting_doctor_id', 'admitting_doctor_manual', 'status'];
        $admVals = '?, ?, ?, ?, NOW(), ?, NOW(), ?, ?, \'ACTIVE\'';
        $admArgs = [$visitId, $admType, $uid, $admitRole, $nurseId, $docId, $docManual];
        if ($recReason !== null) {
            $admCols[] = 'reception_admit_reason'; $admVals .= ', ?'; $admArgs[] = $recReason;
            if (adm_has($pdo, 'admissions', 'admit_gap_minutes')) {
                $admCols[] = 'admit_gap_minutes'; $admVals .= ', ?'; $admArgs[] = $gapMin;
            }
        }
        $pdo->prepare('INSERT INTO admissions (' . implode(', ', $admCols) . ') VALUES (' . $admVals . ')')
            ->execute($admArgs);
        $admissionId = (int) $pdo->lastInsertId();

        $pdo->prepare('UPDATE visits SET disposition = \'SHORT_STAY\', admission_type = ?, admitted_at = NOW() WHERE id = ?')
            ->execute([$admType, $visitId]);

        // >>> BILL-DRAFT (billing): raise the draft admission_bills header in THIS
        // transaction so every admission owns an invoice number from minute zero.
        // Lines are seeded later by ensure_admission_bill() from discharged_at.
        // No-op until sql/2026_10_admission_bill_approvals.sql adds items_seeded_at.
        $billPatient = $pdo->prepare('SELECT patient_id FROM visits WHERE id = ?');
        $billPatient->execute([$visitId]);
        // A header failure (e.g. duplicate invoice number) must NEVER roll back the
        // admission. A failed single INSERT is rolled back by the engine at statement
        // level and leaves the open transaction usable, so swallow + log; the lazy
        // ensure_admission_bill() on the discharge page creates the bill instead.
        try {
            adm_create_bill_header($pdo, $admissionId, (int) $billPatient->fetchColumn(), $uid);
        } catch (Throwable $e) {
            error_log('admit: bill header skipped for admission #' . $admissionId . ': ' . $e->getMessage());
        }
        // <<< BILL-DRAFT

        audit_log($pdo, 'patient_admitted', "Admitted visit #$visitId ($admType), admission #$admissionId by $admitRole, primary nurse #$nurseId", $uid);

        $pdo->commit();

        // Alert admin + admitting doctor (best-effort, after commit).
        if (function_exists('notify_patient_admitted')) {
            notify_patient_admitted($pdo, $admissionId);
        }
        // Log the admission to the yearly Google Sheet, money columns blank — the
        // bill only exists at discharge, which pushes its own row (best-effort).
        if (function_exists('sheet_push')) {
            sheet_push($pdo, 'ADMISSION', $admissionId, $uid);
        }

        // The doctor named on a RECEPTION admit is told they did not mark it
        // (best-effort, after commit; notify_users() never throws).
        if ($isReceptionAdmit && $hasReasonCol && $docId && (int) $docId !== $uid && function_exists('notify_users')) {
            try {
                $pn = $pdo->prepare('SELECT p.name, p.mrn FROM visits v JOIN patients p ON p.id = v.patient_id WHERE v.id = ?');
                $pn->execute([$visitId]);
                $pr = $pn->fetch();
                notify_users($pdo, [(int) $docId], 'patient_admitted_by_reception',
                    'You were named as admitting doctor but did not mark this admission',
                    ($pr ? $pr['name'] . ' (MRN ' . $pr['mrn'] . ')' : 'A patient')
                        . ' was admitted by reception' . ($recReason !== null ? ' — reason: ' . $recReason : '') . '.',
                    'admission.php?id=' . $admissionId, 'Admission #' . $admissionId);
            } catch (Throwable $e) { /* best-effort */ }
        }
        if ($out['notice'] !== '') { $_SESSION['admit_notice'] = $out['notice']; }

        $out['ok'] = true;
        $out['admission_id'] = $admissionId;
        return $out;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $msg = $e->getMessage();
        $out['error'] = [
            'already_admitted' => 'This visit is already admitted.',
            'no_target'        => 'No patient or visit to admit.',
            'no_doctor'        => 'No doctor on record for this patient — register a visit first, then admit.',
            'no_consult_type'  => 'That doctor has no consultation type configured.',
        ][$msg] ?? 'Could not admit — please try again.';
        return $out;
    }
}
