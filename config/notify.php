<?php
// Event-level email notifications. One function per business event; each builds
// the message and fans out to the right recipients (doctor's registered email,
// admin alert address). All senders are fire-and-forget via send_mail() — see
// config/mailer.php for the delivery rules.
//
// IMPORTANT: call these AFTER $pdo->commit(). SMTP can take a few seconds and
// must never sit inside an open transaction or roll back a saved record.

require_once __DIR__ . '/mailer.php';

/** New consultation invoice raised → the visit's doctor. */
function notify_invoice_raised(PDO $pdo, int $billId): void {
    try {
        $stmt = $pdo->prepare('
            SELECT b.invoice_number, b.grand_total, v.token_no, v.doctor_id,
                   p.name AS patient_name, p.mrn, du.name AS doctor_name,
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

        $docEmail = user_email($pdo, (int) $r['doctor_id']);
        if (!$docEmail) { return; }

        $feeLabels = [
            'FULL' => 'Full consultation', 'FREE_FOLLOWUP' => 'Free follow-up',
            'HALF_FOLLOWUP' => '50% follow-up', 'THREE_QUARTER_FOLLOWUP' => '75% follow-up',
        ];
        $body = '<p style="font-size:14px;color:#41504f;margin:0 0 14px;">A patient has been registered under your name and their invoice has been raised.</p>'
            . mail_kv([
                'Patient'       => $r['patient_name'] . ' (MRN ' . $r['mrn'] . ')',
                'Token'         => '#' . $r['token_no'],
                'Invoice'       => $r['invoice_number'],
                'Type'          => $feeLabels[$r['consultation_fee_type']] ?? 'Consultation',
                'Amount'        => 'Rs ' . number_format((float) $r['grand_total'], 2),
                'Time'          => date('d M Y, h:i A'),
            ]);
        send_mail($pdo, $docEmail,
            'New patient — ' . $r['patient_name'] . ' (Token #' . $r['token_no'] . ')',
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
                'Time'           => date('d M Y, h:i A'),
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
            'Admitted at'      => date('d M Y, h:i A', strtotime($r['admitted_at'])),
        ];
        $body = '<p style="font-size:14px;color:#41504f;margin:0 0 14px;">A patient has been admitted.</p>' . mail_kv($rows);

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
            'Admitted'       => date('d M Y, h:i A', strtotime($r['admitted_at'])),
            'Discharged'     => date('d M Y, h:i A', strtotime($r['discharge_finalized_at'] ?? 'now')),
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

        $docEmail = user_email($pdo, (int) $r['doctor_id']);
        if (!$docEmail) { return; }

        $who = $r['patient_name']
            ? $r['patient_name'] . ' (MRN ' . $r['mrn'] . ')'
            : $r['person_name'] . ' (new caller)';
        $kv = [
            'Patient'  => $who,
            'Purpose'  => $r['purpose'],
            'Date'     => date('l, d M Y', strtotime($r['booking_date'])),
        ];
        if ($r['preferred_time']) { $kv['Preferred time'] = $r['preferred_time']; }
        if ($r['note'])           { $kv['Note'] = $r['note']; }
        $kv['Phone']    = $r['phone'];
        $kv['Taken by'] = $r['taken_by'] ?: 'Reception';

        $body = '<p style="font-size:14px;color:#41504f;margin:0 0 14px;">Reception has booked an appointment under your name.</p>'
            . mail_kv($kv);
        send_mail($pdo, $docEmail,
            'New booking — ' . $r['person_name'] . ' (' . $r['purpose'] . ', ' . date('d M', strtotime($r['booking_date'])) . ')',
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

        $docEmail = user_email($pdo, (int) $r['doctor_id']);
        if (!$docEmail) { return; }

        $who = $r['patient_name']
            ? $r['patient_name'] . ' (MRN ' . $r['mrn'] . ')'
            : $r['person_name'];
        $body = '<p style="font-size:14px;color:#41504f;margin:0 0 14px;">An appointment booked under your name has been cancelled.</p>'
            . mail_kv([
                'Patient'      => $who,
                'Purpose'      => $r['purpose'],
                'Was booked for' => date('l, d M Y', strtotime($r['booking_date'])),
                'Reason'       => $r['cancel_reason'] ?: '—',
                'Cancelled by' => $r['cancelled_by'] ?: 'Reception',
            ]);
        send_mail($pdo, $docEmail,
            'Booking cancelled — ' . $r['person_name'] . ' (' . date('d M', strtotime($r['booking_date'])) . ')',
            mail_template('Appointment Cancelled', $body),
            'booking-cancel:' . $bookingId);
    } catch (Throwable $e) { /* never break the page for a notification */ }
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

        $to = array_filter([admin_alert_email(), user_email($pdo, (int) $c['handover_to_id'])]);
        $body = '<p style="font-size:14px;color:#41504f;margin:0 0 14px;">'
              . htmlspecialchars($c['cashier_name']) . ' has closed their shift. '
              . 'The cash handover is awaiting your acknowledgment in the admin portal '
              . '(Cash Handovers → recount + confirm the signed slip is filed).</p>'
            . mail_kv([
                'Closing slip'       => $c['closing_number'],
                'Shift date'         => date('D d M Y', strtotime($c['closing_date'])),
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
            'Shift closed — ' . $c['cashier_name'] . ' ' . date('d M', strtotime($c['closing_date']))
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
            'Shift date'   => date('D d M Y', strtotime($c['closing_date'])),
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
