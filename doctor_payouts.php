<?php
/**
 * Doctor Payouts — create, review and settle. (Accounts Phase 6, 2026-07-26)
 *
 * doctor_share_statement.php shows what a doctor is owed. This RECORDS what was
 * actually paid, line by line, frozen. Everything else in the app reads live
 * rows — right for a view, wrong for a record: a bill voided in August rewrites
 * a July already paid out against, and because consult_share_pct is read live,
 * moving a doctor 70% -> 75% retroactively claims every past month underpaid
 * them. A payout snapshots its figures INCLUDING the rates in force.
 *
 * FLOW: pick doctor + period -> Create draft -> review the frozen lines ->
 * Settle, which posts the Doctor Shares expense and links it.
 *
 * CLAWBACKS appear automatically: any source row paid on an earlier payout that
 * has since been voided or refunded comes back as a negative line at the
 * ORIGINALLY PAID figures. CARRY-FORWARD covers the case where those exceed the
 * period's earnings — no cash moves and the balance lands on the next payout.
 *
 * Gated on FINANCIAL_RUN_PAYOUT (admin-only by decision).
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
refresh_session_permissions($pdo);
require_once __DIR__ . '/config/billing.php';
require_once __DIR__ . '/config/payouts.php';
require_permission('FINANCIAL_RUN_PAYOUT');

$userId = (int) ($_SESSION['user_id'] ?? 0);
$error = $success = '';
$viewId = (int) ($_GET['view'] ?? 0);

// ---- Actions ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_payout') {
        $docId = (int) ($_POST['doctor_id'] ?? 0);
        $from  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['from'] ?? '') ? $_POST['from'] : '';
        $to    = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['to'] ?? '')   ? $_POST['to']   : '';
        if (!$docId || !$from || !$to) {
            $error = 'Pick a doctor and a period.';
        } else {
            [$id, $err] = create_payout($pdo, $docId, $from, $to, $userId);
            if ($err) { $error = $err; } else { $success = 'Draft payout created — review the lines, then settle.'; $viewId = $id; }
        }
    } elseif ($action === 'settle_payout') {
        [$ok, $msg] = settle_payout($pdo, (int) ($_POST['payout_id'] ?? 0), $userId, $_POST['source'] ?? 'BANK');
        if ($ok) { $success = $msg; } else { $error = $msg; }
        $viewId = (int) ($_POST['payout_id'] ?? 0);
    } elseif ($action === 'void_payout') {
        $pid = (int) ($_POST['payout_id'] ?? 0);
        $reason = trim($_POST['void_reason'] ?? '');
        if ($reason === '') {
            $error = 'Give a reason for voiding.';
        } else {
            try {
                // Only a DRAFT can be voided here. A settled payout has moved
                // cash and been reported on — reversing it is a clawback on the
                // next payout, not a delete.
                $upd = $pdo->prepare("UPDATE doctor_payouts SET status = 'voided', voided_at = NOW(), voided_by_id = ?, void_reason = ? WHERE id = ? AND status = 'draft'");
                $upd->execute([$userId, $reason, $pid]);
                if ($upd->rowCount() === 1) {
                    // Lines go with it, freeing their source rows for a future
                    // payout (the UNIQUE key would otherwise block them forever).
                    $pdo->prepare('DELETE FROM doctor_payout_lines WHERE payout_id = ?')->execute([$pid]);
                    $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
                        ->execute([$userId, 'payout_voided', "Voided draft payout #$pid — $reason"]);
                    $success = 'Draft payout discarded. Those bills are available for a new payout.';
                    $viewId = 0;
                } else {
                    $error = 'Only a draft payout can be discarded.';
                }
            } catch (PDOException $e) {
                error_log('[void_payout] ' . $e->getMessage());
                $error = 'Could not discard that payout.';
            }
        }
    }
}

// ---- Data ------------------------------------------------------------------
$doctors = [];
try {
    $doctors = $pdo->query("SELECT id, name, consult_share_pct, consult_has_tax, consult_tax_pct
                            FROM users WHERE base_role = 'DOCTOR' AND is_active = 1 ORDER BY name")->fetchAll();
} catch (PDOException $e) { /* leave empty */ }

