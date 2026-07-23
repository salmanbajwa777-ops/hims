<?php
// Cash handovers — admin side (approved mock 2026-07-23).
//
// Pending closings from reception land here. Admin recounts the cash, ticks
// "signed A5 slip collected & filed", enters the actual amount if the recount
// differs (the difference logs as a HANDOVER DISCREPANCY, separate from the
// drawer variance), and marks received — which stamps who/when on the closing
// and completes the day's audit trail.

require_once __DIR__ . '/config/guard_admin.php';
require_once __DIR__ . '/config/billing.php';
require_permission('ADMIN_RECEIVE_HANDOVER');

$error = '';
$success = '';

// ---------------- Mark received ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_received') {
    $closingId = (int) ($_POST['closing_id'] ?? 0);
    $received = round((float) str_replace(',', '', $_POST['handover_received'] ?? '0'), 2);
    $cashOk = !empty($_POST['cash_ok']);
    $slipFiled = !empty($_POST['slip_filed']);

    try {
        $pdo->beginTransaction();

        // Lock the row so two admins can't both acknowledge the same handover.
        // EDITED closings are receivable too — marking received IS the approval
        // of the cashier's post-close changes.
        $stmt = $pdo->prepare("SELECT * FROM shift_closings WHERE id = ? AND status IN ('PENDING_RECEIPT','EDITED') FOR UPDATE");
        $stmt->execute([$closingId]);
        $closing = $stmt->fetch();

        if (!$closing) {
            $error = 'Handover not found or already received.';
        } elseif (!$cashOk) {
            $error = 'Confirm you have recounted the cash before marking received.';
        } elseif (!$slipFiled) {
            $error = 'Confirm the signed A5 slip is collected and filed before marking received.';
        } elseif ($received < 0) {
            $error = 'The received amount cannot be negative.';
        } else {
            $pdo->prepare("
                UPDATE shift_closings
                SET status = 'RECEIVED', handover_received = ?, received_by_id = ?,
                    received_at = NOW(), slip_filed = 1
                WHERE id = ?
            ")->execute([$received, $_SESSION['user_id'], $closingId]);

            $declared = (float) $closing['handover_declared'];
            $discrepancy = round($received - $declared, 2);
            $wasEdited = $closing['status'] === 'EDITED';
            $detail = "Handover {$closing['closing_number']} received: Rs " . number_format($received, 2)
                    . ' (declared Rs ' . number_format($declared, 2) . ')'
                    . (abs($discrepancy) > 0.009
                        ? ' — DISCREPANCY Rs ' . number_format($discrepancy, 2)
                        : ' — matches declared')
                    . ($wasEdited ? '; cashier edits (×' . (int) $closing['edit_count'] . ') APPROVED' : '')
                    . '; signed slip filed.';
            $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
                ->execute([$_SESSION['user_id'], 'handover_received', $detail]);

            $pdo->commit();

            $success = 'Handover ' . $closing['closing_number'] . ' marked received'
                     . (abs($discrepancy) > 0.009
                        ? ' with a Rs ' . number_format(abs($discrepancy), 2)
                          . ($discrepancy < 0 ? ' shortfall' : ' excess') . ' logged.'
                        : '.');
        }

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = 'Could not mark the handover received. Please try again.';
    }
}

