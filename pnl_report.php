<?php
/**
 * Profit & Loss — the whole picture. (Accounts Phase 5, 2026-07-26)
 *
 * Joins what already exists rather than computing anything new: clinic_revenue()
 * and clinic_doctor_shares() for the income half, clinic_operating_expenses()
 * for the cost half — all in config/billing.php, so this page can never
 * disagree with the income or expense reports.
 *
 *   Gross received
 *   − refunds − tax withheld − doctor shares  =  CLINIC INCOME
 *   − salaries − rent − utilities …           =  NET PROFIT
 *
 * THE INVARIANT
 *   Doctor Shares postings NEVER appear as an operating cost. The earned share
 *   is already deducted above the line; counting the paid row again would
 *   understate profit by exactly the doctor share. clinic_operating_expenses()
 *   enforces this via is_disbursement, not by matching a category name.
 *
 * OPEN vs CLOSED
 *   An open month computes live. A CLOSED month renders from the stored
 *   snapshot in monthly_closings and is never recomputed — otherwise a bill
 *   voided later silently rewrites a month already reported and paid out
 *   against. A badge always says which you are looking at; ambiguity here
 *   defeats the point of closing at all.
 *
 * Gated on FINANCIAL_VIEW_DAILY_PL — the last of the four dead financial keys
 * to finally get a page. Closing needs FINANCIAL_CLOSE_MONTH on top.
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
refresh_session_permissions($pdo);
require_once __DIR__ . '/config/billing.php';
require_permission('FINANCIAL_VIEW_DAILY_PL');

$canClose = has_permission('FINANCIAL_CLOSE_MONTH');
$userId   = (int) ($_SESSION['user_id'] ?? 0);
$error = $success = '';

$month      = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m');
$monthStart = $month . '-01';
$monthEnd   = date('Y-m-t', strtotime($monthStart));
$monthLabel = date('F Y', strtotime($monthStart));
$isCurrent  = $month === date('Y-m');

// ---------------------------------------------------------------------------
// Close / reopen. Both audit-logged; reopening demands a reason.
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canClose) {
    $action = $_POST['action'] ?? '';
    $target = preg_match('/^\d{4}-\d{2}$/', $_POST['month'] ?? '') ? $_POST['month'] : '';

    if ($target === '') {
        $error = 'Invalid month.';
    } elseif ($action === 'close_month') {
        // A month still in progress cannot be closed: more money will arrive.
        if ($target >= date('Y-m')) {
            $error = 'That month is not over yet.';
        } elseif (month_closing($pdo, $target)) {
            $error = 'That month is already closed.';
        } else {
            $s = $target . '-01';
            $e = date('Y-m-t', strtotime($s));
            $rev = clinic_revenue($pdo, $s, $e);
            $shr = clinic_doctor_shares($pdo, $s, $e);
            $exp = clinic_operating_expenses($pdo, $s, $e);
            $income = max(0.0, $rev['net'] - $shr['doctor'] - $shr['tax']);
            $profit = $income - $exp['operating'];

            // Everything needed to re-render the month without a live query.
            $detail = json_encode([
                'streams'    => $rev['streams'],
                'categories' => $exp['rows'],
                'doctors'    => $shr['rows'],
                'cash'       => $rev['cash'],
                'online'     => $rev['online'],
                'bills'      => $rev['bills'],
                'disbursed'  => $exp['disbursed'],
            ]);

            try {
                $pdo->prepare('
                    INSERT INTO monthly_closings
                        (period_month, gross_revenue, refunds, tax_withheld, doctor_shares,
                         clinic_income, operating_costs, net_profit, detail_json, closed_by_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ')->execute([
                    $s, $rev['gross'], $rev['refunds'], $shr['tax'], $shr['doctor'],
                    $income, $exp['operating'], $profit, $detail, $userId,
                ]);
                audit_log($pdo, 'month_closed', 'Closed the books for ' . date('F Y', strtotime($s)) . ' — net profit Rs ' . number_format($profit, 2), $userId);
                $success = date('F Y', strtotime($s)) . ' is closed. Its figures are now frozen.';
                $month = $target; $monthStart = $s; $monthEnd = $e;
                $monthLabel = date('F Y', strtotime($s));
            } catch (PDOException $ex) {
                error_log('[pnl close] ' . $ex->getMessage());
                $error = 'Could not close the month — has sql/add_monthly_closings.sql been run?';
            }
        }
    } elseif ($action === 'reopen_month') {
        $reason = trim($_POST['reopen_reason'] ?? '');
        if ($reason === '') {
            $error = 'Give a reason for reopening the month.';
        } else {
            try {
                $upd = $pdo->prepare('
                    UPDATE monthly_closings
                       SET reopened_at = NOW(), reopened_by_id = ?, reopen_reason = ?
                     WHERE period_month = ? AND reopened_at IS NULL
                ');
                $upd->execute([$userId, $reason, $target . '-01']);
                if ($upd->rowCount() === 1) {
                    audit_log($pdo, 'month_reopened', 'Reopened ' . date('F Y', strtotime($target . '-01')) . ' — ' . $reason, $userId);
                    $success = date('F Y', strtotime($target . '-01')) . ' is open again. Its figures are live once more.';
                } else {
                    $error = 'That month was not closed.';
                }
            } catch (PDOException $ex) {
                error_log('[pnl reopen] ' . $ex->getMessage());
                $error = 'Could not reopen the month.';
            }
        }
    }
}

// ---------------------------------------------------------------------------
// Figures: frozen if the month is closed, live if not.
// ---------------------------------------------------------------------------
$closing = month_closing($pdo, $month);
$frozen  = (bool) $closing;

if ($frozen) {
    $detail = json_decode($closing['detail_json'] ?? '', true) ?: [];
    $gross    = (float) $closing['gross_revenue'];
    $refunds  = (float) $closing['refunds'];
    $tax      = (float) $closing['tax_withheld'];
    $docShare = (float) $closing['doctor_shares'];
    $income   = (float) $closing['clinic_income'];
    $opCosts  = (float) $closing['operating_costs'];
    $profit   = (float) $closing['net_profit'];
    $streams  = $detail['streams']    ?? [];
    $catRows  = $detail['categories'] ?? [];
    $docRows  = $detail['doctors']    ?? [];
    $billCnt  = (int) ($detail['bills'] ?? 0);
    $disbursed = (float) ($detail['disbursed'] ?? 0);
} else {
    $rev = clinic_revenue($pdo, $monthStart, $monthEnd);
    $shr = clinic_doctor_shares($pdo, $monthStart, $monthEnd);
    $exp = clinic_operating_expenses($pdo, $monthStart, $monthEnd);
    $gross    = $rev['gross'];
    $refunds  = $rev['refunds'];
    $tax      = $shr['tax'];
    $docShare = $shr['doctor'];
    $income   = max(0.0, $rev['net'] - $docShare - $tax);
    $opCosts  = $exp['operating'];
    $profit   = $income - $opCosts;
    $streams  = $rev['streams'];
    $catRows  = $exp['rows'];
    $docRows  = $shr['rows'];
    $billCnt  = $rev['bills'];
    $disbursed = $exp['disbursed'];
}

$margin = $gross > 0 ? $profit / $gross * 100 : 0.0;

// ---- CSV -------------------------------------------------------------------
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="pnl-' . $month . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Profit & Loss', $monthLabel, $frozen ? 'CLOSED (frozen)' : 'OPEN (live)']);
    fputcsv($out, []);
    fputcsv($out, ['Gross received', number_format($gross, 2, '.', '')]);
    fputcsv($out, ['Less refunds', number_format(-$refunds, 2, '.', '')]);
    fputcsv($out, ['Less tax withheld', number_format(-$tax, 2, '.', '')]);
    fputcsv($out, ['Less doctor shares', number_format(-$docShare, 2, '.', '')]);
    fputcsv($out, ['CLINIC INCOME', number_format($income, 2, '.', '')]);
    fputcsv($out, []);
    fputcsv($out, ['Operating expenses', '']);
    foreach ($catRows as $c) {
        fputcsv($out, [$c['name'], number_format(-(float) $c['amt'], 2, '.', '')]);
    }
    fputcsv($out, ['Total operating', number_format(-$opCosts, 2, '.', '')]);
    fputcsv($out, []);
    fputcsv($out, ['NET PROFIT', number_format($profit, 2, '.', '')]);
    fputcsv($out, ['Margin on gross %', number_format($margin, 1)]);
    fclose($out);
    exit;
}

$pageTitle = 'Profit & Loss';
$headExtra = <<<CSS
<style>
/* The page header styles are gone with the header itself — the shared
   app bar brings its own from assets/app.css. */

