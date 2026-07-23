<?php
/**
 * Google Sheet Sync — admin view of what reached the yearly invoice sheet.
 *
 * Pushes are fire-and-forget by design (a sheet outage must never block a
 * payment), so this page is where an outage becomes visible and fixable:
 *   - every document that failed to reach the sheet, with the SMTP-style error
 *   - a Resend button per row, and a "Resend all failed" sweep
 *   - a connection test that proves the Apps Script deployment is reachable
 *
 * cron/sheet_retry.php does the same resend automatically every hour; this page
 * exists for the case where someone wants it fixed now, or where a row has hit
 * the retry ceiling and needs a human look.
 */
require_once __DIR__ . '/config/guard_admin.php';
require_once __DIR__ . '/config/sheets.php';

$error = '';
$success = '';

// The whole page depends on a table that may not exist yet.
$migrated = true;
try {
    $pdo->query('SELECT 1 FROM sheet_sync_log LIMIT 1');
} catch (PDOException $e) {
    $migrated = false;
}

// ---- Resend one row ----
if ($migrated && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resend') {
    $id = (int) ($_POST['log_id'] ?? 0);
    $s = $pdo->prepare('SELECT doc_type, doc_ref, payload FROM sheet_sync_log WHERE id = ?');
    $s->execute([$id]);
    $row = $s->fetch();
    if (!$row) {
        $error = 'That log entry no longer exists.';
    } elseif (!sheets_enabled()) {
        $error = 'Sheet sync is not configured yet — add config/sheets_config.php on the server first.';
    } else {
        $payload = json_decode((string) $row['payload'], true);
        if (!is_array($payload)) {
            $error = 'That row\'s saved payload is unreadable — it cannot be resent.';
        } else {
            // Always send the CURRENT secret; it may have been rotated since.
            $payload['secret'] = sheets_config()['shared_secret'] ?? '';
            [$ok, $err] = sheet_send($payload);
            sheet_mark($pdo, $row['doc_type'], (int) $row['doc_ref'], $ok, $err);
            if ($ok) {
                $success = 'Row sent to the sheet.';
                $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
                    ->execute([$_SESSION['user_id'], 'sheet_row_resent', "Resent {$row['doc_type']} #{$row['doc_ref']} to the Google Sheet"]);
            } else {
                $error = 'Still failing: ' . $err;
            }
        }
    }
}

// ---- Resend everything outstanding ----
if ($migrated && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resend_all') {
    if (!sheets_enabled()) {
        $error = 'Sheet sync is not configured yet — add config/sheets_config.php on the server first.';
    } else {
        $s = $pdo->prepare("SELECT doc_type, doc_ref, payload FROM sheet_sync_log WHERE status = 'failed' ORDER BY id LIMIT 200");
        $s->execute();
        $sent = 0; $stillFailing = 0;
        foreach ($s->fetchAll() as $row) {
            $payload = json_decode((string) $row['payload'], true);
            if (!is_array($payload)) { $stillFailing++; continue; }
            $payload['secret'] = sheets_config()['shared_secret'] ?? '';
            [$ok, $err] = sheet_send($payload);
            sheet_mark($pdo, $row['doc_type'], (int) $row['doc_ref'], $ok, $err);
            $ok ? $sent++ : $stillFailing++;
        }
        $success = "Resent $sent row(s)." . ($stillFailing > 0 ? " $stillFailing still failing." : '');
        if ($sent > 0) {
            $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
                ->execute([$_SESSION['user_id'], 'sheet_resend_all', "Resent $sent row(s) to the Google Sheet"]);
        }
    }
}

