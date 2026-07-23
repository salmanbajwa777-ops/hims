<?php
/**
 * Admin daily summary email — one email each evening to the admin alert address.
 *
 * Register on Hostinger (hPanel → Advanced → Cron Jobs), daily at 21:00 PKT:
 *   /usr/bin/php /home/u402528120/domains/babymedics.com/public_html/hims/cron/daily_summary.php
 * (adjust the path to wherever hims/ lives under public_html — check File Manager)
 *
 * Also runnable from the browser as a one-off test:
 *   https://hims.babymedics.com/cron/daily_summary.php?key=hims-daily-2026
 *
 * Covers TODAY (PKT). Sends even on a quiet day so silence is distinguishable
 * from a broken cron.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/mailer.php';

// CLI runs freely; browser runs need the key ('cron_key' in config/mail.php,
// falling back to the default below).
$cronKey = (mail_config() ?? [])['cron_key'] ?? 'hims-daily-2026';
if (php_sapi_name() !== 'cli' && ($_GET['key'] ?? '') !== $cronKey) {
    http_response_code(403);
    exit('Forbidden.');
}

$today = date('Y-m-d');
$niceDate = date('l, d M Y');

// ---- Registrations & revenue (consultation bills created today) ----
$reg = $pdo->query("
    SELECT COUNT(*) AS visits,
           COALESCE(SUM(b.grand_total), 0) AS billed,
           COALESCE(SUM(CASE WHEN b.status = 'paid' THEN b.paid_amount ELSE 0 END), 0) AS collected
    FROM bills b
    WHERE DATE(b.created_at) = CURDATE()
")->fetch();

$newPatients = (int) $pdo->query("SELECT COUNT(*) FROM patients WHERE DATE(created_at) = CURDATE()")->fetchColumn();

// Per-doctor breakdown of today's consultation billing.
$byDoctor = $pdo->query("
    SELECT du.name AS doctor, COUNT(*) AS visits, COALESCE(SUM(b.grand_total), 0) AS billed
    FROM bills b
    JOIN visits v ON v.id = b.visit_id
    JOIN users du ON du.id = v.doctor_id
    WHERE DATE(b.created_at) = CURDATE()
    GROUP BY du.id, du.name
    ORDER BY billed DESC
")->fetchAll();

// ---- Refunds today ----
$ref = $pdo->query("
    SELECT COUNT(*) AS cnt, COALESCE(SUM(amount), 0) AS total
    FROM refunds WHERE DATE(created_at) = CURDATE()
")->fetch();

// ---- Admissions today ----
$admitted = (int) $pdo->query("SELECT COUNT(*) FROM admissions WHERE DATE(admitted_at) = CURDATE()")->fetchColumn();
$discharged = (int) $pdo->query("SELECT COUNT(*) FROM admissions WHERE DATE(discharge_finalized_at) = CURDATE()")->fetchColumn();
$stillIn = (int) $pdo->query("SELECT COUNT(*) FROM admissions WHERE status <> 'DISCHARGED'")->fetchColumn();
$admBilling = $pdo->query("
    SELECT COALESCE(SUM(paid_amount), 0) AS collected, COALESCE(SUM(write_off_amount), 0) AS written_off
    FROM admission_bills WHERE DATE(paid_at) = CURDATE()
")->fetch();

// ---- Counter expenses today (voided excluded) ----
$exp = ['cnt' => 0, 'total' => 0.0];
$expByCat = [];
try {
    $exp = $pdo->query("
        SELECT COUNT(*) AS cnt, COALESCE(SUM(amount), 0) AS total
        FROM expenses WHERE expense_date = CURDATE() AND voided_at IS NULL
    ")->fetch();
    $expByCat = $pdo->query("
        SELECT ec.name, COALESCE(SUM(e.amount), 0) AS total
        FROM expenses e JOIN expense_categories ec ON ec.id = e.category_id
        WHERE e.expense_date = CURDATE() AND e.voided_at IS NULL
        GROUP BY ec.id, ec.name ORDER BY total DESC
    ")->fetchAll();
} catch (Throwable $e) { /* expense tables may not exist yet */ }

