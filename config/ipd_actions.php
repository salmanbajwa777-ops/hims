<?php
/**
 * Shared "admit a patient to In-Door (IPD)" handler.
 *
 * This is the IPD counterpart to config/admission_actions.php (which is ER-only
 * and untouched). One code path for every IPD admit entry point (patients.php,
 * receptionist.php, doctor.php). Brand-new module — its own ipd_* tables and
 * its own IPD_* permission.
 *
 * Two admit contexts, exactly like the ER handler:
 *   1. A patient with a visit today  -> admit against that visit_id.
 *   2. A patient with NO visit today -> a lightweight visit SHELL is auto-created
 *      in the same transaction so ipd_admissions.visit_id stays NOT NULL/UNIQUE.
 *      The shell carries no consultation bill; it only anchors the admission.
 *
 * IPD specifics:
 *   - Room number is TYPED (1-4), clamped here. No bed-resource table.
 *   - Ward must be an enabled ipd_ward_rates row.
 *   - Admitting consultant is REQUIRED: auto-loaded when a doctor admits
 *     themselves, otherwise the receptionist picks a system doctor or types one.
 *   - Marks the visit disposition IN_DOOR (added in sql/ipd/add_ipd_admissions.sql).
 *
 * Gated on IPD_ADMIT_PATIENT. Requires an open PDO, an authenticated session, and
 * config/notify.php loaded by the caller (best-effort alert after commit).
 *
 * Returns ['ok' => bool, 'error' => string, 'admission_id' => int|null].
 */

