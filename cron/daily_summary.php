<?php
/**
 * Admin daily summary email — one email each evening to the admin alert address.
 *
 * Register on Hostinger (hPanel → Advanced → Cron Jobs), daily at 21:00 PKT:
 *   /usr/bin/php /home/u402528120/public_html/hims/cron/daily_summary.php
 * In hPanel's PHP-mode form the /usr/bin/php and /home/u402528120/ parts are
 * pre-filled, so the box only takes:  public_html/hims/cron/daily_summary.php
 * (Path verified against File Manager 2026-07-24 — hims sits DIRECTLY under
 * public_html; there is no domains/babymedics.com/ segment despite hims
 * being served from the hims.babymedics.com subdomain.)
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
$niceDate = date('l, d/m/Y');

// ---- Registrations & revenue (consultation bills created today) ----
$reg = $pdo->query("
    SELECT COUNT(*) AS visits,
           COALESCE(SUM(b.grand_total), 0) AS billed,
           COALESCE(SUM(CASE WHEN b.status = 'paid' THEN b.paid_amount ELSE 0 END), 0) AS collected
    FROM bills b
    WHERE DATE(b.created_at) = CURDATE() AND b.voided_at IS NULL
")->fetch();

$newPatients = (int) $pdo->query("SELECT COUNT(*) FROM patients WHERE DATE(created_at) = CURDATE()")->fetchColumn();

// Per-doctor breakdown of today's consultation billing.
$byDoctor = $pdo->query("
    SELECT du.name AS doctor, COUNT(*) AS visits, COALESCE(SUM(b.grand_total), 0) AS billed
    FROM bills b
    JOIN visits v ON v.id = b.visit_id
    JOIN users du ON du.id = v.doctor_id
    WHERE DATE(b.created_at) = CURDATE() AND b.voided_at IS NULL
    GROUP BY du.id, du.name
    ORDER BY billed DESC
")->fetchAll();

// Per-cashier accountability: each user is responsible for the money THEY
// collected and the expenses THEY posted, not the day as a whole. Union the
// cash/online they took (consult + admission, keyed off paid_by_id — the
// collector) against the counter expenses they paid out, so the summary shows
// who owes what cash at closing. Tolerant of the per-user migration not being
// applied yet (paid_by_id / expenses missing → empty section).
$byCashier = [];
try {
    $byCashier = $pdo->query("
        SELECT u.name AS cashier,
               COALESCE(SUM(m.cash), 0)         AS cash_in,
               COALESCE(SUM(m.online), 0)       AS online_in,
               COALESCE(SUM(m.pay_cnt), 0)      AS pay_cnt,
               COALESCE(SUM(m.expense), 0)      AS expense_out,
               COALESCE(SUM(m.exp_cnt), 0)      AS exp_cnt
        FROM (
            SELECT paid_by_id AS uid,
                   SUM(CASE WHEN payment_method = 'cash' THEN paid_amount ELSE 0 END) AS cash,
                   SUM(CASE WHEN payment_method <> 'cash' THEN paid_amount ELSE 0 END) AS online,
                   COUNT(*) AS pay_cnt, 0 AS expense, 0 AS exp_cnt
            FROM bills
            WHERE status = 'paid' AND voided_at IS NULL
              AND DATE(paid_at) = CURDATE() AND paid_by_id IS NOT NULL
            GROUP BY paid_by_id
            UNION ALL
            SELECT paid_by_id AS uid,
                   SUM(CASE WHEN payment_method = 'cash' THEN paid_amount ELSE 0 END),
                   SUM(CASE WHEN payment_method <> 'cash' THEN paid_amount ELSE 0 END),
                   COUNT(*), 0, 0
            FROM admission_bills
            WHERE status = 'paid' AND voided_at IS NULL
              AND DATE(paid_at) = CURDATE() AND paid_by_id IS NOT NULL
            GROUP BY paid_by_id
            UNION ALL
            SELECT posted_by_id AS uid, 0, 0, 0, SUM(amount), COUNT(*)
            FROM expenses
            WHERE expense_date = CURDATE() AND voided_at IS NULL
              AND approval_status <> 'REJECTED'
            GROUP BY posted_by_id
        ) m
        JOIN users u ON u.id = m.uid
        GROUP BY m.uid, u.name
        HAVING pay_cnt > 0 OR exp_cnt > 0
        ORDER BY cash_in DESC
    ")->fetchAll();
} catch (Throwable $e) { /* per-user columns not migrated yet — skip section */ }