// ---- Bookings today (this runs at 21:00, BEFORE the 22:00 no-show sweep, so
// unconsumed bookings are reported while they're still actionable) ----
$bkStats = null;
try {
    $bkStats = $pdo->query("
        SELECT COUNT(*) AS total,
               SUM(status = 'ARRIVED') AS arrived,
               SUM(status = 'BOOKED') AS still_open,
               SUM(status = 'CANCELLED') AS cancelled,
               SUM(status = 'NO_SHOW') AS no_show
        FROM bookings WHERE booking_date = CURDATE()
    ")->fetch();
    if ((int) $bkStats['total'] === 0) { $bkStats = null; }
} catch (Throwable $e) { /* bookings table may not exist yet */ }

// ---- Email failures today (so a broken SMTP surfaces in the summary itself) ----
$mailFails = 0;
try {
    $mailFails = (int) $pdo->query("SELECT COUNT(*) FROM email_log WHERE status = 'failed' AND DATE(created_at) = CURDATE()")->fetchColumn();
} catch (Throwable $e) { /* table may not exist yet */ }

// ---- Compose ----
$fmt = function ($n) { return 'Rs ' . number_format((float) $n, 0); };

$body = '<p style="font-size:14px;color:#41504f;margin:0 0 14px;">Here is the day\'s summary for <strong>' . $niceDate . '</strong>.</p>'
    . mail_kv([
        'New patients registered' => $newPatients,
        'Consultations billed'    => $reg['visits'] . ' — ' . $fmt($reg['billed']),
        'Consultation collected'  => $fmt($reg['collected']),
        'Refunds issued'          => $ref['cnt'] . ' — ' . $fmt($ref['total']),
        'Admissions today'        => $admitted,
        'Discharges today'        => $discharged . ' (collected ' . $fmt($admBilling['collected'])
                                     . ((float) $admBilling['written_off'] > 0 ? ', WRITTEN OFF ' . $fmt($admBilling['written_off']) : '') . ')',
        'Patients still admitted' => $stillIn,
        'Counter expenses'        => $exp['cnt'] . ' — ' . $fmt($exp['total']),
    ]);

if ($expByCat) {
    $expLines = [];
    foreach ($expByCat as $c) {
        $expLines[] = htmlspecialchars($c['name']) . ' ' . $fmt($c['total']);
    }
    $body .= '<p style="margin:6px 0 0;font-size:12.5px;color:#41504f;">Expenses by category: '
        . implode(' · ', $expLines) . '</p>';
}

if ($bkStats) {
    $bkLine = (int) $bkStats['total'] . ' booked — ' . (int) $bkStats['arrived'] . ' arrived';
    if ((int) $bkStats['cancelled'] > 0) { $bkLine .= ', ' . (int) $bkStats['cancelled'] . ' cancelled'; }
    if ((int) $bkStats['no_show'] > 0)   { $bkLine .= ', ' . (int) $bkStats['no_show'] . ' no-show'; }
    $body .= '<p style="margin:10px 0 0;font-size:12.5px;color:#41504f;">Phone bookings today: ' . $bkLine . '.</p>';
    if ((int) $bkStats['still_open'] > 0) {
        $body .= '<p style="margin:4px 0 0;font-size:12.5px;color:#b45309;"><strong>'
            . (int) $bkStats['still_open'] . ' booking(s) still unconsumed</strong> — the 10 pm sweep will mark them no-show unless the patient arrives.</p>';
    }
}

if ($byDoctor) {
    $body .= '<h3 style="margin:18px 0 8px;font-size:14px;color:#0E5456;">By doctor</h3>'
        . '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;">'
        . '<tr>'
        . '<td style="padding:7px 10px;border:1px solid #e5ecec;background:#0E5456;color:#fff;font-size:12px;">Doctor</td>'
        . '<td style="padding:7px 10px;border:1px solid #e5ecec;background:#0E5456;color:#fff;font-size:12px;">Visits</td>'
        . '<td style="padding:7px 10px;border:1px solid #e5ecec;background:#0E5456;color:#fff;font-size:12px;">Billed</td>'
        . '</tr>';
    foreach ($byDoctor as $d) {
        $body .= '<tr>'
            . '<td style="padding:7px 10px;border:1px solid #e5ecec;font-size:13px;">' . htmlspecialchars($d['doctor']) . '</td>'
            . '<td style="padding:7px 10px;border:1px solid #e5ecec;font-size:13px;">' . (int) $d['visits'] . '</td>'
            . '<td style="padding:7px 10px;border:1px solid #e5ecec;font-size:13px;font-weight:bold;">' . $fmt($d['billed']) . '</td>'
            . '</tr>';
    }
    $body .= '</table>';
}

if ($mailFails > 0) {
    $body .= '<p style="margin:16px 0 0;font-size:13px;color:#b3261e;"><strong>' . $mailFails
        . ' notification email(s) failed to send today</strong> — check the email_log table.</p>';
}

$ok = send_mail(
    $pdo,
    admin_alert_email(),
    'HMIS daily summary — ' . date('d M') . ' · ' . $fmt($reg['collected'] + $admBilling['collected']) . ' collected',
    mail_template('Daily Summary — ' . $niceDate, $body),
    'daily-summary:' . $today
);

if (php_sapi_name() === 'cli') {
    echo ($ok ? 'sent' : 'FAILED (see email_log)') . "\n";
} else {
    echo $ok ? 'Daily summary sent.' : 'Send FAILED — check the email_log table and config/mail.php.';
    if (!$ok) {
        // Surface the recorded SMTP error right here so diagnosing doesn't
        // require a phpMyAdmin round-trip. Key-gated page, admin-only info.
        try {
            $last = $pdo->query("SELECT status, error, created_at FROM email_log ORDER BY id DESC LIMIT 1")->fetch();
            if ($last) {
                echo '<br><br>Last attempt (' . htmlspecialchars($last['created_at']) . '): <strong>'
                    . htmlspecialchars($last['status']) . '</strong> — '
                    . htmlspecialchars($last['error'] ?? '(no error recorded)');
            }
        } catch (Throwable $e) {
            echo '<br><br>email_log table does not exist yet — run sql/add_email_log.sql in phpMyAdmin.';
        }
        if (!mail_config()) {
            echo '<br>config/mail.php is missing, disabled, or its password is still CHANGE_ME.';
        }
    }
}
