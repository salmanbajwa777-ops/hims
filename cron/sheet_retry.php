<?php
/**
 * Google Sheet retry sweep — re-sends invoice rows whose live push failed.
 *
 * The push at registration/discharge is fire-and-forget so a slow or unreachable
 * sheet can never block a payment. That means a failed push has to be recoverable
 * elsewhere; this is that. Every failure sits in sheet_sync_log as 'failed' with
 * the exact JSON payload it tried to send, and this sweep re-sends it verbatim —
 * so the sheet ends up with the figures as they stood at the time, not as they
 * might look after a later edit.
 *
 * Register on Hostinger (hPanel → Advanced → Cron Jobs), hourly:
 *   /usr/bin/php /home/u402528120/domains/babymedics.com/public_html/hims/cron/sheet_retry.php
 *
 * Also runnable from the browser as a one-off:
 *   https://hims.babymedics.com/cron/sheet_retry.php?key=hims-daily-2026
 *
 * Self-healing like cron/mark_no_show.php: it sweeps EVERY outstanding row, not
 * just recent ones, so a missed hour (or a sheet that was down all day) catches up
 * on the next run. Rows are retried at most MAX_ATTEMPTS times so a permanently
 * malformed row can't be re-sent forever; those stay visible on sheet_log.php for
 * a manual resend.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/mailer.php';
require_once __DIR__ . '/../config/sheets.php';

const MAX_ATTEMPTS = 12;
const BATCH_LIMIT  = 100;   // keeps one run inside PHP's max_execution_time

// CLI runs freely; browser runs need the key (same key as the other crons).
$cronKey = (mail_config() ?? [])['cron_key'] ?? 'hims-daily-2026';
if (php_sapi_name() !== 'cli' && ($_GET['key'] ?? '') !== $cronKey) {
    http_response_code(403);
    exit('Forbidden.');
}

$isCli = php_sapi_name() === 'cli';
if (!$isCli) { header('Content-Type: text/plain; charset=utf-8'); }

if (!sheets_enabled()) {
    exit("Sheet sync is not configured (config/sheets_config.php) — nothing to do.\n");
}

$sent = 0; $failed = 0; $gaveUp = 0;

try {
    $stmt = $pdo->prepare('
        SELECT id, doc_type, doc_ref, invoice_number, payload, attempts
        FROM sheet_sync_log
        WHERE status = ? AND attempts < ?
        ORDER BY id
        LIMIT ' . BATCH_LIMIT
    );
    $stmt->execute(['failed', MAX_ATTEMPTS]);
    $rows = $stmt->fetchAll();

    foreach ($rows as $r) {
        $payload = json_decode((string) $r['payload'], true);
        if (!is_array($payload)) {
            // Unusable payload — stop retrying it rather than looping forever.
            $pdo->prepare('UPDATE sheet_sync_log SET attempts = ?, last_error = ? WHERE id = ?')
                ->execute([MAX_ATTEMPTS, 'payload not decodable', $r['id']]);
            $gaveUp++;
            continue;
        }

        // The secret may have been rotated since the row was queued; always send
        // the CURRENT one rather than whatever was stored with the payload.
        $payload['secret'] = sheets_config()['shared_secret'] ?? '';

        [$ok, $err] = sheet_send($payload);
        sheet_mark($pdo, $r['doc_type'], (int) $r['doc_ref'], $ok, $err);

        if ($ok) {
            $sent++;
        } else {
            $failed++;
            if ((int) $r['attempts'] + 1 >= MAX_ATTEMPTS) { $gaveUp++; }
        }
    }

    if ($sent > 0) {
        $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (NULL, ?, ?)')
            ->execute(['sheet_retry_swept', "Re-sent $sent invoice row(s) to the Google Sheet"]);
    }

    echo "Checked " . count($rows) . " outstanding row(s): $sent sent, $failed still failing";
    echo $gaveUp > 0 ? ", $gaveUp gave up (max attempts reached — resend manually from sheet_log.php)\n" : "\n";
} catch (PDOException $e) {
    // sheet_sync_log missing = migration not run yet. Not an error worth alerting on.
    echo "sheet_sync_log unavailable — has sql/add_sheet_sync.sql been run? (" . $e->getMessage() . ")\n";
} catch (Throwable $e) {
    echo "Sweep failed: " . $e->getMessage() . "\n";
}