// ---- Connection test: sends nothing, just proves the endpoint answers ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test') {
    if (!sheets_enabled()) {
        $error = 'Nothing to test yet — config/sheets_config.php is missing or still holds the placeholder values.';
    } else {
        // Ping mode: the script validates the secret and answers without opening
        // the spreadsheet, so testing never appends a row or creates a stray tab.
        [$ok, $err] = sheet_send([
            'secret' => sheets_config()['shared_secret'] ?? '',
            'ping'   => true,
        ]);
        if ($ok) {
            $success = 'Connected — the Apps Script accepted the shared secret. Live pushes will work.';
        } elseif (stripos((string) $err, 'no columns') !== false) {
            // An older deployment of the script predates ping mode; reaching this
            // branch still proves the URL and the secret are both good.
            $success = 'Connected — the shared secret was accepted. (Re-deploy the Apps Script to pick up the latest version.)';
        } elseif (stripos((string) $err, 'bad secret') !== false) {
            $error = 'Reached the script, but it rejected the secret. Make sure SHARED_SECRET in the Apps Script matches config/sheets_config.php, and that you redeployed after editing it.';
        } else {
            $error = 'Could not reach the sheet: ' . $err;
        }
    }
}

// ---- Load ----
$rows = [];
$counts = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
$filter = $_GET['status'] ?? 'all';
if ($migrated) {
    foreach ($pdo->query('SELECT status, COUNT(*) c FROM sheet_sync_log GROUP BY status')->fetchAll() as $c) {
        $counts[$c['status']] = (int) $c['c'];
    }
    $sql = '
        SELECT l.*,
               COALESCE(p1.name, p2.name, p3.name) AS patient_name
        FROM sheet_sync_log l
        LEFT JOIN bills b ON l.doc_type = \'INVOICE\' AND b.id = l.doc_ref
        LEFT JOIN visits v1 ON v1.id = b.visit_id
        LEFT JOIN patients p1 ON p1.id = v1.patient_id
        LEFT JOIN admissions a2 ON l.doc_type = \'ADMISSION\' AND a2.id = l.doc_ref
        LEFT JOIN visits v2 ON v2.id = a2.visit_id
        LEFT JOIN patients p2 ON p2.id = v2.patient_id
        LEFT JOIN admission_bills ab ON l.doc_type = \'DISCHARGE\' AND ab.id = l.doc_ref
        LEFT JOIN admissions a3 ON a3.id = ab.admission_id
        LEFT JOIN visits v3 ON v3.id = a3.visit_id
        LEFT JOIN patients p3 ON p3.id = v3.patient_id
    ';
    if (in_array($filter, ['sent', 'failed', 'skipped'], true)) {
        $sql .= ' WHERE l.status = ' . $pdo->quote($filter);
    }
    $sql .= ' ORDER BY l.id DESC LIMIT 300';
    try {
        $rows = $pdo->query($sql)->fetchAll();
    } catch (PDOException $e) {
        // Older DBs may lack one of the joined tables; fall back to the bare log.
        $rows = $pdo->query('SELECT * FROM sheet_sync_log ORDER BY id DESC LIMIT 300')->fetchAll();
    }
}

$cfg = sheets_config();
$pageTitle = 'Google Sheet Sync';
$headExtra = <<<CSS
<style>
.header { height: 72px; position: sticky; top: 0; z-index: 20; display: flex; align-items: center; justify-content: space-between; padding: 0 32px; background: rgba(255,255,255,.80); backdrop-filter: blur(18px); border-bottom: 1px solid var(--border); }
.header-right { display: flex; align-items: center; gap: 18px; margin-left: auto; }
.header-date { font-size: 13px; color: var(--text-secondary); white-space: nowrap; }
.logout-link { font-size: 13px; color: var(--text-secondary); font-weight: 500; }

