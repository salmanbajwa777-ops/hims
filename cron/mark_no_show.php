<?php
/**
 * No-show sweep — flips stale BOOKED bookings to NO_SHOW after the clinic day.
 *
 * Register on Hostinger (hPanel → Advanced → Cron Jobs), daily at 22:00 PKT
 * (a SECOND entry, next to the 21:00 daily_summary — the summary deliberately
 * runs first so it can report unconsumed bookings while they're still open):
 *   /usr/bin/php /home/u402528120/public_html/hims/cron/mark_no_show.php
 * In hPanel's PHP-mode form the /usr/bin/php and /home/u402528120/ parts are
 * pre-filled, so the box only takes:  public_html/hims/cron/mark_no_show.php
 * (Path verified against File Manager 2026-07-24 — hims sits DIRECTLY under
 * public_html; there is no domains/babymedics.com/ segment despite hims
 * being served from the hims.babymedics.com subdomain.)
 *
 * Also runnable from the browser as a one-off test:
 *   https://hims.babymedics.com/cron/mark_no_show.php?key=hims-daily-2026
 *
 * Self-healing: sweeps EVERY booking_date <= today still sitting in BOOKED,
 * not just today's — a missed cron night catches up automatically on the next
 * run (same principle as VacEmp's mark_absent catch-up window). Manual
 * no-show marking on bookings.php covers a desk closing early.
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

try {
    $upd = $pdo->prepare("
        UPDATE bookings SET status = 'NO_SHOW'
        WHERE status = 'BOOKED' AND booking_date <= CURDATE()
    ");
    $upd->execute();
    $swept = $upd->rowCount();

    if ($swept > 0) {
        // System action — attributed to no user.
        $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (NULL, ?, ?)')
            ->execute(['booking_no_show_sweep', "Nightly sweep marked $swept booking(s) as no-show"]);
    }

    $msg = "Swept $swept stale booking(s) to NO_SHOW.";
    echo (php_sapi_name() === 'cli' ? $msg . "\n" : $msg);
} catch (Throwable $e) {
    // bookings table missing (migration not run) or transient DB error.
    $msg = 'Sweep FAILED: ' . $e->getMessage();
    echo (php_sapi_name() === 'cli' ? $msg . "\n" : htmlspecialchars($msg));
}
