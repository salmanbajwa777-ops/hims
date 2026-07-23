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
 * Gated on ADMISSION_ADMIT_PATIENT (doctor + reception + admin + manager). Requires an
 * open PDO, an authenticated session, and config/notify.php already loaded by the caller.
 *
 * Returns ['ok' => bool, 'error' => string, 'admission_id' => int|null]. The caller
 * decides how to redirect / surface the result.
 */

// Required here rather than left to the callers: admits arrive from three pages
// (receptionist.php, doctor.php, patients.php) and only one of them loaded this
// library, so a sheet row silently went missing from the other two.
require_once __DIR__ . '/sheets.php';

function handle_admit_patient(PDO $pdo): array {
    $out = ['ok' => false, 'error' => '', 'admission_id' => null];

    if (!has_permission('ADMISSION_ADMIT_PATIENT')) {
        $out['error'] = 'You do not have permission to admit patients.';
        return $out;
    }

    $visitId   = (int) ($_POST['visit_id'] ?? 0);
    $patientId = (int) ($_POST['patient_id'] ?? 0);   // used only when there's no visit
    $admType   = $_POST['admission_type'] ?? '';
    $docId     = (int) ($_POST['admitting_doctor_id'] ?? 0) ?: null;
    $docManual = trim($_POST['admitting_doctor_manual'] ?? '') ?: null;

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
    $admitRole = in_array($baseRole, ['ADMIN','MANAGER','RECEPTIONIST','DOCTOR','NURSE'], true) ? $baseRole : 'RECEPTIONIST';

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
                $shellDoctorId = $docId;
                if (!$shellDoctorId) {
                    $lastDoc = $pdo->prepare('SELECT doctor_id FROM visits WHERE patient_id = ? ORDER BY id DESC LIMIT 1');
                    $lastDoc->execute([$patientId]);
                    $shellDoctorId = (int) ($lastDoc->fetchColumn() ?: 0) ?: null;
                }
                if (!$shellDoctorId) {
                    throw new RuntimeException('no_doctor');
                }
                $ctStmt = $pdo->prepare('SELECT id, fee FROM doctor_consult_types WHERE doctor_id = ? ORDER BY id LIMIT 1');
                $ctStmt->execute([$shellDoctorId]);
                $ct = $ctStmt->fetch();
                if (!$ct) {
                    throw new RuntimeException('no_consult_type');
                }

                // Race-safe token, same upsert as registration.
                $pdo->prepare('
                    INSERT INTO visit_queue_counters (doctor_id, visit_date, next_token)
                    VALUES (?, CURDATE(), 2)
                    ON DUPLICATE KEY UPDATE next_token = LAST_INSERT_ID(next_token) + 1
                ')->execute([$shellDoctorId]);
                $lastId = (int) $pdo->lastInsertId();
                $tokenNo = $lastId > 0 ? $lastId : 1;

                // Shell visit: anchors the admission only. It carries NO consultation
                // bill, so its fee never reaches any tally. consult_status = 'DONE' keeps
                // it out of the doctor's waiting queue (the patient is being admitted, not
                // waiting for an OPD consult). payment_mode is NOT NULL in the schema, so a
                // neutral 'CASH' is stored even though nothing is billed here.
                $pdo->prepare('
                    INSERT INTO visits (token_no, patient_id, doctor_id, doctor_consult_type_id, fee, discount_pct, payment_mode, visit_date, created_by_id, consultation_fee_type, consult_status, disposition)
                    VALUES (?, ?, ?, ?, ?, 0, ?, CURDATE(), ?, ?, ?, ?)
                ')->execute([
                    $tokenNo, $patientId, $shellDoctorId, (int) $ct['id'], (float) $ct['fee'],
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

        $pdo->prepare('
            INSERT INTO admissions
                (visit_id, admission_type, admitted_by_id, admitted_by_role, admitted_at,
                 admitting_doctor_id, admitting_doctor_manual, status)
            VALUES (?, ?, ?, ?, NOW(), ?, ?, \'PENDING_ASSIGNMENT\')
        ')->execute([
            $visitId, $admType, $uid, $admitRole,
            $docId, $docId ? null : $docManual,
        ]);
        $admissionId = (int) $pdo->lastInsertId();

        $pdo->prepare('UPDATE visits SET disposition = \'SHORT_STAY\', admission_type = ?, admitted_at = NOW() WHERE id = ?')
            ->execute([$admType, $visitId]);

        $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
            ->execute([$uid, 'patient_admitted', "Admitted visit #$visitId ($admType), admission #$admissionId by $admitRole"]);

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