$payouts = [];
$tablesReady = true;
try {
    $payouts = $pdo->query("
        SELECT p.*, u.name AS doctor_name
        FROM doctor_payouts p JOIN users u ON u.id = p.doctor_id
        ORDER BY p.created_at DESC LIMIT 60
    ")->fetchAll();
} catch (PDOException $e) { $tablesReady = false; }

$view = null; $viewLines = [];
if ($viewId > 0 && $tablesReady) {
    try {
        $q = $pdo->prepare('SELECT p.*, u.name AS doctor_name FROM doctor_payouts p JOIN users u ON u.id = p.doctor_id WHERE p.id = ?');
        $q->execute([$viewId]);
        $view = $q->fetch() ?: null;
        if ($view) {
            $l = $pdo->prepare('SELECT * FROM doctor_payout_lines WHERE payout_id = ? ORDER BY line_kind, occurred_on, id');
            $l->execute([$viewId]);
            $viewLines = $l->fetchAll();
        }
    } catch (PDOException $e) { /* leave null */ }
}

// Default period: the month just gone, the usual payout cycle.
$defFrom = date('Y-m-01', strtotime('-1 month'));
$defTo   = date('Y-m-t', strtotime('-1 month'));

$pageTitle = 'Doctor Payouts';
$headExtra = <<<CSS
<style>
.header { height: 72px; position: sticky; top: 0; z-index: 20; display: flex; align-items: center; justify-content: space-between; padding: 0 32px; background: rgba(255,255,255,.80); backdrop-filter: blur(18px); border-bottom: 1px solid var(--border); }
.header-right { display: flex; align-items: center; gap: 18px; margin-left: auto; }
.header-date { font-size: 13px; color: var(--text-secondary); white-space: nowrap; }
.logout-link { font-size: 13px; color: var(--text-secondary); font-weight: 500; }

.f-group { margin-bottom: 14px; }
.f-group label { display: block; font-size: 12.5px; font-weight: 600; margin-bottom: 6px; }
.f-group input, .f-group select { width: 100%; padding: 10px 12px; border: 1px solid var(--border); border-radius: 10px; font: inherit; font-size: 13.5px; background: #fff; }
.f-group input:focus, .f-group select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,127,126,.15); }
.f-row { display: flex; gap: 10px; }
.f-row .f-group { flex: 1; }

