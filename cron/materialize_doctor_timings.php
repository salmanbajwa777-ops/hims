<?php
/**
 * Materialize today's doctor_day_timings from each doctor's weekly template.
 *
 * doctor_day_timings is what everything actually reads — token_session_for()
 * (config/tokens.php), the doctor_off availability check in bookings.php, and
 * the shift-start popup in receptionist.php. But nothing writes a row into it
 * except a human opening Doctor Timings and clicking "Save today's timings".
 * A day where nobody does that — a busy morning, a new receptionist who
 * doesn't know the page exists — silently strands every one of those readers
 * on their empty-row fallback: token_session_for() sits on session 1 all day,
 * doctor_off is always false, the popup shows blank hours instead of the
 * doctor's real ones.
 *
 * This job closes that gap the same way doctor_timings.php's own in-memory
 * prefill already does it, just persisted: for every doctor with no row yet
 * for today, copy today's weekday from doctor_weekly_schedule into
 * doctor_day_timings. updated_by is left NULL, so the row still reads as
 * "not confirmed yet" everywhere the UI checks that (doctor_timings.php:264)
 * — reception can still override it, and the audit trail still shows whether
 * a human ever actually looked at today's hours.
 *
 * Register on Hostinger (hPanel → Advanced → Cron Jobs), daily at 00:05 PKT
 * (just after midnight, so today's date is materialized before the first
 * patient of the day walks in):
 *   /usr/bin/php /home/u402528120/public_html/hims/cron/materialize_doctor_timings.php
 * In hPanel's PHP-mode form the /usr/bin/php and /home/u402528120/ parts are
 * pre-filled, so the box only takes:
 *   public_html/hims/cron/materialize_doctor_timings.php
 *
 * Also runnable from the browser as a one-off test:
 *   https://hims.babymedics.com/cron/materialize_doctor_timings.php?key=hims-daily-2026
 *
 * Self-healing: it only ever inserts rows that don't exist yet for CURDATE(),
 * so running it late, twice, or on a day reception already confirmed by hand
 * is always a no-op for the rows that matter.
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
    $weekday = (int) date('N');

    $ins = $pdo->prepare('
        INSERT INTO doctor_day_timings
            (doctor_id, timing_date, start_time, end_time, start_time_2, end_time_2, status)
        SELECT dws.doctor_id, CURDATE(),
               dws.start_time, dws.end_time, dws.start_time_2, dws.end_time_2,
               IF(dws.is_off, \'OFF\', \'AVAILABLE\')
        FROM doctor_weekly_schedule dws
        JOIN users u ON u.id = dws.doctor_id AND u.base_role = \'DOCTOR\'
        WHERE dws.weekday = ?
          AND NOT EXISTS (
              SELECT 1 FROM doctor_day_timings t
              WHERE t.doctor_id = dws.doctor_id AND t.timing_date = CURDATE()
          )
    ');
    $ins->execute([$weekday]);
    $made = $ins->rowCount();

    if ($made > 0) {
        // System action — attributed to no user, same convention as the
        // no-show sweep.
        $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (NULL, ?, ?)')
            ->execute(['doctor_timings_materialized', "Auto-filled today's timings for $made doctor(s) from their weekly schedule"]);
    }

    $msg = "Materialized today's timings for $made doctor(s) from their weekly schedule.";
    echo (php_sapi_name() === 'cli' ? $msg . "\n" : $msg);
} catch (Throwable $e) {
    // doctor_weekly_schedule / doctor_day_timings missing (migration not run)
    // or a transient DB error.
    $msg = 'Materialize FAILED: ' . $e->getMessage();
    echo (php_sapi_name() === 'cli' ? $msg . "\n" : htmlspecialchars($msg));
}