// ---------------- Page data ----------------
try {
    $pendingStmt = $pdo->query("
        SELECT c.*, cu.name AS cashier_name
        FROM shift_closings c
        JOIN users cu ON cu.id = c.cashier_id
        WHERE c.status IN ('PENDING_RECEIPT','EDITED')
        ORDER BY c.closing_date DESC, c.id
    ");
    $pending = $pendingStmt->fetchAll();
} catch (PDOException $e) {
    http_response_code(500);
    die('Shift-closing tables missing or outdated — run sql/add_per_user_closings.sql.');
}

// Per-field change history for the pending EDITED closings, newest round first.
$editLogs = [];
if ($pending) {
    $ids = array_column($pending, 'id');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    try {
        $elStmt = $pdo->prepare("
            SELECT e.*, u.name AS edited_by_name
            FROM shift_closing_edits e
            JOIN users u ON u.id = e.edited_by_id
            WHERE e.closing_id IN ($ph)
            ORDER BY e.closing_id, e.edit_round DESC, e.id
        ");
        $elStmt->execute($ids);
        foreach ($elStmt->fetchAll() as $row) {
            $editLogs[(int) $row['closing_id']][] = $row;
        }
    } catch (PDOException $e) {
        // edits table not migrated yet — show closings without change detail
    }
}

$FIELD_LABELS = [
    'counted_cash'      => 'Counted cash',
    'handover_declared' => 'Handover declared',
    'variance_note'     => 'Variance note',
    'denominations'     => 'Denominations',
];

$historyStmt = $pdo->query("
    SELECT c.closing_number, c.closing_date, c.handover_declared, c.handover_received,
           c.variance, c.received_at, c.id, c.edit_count,
           cu.name AS cashier_name, ru.name AS received_by_name
    FROM shift_closings c
    JOIN users cu ON cu.id = c.cashier_id
    LEFT JOIN users ru ON ru.id = c.received_by_id
    WHERE c.status = 'RECEIVED'
    ORDER BY c.closing_date DESC
    LIMIT 30
");
$history = $historyStmt->fetchAll();

$pageTitle = 'Cash Handovers';
require __DIR__ . '/partials/head.php';
$navActive = 'handovers';
require __DIR__ . '/partials/sidebar.php';
?>
<style>
.ho-grid { display: grid; grid-template-columns: 1.05fr .95fr; gap: 22px; align-items: start; }
@media (max-width: 980px) { .ho-grid { grid-template-columns: 1fr; } }
.ho-col { display: flex; flex-direction: column; gap: 22px; }

.pend { display: grid; grid-template-columns: 1fr auto; gap: 16px; align-items: center; border: 1px solid var(--border); border-radius: var(--radius-input); padding: 14px 16px; background: var(--bg); }
.pend .who { font-weight: 700; font-size: 14px; }
.pend .det { font-size: 12.5px; color: var(--text-muted); margin-top: 2px; }
.pend .amt { font-size: 20px; font-weight: 700; font-variant-numeric: tabular-nums; text-align: right; }

.check-line { display: flex; align-items: flex-start; gap: 10px; padding: 9px 0; }
.check-line input[type=checkbox] { width: 18px; height: 18px; margin-top: 2px; accent-color: var(--primary-dark); flex-shrink: 0; }
.check-line label { font-size: 13.5px; cursor: pointer; }
.check-line .d { font-size: 12px; color: var(--text-muted); font-weight: 400; }

.hfield { display: flex; flex-direction: column; gap: 6px; margin: 8px 0 14px; }
.hfield label { font-size: 12px; font-weight: 600; color: var(--text-secondary); }
.hfield input { padding: 10px 12px; border: 1px solid var(--border); border-radius: var(--radius-input); font: inherit; font-size: 15px; font-weight: 700; font-variant-numeric: tabular-nums; background: var(--bg); color: var(--text); }
.hfield input:focus { outline: 2px solid var(--primary); outline-offset: 1px; border-color: var(--primary); }

.rcv-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; border: none; border-radius: var(--radius-btn); font: inherit; font-weight: 600; font-size: 14px; padding: 12px 22px; cursor: pointer; background: var(--primary-dark); color: #fff; width: 100%; }
.rcv-btn:hover { background: var(--primary); }
.hint-note { font-size: 12px; color: var(--text-muted); text-align: center; margin-top: 10px; }

.htable { width: 100%; border-collapse: collapse; font-variant-numeric: tabular-nums; }
.htable th { text-align: left; font-size: 11px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--text-muted); padding: 7px 10px; border-bottom: 1px solid var(--border); }
.htable td { padding: 9px 10px; border-bottom: 1px solid var(--border); font-size: 13px; }
.htable tr:last-child td { border-bottom: none; }
.htable td.num { text-align: right; white-space: nowrap; }

.ho-pill { display: inline-block; border-radius: 999px; padding: 2px 10px; font-size: 11.5px; font-weight: 600; }
.ho-pill.green { background: var(--green-bg); color: var(--green-text); }
.ho-pill.amber { background: var(--amber-bg); color: var(--amber-text); }
.ho-pill.red { background: var(--red-bg); color: var(--red-text); }

.edit-log { margin-top: 12px; border: 1px solid var(--amber); border-radius: var(--radius-input); background: var(--amber-bg); padding: 12px 14px; overflow-x: auto; }
.edit-log-title { font-size: 12px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: var(--amber-text); margin-bottom: 8px; }
.edit-log .htable td, .edit-log .htable th { border-color: rgba(146,64,14,.25); }
.chg-old { color: var(--red-text); text-decoration: line-through; }
.chg-new { color: var(--green-text); font-weight: 700; }
.edit-log-note { font-size: 11.5px; color: var(--amber-text); margin-top: 8px; }
.alert-error { background: var(--red-bg); color: var(--red-text); border-radius: var(--radius-input); padding: 12px 16px; font-size: 13px; font-weight: 500; }
.alert-ok { background: var(--green-bg); color: var(--green-text); border-radius: var(--radius-input); padding: 12px 16px; font-size: 13px; font-weight: 500; }
.empty { text-align: center; color: var(--text-muted); padding: 26px 0; font-size: 13.5px; }
.slip-link { font-weight: 600; color: var(--primary-dark); text-decoration: underline; }
</style>

<div class="content">

    <div class="page-head">
        <h1>Cash Handovers</h1>
        <p>Each receptionist's shift closing lands here separately. Recount their cash, review any highlighted post-close edits, confirm the signed slip is filed, then mark received — that locks the shift for good.</p>
    </div>

    <?php if ($error): ?><div class="alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert-ok"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <div class="ho-grid">
        <div class="ho-col">

            <div class="card">
                <h2 style="font-size:15px;font-weight:700;margin-bottom:4px;">Pending handover<?= count($pending) === 1 ? '' : 's' ?>
                    <?php if ($pending): ?><span class="ho-pill amber" style="margin-left:8px;"><?= count($pending) ?> pending</span><?php endif; ?>
                </h2>
                <p style="font-size:12.5px;color:var(--text-muted);margin-bottom:14px;">Both confirmations are required — this is the audit gate.</p>

                <?php if (!$pending): ?>
                    <div class="empty">No handovers awaiting receipt. Reception hasn't closed a day yet, or everything is already received.</div>
                <?php endif; ?>

                <?php foreach ($pending as $p): ?>
                <form method="POST" action="admin_handovers.php" style="<?= $p !== $pending[0] ? 'margin-top:22px;padding-top:22px;border-top:1px solid var(--border);' : '' ?>">
                    <input type="hidden" name="action" value="mark_received">
                    <input type="hidden" name="closing_id" value="<?= (int) $p['id'] ?>">

                    <?php $pEdited = $p['status'] === 'EDITED'; ?>
                    <div class="pend"<?= $pEdited ? ' style="border-color:var(--amber);background:var(--amber-bg);"' : '' ?>>
                        <div>
                            <div class="who"><?= htmlspecialchars($p['cashier_name']) ?> — <?= date('D d/m/Y', strtotime($p['closing_date'])) ?>
                                <?php if ($pEdited): ?><span class="ho-pill amber" style="margin-left:6px;">EDITED ×<?= (int) $p['edit_count'] ?> — review changes</span><?php endif; ?>
                            </div>
                            <div class="det">
                                Slip <a class="slip-link" href="shift_closing.php?print=1&closing_id=<?= (int) $p['id'] ?>" target="_blank"><?= htmlspecialchars($p['closing_number']) ?></a>
                                · closed <?= date('H:i', strtotime($p['created_at'])) ?>
                                <?= $pEdited && $p['edited_at'] ? ' · last edit ' . date('H:i', strtotime($p['edited_at'])) : '' ?>
                                · variance <?= number_format((float) $p['variance'], 2) ?><?= $p['variance_note'] ? ' (note attached)' : '' ?>
                            </div>
                        </div>
                        <div>
                            <div class="amt">Rs <?= number_format((float) $p['handover_declared'], 0) ?></div>
                            <div class="det" style="text-align:right;">declared handover</div>
                        </div>
                    </div>

                    <?php if (!empty($editLogs[(int) $p['id']])): ?>
                    <div class="edit-log">
                        <div class="edit-log-title">What the cashier changed after closing</div>
                        <table class="htable">
                            <tr><th>Round</th><th>Field</th><th>Was</th><th>Now</th><th>When</th></tr>
                            <?php foreach ($editLogs[(int) $p['id']] as $e): ?>
                            <tr>
                                <td>#<?= (int) $e['edit_round'] ?></td>
                                <td><?= htmlspecialchars($FIELD_LABELS[$e['field_name']] ?? $e['field_name']) ?></td>
                                <td class="chg-old"><?= htmlspecialchars($e['old_value'] ?? '—') ?></td>
                                <td class="chg-new"><?= htmlspecialchars($e['new_value'] ?? '—') ?></td>
                                <td style="white-space:nowrap;"><?= date('H:i', strtotime($e['created_at'])) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </table>
                        <p class="edit-log-note">Marking received approves these changes and locks the closing permanently.</p>
                    </div>
                    <?php endif; ?>

                    <?php if ($p['variance_note']): ?>
                        <p style="font-size:12.5px;color:var(--text-secondary);margin-top:10px;"><b>Variance note:</b> <?= htmlspecialchars($p['variance_note']) ?></p>
                    <?php endif; ?>

                    <div style="margin-top:12px;">
                        <div class="check-line">
                            <input type="checkbox" id="cash_ok_<?= (int) $p['id'] ?>" name="cash_ok" value="1" required>
                            <label for="cash_ok_<?= (int) $p['id'] ?>"><b>Cash received &amp; recounted</b>
                                <div class="d">If your recount differs from the declared Rs <?= number_format((float) $p['handover_declared'], 0) ?>, correct the amount below — the difference logs as a handover discrepancy.</div>
                            </label>
                        </div>
                        <div class="check-line">
                            <input type="checkbox" id="slip_ok_<?= (int) $p['id'] ?>" name="slip_filed" value="1" required>
                            <label for="slip_ok_<?= (int) $p['id'] ?>"><b>Signed A5 slip collected &amp; filed</b>
                                <div class="d">Paper copy of <?= htmlspecialchars($p['closing_number']) ?> signed by both parties and placed in the audit file.</div>
                            </label>
                        </div>
                    </div>

                    <div class="hfield">
                        <label for="rcvd_<?= (int) $p['id'] ?>">Actual amount received (Rs) <span style="font-weight:400;color:var(--text-muted)">— edit only if different</span></label>
                        <input id="rcvd_<?= (int) $p['id'] ?>" name="handover_received" type="number" min="0" step="1"
                               value="<?= number_format((float) $p['handover_declared'], 0, '.', '') ?>">
                    </div>

                    <button class="rcv-btn" type="submit"
                            onclick="return confirm('<?= $pEdited ? 'Mark received AND approve the cashier\'s edits? The closing locks permanently.' : 'Mark this handover received? Your name and the time will be stamped on ' . htmlspecialchars($p['closing_number']) . '.' ?>');">
                        <?= $pEdited ? 'Approve changes &amp; mark received' : 'Mark received' ?>
                    </button>
                    <p class="hint-note">Stamps your name + time on <?= htmlspecialchars($p['closing_number']) ?><?= $pEdited ? ', approves the highlighted changes,' : '' ?> and locks the shift's audit trail.</p>
                </form>
                <?php endforeach; ?>
            </div>

        </div>

        <div class="ho-col">
            <div class="card">
                <h2 style="font-size:15px;font-weight:700;margin-bottom:4px;">Recently received</h2>
                <p style="font-size:12.5px;color:var(--text-muted);margin-bottom:14px;">Completed handovers, newest first. Click a slip number to reprint.</p>
                <div style="overflow-x:auto;">
                <table class="htable">
                    <tr><th>Date</th><th>Slip</th><th>Cashier</th><th class="num">Declared</th><th class="num">Received</th><th>Status</th></tr>
                    <?php if (!$history): ?>
                        <tr><td colspan="6" class="empty">Nothing received yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($history as $h): ?>
                    <?php $disc = round((float) $h['handover_received'] - (float) $h['handover_declared'], 2); ?>
                    <tr>
                        <td><?= date('D d/m', strtotime($h['closing_date'])) ?></td>
                        <td><a class="slip-link" href="shift_closing.php?print=1&closing_id=<?= (int) $h['id'] ?>" target="_blank"><?= htmlspecialchars($h['closing_number']) ?></a></td>
                        <td><?= htmlspecialchars($h['cashier_name']) ?></td>
                        <td class="num"><?= number_format((float) $h['handover_declared'], 0) ?></td>
                        <td class="num"><?= number_format((float) $h['handover_received'], 0) ?></td>
                        <td>
                            <?php if (abs($disc) > 0.009): ?>
                                <span class="ho-pill red"><?= $disc < 0 ? '− ' : '+ ' ?><?= number_format(abs($disc), 0) ?> discrepancy</span>
                            <?php else: ?>
                                <span class="ho-pill green">Received · filed</span>
                            <?php endif; ?>
                            <?php if ((int) ($h['edit_count'] ?? 0) > 0): ?>
                                <span class="ho-pill amber" title="Cashier edited before receipt; approved at mark-received">edited ×<?= (int) $h['edit_count'] ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
                </div>
            </div>
        </div>
    </div>

</div>
</div></div><!-- .main + .app -->
</body>
</html>