.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.neg { color: #b42318; }
.pos { color: #067647; }
tr.claw td { background: rgba(180,35,24,.045); }
tr.grand-row td { font-weight: 800; border-top: 2px solid var(--border); }

.pill { display: inline-flex; align-items: center; font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 20px; text-transform: uppercase; letter-spacing: .04em; }
.pill.draft  { background: rgba(234,88,12,.12); color: #9A3412; }
.pill.paid   { background: rgba(6,118,71,.12); color: #067647; }
.pill.voided { background: rgba(100,116,139,.15); color: #475569; }

.ladder { list-style: none; margin: 0; padding: 0; max-width: 560px; }
.ladder li { display: flex; align-items: baseline; justify-content: space-between; gap: 14px; padding: 8px 0; font-size: 13.5px; border-bottom: 1px solid var(--border); }
.ladder li.deduct .amt { color: #b42318; }
.ladder li.final { border-bottom: 0; border-top: 2px solid var(--border); font-weight: 800; font-size: 16px; padding-top: 12px; }
.ladder .amt { font-variant-numeric: tabular-nums; white-space: nowrap; }
.ladder .lbl small { display: block; font-size: 11.5px; color: var(--text-muted); font-weight: 400; }

.alert { border-radius: 10px; padding: 11px 14px; font-size: 13px; margin-bottom: 16px; }
.alert.ok  { background: rgba(6,118,71,.10); border: 1px solid rgba(6,118,71,.30); color: #067647; }
.alert.err { background: rgba(180,35,24,.10); border: 1px solid rgba(180,35,24,.30); color: #b42318; }
.note { border-left: 3px solid var(--primary); padding: 10px 14px; background: var(--primary-light, rgba(26,127,126,.08)); border-radius: 0 10px 10px 0; font-size: 12.5px; line-height: 1.55; margin-bottom: 14px; }
.warn-note { border-left: 3px solid #b54708; background: #fffaeb; padding: 10px 14px; border-radius: 0 10px 10px 0; font-size: 12.5px; line-height: 1.55; margin-bottom: 14px; }
.grid2 { display: grid; grid-template-columns: minmax(280px, 340px) 1fr; gap: 18px; align-items: start; }
@media (max-width: 900px) { .grid2 { grid-template-columns: 1fr; } }

@media print {
    .sidebar, .mobile-bar, .header, .nav-group, .appbar, .no-print { display: none !important; }
    .main { margin: 0 !important; }
    .card { box-shadow: none !important; border: 1px solid #ccc; break-inside: avoid; }
}
</style>
CSS;
require __DIR__ . '/partials/head.php';
$navActive = 'doctor_payouts';
require __DIR__ . '/partials/sidebar.php';

$money = function ($n) { return 'Rs ' . number_format($n); };
?>
        <header class="header">
            <div class="page-title" style="font-size:16px;">Doctor Payouts</div>
            <div class="header-right">
                <span class="header-date"><?= date('D, d/m/Y') ?></span>
                <a class="logout-link" href="logout.php">Logout</a>
            </div>
        </header>

        <div class="content">
            <div class="page-head">
                <div>
                    <div class="page-title">Doctor Payouts</div>
                    <div class="page-sub">Frozen records of what was actually paid, line by line</div>
                </div>
            </div>

            <?php if ($success): ?><div class="alert ok"><?= htmlspecialchars($success) ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

            <?php if (!$tablesReady): ?>
            <div class="warn-note">
                <strong>Not set up yet.</strong> Run <code>sql/add_doctor_payouts.sql</code> in phpMyAdmin
                to create the payout tables, then reload this page.
            </div>
            <?php endif; ?>

            <div class="grid2">
                <!-- Create -->
                <div class="card no-print">
                    <div class="section-title">New Payout</div>
                    <div class="section-sub">Earnings for the period, plus any adjustments carried over.</div>
                    <form method="POST" action="doctor_payouts.php">
                        <input type="hidden" name="action" value="create_payout">
                        <div class="f-group">
                            <label>Doctor</label>
                            <select name="doctor_id" required>
                                <option value="">Select a doctor&hellip;</option>
                                <?php foreach ($doctors as $d): ?>
                                <option value="<?= (int) $d['id'] ?>">
                                    <?= htmlspecialchars($d['name']) ?>
                                    — <?= number_format((float) $d['consult_share_pct'], 0) ?>%<?= (int) $d['consult_has_tax'] === 1 ? ', tax ' . number_format((float) $d['consult_tax_pct'], 0) . '%' : ', no tax' ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="f-row">
                            <div class="f-group">
                                <label>From</label>
                                <input type="date" name="from" value="<?= htmlspecialchars($defFrom) ?>" required>
                            </div>
                            <div class="f-group">
                                <label>To</label>
                                <input type="date" name="to" value="<?= htmlspecialchars($defTo) ?>" required>
                            </div>
                        </div>
                        <button type="submit" class="btn" style="width:100%;">Create draft</button>
                        <div class="muted-note" style="margin-top:8px;">
                            Bills already on another payout are skipped, so overlapping periods
                            cannot pay for the same work twice.
                        </div>
                    </form>
                </div>

                <!-- View / list -->
                <div>
                <?php if ($view): ?>
                    <div class="card">
                        <div class="section-title">
                            <?= htmlspecialchars($view['payout_number']) ?>
                            <span class="pill <?= htmlspecialchars($view['status']) ?>"><?= htmlspecialchars($view['status']) ?></span>
                        </div>
                        <div class="section-sub">
                            <?= htmlspecialchars($view['doctor_name']) ?> ·
                            <?= date('d/m/Y', strtotime($view['period_start'])) ?> – <?= date('d/m/Y', strtotime($view['period_end'])) ?>
                            · split <?= number_format((float) $view['share_pct'], 0) ?>%<?= (int) $view['has_tax'] === 1 ? ', tax ' . number_format((float) $view['tax_pct'], 0) . '%' : ', no tax' ?>
                            <small>(rates frozen when this payout was created)</small>
                        </div>

                        <ul class="ladder">
                            <li><span class="lbl">Gross fees generated</span><span class="amt"><?= $money($view['gross_amount']) ?></span></li>
                            <li class="deduct"><span class="lbl">Less tax withheld<small>deposited by the clinic</small></span><span class="amt">−<?= $money($view['tax_withheld']) ?></span></li>
                            <li><span class="lbl">Earned this period</span><span class="amt"><?= $money($view['earned_amount']) ?></span></li>
                            <?php if ((float) $view['clawback_amount'] > 0): ?>
                            <li class="deduct"><span class="lbl">Less adjustments<small>bills voided or refunded after an earlier payout</small></span><span class="amt">−<?= $money($view['clawback_amount']) ?></span></li>
                            <?php endif; ?>
                            <?php if ((float) $view['carried_in'] > 0): ?>
                            <li class="deduct"><span class="lbl">Less balance brought forward<small>unrecovered from the last payout</small></span><span class="amt">−<?= $money($view['carried_in']) ?></span></li>
                            <?php endif; ?>
                            <li class="final"><span class="lbl">Net payable</span><span class="amt"><?= $money($view['net_paid']) ?></span></li>
                        </ul>

                        <?php if ((float) $view['carried_out'] > 0): ?>
                        <div class="warn-note" style="margin-top:14px;">
                            <strong><?= $money($view['carried_out']) ?> carried forward.</strong>
                            Adjustments exceeded what was earned this period, so no cash moves and the
                            balance is deducted from this doctor's next payout. Nobody is asked to hand
                            money back.
                        </div>
                        <?php endif; ?>

                        <?php if ($view['status'] === 'draft'): ?>
                        <div class="no-print" style="display:flex;gap:10px;flex-wrap:wrap;margin-top:16px;">
                            <form method="POST" action="doctor_payouts.php" style="margin:0;display:flex;gap:10px;align-items:center;"
                                  onsubmit="return confirm('Settle <?= htmlspecialchars($view['payout_number']) ?>? This records the payment and freezes it.');">
                                <input type="hidden" name="action" value="settle_payout">
                                <input type="hidden" name="payout_id" value="<?= (int) $view['id'] ?>">
                                <?php if ((float) $view['net_paid'] > 0): ?>
                                <select name="source" style="padding:9px 12px;border:1px solid var(--border);border-radius:10px;font:inherit;font-size:13px;">
                                    <option value="BANK">Bank transfer</option>
                                    <option value="CASH_COUNTER">Counter cash</option>
                                    <option value="OWNER">Owner / outside the counter</option>
                                </select>
                                <?php endif; ?>
                                <button type="submit" class="btn">Settle payout</button>
                            </form>
                            <form method="POST" action="doctor_payouts.php" style="margin:0;"
                                  onsubmit="var r=prompt('Why discard this draft?');if(!r){return false;}this.void_reason.value=r;return true;">
                                <input type="hidden" name="action" value="void_payout">
                                <input type="hidden" name="payout_id" value="<?= (int) $view['id'] ?>">
                                <input type="hidden" name="void_reason" value="">
                                <button type="submit" class="btn secondary">Discard draft</button>
                            </form>
                            <button type="button" class="btn secondary" onclick="window.print()">Print</button>
                        </div>
                        <?php else: ?>
                        <div class="no-print" style="margin-top:16px;">
                            <button type="button" class="btn secondary" onclick="window.print()">Print</button>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="card">
                        <div class="section-title">Lines</div>
                        <div class="section-sub">
                            Every bill and ward round this payout covers, frozen at the figures paid.
                            Adjustments are reversed at their ORIGINAL amounts, not recomputed — a later
                            rate change never alters what was already paid.
                        </div>
                        <div style="overflow-x:auto;">
                        <table>
                            <thead>
                                <tr><th>Date</th><th>Source</th><th>Patient</th><th class="num">Gross</th><th class="num">Tax</th><th class="num">Doctor</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($viewLines as $l): $claw = $l['line_kind'] === 'CLAWBACK'; ?>
                                <tr class="<?= $claw ? 'claw' : '' ?>">
                                    <td><?= date('d/m/Y', strtotime($l['occurred_on'])) ?></td>
                                    <td>
                                        <?= htmlspecialchars($l['source_type']) ?>
                                        <?php if ($claw): ?><br><small class="muted"><?= htmlspecialchars($l['clawback_reason']) ?></small><?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($l['patient_name'] ?? '—') ?></td>
                                    <td class="num <?= $claw ? 'neg' : '' ?>"><?= $money($l['gross']) ?></td>
                                    <td class="num <?= $claw ? 'neg' : '' ?>"><?= $money($l['tax']) ?></td>
                                    <td class="num <?= $claw ? 'neg' : '' ?>"><?= $money($l['doctor_share']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (!$viewLines): ?>
                                <tr><td colspan="6" class="muted" style="padding:18px 10px;">No lines — this payout is a carried balance only.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        </div>
                    </div>
                <?php endif; ?>

                    <div class="card no-print">
                        <div class="section-title">Recent Payouts</div>
                        <div class="section-sub">Newest first. Click one to see its lines.</div>
                        <div style="overflow-x:auto;">
                        <table>
                            <thead>
                                <tr><th>Number</th><th>Doctor</th><th>Period</th><th class="num">Net paid</th><th>Status</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payouts as $p): ?>
                                <tr>
                                    <td><a href="doctor_payouts.php?view=<?= (int) $p['id'] ?>"><?= htmlspecialchars($p['payout_number']) ?></a></td>
                                    <td><?= htmlspecialchars($p['doctor_name']) ?></td>
                                    <td><?= date('d/m/Y', strtotime($p['period_start'])) ?> – <?= date('d/m/Y', strtotime($p['period_end'])) ?></td>
                                    <td class="num"><?= $money($p['net_paid']) ?><?= (float) $p['carried_out'] > 0 ? '<br><small class="muted neg">+' . $money($p['carried_out']) . ' carried</small>' : '' ?></td>
                                    <td><span class="pill <?= htmlspecialchars($p['status']) ?>"><?= htmlspecialchars($p['status']) ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (!$payouts): ?>
                                <tr><td colspan="5" class="muted" style="padding:18px 10px;">No payouts yet.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