// ---- Refunds today ----
$ref = $pdo->query("
    SELECT COUNT(*) AS cnt, COALESCE(SUM(amount), 0) AS total
    FROM refunds WHERE DATE(created_at) = CURDATE() AND voided_at IS NULL
")->fetch();

// ---- Admissions today ----
$admitted = (int) $pdo->query("SELECT COUNT(*) FROM admissions WHERE DATE(admitted_at) = CURDATE()")->fetchColumn();
$discharged = (int) $pdo->query("SELECT COUNT(*) FROM admissions WHERE DATE(discharge_finalized_at) = CURDATE()")->fetchColumn();
$stillIn = (int) $pdo->query("SELECT COUNT(*) FROM admissions WHERE status <> 'DISCHARGED'")->fetchColumn();
$admBilling = $pdo->query("
    SELECT COALESCE(SUM(paid_amount), 0) AS collected, COALESCE(SUM(write_off_amount), 0) AS written_off
    FROM admission_bills WHERE DATE(paid_at) = CURDATE() AND voided_at IS NULL
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

// Per-cashier cash accountability — who is responsible for what at closing.
// Expected cash in hand = their cash collected − their counter expenses (their
// own cash refunds would net here too, but refunds aren't attributed per user
// in this rollup; the shift-closing page is the authoritative per-user tally).
if ($byCashier) {
    $body .= '<h3 style="margin:18px 0 8px;font-size:14px;color:#0E5456;">Cash accountability by cashier</h3>'
        . '<p style="margin:0 0 8px;font-size:12px;color:#6b7a79;">Each user is responsible for the money they collected and the expenses they paid out — not the day as a whole.</p>'
        . '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;">'
        . '<tr>'
        . '<td style="padding:7px 10px;border:1px solid #e5ecec;background:#0E5456;color:#fff;font-size:12px;">Cashier</td>'
        . '<td style="padding:7px 10px;border:1px solid #e5ecec;background:#0E5456;color:#fff;font-size:12px;">Cash in</td>'
        . '<td style="padding:7px 10px;border:1px solid #e5ecec;background:#0E5456;color:#fff;font-size:12px;">Online in</td>'
        . '<td style="padding:7px 10px;border:1px solid #e5ecec;background:#0E5456;color:#fff;font-size:12px;">Expenses</td>'
        . '<td style="padding:7px 10px;border:1px solid #e5ecec;background:#0E5456;color:#fff;font-size:12px;">Expected cash</td>'
        . '</tr>';
    foreach ($byCashier as $c) {
        $expected = (float) $c['cash_in'] - (float) $c['expense_out'];
        $body .= '<tr>'
            . '<td style="padding:7px 10px;border:1px solid #e5ecec;font-size:13px;">' . htmlspecialchars($c['cashier'])
            . ' <span style="color:#6b7a79;font-size:11px;">(' . (int) $c['pay_cnt'] . ' pay'
            . ((int) $c['exp_cnt'] > 0 ? ', ' . (int) $c['exp_cnt'] . ' exp' : '') . ')</span></td>'
            . '<td style="padding:7px 10px;border:1px solid #e5ecec;font-size:13px;">' . $fmt($c['cash_in']) . '</td>'
            . '<td style="padding:7px 10px;border:1px solid #e5ecec;font-size:13px;">' . $fmt($c['online_in']) . '</td>'
            . '<td style="padding:7px 10px;border:1px solid #e5ecec;font-size:13px;color:' . ((float) $c['expense_out'] > 0 ? '#b3261e' : '#41504f') . ';">'
            . ((float) $c['expense_out'] > 0 ? '− ' . $fmt($c['expense_out']) : $fmt(0)) . '</td>'
            . '<td style="padding:7px 10px;border:1px solid #e5ecec;font-size:13px;font-weight:bold;">' . $fmt($expected) . '</td>'
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
    'HMIS daily summary — ' . date('d/m') . ' · ' . $fmt($reg['collected'] + $admBilling['collected']) . ' collected',
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