.note-box { font-size: 12.5px; color: var(--text-secondary); background: var(--primary-light); border-radius: 10px; padding: 12px 16px; margin-bottom: 18px; line-height: 1.6; }
.note-box.warn { color: #92400E; background: rgba(245,158,11,.10); }
.stat-row { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 18px; }
.stat-chip { display: flex; flex-direction: column; gap: 2px; padding: 10px 16px; border-radius: 12px; border: 1px solid var(--border); background: #fff; text-decoration: none; min-width: 104px; }
.stat-chip.on { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,127,126,.12); }
.stat-chip .n { font-size: 20px; font-weight: 700; color: var(--text-primary); line-height: 1.1; }
.stat-chip .l { font-size: 11.5px; font-weight: 600; color: var(--text-secondary); }
.stat-chip.bad .n { color: var(--red-text); }
.stat-chip.good .n { color: var(--primary); }
.link-btn { background: none; border: none; color: var(--primary); font: inherit; font-size: 12.5px; font-weight: 600; cursor: pointer; padding: 0; }
.err-cell { font-size: 11.5px; color: var(--red-text); max-width: 320px; word-break: break-word; line-height: 1.45; }
.mono { font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace; font-size: 12px; }
.doc-tag { font-size: 11px; font-weight: 700; letter-spacing: .04em; padding: 3px 8px; border-radius: 6px; background: var(--bg); border: 1px solid var(--border); color: var(--text-secondary); white-space: nowrap; }
.doc-tag.INVOICE { color: #0E5456; background: rgba(26,127,126,.10); border-color: rgba(26,127,126,.28); }
.doc-tag.ADMISSION { color: #6D28D9; background: rgba(139,92,246,.10); border-color: rgba(139,92,246,.28); }
.doc-tag.DISCHARGE { color: #92400E; background: rgba(245,158,11,.12); border-color: rgba(245,158,11,.30); }
.setup-list { margin: 10px 0 0 18px; padding: 0; font-size: 12.5px; line-height: 1.9; color: var(--text-secondary); }
.setup-list code { background: rgba(0,0,0,.05); padding: 1px 6px; border-radius: 5px; font-size: 12px; }
/* Eight columns incl. a wrapped error message: let the TABLE scroll on narrow
   screens rather than the page body. app.css styles bare <table> but ships no
   scroll container. */
.table-wrap { overflow-x: auto; }
.table-wrap table { min-width: 860px; }
</style>
CSS;
require __DIR__ . '/partials/head.php';
$navActive = 'sheet_log';
require __DIR__ . '/partials/sidebar.php';
?>
        <header class="header">
            <div class="page-title" style="font-size:16px;">Google Sheet Sync</div>
            <div class="header-right">
                <span class="header-date"><?= date('D, d/m/Y') ?></span>
                <a class="logout-link" href="logout.php">Logout</a>
            </div>
        </header>

        <div class="content">
            <div class="page-head">
                <div>
                    <div class="page-title">Google Sheet Sync</div>
                    <div class="page-sub">Every invoice, admission and discharge logged to the yearly sheet</div>
                </div>
                <form method="post" style="margin:0;">
                    <input type="hidden" name="action" value="test">
                    <button type="submit" class="btn secondary">Test connection</button>
                </form>
            </div>

            <?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($success): ?><div class="alert success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

            <?php if (!$migrated): ?>
                <div class="note-box warn">
                    <strong>Migration not run yet.</strong> Run <code>sql/add_sheet_sync.sql</code> in phpMyAdmin to
                    create the <code>sheet_sync_log</code> table and add the optional patient email field.
                    Until then, invoices are still generated normally — they simply aren't logged to the sheet.
                </div>
            <?php elseif (!sheets_enabled()): ?>
                <div class="note-box warn">
                    <strong>Not connected yet.</strong> Invoices are being generated and recorded, but nothing is
                    reaching the sheet. To finish setup:
                    <ol class="setup-list">
                        <li>Open the Google Sheet → <strong>Extensions → Apps Script</strong>, paste the contents of
                            <code>docs/google-apps-script.gs</code>, and set <code>SHARED_SECRET</code>.</li>
                        <li><strong>Deploy → New deployment → Web app</strong>, execute as <em>Me</em>, access <em>Anyone</em>. Copy the <code>/exec</code> URL.</li>
                        <li>Copy <code>config/sheets.example.php</code> to <code>config/sheets_config.php</code> on the
                            server and paste in the URL and the same secret.</li>
                        <li>Come back and press <strong>Test connection</strong>.</li>
                    </ol>
                </div>
            <?php else: ?>
                <div class="note-box">
                    Sending to <span class="mono"><?= htmlspecialchars(substr((string) $cfg['webapp_url'], 0, 62)) ?>…</span>
                    &nbsp;·&nbsp; tab <strong><?= htmlspecialchars(str_replace('{year}', date('Y'), $cfg['tab_pattern'] ?? 'Baby Medics {year}')) ?></strong>.
                    Failed rows retry automatically every hour via <code>cron/sheet_retry.php</code>.
                </div>
            <?php endif; ?>

            <?php if ($migrated): ?>
            <div class="stat-row">
                <a class="stat-chip <?= $filter === 'all' ? 'on' : '' ?>" href="sheet_log.php">
                    <span class="n"><?= array_sum($counts) ?></span><span class="l">All rows</span>
                </a>
                <a class="stat-chip good <?= $filter === 'sent' ? 'on' : '' ?>" href="sheet_log.php?status=sent">
                    <span class="n"><?= $counts['sent'] ?></span><span class="l">In the sheet</span>
                </a>
                <a class="stat-chip bad <?= $filter === 'failed' ? 'on' : '' ?>" href="sheet_log.php?status=failed">
                    <span class="n"><?= $counts['failed'] ?></span><span class="l">Failed</span>
                </a>
                <?php if ($counts['skipped'] > 0): ?>
                <a class="stat-chip <?= $filter === 'skipped' ? 'on' : '' ?>" href="sheet_log.php?status=skipped">
                    <span class="n"><?= $counts['skipped'] ?></span><span class="l">Not connected</span>
                </a>
                <?php endif; ?>
                <?php if ($counts['failed'] > 0 && sheets_enabled()): ?>
                <form method="post" style="margin:0; display:flex; align-items:center;">
                    <input type="hidden" name="action" value="resend_all">
                    <button type="submit" class="btn">Resend all failed</button>
                </form>
                <?php endif; ?>
            </div>

            <div class="card">
                <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Type</th>
                            <th>Invoice #</th>
                            <th>Patient</th>
                            <th>Year</th>
                            <th>Status</th>
                            <th>Detail</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="8" class="empty">Nothing logged yet. The next invoice raised will appear here.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td class="mono"><?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></td>
                            <td><span class="doc-tag <?= htmlspecialchars($r['doc_type']) ?>"><?= htmlspecialchars($r['doc_type']) ?></span></td>
                            <td class="mono"><?= htmlspecialchars($r['invoice_number'] ?: '—') ?></td>
                            <td><?= htmlspecialchars($r['patient_name'] ?? '—') ?></td>
                            <td><?= (int) $r['sheet_year'] ?></td>
                            <td>
                                <?php if ($r['status'] === 'sent'): ?>
                                    <span class="status-pill active">In sheet</span>
                                <?php elseif ($r['status'] === 'skipped'): ?>
                                    <span class="status-pill pending">Not sent</span>
                                <?php else: ?>
                                    <span class="status-pill on-leave">Failed</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($r['status'] === 'sent'): ?>
                                    <span style="font-size:12px;color:var(--text-muted);">
                                        <?= $r['sent_at'] ? date('d/m/Y H:i', strtotime($r['sent_at'])) : '' ?>
                                    </span>
                                <?php elseif ($r['last_error']): ?>
                                    <div class="err-cell"><?= htmlspecialchars($r['last_error']) ?></div>
                                    <span style="font-size:11px;color:var(--text-muted);"><?= (int) $r['attempts'] ?> attempt<?= (int) $r['attempts'] === 1 ? '' : 's' ?></span>
                                <?php else: ?>
                                    <span style="font-size:12px;color:var(--text-muted);">Waiting for setup</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($r['status'] !== 'sent' && sheets_enabled()): ?>
                                <form method="post" style="margin:0;">
                                    <input type="hidden" name="action" value="resend">
                                    <input type="hidden" name="log_id" value="<?= (int) $r['id'] ?>">
                                    <button type="submit" class="link-btn">Resend</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
