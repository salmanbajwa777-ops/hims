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
 *   - Room category must be an enabled ipd_room_rates row.
 *   - Admitting consultant is REQUIRED: auto-loaded when a doctor admits
 *     themselves, otherwise the receptionist picks a system doctor or types one.
 *   - Marks the visit disposition IN_DOOR (added in sql/ipd/add_ipd_admissions.sql).
 *
 * Gated on IPD_ADMIT_PATIENT. Requires an open PDO and an authenticated session.
 *
 * Returns ['ok' => bool, 'error' => string, 'admission_id' => int|null].
 */

// Required here, not left to callers: IPD admits arrive from three pages and the
// shell visit needs a session-aware token from every one of them.

require_once __DIR__ . '/permissions.php';   // audit_log(), has_permission()
require_once __DIR__ . '/tokens.php';
// notify_ipd_patient_admitted() — loaded here rather than left to the caller. It
// used to be the caller's job, which meant a page that forgot it lost the admit
// alert silently (the call site is behind function_exists()). require_once makes
// this a no-op for the pages that already load it.
require_once __DIR__ . '/notify.php';
// Primary nurse is mandatory at admit — shared roster/validator with the ER handler.
require_once __DIR__ . '/nurses.php';
require_once __DIR__ . '/doctors.php';   // last_seen_doctor_id() for the shell visit

function handle_ipd_admit(PDO $pdo): array {
    $out = ['ok' => false, 'error' => '', 'admission_id' => null];

    if (!has_permission('IPD_ADMIT_PATIENT')) {
        $out['error'] = 'You do not have permission to admit patients to In-Door.';
        return $out;
    }

    $visitId   = (int) ($_POST['visit_id'] ?? 0);
    $patientId = (int) ($_POST['patient_id'] ?? 0);   // used only when there's no visit
    $roomCategory      = trim($_POST['room_category'] ?? '');
    $roomNo    = (int) ($_POST['room_no'] ?? 0);
    $consultId = (int) ($_POST['admitting_consultant_id'] ?? 0) ?: null;
    $consultManual = mb_strtoupper(trim($_POST['admitting_consultant_manual'] ?? ''), 'UTF-8') ?: null;
    $provDiag  = trim($_POST['provisional_diagnosis'] ?? '');
    $provDiag  = $provDiag === '' ? null : mb_substr($provDiag, 0, 500);
    $nurseId   = (int) ($_POST['assigned_nurse_id'] ?? 0);

    $baseRole = $_SESSION['base_role'] ?? '';
    $uid = (int) $_SESSION['user_id'];
    $admitRole = in_array($baseRole, ['ADMIN','DOCTOR','STAFF'], true) ? $baseRole : 'STAFF';

    // A doctor admitting themselves auto-loads as the consultant.
    if ($baseRole === 'DOCTOR' && !$consultId && !$consultManual) {
        $consultId = $uid;
    }

    // ---- Validate ----
    // The room category must be currently enabled.
    $categoryOk = $pdo->prepare('SELECT 1 FROM ipd_room_rates WHERE room_category = ? AND is_enabled = 1');
    $categoryOk->execute([$roomCategory]);
    if (!$categoryOk->fetchColumn()) {
        $out['error'] = 'Pick a valid, enabled room category.';
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
    // Primary nurse REQUIRED — no in-door stay without someone accountable for it.
    // Ownership only: any staff member holding the relevant nursing permission
    // can still log care, medications and vitals against this admission. The
    // roster key matches the one ipd_admission.php offers for handovers.
    if (!is_valid_nurse($pdo, 'IPD_RECORD_HANDOVER', $nurseId)) {
        $out['error'] = $nurseId > 0
            ? 'The selected primary nurse is not an active nursing staff member.'
            : 'Assign a primary nurse to admit this patient.';
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
                // The consultant when they're a system user; otherwise the doctor
                // who last saw this patient (shared definition with the picker).
                $shellDoctorId = $consultId ?: last_seen_doctor_id($pdo, $patientId);
                if (!$shellDoctorId) {
                    throw new RuntimeException('no_doctor');
                }
                $ctStmt = $pdo->prepare('SELECT id, fee FROM doctor_consult_types WHERE doctor_id = ? ORDER BY id LIMIT 1');
                $ctStmt->execute([$shellDoctorId]);
                $ct = $ctStmt->fetch();
                if (!$ct) {
                    throw new RuntimeException('no_consult_type');
                }

                // Race-safe, session-aware token — same helper as registration / ER admit.
                $token = issue_token($pdo, $shellDoctorId);
                $tokenNo = $token['no'];
                $tokenSession = $token['session'];

                $pdo->prepare('
                    INSERT INTO visits (token_no, token_session, patient_id, doctor_id, doctor_consult_type_id, fee, discount_pct, payment_mode, visit_date, created_by_id, consultation_fee_type, consult_status, disposition)
                    VALUES (?, ?, ?, ?, ?, ?, 0, ?, CURDATE(), ?, ?, ?, ?)
                ')->execute([
                    $tokenNo, $tokenSession, $patientId, $shellDoctorId, (int) $ct['id'], (float) $ct['fee'],
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

        // assigned_nurse_id / assigned_at come from sql/ipd/add_ipd_assigned_nurse.sql.
        $pdo->prepare('
            INSERT INTO ipd_admissions
                (visit_id, room_category, room_no, admitted_at, admitting_consultant_id,
                 admitting_consultant_manual, provisional_diagnosis, admitted_by_id, admitted_by_role,
                 assigned_nurse_id, assigned_at, status)
            VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, NOW(), \'ACTIVE\')
        ')->execute([
            $visitId, $roomCategory, $roomNo, $consultId,
            $consultId ? null : $consultManual, $provDiag, $uid, $admitRole, $nurseId,
        ]);
        $admissionId = (int) $pdo->lastInsertId();

        // Mark the visit as In-Door so it never blurs with ER SHORT_STAY counts.
        $pdo->prepare('UPDATE visits SET disposition = \'IN_DOOR\', admitted_at = NOW() WHERE id = ?')
            ->execute([$visitId]);

        audit_log($pdo, 'ipd_patient_admitted', "IPD admit: visit #$visitId, room category $roomCategory, room $roomNo, admission #$admissionId by $admitRole, primary nurse #$nurseId", $uid);

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
