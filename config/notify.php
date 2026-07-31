<?php
// Event-level email notifications. One function per business event; each builds
// the message and fans out to the right recipients (doctor's registered email,
// admin alert address). All senders are fire-and-forget via send_mail() — see
// config/mailer.php for the delivery rules.
//
// IMPORTANT: call these AFTER $pdo->commit(). SMTP can take a few seconds and
// must never sit inside an open transaction or roll back a saved record.

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/tokens.php';
// Each function below also writes an in-app notification for the same audience,
// so the header bell mirrors what went to the inbox. See config/notifications.php.
require_once __DIR__ . '/notifications.php';

/** New consultation invoice raised → the visit's doctor. */
function notify_invoice_raised(PDO $pdo, int $billId): void {
    try {
        $stmt = $pdo->prepare('
            SELECT b.invoice_number, b.grand_total, v.token_no, v.doctor_id,
                   p.name AS patient_name, p.mrn, du.name AS doctor_name, du.token_prefix,
                   v.consultation_fee_type
            FROM bills b
            JOIN visits v ON v.id = b.visit_id
            JOIN patients p ON p.id = v.patient_id
            LEFT JOIN users du ON du.id = v.doctor_id
            WHERE b.id = ?
        ');
        $stmt->execute([$billId]);
        $r = $stmt->fetch();
        if (!$r) { return; }

        // In-app first: the doctor sees this in the bell whether or not they
        // have an email on file, which the mail path below requires.
        notify_users($pdo, [(int) $r['doctor_id']], 'invoice_raised',
            'New patient in your queue',
            $r['patient_name'] . ' (MRN ' . $r['mrn'] . ') — Rs ' . number_format((float) $r['grand_total'], 0),
            'doctor.php', $r['invoice_number']);

        // OPT-IN ONLY. This fires on every registration and every follow-up, which
        // makes it the app's highest-volume email by a wide margin — enough to push
        // the hosting account against its sending limit. It now defaults OFF
        // (sql/add_email_on_new_patient.sql); a doctor switches it on for themselves
        // in profile.php. Nothing is lost by staying off: the bell above already
        // told them, and it ran before this check for exactly that reason.
        // Guarded so an un-migrated server keeps the old always-send behaviour
        // rather than going silent — same pattern as profile.php's document read.
        $wantsEmail = true;
        try {
            $pStmt = $pdo->prepare('SELECT email_on_new_patient FROM users WHERE id = ?');
            $pStmt->execute([(int) $r['doctor_id']]);
            $wantsEmail = (bool) $pStmt->fetchColumn();
        } catch (PDOException $e) {
            $wantsEmail = true; // column not migrated yet
        }
        if (!$wantsEmail) { return; }

        $docEmail = user_email($pdo, (int) $r['doctor_id']);
        if (!$docEmail) { return; }

        $feeLabels = [
            'FULL' => 'Full consultation', 'FREE_FOLLOWUP' => 'Free follow-up',
            'HALF_FOLLOWUP' => '50% follow-up', 'THREE_QUARTER_FOLLOWUP' => '75% follow-up',
        ];
        // Coded token ("SB-1"). The email carries its own timestamp, so the number
        // restarting each session needs no extra qualifier here.
        $tokenText = token_code($r['token_prefix'] ?? null, $r['doctor_name'] ?? '', $r['token_no']);

        $body = '<p style="font-size:14px;color:#41504f;margin:0 0 14px;">A patient has been registered under your name and their invoice has been raised.</p>'
            . mail_kv([
                'Patient'       => $r['patient_name'] . ' (MRN ' . $r['mrn'] . ')',
                'Token'         => $tokenText,
                'Invoice'       => $r['invoice_number'],
                'Type'          => $feeLabels[$r['consultation_fee_type']] ?? 'Consultation',
                'Amount'        => 'Rs ' . number_format((float) $r['grand_total'], 2),
                'Time'          => date('d/m/Y, h:i A'),
            ]);
        send_mail($pdo, $docEmail,
            'New patient — ' . $r['patient_name'] . ' (Token ' . $tokenText . ')',
            mail_template('New Patient in Your Queue', $body),
            'invoice:' . $r['invoice_number']);
    } catch (Throwable $e) { /* never break the page for a notification */ }
}

/** Refund issued → admin + the approving doctor. */
function notify_refund_issued(PDO $pdo, int $refundId): void {
    try {
        $stmt = $pdo->prepare('
            SELECT r.refund_number, r.amount, r.reason, r.refund_mode,
                   b.invoice_number, p.name AS patient_name, p.mrn,
                   r.approved_by_id, du.name AS doctor_name, gu.name AS generated_by
            FROM refunds r
            JOIN bills b ON b.id = r.bill_id
            JOIN visits v ON v.id = b.visit_id
            JOIN patients p ON p.id = v.patient_id
            LEFT JOIN users du ON du.id = r.approved_by_id
            LEFT JOIN users gu ON gu.id = r.generated_by_id
            WHERE r.id = ?
        ');
        $stmt->execute([$refundId]);
        $r = $stmt->fetch();
        if (!$r) { return; }

        notify_users($pdo, array_merge(notif_admin_ids($pdo), [(int) $r['approved_by_id']]), 'refund_issued',
            'Refund issued — Rs ' . number_format((float) $r['amount'], 0),
            $r['patient_name'] . ' (MRN ' . $r['mrn'] . ') against ' . $r['invoice_number'],
            'refund.php', $r['refund_number']);

        $to = array_filter([admin_alert_email(), user_email($pdo, (int) $r['approved_by_id'])]);
        $body = '<p style="font-size:14px;color:#41504f;margin:0 0 14px;">A refund voucher has been issued.</p>'
            . mail_kv([
                'Refund voucher' => $r['refund_number'],
                'Against invoice'=> $r['invoice_number'],
                'Patient'        => $r['patient_name'] . ' (MRN ' . $r['mrn'] . ')',
                'Amount'         => 'Rs ' . number_format((float) $r['amount'], 2),
                'Reason'         => $r['reason'],
                'Mode'           => ucwords(str_replace('_', ' ', $r['refund_mode'])),
                'Approved by'    => 'Dr ' . $r['doctor_name'],
                'Issued by'      => $r['generated_by'],
                'Time'           => date('d/m/Y, h:i A'),
            ]);
        send_mail($pdo, $to,
            'Refund ' . $r['refund_number'] . ' — Rs ' . number_format((float) $r['amount'], 0) . ' (' . $r['patient_name'] . ')',
            mail_template('Refund Issued', $body),
            'refund:' . $r['refund_number']);
    } catch (Throwable $e) { /* best-effort */ }
}

/** Patient admitted → admin + admitting doctor (if a registered one was picked). */
function notify_patient_admitted(PDO $pdo, int $admissionId): void {
    try {
        $stmt = $pdo->prepare('
            SELECT a.admission_type, a.admitted_at, a.admitting_doctor_id,
                   COALESCE(du.name, a.admitting_doctor_manual, "—") AS doctor_name,
                   p.name AS patient_name, p.mrn, v.token_no
            FROM admissions a
            JOIN visits v ON v.id = a.visit_id
            JOIN patients p ON p.id = v.patient_id
            LEFT JOIN users du ON du.id = a.admitting_doctor_id
            WHERE a.id = ?
        ');
        $stmt->execute([$admissionId]);
        $r = $stmt->fetch();
        if (!$r) { return; }

        $typeLabels = ['ROUTINE' => 'Routine', 'PRIVATE' => 'Private Room', 'LONG_PRIVATE' => 'Long Private'];
        $rows = [
            'Patient'          => $r['patient_name'] . ' (MRN ' . $r['mrn'] . ')',
            'Admission type'   => $typeLabels[$r['admission_type']] ?? $r['admission_type'],
            'Admitting doctor' => $r['doctor_name'],
            'Admitted at'      => date('d/m/Y, h:i A', strtotime($r['admitted_at'])),
        ];
        $body = '<p style="font-size:14px;color:#41504f;margin:0 0 14px;">A patient has been admitted.</p>' . mail_kv($rows);

        notify_users($pdo, array_merge(notif_admin_ids($pdo), [(int) $r['admitting_doctor_id']]), 'patient_admitted',
            'Patient admitted — ' . ($typeLabels[$r['admission_type']] ?? $r['admission_type']),
            $r['patient_name'] . ' (MRN ' . $r['mrn'] . ') under ' . $r['doctor_name'],
            'admissions.php', 'Admission #' . $admissionId);

        // Admin always; doctor additionally if they're a registered user with an email.
        send_mail($pdo, admin_alert_email(),
            'Admission — ' . $r['patient_name'] . ' (' . ($typeLabels[$r['admission_type']] ?? $r['admission_type']) . ')',
            mail_template('Patient Admitted', $body),
            'admission:' . $admissionId);

        $docEmail = user_email($pdo, (int) $r['admitting_doctor_id']);
        if ($docEmail) {
            $docBody = '<p style="font-size:14px;color:#41504f;margin:0 0 14px;">A patient has been admitted under your care.</p>' . mail_kv($rows);
            send_mail($pdo, $docEmail,
                'Patient admitted under your care — ' . $r['patient_name'],
                mail_template('Patient Admitted Under Your Care', $docBody),
                'admission-doctor:' . $admissionId);
        }
    } catch (Throwable $e) { /* best-effort */ }
}

/**
 * IPD (In-Door) admission → admin + the admitting consultant.
 *
 * config/ipd_actions.php has called this since the IPD module shipped, behind a
 * function_exists() guard — but the function was never written, so the guard
 * swallowed the call and EVERY In-Door admission notified nobody at all, with no
 * error anywhere. ER admits notified; IPD admits silently did not.
 *
 * Deliberately mirrors notify_patient_admitted() above, but IPD is a separate
 * module with its own tables and its own column names: ipd_admissions, and
 * admitting_consultant_id / _manual rather than admitting_doctor_id / _manual.
 * (room_category was renamed from `ward` by sql/ipd/add_ipd_room_charges.sql.)
 */
function notify_ipd_patient_admitted(PDO $pdo, int $admissionId): void {
    try {
        $stmt = $pdo->prepare('
            SELECT a.room_category, a.room_no, a.admitted_at, a.provisional_diagnosis,
                   a.admitting_consultant_id,
                   COALESCE(cu.name, a.admitting_consultant_manual, "—") AS consultant_name,
                   p.name AS patient_name, p.mrn
            FROM ipd_admissions a
            JOIN visits v ON v.id = a.visit_id
            JOIN patients p ON p.id = v.patient_id
            LEFT JOIN users cu ON cu.id = a.admitting_consultant_id
            WHERE a.id = ?
        ');
        $stmt->execute([$admissionId]);
        $r = $stmt->fetch();
        if (!$r) { return; }

        $rows = [
            'Patient'         => $r['patient_name'] . ' (MRN ' . $r['mrn'] . ')',
            'Room'            => $r['room_category'] . ' — Room ' . $r['room_no'],
            'Consultant'      => $r['consultant_name'],
            'Admitted at'     => date('d/m/Y, h:i A', strtotime($r['admitted_at'])),
        ];
        // Only carried when one was recorded — an empty "Diagnosis —" row reads
        // as missing information rather than as "not applicable yet".
        if (trim((string) $r['provisional_diagnosis']) !== '') {
            $rows['Provisional diagnosis'] = $r['provisional_diagnosis'];
        }
        $body = '<p style="font-size:14px;color:#41504f;margin:0 0 14px;">A patient has been admitted to In-Door.</p>' . mail_kv($rows);

        // Reuses the 'patient_admitted' type so the bell icon/tone matches the ER
        // admit — notif_tone() matches on substring, and a new string would earn
        // nothing here.
        notify_users($pdo, array_merge(notif_admin_ids($pdo), [(int) $r['admitting_consultant_id']]), 'patient_admitted',
            'In-Door admission — ' . $r['room_category'] . ' Room ' . $r['room_no'],
            $r['patient_name'] . ' (MRN ' . $r['mrn'] . ') under ' . $r['consultant_name'],
            'ipd_admissions.php', 'IPD #' . $admissionId);

        // Admin always; consultant additionally if they're a registered user with
        // an email (they may have been typed free-text into _manual).
        send_mail($pdo, admin_alert_email(),
            'In-Door admission — ' . $r['patient_name'] . ' (' . $r['room_category'] . ' Room ' . $r['room_no'] . ')',
            mail_template('Patient Admitted to In-Door', $body),
            'ipd-admission:' . $admissionId);

        $docEmail = user_email($pdo, (int) $r['admitting_consultant_id']);
        if ($docEmail) {
            $docBody = '<p style="font-size:14px;color:#41504f;margin:0 0 14px;">A patient has been admitted to In-Door under your care.</p>' . mail_kv($rows);
            send_mail($pdo, $docEmail,
                'In-Door patient admitted under your care — ' . $r['patient_name'],
                mail_template('In-Door Patient Under Your Care', $docBody),
                'ipd-admission-doctor:' . $admissionId);
        }
    } catch (Throwable $e) { /* best-effort */ }
}

/** Discharge finalized (paid in full or write-off approved) → admin. */
function notify_patient_discharged(PDO $pdo, int $admissionId, float $writeOff = 0.0): void {
    try {
        $stmt = $pdo->prepare('
            SELECT a.admitted_at, a.discharge_finalized_at,
                   COALESCE(du.name, a.admitting_doctor_manual, "—") AS doctor_name,
                   p.name AS patient_name, p.mrn,
                   ab.invoice_number, ab.grand_total, ab.paid_amount, ab.payment_method
            FROM admissions a
            JOIN visits v ON v.id = a.visit_id
            JOIN patients p ON p.id = v.patient_id
            LEFT JOIN users du ON du.id = a.admitting_doctor_id
            LEFT JOIN admission_bills ab ON ab.admission_id = a.id
            WHERE a.id = ?
        ');
        $stmt->execute([$admissionId]);
        $r = $stmt->fetch();
        if (!$r) { return; }

        $rows = [
            'Patient'        => $r['patient_name'] . ' (MRN ' . $r['mrn'] . ')',
            'Doctor'         => $r['doctor_name'],
            'Admitted'       => date('d/m/Y, h:i A', strtotime($r['admitted_at'])),
            'Discharged'     => date('d/m/Y, h:i A', strtotime($r['discharge_finalized_at'] ?? 'now')),
            'Admission bill' => $r['invoice_number'] ?? '—',
            'Bill total'     => 'Rs ' . number_format((float) ($r['grand_total'] ?? 0), 2),
            'Paid'           => 'Rs ' . number_format((float) ($r['paid_amount'] ?? 0), 2)
                                . ($r['payment_method'] ? ' (' . $r['payment_method'] . ')' : ''),
        ];
        if ($writeOff > 0.001) {
            $rows['WRITTEN OFF'] = 'Rs ' . number_format($writeOff, 2) . ' — gone forever, patient flagged';
        }
        $body = '<p style="font-size:14px;color:#41504f;margin:0 0 14px;">A patient has been discharged'
              . ($writeOff > 0.001 ? ' <strong style="color:#b3261e;">with an approved write-off</strong>' : '')
              . '.</p>' . mail_kv($rows);
        notify_users($pdo, notif_admin_ids($pdo), 'patient_discharged',
            ($writeOff > 0.001 ? 'Discharge with WRITE-OFF' : 'Patient discharged'),
            $r['patient_name'] . ' (MRN ' . $r['mrn'] . ') — bill Rs ' . number_format((float) ($r['grand_total'] ?? 0), 0)
                . ($writeOff > 0.001 ? ', written off Rs ' . number_format($writeOff, 0) : ''),
            'admissions.php', $r['invoice_number'] ?? ('Admission #' . $admissionId));

        send_mail($pdo, admin_alert_email(),
            ($writeOff > 0.001 ? 'Discharge + WRITE-OFF — ' : 'Discharge — ') . $r['patient_name'],
            mail_template('Patient Discharged', $body),
            'discharge:' . $admissionId);
    } catch (Throwable $e) { /* best-effort */ }
}

/** New staff account created → welcome email with login link + temporary password. */
function notify_staff_welcome(PDO $pdo, int $userId, string $tempPassword): void {
    try {
        $stmt = $pdo->prepare('SELECT name, email, base_role FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $u = $stmt->fetch();
        if (!$u || !$u['email']) { return; }

        $base = (mail_config() ?? [])['base_url'] ?? 'https://hims.babymedics.com';
        $login = ($u['email'] ?: '');
        $body = '<p style="font-size:14px;color:#41504f;margin:0 0 14px;">Welcome to the Babymedics Hospital Management System, '
              . htmlspecialchars(explode(' ', trim($u['name']))[0]) . '! Your account has been created.</p>'
            . mail_kv([
                'Name'               => $u['name'],
                'Role'               => ucfirst(strtolower($u['base_role'])),
                'Sign-in email'      => $login,
                'Temporary password' => $tempPassword,
            ])
            . '<p style="font-size:14px;color:#41504f;margin:0 0 18px;">You will be asked to set your own password the first time you sign in.</p>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 6px;"><tr><td style="background:#0E5456;border-radius:8px;">'
            . '<a href="' . htmlspecialchars($base) . '/index.php" style="display:inline-block;padding:11px 26px;color:#ffffff;font-size:14px;font-weight:bold;text-decoration:none;">Sign in to HMIS</a>'
            . '</td></tr></table>';
        send_mail($pdo, $u['email'],
            'Your Babymedics HMIS account',
            mail_template('Your Account Is Ready', $body, 'Keep this email private — it contains your temporary password.'),
            'welcome:user#' . $userId);
    } catch (Throwable $e) { /* best-effort */ }
}

/** New phone booking taken → the booked doctor. Amount-less by design: fees
 *  are an arrival-time concern (revisit engine + discounts), never quoted on
 *  the phone. */
function notify_booking_created(PDO $pdo, int $bookingId): void {
    try {
        $stmt = $pdo->prepare('
            SELECT bk.person_name, bk.phone, bk.booking_date, bk.preferred_time, bk.note,
                   bk.doctor_id, dct.label AS purpose,
                   p.name AS patient_name, p.mrn,
                   cu.name AS taken_by
            FROM bookings bk
            JOIN doctor_consult_types dct ON dct.id = bk.doctor_consult_type_id
            LEFT JOIN patients p ON p.id = bk.patient_id
            LEFT JOIN users cu ON cu.id = bk.created_by_id
            WHERE bk.id = ?
        ');
        $stmt->execute([$bookingId]);
        $r = $stmt->fetch();
        if (!$r) { return; }

        $who = $r['patient_name']
            ? $r['patient_name'] . ' (MRN ' . $r['mrn'] . ')'
            : $r['person_name'] . ' (new caller)';

        notify_users($pdo, [(int) $r['doctor_id']], 'booking_created',
            'New booking — ' . date('d/m', strtotime($r['booking_date'])),
            $who . ' · ' . $r['purpose'] . ($r['preferred_time'] ? ' at ' . $r['preferred_time'] : ''),
            'bookings.php', 'Booking #' . $bookingId);

        $docEmail = user_email($pdo, (int) $r['doctor_id']);
        if (!$docEmail) { return; }

        $kv = [
            'Patient'  => $who,
            'Purpose'  => $r['purpose'],
            'Date'     => date('l, d/m/Y', strtotime($r['booking_date'])),
        ];
        if ($r['preferred_time']) { $kv['Preferred time'] = $r['preferred_time']; }
        if ($r['note'])           { $kv['Note'] = $r['note']; }
        $kv['Phone']    = $r['phone'];
        $kv['Taken by'] = $r['taken_by'] ?: 'Reception';

        $body = '<p style="font-size:14px;color:#41504f;margin:0 0 14px;">Reception has booked an appointment under your name.</p>'
            . mail_kv($kv);
        send_mail($pdo, $docEmail,
            'New booking — ' . $r['person_name'] . ' (' . $r['purpose'] . ', ' . date('d/m', strtotime($r['booking_date'])) . ')',
            mail_template('New Appointment Booked', $body),
            'booking:' . $bookingId);
    } catch (Throwable $e) { /* never break the page for a notification */ }
}

/** Booking cancelled → the booked doctor, with the reason. */
function notify_booking_cancelled(PDO $pdo, int $bookingId): void {
    try {
        $stmt = $pdo->prepare('
            SELECT bk.person_name, bk.booking_date, bk.cancel_reason,
                   bk.doctor_id, dct.label AS purpose,
                   p.name AS patient_name, p.mrn,
                   cu.name AS cancelled_by
            FROM bookings bk
            JOIN doctor_consult_types dct ON dct.id = bk.doctor_consult_type_id
            LEFT JOIN patients p ON p.id = bk.patient_id
            LEFT JOIN users cu ON cu.id = bk.cancelled_by_id
            WHERE bk.id = ?
        ');
        $stmt->execute([$bookingId]);
        $r = $stmt->fetch();
        if (!$r) { return; }

        $who = $r['patient_name']
            ? $r['patient_name'] . ' (MRN ' . $r['mrn'] . ')'
            : $r['person_name'];

        notify_users($pdo, [(int) $r['doctor_id']], 'booking_cancelled',
            'Booking cancelled — ' . date('d/m', strtotime($r['booking_date'])),
            $who . ' · ' . $r['purpose'] . ($r['cancel_reason'] ? ' — ' . $r['cancel_reason'] : ''),
            'bookings.php', 'Booking #' . $bookingId);

        $docEmail = user_email($pdo, (int) $r['doctor_id']);
        if (!$docEmail) { return; }

        $body = '<p style="font-size:14px;color:#41504f;margin:0 0 14px;">An appointment booked under your name has been cancelled.</p>'
            . mail_kv([
                'Patient'      => $who,
                'Purpose'      => $r['purpose'],
                'Was booked for' => date('l, d/m/Y', strtotime($r['booking_date'])),
                'Reason'       => $r['cancel_reason'] ?: '—',
                'Cancelled by' => $r['cancelled_by'] ?: 'Reception',
            ]);
        send_mail($pdo, $docEmail,
            'Booking cancelled — ' . $r['person_name'] . ' (' . date('d/m', strtotime($r['booking_date'])) . ')',
            mail_template('Appointment Cancelled', $body),
            'booking-cancel:' . $bookingId);
    } catch (Throwable $e) { /* never break the page for a notification */ }
}

/**
 * Expense posted → everyone who can approve expenses (FINANCIAL_APPROVE_EXPENSES), plus the admin
 * alert address. Carries a single-use 60-minute magic link that lands straight
 * on the approval page and lets the recipient Approve/Reject with one click, no
 * login. The raw token is generated here and its SHA-256 hash stored; the token
 * row must be created in the SAME transaction as the expense so a committed
 * expense always has a matching (un-forgeable) link.
 *
 * Returns the raw token so the caller could log it if needed; callers normally
 * ignore it. Call AFTER $pdo->commit() like every other notify_* function.
 */
function notify_expense_posted(PDO $pdo, int $expenseId, string $rawToken): void {
    try {
        // over_limit/limit_note may be absent if the migration hasn't run yet;
        // fall back to a column-free SELECT so the email still sends mid-deploy.
        try {
            $stmt = $pdo->prepare('
                SELECT e.expense_number, e.amount, e.description, e.paid_to, e.expense_date,
                       e.approval_status, e.over_limit, e.limit_note,
                       ec.name AS category_name, u.name AS posted_by_name, t.expires_at
                FROM expenses e
                JOIN expense_categories ec ON ec.id = e.category_id
                JOIN users u ON u.id = e.posted_by_id
                LEFT JOIN expense_approval_tokens t ON t.expense_id = e.id
                WHERE e.id = ? ORDER BY t.id DESC LIMIT 1
            ');
            $stmt->execute([$expenseId]);
            $r = $stmt->fetch();
        } catch (PDOException $ex) {
            $stmt = $pdo->prepare('
                SELECT e.expense_number, e.amount, e.description, e.paid_to, e.expense_date,
                       e.approval_status, ec.name AS category_name, u.name AS posted_by_name, t.expires_at
                FROM expenses e
                JOIN expense_categories ec ON ec.id = e.category_id
                JOIN users u ON u.id = e.posted_by_id
                LEFT JOIN expense_approval_tokens t ON t.expense_id = e.id
                WHERE e.id = ? ORDER BY t.id DESC LIMIT 1
            ');
            $stmt->execute([$expenseId]);
            $r = $stmt->fetch();
        }
        if (!$r) { return; }
        $isOver = !empty($r['over_limit']);

        // An admin's own posting is auto-approved — no one to email.
        if ($r['approval_status'] !== 'PENDING') { return; }

        // Recipients: admin alert address + everyone who can APPROVE expenses.
        // Role-agnostic now that MANAGER is folded into STAFF — the audience is
        // defined by the FINANCIAL_APPROVE_EXPENSES permission (effective =
        // role grant minus user revoke, plus user grant), matching load_permissions().
        // Same audience as the email below, resolved as ids: everyone holding
        // FINANCIAL_APPROVE_EXPENSES, plus every admin.
        notify_users($pdo,
            array_merge(notif_admin_ids($pdo), notif_users_with_permission($pdo, 'FINANCIAL_APPROVE_EXPENSES')),
            $isOver ? 'expense_approval_over_limit' : 'expense_approval',
            ($isOver ? 'OVER-LIMIT expense needs approval' : 'Expense awaiting approval')
                . ' — Rs ' . number_format((float) $r['amount'], 0),
            $r['category_name'] . ' · ' . $r['description'] . ' — posted by ' . $r['posted_by_name'],
            'expenses.php', $r['expense_number']);

        $to = [admin_alert_email()];
        $mgr = $pdo->query("
            SELECT u.email FROM users u
            WHERE u.is_active = 1 AND u.email IS NOT NULL AND u.email <> ''
              AND (
                    (   EXISTS (SELECT 1 FROM role_permissions rp
                                JOIN permissions p ON p.id = rp.permission_id
                                WHERE rp.base_role = u.base_role AND p.`key` = 'FINANCIAL_APPROVE_EXPENSES')
                     AND NOT EXISTS (SELECT 1 FROM user_permission_overrides o
                                     JOIN permissions p ON p.id = o.permission_id
                                     WHERE o.user_id = u.id AND p.`key` = 'FINANCIAL_APPROVE_EXPENSES' AND o.granted = 0))
                 OR EXISTS (SELECT 1 FROM user_permission_overrides o
                            JOIN permissions p ON p.id = o.permission_id
                            WHERE o.user_id = u.id AND p.`key` = 'FINANCIAL_APPROVE_EXPENSES' AND o.granted = 1)
              )
        ");
        foreach ($mgr->fetchAll() as $m) { $to[] = $m['email']; }
        $to = array_values(array_unique(array_filter($to)));
        if (!$to) { return; }

        $base = (mail_config() ?? [])['base_url'] ?? 'https://hims.babymedics.com';
        $link = $base . '/approve_expense.php?token=' . urlencode($rawToken);
        $expiresTxt = $r['expires_at']
            ? date('h:i A', strtotime($r['expires_at'])) . ' today (60 minutes)'
            : '60 minutes';

        $overBanner = $isOver
            ? '<p style="font-size:14px;color:#9A3412;background:#FFF4ED;border:1px solid #FBCFB0;border-radius:8px;padding:11px 14px;margin:0 0 14px;font-weight:bold;">'
              . '⚠️ Over shift limit — needs immediate approval.'
              . ($r['limit_note'] ? '<br><span style="font-weight:normal;">' . htmlspecialchars($r['limit_note']) . '</span>' : '')
              . '</p>'
            : '';
        $body = $overBanner
            . '<p style="font-size:14px;color:#41504f;margin:0 0 14px;">'
              . htmlspecialchars($r['posted_by_name']) . ' has posted a counter expense that needs your approval. '
              . 'Cash has already left the drawer against this voucher.</p>'
            . mail_kv([
                'Voucher'   => $r['expense_number'],
                'Category'  => $r['category_name'],
                'Amount'    => 'Rs ' . number_format((float) $r['amount'], 2),
                'For'       => $r['description'],
                'Paid to'   => $r['paid_to'] ?: '—',
                'Posted by' => $r['posted_by_name'],
                'Date'      => date('d/m/Y', strtotime($r['expense_date'])),
            ])
            . '<p style="font-size:14px;color:#41504f;margin:0 0 18px;">Tap below to approve or reject. '
            . 'This one-click link works without signing in and expires at <strong>' . htmlspecialchars($expiresTxt)
            . '</strong>. After it expires you can still act from the Expenses page in the app.</p>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 6px;"><tr><td style="background:#0E5456;border-radius:8px;">'
            . '<a href="' . htmlspecialchars($link) . '" style="display:inline-block;padding:11px 26px;color:#ffffff;font-size:14px;font-weight:bold;text-decoration:none;">Review this expense</a>'
            . '</td></tr></table>';
        send_mail($pdo, $to,
            ($isOver ? '⚠️ OVER-LIMIT expense approval — ' : 'Expense approval — ')
            . $r['expense_number'] . ' — Rs ' . number_format((float) $r['amount'], 0)
            . ' (' . $r['posted_by_name'] . ')',
            mail_template($isOver ? 'Over-Limit Expense — Approval Needed' : 'Expense Awaiting Approval', $body,
                'This link authorizes approval of one expense for 60 minutes — keep it private.'),
            'expense-approval:' . $r['expense_number']);
    } catch (Throwable $e) { /* never break the page for a notification */ }
}

/** Expense approved or rejected → the person who posted it (if they have an
 *  email) + the admin alert address, so the poster knows the cash is cleared or
 *  needs returning, and the record is centrally visible. */
function notify_expense_decided(PDO $pdo, int $expenseId): void {
    try {
        $stmt = $pdo->prepare('
            SELECT e.expense_number, e.amount, e.description, e.approval_status,
                   e.rejection_reason, ec.name AS category_name,
                   e.posted_by_id, pu.name AS posted_by_name, pu.email AS posted_by_email,
                   au.name AS approver_name
            FROM expenses e
            JOIN expense_categories ec ON ec.id = e.category_id
            JOIN users pu ON pu.id = e.posted_by_id
            LEFT JOIN users au ON au.id = e.approved_by_id
            WHERE e.id = ?
        ');
        $stmt->execute([$expenseId]);
        $r = $stmt->fetch();
        if (!$r || $r['approval_status'] === 'PENDING') { return; }

        $approved = $r['approval_status'] === 'APPROVED';

        // The poster needs this one most — a rejection means cash goes back in
        // the drawer — and they may well have no email on file.
        notify_users($pdo, [(int) $r['posted_by_id']],
            $approved ? 'expense_approved' : 'expense_rejected',
            'Expense ' . ($approved ? 'approved' : 'REJECTED') . ' — Rs ' . number_format((float) $r['amount'], 0),
            $r['category_name'] . ' · ' . $r['description']
                . (!$approved && $r['rejection_reason'] ? ' — ' . $r['rejection_reason'] : '')
                . ' · by ' . ($r['approver_name'] ?? '—'),
            'expenses.php', $r['expense_number']);

        $to = array_values(array_filter(array_unique([
            admin_alert_email(),
            ($r['posted_by_email'] && filter_var($r['posted_by_email'], FILTER_VALIDATE_EMAIL)) ? $r['posted_by_email'] : null,
        ])));
        if (!$to) { return; }

        $kv = [
            'Voucher'   => $r['expense_number'],
            'Category'  => $r['category_name'],
            'Amount'    => 'Rs ' . number_format((float) $r['amount'], 2),
            'For'       => $r['description'],
            'Decision'  => $approved ? 'APPROVED' : 'REJECTED',
            'By'        => $r['approver_name'] ?? '—',
        ];
        if (!$approved && $r['rejection_reason']) {
            $kv['Reason'] = $r['rejection_reason'];
        }
        $lead = $approved
            ? 'Your counter expense has been <strong style="color:#0E5456;">approved</strong>.'
            : 'Your counter expense has been <strong style="color:#b3261e;">rejected</strong> — the cash should be returned to the drawer; this voucher drops out of the shift tally.';
        $body = '<p style="font-size:14px;color:#41504f;margin:0 0 14px;">' . $lead . '</p>' . mail_kv($kv);
        send_mail($pdo, $to,
            'Expense ' . ($approved ? 'approved' : 'REJECTED') . ' — ' . $r['expense_number']
            . ' (Rs ' . number_format((float) $r['amount'], 0) . ')',
            mail_template('Expense ' . ($approved ? 'Approved' : 'Rejected'), $body),
            'expense-decided:' . $r['expense_number']);
    } catch (Throwable $e) { /* best-effort */ }
}

/** Day closed by reception → admin alert + the admin named on the handover. */
function notify_day_closed(PDO $pdo, int $closingId): void {
    try {
        $stmt = $pdo->prepare('
            SELECT c.*, cu.name AS cashier_name, au.name AS admin_name
            FROM shift_closings c
            JOIN users cu ON cu.id = c.cashier_id
            JOIN users au ON au.id = c.handover_to_id
            WHERE c.id = ?
        ');
        $stmt->execute([$closingId]);
        $c = $stmt->fetch();
        if (!$c) { return; }

        $variance = (float) $c['variance'];
        $varianceText = abs($variance) < 0.01 ? 'Balanced'
            : 'Rs ' . number_format(abs($variance), 2) . ($variance < 0 ? ' SHORT' : ' OVER');

        $netCollected = (float) $c['cash_consult_total'] + (float) $c['cash_admission_total']
                      + (float) $c['online_total'] - (float) $c['cash_refund_total'];

        notify_users($pdo, array_merge(notif_admin_ids($pdo), [(int) $c['handover_to_id']]), 'day_closed',
            'Shift closed — handover Rs ' . number_format((float) $c['handover_declared'], 0) . ' pending',
            $c['cashier_name'] . ' · ' . date('D d/m/Y', strtotime($c['closing_date'])) . ' · ' . $varianceText,
            'admin_handovers.php', $c['closing_number']);

        $to = array_filter([admin_alert_email(), user_email($pdo, (int) $c['handover_to_id'])]);
        $body = '<p style="font-size:14px;color:#41504f;margin:0 0 14px;">'
              . htmlspecialchars($c['cashier_name']) . ' has closed their shift. '
              . 'The cash handover is awaiting your acknowledgment in the admin portal '
              . '(Cash Handovers → recount + confirm the signed slip is filed).</p>'
            . mail_kv([
                'Closing slip'       => $c['closing_number'],
                'Shift date'         => date('D d/m/Y', strtotime($c['closing_date'])),
                'Cashier'            => $c['cashier_name'],
                'Their collections'  => 'Rs ' . number_format($netCollected, 2),
                'Cash'               => 'Rs ' . number_format((float) $c['cash_consult_total'] + (float) $c['cash_admission_total'], 2)
                                        . ' (' . ((int) $c['cash_consult_count'] + (int) $c['cash_admission_count']) . ' payments)',
                'Online'             => 'Rs ' . number_format((float) $c['online_total'], 2)
                                        . ' (' . (int) $c['online_count'] . ' payments)',
                'Cash refunds'       => 'Rs ' . number_format((float) $c['cash_refund_total'], 2),
                'Counter expenses'   => 'Rs ' . number_format((float) ($c['expense_total'] ?? 0), 2)
                                        . ' (' . (int) ($c['expense_count'] ?? 0) . ' vouchers)',
                'Expected in hand'   => 'Rs ' . number_format((float) $c['expected_cash'], 2),
                'Counted'            => 'Rs ' . number_format((float) $c['counted_cash'], 2),
                'Variance'           => $varianceText . ($c['variance_note'] ? ' — ' . $c['variance_note'] : ''),
                'Handover declared'  => 'Rs ' . number_format((float) $c['handover_declared'], 2) . ' → ' . $c['admin_name'],
            ]);
        send_mail($pdo, $to,
            'Shift closed — ' . $c['cashier_name'] . ' ' . date('d/m', strtotime($c['closing_date']))
            . ' — handover Rs ' . number_format((float) $c['handover_declared'], 0) . ' pending (' . $c['closing_number'] . ')',
            mail_template('Shift Closing & Cash Handover', $body),
            'closing:' . $c['closing_number']);
    } catch (Throwable $e) { /* best-effort */ }
}

/** Cashier edited their closed shift (before admin receipt) → admin alert +
 *  the admin named on the handover, with every changed field old→new. */
function notify_closing_edited(PDO $pdo, int $closingId, int $round): void {
    try {
        $stmt = $pdo->prepare('
            SELECT c.*, cu.name AS cashier_name, au.name AS admin_name
            FROM shift_closings c
            JOIN users cu ON cu.id = c.cashier_id
            JOIN users au ON au.id = c.handover_to_id
            WHERE c.id = ?
        ');
        $stmt->execute([$closingId]);
        $c = $stmt->fetch();
        if (!$c) { return; }

        $labels = [
            'counted_cash'      => 'Counted cash',
            'handover_declared' => 'Handover declared',
            'variance_note'     => 'Variance note',
            'denominations'     => 'Denominations',
        ];
        $chStmt = $pdo->prepare('
            SELECT field_name, old_value, new_value
            FROM shift_closing_edits
            WHERE closing_id = ? AND edit_round = ?
            ORDER BY id
        ');
        $chStmt->execute([$closingId, $round]);
        $kv = [
            'Closing slip' => $c['closing_number'],
            'Shift date'   => date('D d/m/Y', strtotime($c['closing_date'])),
            'Cashier'      => $c['cashier_name'],
            'Edit round'   => '#' . $round . ' of this closing',
        ];
        foreach ($chStmt->fetchAll() as $ch) {
            $kv[$labels[$ch['field_name']] ?? $ch['field_name']] =
                ($ch['old_value'] !== null && $ch['old_value'] !== '' ? $ch['old_value'] : '—')
                . '  →  '
                . ($ch['new_value'] !== null && $ch['new_value'] !== '' ? $ch['new_value'] : '—');
        }
        $kv['Now declares'] = 'Rs ' . number_format((float) $c['handover_declared'], 2)
                            . ' (counted Rs ' . number_format((float) $c['counted_cash'], 2) . ')';

        notify_users($pdo, array_merge(notif_admin_ids($pdo), [(int) $c['handover_to_id']]), 'closing_edited',
            'Closed shift EDITED — review required',
            $c['cashier_name'] . ' changed their figures (round ' . $round . ') · now declares Rs '
                . number_format((float) $c['handover_declared'], 0),
            'admin_handovers.php', $c['closing_number']);

        $to = array_filter([admin_alert_email(), user_email($pdo, (int) $c['handover_to_id'])]);
        $body = '<p style="font-size:14px;color:#B45309;font-weight:bold;margin:0 0 14px;">'
              . htmlspecialchars($c['cashier_name']) . ' EDITED their closed shift after submitting it. '
              . 'The changes below are already in force and are highlighted on the Cash Handovers page — '
              . 'marking the handover received approves them.</p>'
            . mail_kv($kv);
        send_mail($pdo, $to,
            'EDITED closing ' . $c['closing_number'] . ' — ' . $c['cashier_name']
            . ' changed their shift figures (round ' . $round . ')',
            mail_template('Shift Closing Edited — Review Required', $body),
            'closing-edit:' . $c['closing_number'] . ':' . $round);
    } catch (Throwable $e) { /* best-effort */ }
}

/**
 * A receptionist gave a flat discount on a procedure bill — tell the doctor.
 *
 * The patient has already paid and gone; this is not an authorisation request.
 * The doctor has 24 hours to record whether they agree, and their answer moves
 * no money either way (a procedure bill cannot be refunded — refunds.bill_id is
 * FK'd to the OPD bills table — and a void is refused once the cashier's day is
 * signed, which inside 24 hours is the normal case). What the answer does do is
 * land on the admin report, which is where the actual control lives.
 *
 * WHY THIS QUOTES THE DOCTOR'S OWN RUPEES.
 * procedure_bill.php spreads a discount across the bill's lines and doctor share
 * is computed per line on the discounted amount, so a discount comes out of the
 * doctor's earnings at their own share percentage. Sending them "Rs 2,000
 * discount" would hide the only number they actually care about. So this
 * re-computes what they would have earned at list price against what they will
 * now earn, and leads with the difference.
 */
function notify_procedure_discount(PDO $pdo, int $procBillId): void {
    try {
        $stmt = $pdo->prepare('
            SELECT pb.invoice_number, pb.manual_discount_amount, pb.manual_discount_reason,
                   pb.grand_total, pb.discount_doctor_id, pb.discount_approval,
                   p.name AS patient_name,
                   cu.name AS raised_by_name
            FROM procedure_bills pb
            JOIN patients p ON p.id = pb.patient_id
            LEFT JOIN users cu ON cu.id = pb.created_by_id
            WHERE pb.id = ?
        ');
        $stmt->execute([$procBillId]);
        $b = $stmt->fetch();
        if (!$b || $b['discount_approval'] !== 'PENDING' || !$b['discount_doctor_id']) {
            return;
        }

        // The doctor's own exposure, from the per-line share snapshots. Summed
        // twice over the same lines: once on what was actually billed, once on
        // what the line would have been with no discount at all. The gap is
        // what the discount cost them personally.
        //
        // doctor_split() lives in billing.php, which this file does NOT require
        // (notify.php is deliberately light — it is pulled into pages that have
        // no business loading the billing engine). Every caller today loads it
        // first, but relying on that would make the doctor's own rupee figure
        // silently disappear the day a caller does not. Load it here instead.
        if (!function_exists('doctor_split') && is_file(__DIR__ . '/billing.php')) {
            require_once __DIR__ . '/billing.php';
        }
        $doctorLoss = 0.0;
        if (function_exists('doctor_split')) {
            $li = $pdo->prepare('
                SELECT amount, unit_rate, quantity, doctor_share_pct, has_tax, tax_percent,
                       COALESCE(disposables_cost, 0) AS disposables_cost
                FROM procedure_bill_items WHERE procedure_bill_id = ?
            ');
            $li->execute([$procBillId]);
            foreach ($li->fetchAll() as $ln) {
                $disp   = (float) $ln['disposables_cost'];
                $actual = doctor_split((float) $ln['amount'], (float) $ln['doctor_share_pct'],
                                       (bool) $ln['has_tax'], (float) $ln['tax_percent'], $disp);
                $list   = doctor_split((float) $ln['unit_rate'] * (float) $ln['quantity'],
                                       (float) $ln['doctor_share_pct'],
                                       (bool) $ln['has_tax'], (float) $ln['tax_percent'], $disp);
                $doctorLoss += ($list['doctor'] - $actual['doctor']);
            }
        }
        $doctorLoss = max(0.0, round($doctorLoss, 0));

        $discount = (float) $b['manual_discount_amount'];
        $title = 'Discount Rs ' . number_format($discount, 0) . ' on ' . $b['invoice_number']
            . ($doctorLoss > 0 ? ' — your share is Rs ' . number_format($doctorLoss, 0) . ' lower' : '');

        $body = strtoupper((string) $b['patient_name'])
            . ' · paid Rs ' . number_format((float) $b['grand_total'], 0)
            . ' · by ' . ($b['raised_by_name'] ?? 'reception')
            . ($b['manual_discount_reason'] ? ' — ' . $b['manual_discount_reason'] : '')
            . ' · 24h to respond; the patient has already paid.';

        // In-app FIRST and unconditionally. notify.php's mail helpers return
        // early when there is no address on file, so an email-first ordering
        // would silently skip the bell for exactly the doctors who most need it.
        notify_users($pdo, [(int) $b['discount_doctor_id']], 'procedure_discount',
            $title, $body, 'procedure_discount_approvals.php', $b['invoice_number']);
    } catch (Throwable $e) { /* best-effort — never cost the clinic a paid bill */ }
}