.month-form { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.month-form input[type=month] { padding: 9px 12px; border: 1px solid var(--border); border-radius: 10px; font: inherit; font-size: 13.5px; background: #fff; }
.month-form input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,127,126,.15); }

.kpi-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 14px; margin-bottom: 18px; }
.kpi { border: 1px solid var(--border); border-radius: 14px; padding: 16px 18px; background: var(--card); box-shadow: var(--shadow-sm); }
.kpi .k { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted); }
.kpi .v { font-size: 22px; font-weight: 800; margin-top: 4px; font-variant-numeric: tabular-nums; }
.kpi .sub { font-size: 11.5px; color: var(--text-muted); margin-top: 3px; }
.kpi.profit .v { color: #067647; }
.kpi.loss .v { color: #b42318; }

.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }

/* The P&L itself: one ladder, indented sub-lines, two rules that matter. */
.pnl { list-style: none; margin: 0; padding: 0; max-width: 620px; }
.pnl li { display: flex; align-items: baseline; justify-content: space-between; gap: 14px; padding: 8px 0; font-size: 13.5px; border-bottom: 1px solid var(--border); }
.pnl li.sub { padding-left: 18px; font-size: 12.5px; color: var(--text-secondary); border-bottom: 1px dotted var(--border); }
.pnl li.deduct .amt { color: #b42318; }
.pnl li.rule { border-bottom: 0; border-top: 2px solid var(--border); font-weight: 800; font-size: 15px; padding-top: 11px; margin-top: 2px; }
.pnl li.final { border-bottom: 0; border-top: 3px double var(--text-primary, #16211C); font-weight: 800; font-size: 17px; padding-top: 13px; margin-top: 4px; }
.pnl li.final.pos .amt { color: #067647; }
.pnl li.final.neg .amt { color: #b42318; }
.pnl .amt { font-variant-numeric: tabular-nums; white-space: nowrap; }
.pnl .lbl small { display: block; font-size: 11.5px; color: var(--text-muted); font-weight: 400; margin-top: 1px; }

.badge { display: inline-flex; align-items: center; gap: 6px; font-size: 11.5px; font-weight: 700; padding: 4px 11px; border-radius: 20px; text-transform: uppercase; letter-spacing: .04em; }
.badge.open   { background: rgba(234,88,12,.12); color: #9A3412; }
.badge.closed { background: rgba(6,118,71,.12); color: #067647; }

.close-bar { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;
             border: 1px solid var(--border); border-radius: 12px; padding: 14px 16px; background: var(--card); margin-bottom: 18px; }
.close-bar .txt { font-size: 12.5px; line-height: 1.55; flex: 1 1 320px; }
.alert { border-radius: 10px; padding: 11px 14px; font-size: 13px; margin-bottom: 16px; }
.alert.ok  { background: rgba(6,118,71,.10); border: 1px solid rgba(6,118,71,.30); color: #067647; }
.alert.err { background: rgba(180,35,24,.10); border: 1px solid rgba(180,35,24,.30); color: #b42318; }

@media print {
    .sidebar, .mobile-bar, .header, .month-form, .print-btn, .nav-group, .appbar, .close-bar { display: none !important; }
    .main { margin: 0 !important; }
    .card { box-shadow: none !important; border: 1px solid #ccc; break-inside: avoid; }
    .badge { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>
CSS;
require __DIR__ . '/partials/head.php';
$navActive = 'pnl_report';
require __DIR__ . '/partials/sidebar.php';

$money = function ($n) { return 'Rs ' . number_format($n); };
?>
        <?php /* The page's own mini-header (title + date + Logout) is gone: the
                 shared app bar above carries date and Logout on every page,
                 and the title is repeated in .page-head just below. */ ?>

        <div class="content">
            <div class="page-head">
                <div>
                    <div class="page-title">
                        Profit &amp; Loss — <?= htmlspecialchars($monthLabel) ?>
                        <span class="badge <?= $frozen ? 'closed' : 'open' ?>"><?= $frozen ? 'Closed' : 'Open' ?></span>
                    </div>
                    <div class="page-sub">
                        <?= $frozen
                            ? 'Frozen figures, stored when the month was closed — later voids and refunds cannot change them'
                            : 'Live figures, recomputed on every load' ?>
                    </div>
                </div>
                <form class="month-form" method="GET" action="pnl_report.php">
                    <input type="month" name="month" value="<?= htmlspecialchars($month) ?>" max="<?= date('Y-m') ?>">
                    <button type="submit" class="btn secondary">View</button>
                    <a class="btn secondary" href="pnl_report.php?month=<?= urlencode($month) ?>&amp;export=csv">CSV</a>
                    <button type="button" class="btn print-btn" onclick="window.print()">Print</button>
                </form>
            </div>

            <?php if ($success): ?><div class="alert ok"><?= htmlspecialchars($success) ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

            <?php if ($canClose): ?>
            <div class="close-bar">
                <div class="txt">
                    <?php if ($frozen): ?>
                        <strong>Closed <?= date('d/m/Y', strtotime($closing['closed_at'])) ?>.</strong>
                        These figures are stored, not recomputed, and back-dated postings into
                        <?= htmlspecialchars($monthLabel) ?> are blocked. Reopening makes them live again.
                    <?php elseif ($isCurrent): ?>
                        <strong><?= htmlspecialchars($monthLabel) ?> is still in progress.</strong>
                        A month can only be closed once it is over.
                    <?php else: ?>
                        <strong>These figures are live.</strong> A void or refund processed later will
                        change them. Closing the month freezes them and blocks back-dated postings.
                    <?php endif; ?>
                </div>
                <?php if ($frozen): ?>
                <form method="POST" action="pnl_report.php?month=<?= urlencode($month) ?>" style="margin:0;"
                      onsubmit="var r=prompt('Why reopen <?= htmlspecialchars($monthLabel) ?>? This unfreezes its figures.');if(!r){return false;}this.reopen_reason.value=r;return true;">
                    <input type="hidden" name="action" value="reopen_month">
                    <input type="hidden" name="month" value="<?= htmlspecialchars($month) ?>">
                    <input type="hidden" name="reopen_reason" value="">
                    <button type="submit" class="btn secondary">Reopen month</button>
                </form>
                <?php elseif (!$isCurrent): ?>
                <form method="POST" action="pnl_report.php?month=<?= urlencode($month) ?>" style="margin:0;"
                      onsubmit="return confirm('Close <?= htmlspecialchars($monthLabel) ?>? Its figures will be frozen and back-dated postings blocked.');">
                    <input type="hidden" name="action" value="close_month">
                    <input type="hidden" name="month" value="<?= htmlspecialchars($month) ?>">
                    <button type="submit" class="btn">Close month</button>
                </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="kpi-row">
                <div class="kpi <?= $profit >= 0 ? 'profit' : 'loss' ?>">
                    <div class="k">Net profit</div>
                    <div class="v"><?= $money($profit) ?></div>
                    <div class="sub"><?= number_format($margin, 1) ?>% of gross</div>
                </div>
                <div class="kpi">
                    <div class="k">Clinic income</div>
                    <div class="v"><?= $money($income) ?></div>
                    <div class="sub">after doctors &amp; tax</div>
                </div>
                <div class="kpi">
                    <div class="k">Gross received</div>
                    <div class="v"><?= $money($gross) ?></div>
                    <div class="sub"><?= number_format($billCnt) ?> bills</div>
                </div>
                <div class="kpi">
                    <div class="k">Operating costs</div>
                    <div class="v"><?= $money($opCosts) ?></div>
                    <div class="sub">excludes doctor payouts</div>
                </div>
            </div>

            <div class="card">
                <div class="section-title">Profit &amp; Loss</div>
                <div class="section-sub">
                    Cash basis — money received and cash spent, by the month each belongs to.
                    Doctor payouts are deducted once, as earned share above the line, never again as a cost.
                </div>
                <ul class="pnl">
                    <li>
                        <span class="lbl">Gross received<small><?= number_format($billCnt) ?> paid bills, all streams</small></span>
                        <span class="amt"><?= $money($gross) ?></span>
                    </li>
                    <?php foreach ($streams as $s): if (($s['gross'] ?? 0) <= 0) continue; ?>
                    <li class="sub">
                        <span class="lbl"><?= htmlspecialchars($s['label']) ?></span>
                        <span class="amt"><?= $money($s['gross']) ?></span>
                    </li>
                    <?php endforeach; ?>

                    <li class="deduct"><span class="lbl">Less refunds</span><span class="amt">−<?= $money($refunds) ?></span></li>
                    <li class="deduct">
                        <span class="lbl">Less tax withheld<small>deposited on the doctors' behalf</small></span>
                        <span class="amt">−<?= $money($tax) ?></span>
                    </li>
                    <li class="deduct">
                        <span class="lbl">Less doctor shares<small>earned this month, paid out separately</small></span>
                        <span class="amt">−<?= $money($docShare) ?></span>
                    </li>
                    <li class="rule"><span class="lbl">Clinic income</span><span class="amt"><?= $money($income) ?></span></li>

                    <?php foreach ($catRows as $c): ?>
                    <li class="sub deduct">
                        <span class="lbl"><?= htmlspecialchars($c['name']) ?></span>
                        <span class="amt">−<?= $money($c['amt']) ?></span>
                    </li>
                    <?php endforeach; ?>
                    <?php if (!$catRows): ?>
                    <li class="sub"><span class="lbl muted">No operating expenses recorded for this month.</span><span class="amt">—</span></li>
                    <?php endif; ?>
                    <li class="rule deduct"><span class="lbl">Total operating costs</span><span class="amt">−<?= $money($opCosts) ?></span></li>

                    <li class="final <?= $profit >= 0 ? 'pos' : 'neg' ?>">
                        <span class="lbl">Net <?= $profit >= 0 ? 'profit' : 'loss' ?></span>
                        <span class="amt"><?= $money(abs($profit)) ?></span>
                    </li>
                </ul>
            </div>

            <?php if ($disbursed > 0): ?>
            <div class="card">
                <div class="section-title">Doctor Payouts This Month</div>
                <div class="section-sub">
                    <?= $money($disbursed) ?> was actually disbursed to doctors in <?= htmlspecialchars($monthLabel) ?>,
                    against <?= $money($docShare) ?> earned. Shown for reconciliation only — the earned figure is
                    already deducted above, so counting these payments again would understate profit by exactly
                    the doctor share.
                </div>
            </div>
            <?php endif; ?>

            <?php if ($docRows): ?>
            <div class="card">
                <div class="section-title">Earned by Doctor</div>
                <div class="section-sub">The share deducted above, per doctor. Tax withheld is what the clinic deposits.</div>
                <div style="overflow-x:auto;">
                <table>
                    <thead><tr><th>Doctor</th><th class="num">Earned</th><th class="num">Tax withheld</th></tr></thead>
                    <tbody>
                        <?php foreach ($docRows as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars($r['name']) ?></td>
                            <td class="num"><?= $money($r['doctor']) ?></td>
                            <td class="num"><?= ($r['tax'] ?? 0) > 0 ? $money($r['tax']) : '<span class="muted">self-deposits</span>' ?></td>
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