function handle_ipd_admit(PDO $pdo): array {
    $out = ['ok' => false, 'error' => '', 'admission_id' => null];

    if (!has_permission('IPD_ADMIT_PATIENT')) {
        $out['error'] = 'You do not have permission to admit patients to In-Door.';
        return $out;
    }

    $visitId   = (int) ($_POST['visit_id'] ?? 0);
    $patientId = (int) ($_POST['patient_id'] ?? 0);   // used only when there's no visit
    $ward      = trim($_POST['ward'] ?? '');
    $roomNo    = (int) ($_POST['room_no'] ?? 0);
    $consultId = (int) ($_POST['admitting_consultant_id'] ?? 0) ?: null;
    $consultManual = mb_strtoupper(trim($_POST['admitting_consultant_manual'] ?? ''), 'UTF-8') ?: null;
    $provDiag  = trim($_POST['provisional_diagnosis'] ?? '');
    $provDiag  = $provDiag === '' ? null : mb_substr($provDiag, 0, 500);

    $baseRole = $_SESSION['base_role'] ?? '';
    $uid = (int) $_SESSION['user_id'];
    $admitRole = in_array($baseRole, ['ADMIN','DOCTOR','STAFF'], true) ? $baseRole : 'STAFF';

    // A doctor admitting themselves auto-loads as the consultant.
    if ($baseRole === 'DOCTOR' && !$consultId && !$consultManual) {
        $consultId = $uid;
    }

    // ---- Validate ----
    // Ward must be currently enabled.
    $wardOk = $pdo->prepare('SELECT 1 FROM ipd_ward_rates WHERE ward = ? AND is_enabled = 1');
    $wardOk->execute([$ward]);
    if (!$wardOk->fetchColumn()) {
        $out['error'] = 'Pick a valid, enabled ward.';
        return $out;
    }
    // Room 1-4, typed.
    if ($roomNo < 1 || $roomNo > 4) {
        $out['error'] = 'Room number must be between 1 and 4.';
        return $out;
    }
    // Consultant required — a system doctor or a typed name.
    if (!$consultId && !$consultManual) {
        $out['error'] = 'An admitting consultant is required.';
        return $out;
    }
    // If a system consultant id was given, it must be a real doctor.
    if ($consultId) {
        $docOk = $pdo->prepare("SELECT 1 FROM users WHERE id = ? AND base_role = 'DOCTOR'");
        $docOk->execute([$consultId]);
        if (!$docOk->fetchColumn()) {
            $out['error'] = 'The selected consultant is not a valid doctor.';
            return $out;
        }
    }

    $pdo->beginTransaction();
    try {
        // Resolve the visit to admit against (mirrors the ER handler).
        if ($visitId <= 0) {
            if ($patientId <= 0) {
                throw new RuntimeException('no_target');
            }
            // Prefer an existing not-yet-IPD-admitted visit for this patient today.
            $todays = $pdo->prepare("
                SELECT v.id FROM visits v
                LEFT JOIN ipd_admissions a ON a.visit_id = v.id
                WHERE v.patient_id = ? AND v.visit_date = CURDATE() AND a.id IS NULL
                ORDER BY v.id DESC LIMIT 1
            ");
            $todays->execute([$patientId]);
            $visitId = (int) ($todays->fetchColumn() ?: 0);

            if ($visitId <= 0) {
                // No usable visit today — create a shell. visits columns are NOT NULL,
                // so it needs a doctor + consult type. Use the admitting consultant if a
                // system user; else the patient's most recent doctor. The shell carries
                // NO consultation bill.
                $shellDoctorId = $consultId;
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

                // Race-safe token, same upsert as registration / ER admit.
                $pdo->prepare('
                    INSERT INTO visit_queue_counters (doctor_id, visit_date, next_token)
                    VALUES (?, CURDATE(), 2)
                    ON DUPLICATE KEY UPDATE next_token = LAST_INSERT_ID(next_token) + 1
                ')->execute([$shellDoctorId]);
                $lastId = (int) $pdo->lastInsertId();
                $tokenNo = $lastId > 0 ? $lastId : 1;

                $pdo->prepare('
                    INSERT INTO visits (token_no, patient_id, doctor_id, doctor_consult_type_id, fee, discount_pct, payment_mode, visit_date, created_by_id, consultation_fee_type, consult_status, disposition)
                    VALUES (?, ?, ?, ?, ?, 0, ?, CURDATE(), ?, ?, ?, ?)
                ')->execute([
                    $tokenNo, $patientId, $shellDoctorId, (int) $ct['id'], (float) $ct['fee'],
                    'CASH', $uid, 'FULL', 'DONE', 'IN_DOOR',
                ]);
                $visitId = (int) $pdo->lastInsertId();
            }
        }

        // One IPD admission per visit (ipd_admissions.visit_id is UNIQUE).
        $exists = $pdo->prepare('SELECT 1 FROM ipd_admissions WHERE visit_id = ?');
        $exists->execute([$visitId]);
        if ($exists->fetchColumn()) {
            throw new RuntimeException('already_admitted');
        }

        $pdo->prepare('
            INSERT INTO ipd_admissions
                (visit_id, ward, room_no, admitted_at, admitting_consultant_id,
                 admitting_consultant_manual, provisional_diagnosis, admitted_by_id, admitted_by_role, status)
            VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?, \'ACTIVE\')
        ')->execute([
            $visitId, $ward, $roomNo, $consultId,
            $consultId ? null : $consultManual, $provDiag, $uid, $admitRole,
        ]);
        $admissionId = (int) $pdo->lastInsertId();

        // Mark the visit as In-Door so it never blurs with ER SHORT_STAY counts.
        $pdo->prepare('UPDATE visits SET disposition = \'IN_DOOR\', admitted_at = NOW() WHERE id = ?')
            ->execute([$visitId]);

        $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
            ->execute([$uid, 'ipd_patient_admitted', "IPD admit: visit #$visitId, ward $ward room $roomNo, admission #$admissionId by $admitRole"]);

        $pdo->commit();

        // Best-effort alert after commit (never fails the admit).
        if (function_exists('notify_ipd_patient_admitted')) {
            notify_ipd_patient_admitted($pdo, $admissionId);
        }

        $out['ok'] = true;
        $out['admission_id'] = $admissionId;
        return $out;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $msg = $e->getMessage();
        $out['error'] = [
            'already_admitted' => 'This visit is already admitted to In-Door.',
            'no_target'        => 'No patient or visit to admit.',
            'no_doctor'        => 'No doctor on record for this patient — register a visit first, then admit.',
            'no_consult_type'  => 'That doctor has no consultation type configured.',
        ][$msg] ?? 'Could not admit — please try again.';
        return $out;
    }
}
