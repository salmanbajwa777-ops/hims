<?php
/**
 * Expense Report — monthly category analysis. (Accounts Phase 2, 2026-07-26)
 *
 * Answers the questions the expenses LIST cannot: what does a category cost per
 * month, is this month unusual, and where did the money actually come from.
 *
 * THE PERIOD RULE
 *   Every figure groups on COALESCE(period_month, expense_date) — the month an
 *   expense BELONGS to, not the day cash moved. July's salary paid on 5 August
 *   counts in JULY. Categories flagged is_period_based (Salaries, Doctor Shares)
 *   carry period_month; everything else — rent included — keys off expense_date.
 *
 * DISBURSEMENTS ARE NOT OPERATING EXPENSES
 *   Doctor Shares is flagged is_disbursement: the money is a pass-through to the
 *   doctor, and the P&L already deducts the EARNED share from revenue before
 *   arriving at clinic income. Counting the posted row as an operating cost too
 *   would understate profit by exactly the doctor share. It is therefore shown
 *   in its own block, never inside the operating total.
 *
 * Voided rows and REJECTED postings are excluded everywhere.
 * Gated on FINANCIAL_VIEW_CLINIC_REPORTS — the first page ever to use that key
 * (it has been seeded and granted since the RBAC overhaul, checked by nothing).
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
refresh_session_permissions($pdo);
require_permission('FINANCIAL_VIEW_CLINIC_REPORTS');

$month      = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m');
$monthStart = $month . '-01';
$monthEnd   = date('Y-m-t', strtotime($monthStart));
$monthLabel = date('F Y', strtotime($monthStart));
// Previous month, for the month-over-month column.
$prevStart  = date('Y-m-01', strtotime($monthStart . ' -1 month'));
$prevEnd    = date('Y-m-t', strtotime($prevStart));
$prevLabel  = date('M Y', strtotime($prevStart));

// ---------------------------------------------------------------------------
// Schema probe. add_accounts_phase1.sql may not have been run yet (code deploys
// ahead of SQL on this project), so every new column is used only if it exists.
// Without it the page still renders — it just falls back to expense_date and
// shows no source split, rather than 500-ing on an unknown column.
// ---------------------------------------------------------------------------
$hasPhase1 = false;
try {
    $hasPhase1 = (int) $pdo->query("
        SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND (   (table_name = 'expenses'            AND column_name = 'period_month')
               OR (table_name = 'expense_categories'  AND column_name = 'is_disbursement'))
    ")->fetchColumn() === 2;
} catch (Throwable $e) { /* leave false */ }

// The accounting-period expression, and the disbursement flag, both degrade.
$periodExpr = $hasPhase1 ? 'COALESCE(e.period_month, e.expense_date)' : 'e.expense_date';
$disbExpr   = $hasPhase1 ? 'ec.is_disbursement' : '0';
$srcExpr    = $hasPhase1 ? 'e.source' : "'CASH_COUNTER'";

// Live rows only: never voided, never rejected.
$alive = "e.voided_at IS NULL AND e.approval_status <> 'REJECTED'";

/** One month's spend per category. Returns [cat_id => row]. */
$monthByCategory = function (string $start, string $end) use ($pdo, $periodExpr, $disbExpr, $alive) {
    $q = $pdo->prepare("
        SELECT ec.id, ec.name, $disbExpr AS is_disb,
               COUNT(*) AS n, COALESCE(SUM(e.amount), 0) AS amt
        FROM expenses e
        JOIN expense_categories ec ON ec.id = e.category_id
        WHERE $periodExpr BETWEEN ? AND ? AND $alive
        GROUP BY ec.id, ec.name, is_disb
        ORDER BY amt DESC
    ");
    $q->execute([$start, $end]);
    $out = [];
    foreach ($q->fetchAll() as $r) { $out[(int) $r['id']] = $r; }
    return $out;
};

try {
    $cur  = $monthByCategory($monthStart, $monthEnd);
    $prev = $monthByCategory($prevStart, $prevEnd);
} catch (PDOException $e) {
    // Expense module itself missing — show an empty report rather than a fatal.
    error_log('[expense_report] ' . $e->getMessage());
    $cur = $prev = [];
}

// Totals, keeping disbursements strictly out of the operating figure.
$opTotal = $disbTotal = 0.0;
$opCount = 0;
foreach ($cur as $r) {
    if ((int) $r['is_disb'] === 1) { $disbTotal += (float) $r['amt']; }
    else { $opTotal += (float) $r['amt']; $opCount += (int) $r['n']; }
}
$prevOpTotal = 0.0;
foreach ($prev as $r) {
    if ((int) $r['is_disb'] !== 1) { $prevOpTotal += (float) $r['amt']; }
}
$momDelta = $opTotal - $prevOpTotal;
$momPct   = $prevOpTotal > 0 ? ($momDelta / $prevOpTotal) * 100 : null;

// ---- Where the money came from (counter vs bank vs owner) ----
$bySource = [];
try {
    $q = $pdo->prepare("
        SELECT $srcExpr AS src, COUNT(*) AS n, COALESCE(SUM(e.amount), 0) AS amt
        FROM expenses e
        JOIN expense_categories ec ON ec.id = e.category_id
        WHERE $periodExpr BETWEEN ? AND ? AND $alive
        GROUP BY src ORDER BY amt DESC
    ");
    $q->execute([$monthStart, $monthEnd]);
    $bySource = $q->fetchAll();
} catch (PDOException $e) { /* pre-migration */ }

// ---- Top payees ----
$topPayees = [];
try {
    $q = $pdo->prepare("
        SELECT e.paid_to, COUNT(*) AS n, COALESCE(SUM(e.amount), 0) AS amt
        FROM expenses e
        JOIN expense_categories ec ON ec.id = e.category_id
        WHERE $periodExpr BETWEEN ? AND ? AND $alive
          AND e.paid_to IS NOT NULL AND e.paid_to <> ''
        GROUP BY e.paid_to ORDER BY amt DESC LIMIT 10
    ");
    $q->execute([$monthStart, $monthEnd]);
    $topPayees = $q->fetchAll();
} catch (PDOException $e) { /* pre-migration */ }

// ---- 6-month trend (operating only) ----
$trend = [];
try {
    $trendStart = date('Y-m-01', strtotime($monthStart . ' -5 months'));
    $q = $pdo->prepare("
        SELECT DATE_FORMAT($periodExpr, '%Y-%m') AS ym,
               COALESCE(SUM(CASE WHEN $disbExpr = 1 THEN 0 ELSE e.amount END), 0) AS amt
        FROM expenses e
        JOIN expense_categories ec ON ec.id = e.category_id
        WHERE $periodExpr BETWEEN ? AND ? AND $alive
        GROUP BY ym ORDER BY ym
    ");
    $q->execute([$trendStart, $monthEnd]);
    foreach ($q->fetchAll() as $r) { $trend[$r['ym']] = (float) $r['amt']; }
} catch (PDOException $e) { /* pre-migration */ }
// Fill gaps so a zero-spend month renders as a gap, not a missing bar.
$trendMonths = [];
for ($i = 5; $i >= 0; $i--) {
    $ym = date('Y-m', strtotime($monthStart . " -$i months"));
    $trendMonths[$ym] = $trend[$ym] ?? 0.0;
}
$trendMax = max(1.0, max($trendMonths));

// ---------------------------------------------------------------------------
// CSV export. Emitted BEFORE any HTML — the report you see is the report you
// download, same query, same period rule.
// ---------------------------------------------------------------------------
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="expenses-' . $month . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Expense Report', $monthLabel]);
    fputcsv($out, []);
    fputcsv($out, ['Category', 'Postings', 'Amount (Rs)', 'Share of operating %', $prevLabel . ' (Rs)', 'Change (Rs)']);
    foreach ($cur as $id => $r) {
        if ((int) $r['is_disb'] === 1) { continue; }
        $p = (float) ($prev[$id]['amt'] ?? 0);
        fputcsv($out, [
            $r['name'], (int) $r['n'], number_format((float) $r['amt'], 2, '.', ''),
            $opTotal > 0 ? number_format((float) $r['amt'] / $opTotal * 100, 1) : '0.0',
            number_format($p, 2, '.', ''),
            number_format((float) $r['amt'] - $p, 2, '.', ''),
        ]);
    }
    fputcsv($out, ['OPERATING TOTAL', $opCount, number_format($opTotal, 2, '.', ''), '100.0',
                   number_format($prevOpTotal, 2, '.', ''), number_format($momDelta, 2, '.', '')]);
    if ($disbTotal > 0) {
        fputcsv($out, []);
        fputcsv($out, ['DISBURSEMENTS (not an operating cost)']);
        foreach ($cur as $r) {
            if ((int) $r['is_disb'] !== 1) { continue; }
            fputcsv($out, [$r['name'], (int) $r['n'], number_format((float) $r['amt'], 2, '.', '')]);
        }
    }
    fclose($out);
    exit;
}

$pageTitle = 'Expense Report';
$extraCss = <<<CSS
<style>
.header { height: 72px; position: sticky; top: 0; z-index: 20; display: flex; align-items: center; justify-content: space-between; padding: 0 32px; background: rgba(255,255,255,.80); backdrop-filter: blur(18px); border-bottom: 1px solid var(--border); }
.header-right { display: flex; align-items: center; gap: 18px; margin-left: auto; }
.header-date { font-size: 13px; color: var(--text-secondary); white-space: nowrap; }
.logout-link { font-size: 13px; color: var(--text-secondary); font-weight: 500; }

.month-form { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.month-form input[type=month] { padding: 9px 12px; border: 1px solid var(--border); border-radius: 10px; font: inherit; font-size: 13.5px; background: #fff; }
.month-form input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,127,126,.15); }

.kpi-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 14px; margin-bottom: 18px; }
.kpi { border: 1px solid var(--border); border-radius: 14px; padding: 16px 18px; background: var(--card); box-shadow: var(--shadow-sm); }
.kpi .k { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted); }
.kpi .v { font-size: 22px; font-weight: 800; margin-top: 4px; font-variant-numeric: tabular-nums; }
.kpi .sub { font-size: 11.5px; color: var(--text-muted); margin-top: 3px; }
.kpi.total .v { color: var(--primary-dark); }

.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
tr.grand-row td { font-weight: 800; border-top: 2px solid var(--border); }
.up   { color: #b42318; font-weight: 700; }
.down { color: #067647; font-weight: 700; }
.flat { color: var(--text-muted); }

/* Share-of-total bar, drawn inline behind the percentage. */
.share-cell { min-width: 120px; }
.share-bar { height: 6px; border-radius: 4px; background: var(--primary-light); overflow: hidden; margin-top: 4px; }
.share-bar span { display: block; height: 100%; background: var(--primary); }

/* Trend: pure CSS columns, no chart library (CSP-safe, print-safe). */
.trend { display: flex; align-items: flex-end; gap: 10px; height: 130px; padding: 8px 2px 0; }
.trend .col { flex: 1; display: flex; flex-direction: column; justify-content: flex-end; align-items: center; height: 100%; }
.trend .bar { width: 100%; max-width: 54px; background: var(--primary); border-radius: 6px 6px 0 0; min-height: 2px; }
.trend .bar.current { background: var(--primary-dark); }
.trend .lbl { font-size: 10.5px; color: var(--text-muted); margin-top: 6px; white-space: nowrap; }
.trend .amt { font-size: 10.5px; font-weight: 700; margin-bottom: 4px; font-variant-numeric: tabular-nums; }

.disb-note { border-left: 3px solid var(--primary); padding: 10px 14px; background: var(--primary-light); border-radius: 0 10px 10px 0; font-size: 12.5px; line-height: 1.55; margin-bottom: 14px; }
.warn-note { border-left: 3px solid #b54708; background: #fffaeb; padding: 10px 14px; border-radius: 0 10px 10px 0; font-size: 12.5px; margin-bottom: 16px; }

@media print {
    .sidebar, .mobile-bar, .header, .month-form, .print-btn, .nav-group { display: none !important; }
    .main { margin: 0 !important; }
    .card { box-shadow: none !important; border: 1px solid #ccc; break-inside: avoid; }
}
</style>
CSS;
require __DIR__ . '/partials/head.php';
$navActive = 'expense_report';
require __DIR__ . '/partials/sidebar.php';

$fmtDelta = function (float $d): string {
    if (abs($d) < 0.5) { return '<span class="flat">—</span>'; }
    $cls = $d > 0 ? 'up' : 'down';
    return '<span class="' . $cls . '">' . ($d > 0 ? '+' : '−') . 'Rs ' . number_format(abs($d)) . '</span>';
};
?>
        <header class="header">
            <div class="page-title" style="font-size:16px;">Expense Report</div>
            <div class="header-right">
                <span class="header-date"><?= date('D, d/m/Y') ?></span>
                <a class="logout-link" href="logout.php">Logout</a>
            </div>
        </header>

        <div class="content">
            <div class="page-head">
                <div>
                    <div class="page-title">Expense Report — <?= htmlspecialchars($monthLabel) ?></div>
                    <div class="page-sub">Grouped by the month each cost belongs to, not the day it was paid</div>
                </div>
                <form class="month-form" method="GET" action="expense_report.php">
                    <input type="month" name="month" value="<?= htmlspecialchars($month) ?>" max="<?= date('Y-m') ?>">
                    <button type="submit" class="btn secondary">View</button>
                    <a class="btn secondary" href="expense_report.php?month=<?= urlencode($month) ?>&amp;export=csv">CSV</a>
                    <button type="button" class="btn print-btn" onclick="window.print()">Print</button>
                </form>
            </div>

            <?php if (!$hasPhase1): ?>
            <div class="warn-note">
                <strong>Showing partial figures.</strong> <code>sql/add_accounts_phase1.sql</code> has not been run on
                this database yet, so every expense is grouped by its payment date and no
                disbursement or payment-source split is available. Run that migration in
                phpMyAdmin and reload.
            </div>
            <?php endif; ?>

            <div class="kpi-row">
                <div class="kpi total">
                    <div class="k">Operating expenses</div>
                    <div class="v">Rs <?= number_format($opTotal) ?></div>
                    <div class="sub"><?= number_format($opCount) ?> posting<?= $opCount === 1 ? '' : 's' ?></div>
                </div>
                <div class="kpi">
                    <div class="k">vs <?= htmlspecialchars($prevLabel) ?></div>
                    <div class="v" style="font-size:19px;"><?= $fmtDelta($momDelta) ?></div>
                    <div class="sub"><?= $momPct === null ? 'no prior month' : number_format($momPct, 1) . '% change' ?></div>
                </div>
                <div class="kpi">
                    <div class="k">Largest category</div>
                    <?php
                    $largest = null;
                    foreach ($cur as $r) { if ((int) $r['is_disb'] !== 1) { $largest = $r; break; } }
                    ?>
                    <div class="v" style="font-size:16px;"><?= $largest ? htmlspecialchars($largest['name']) : '—' ?></div>
                    <div class="sub"><?= $largest ? 'Rs ' . number_format((float) $largest['amt']) : 'nothing posted' ?></div>
                </div>
                <?php if ($disbTotal > 0): ?>
                <div class="kpi">
                    <div class="k">Disbursements</div>
                    <div class="v">Rs <?= number_format($disbTotal) ?></div>
                    <div class="sub">excluded from operating</div>
                </div>
                <?php endif; ?>
            </div>

            <!-- By category -->
            <div class="card">
                <div class="section-title">By Category</div>
                <div class="section-sub">Share of the operating total, with last month alongside. Voided and rejected postings are excluded.</div>
                <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th class="num">Postings</th>
                            <th class="num">Amount</th>
                            <th class="share-cell">Share</th>
                            <th class="num"><?= htmlspecialchars($prevLabel) ?></th>
                            <th class="num">Change</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $anyOperating = false;
                        foreach ($cur as $id => $r):
                            if ((int) $r['is_disb'] === 1) { continue; }
                            $anyOperating = true;
                            $amt   = (float) $r['amt'];
                            $pAmt  = (float) ($prev[$id]['amt'] ?? 0);
                            $share = $opTotal > 0 ? $amt / $opTotal * 100 : 0;
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($r['name']) ?></td>
                            <td class="num"><?= number_format((int) $r['n']) ?></td>
                            <td class="num">Rs <?= number_format($amt) ?></td>
                            <td class="share-cell">
                                <?= number_format($share, 1) ?>%
                                <div class="share-bar"><span style="width:<?= number_format($share, 2) ?>%;"></span></div>
                            </td>
                            <td class="num"><?= $pAmt > 0 ? 'Rs ' . number_format($pAmt) : '—' ?></td>
                            <td class="num"><?= $fmtDelta($amt - $pAmt) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$anyOperating): ?>
                        <tr><td colspan="6" class="muted" style="padding:20px 10px;">Nothing was posted for <?= htmlspecialchars($monthLabel) ?>.</td></tr>
                        <?php endif; ?>
                        <?php if ($anyOperating): ?>
                        <tr class="grand-row">
                            <td>Operating total</td>
                            <td class="num"><?= number_format($opCount) ?></td>
                            <td class="num">Rs <?= number_format($opTotal) ?></td>
                            <td class="share-cell">100%</td>
                            <td class="num"><?= $prevOpTotal > 0 ? 'Rs ' . number_format($prevOpTotal) : '—' ?></td>
                            <td class="num"><?= $fmtDelta($momDelta) ?></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>

            <?php if ($disbTotal > 0): ?>
            <!-- Disbursements: shown, but deliberately outside the operating total -->
            <div class="card">
                <div class="section-title">Disbursements</div>
                <div class="disb-note">
                    Money paid <em>through</em> the clinic rather than spent by it. The profit
                    &amp; loss already deducts the doctor's <strong>earned</strong> share from
                    revenue before arriving at clinic income — counting these payments as an
                    operating cost as well would understate profit by exactly the same amount.
                    They are listed here so what was <strong>earned</strong> can be reconciled
                    against what was actually <strong>paid out</strong>.
                </div>
                <div style="overflow-x:auto;">
                <table>
                    <thead><tr><th>Category</th><th class="num">Postings</th><th class="num">Amount</th></tr></thead>
                    <tbody>
                        <?php foreach ($cur as $r): if ((int) $r['is_disb'] !== 1) { continue; } ?>
                        <tr>
                            <td><?= htmlspecialchars($r['name']) ?></td>
                            <td class="num"><?= number_format((int) $r['n']) ?></td>
                            <td class="num">Rs <?= number_format((float) $r['amt']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- Trend -->
            <div class="card">
                <div class="section-title">Six-Month Trend</div>
                <div class="section-sub">Operating expenses only, by the month each cost belongs to.</div>
                <div class="trend">
                    <?php foreach ($trendMonths as $ym => $amt):
                        $h = $trendMax > 0 ? ($amt / $trendMax) * 100 : 0; ?>
                    <div class="col">
                        <div class="amt"><?= $amt > 0 ? number_format($amt / 1000, 0) . 'k' : '' ?></div>
                        <div class="bar<?= $ym === $month ? ' current' : '' ?>" style="height:<?= number_format($h, 2) ?>%;"></div>
                        <div class="lbl"><?= htmlspecialchars(date('M y', strtotime($ym . '-01'))) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if ($bySource): ?>
            <!-- Paid from -->
            <div class="card">
                <div class="section-title">Paid From</div>
                <div class="section-sub">Only counter cash is deducted from a shift's expected drawer.</div>
                <div style="overflow-x:auto;">
                <table>
                    <thead><tr><th>Source</th><th class="num">Postings</th><th class="num">Amount</th></tr></thead>
                    <tbody>
                        <?php
                        $srcLabels = ['CASH_COUNTER' => 'Counter cash', 'BANK' => 'Bank transfer', 'OWNER' => 'Owner / outside the counter'];
                        foreach ($bySource as $s): ?>
                        <tr>
                            <td><?= htmlspecialchars($srcLabels[$s['src']] ?? $s['src']) ?></td>
                            <td class="num"><?= number_format((int) $s['n']) ?></td>
                            <td class="num">Rs <?= number_format((float) $s['amt']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($topPayees): ?>
            <!-- Top payees -->
            <div class="card">
                <div class="section-title">Top Payees</div>
                <div class="section-sub">Where the money went, by the name recorded on each voucher.</div>
                <div style="overflow-x:auto;">
                <table>
                    <thead><tr><th>Paid to</th><th class="num">Postings</th><th class="num">Amount</th></tr></thead>
                    <tbody>
                        <?php foreach ($topPayees as $p): ?>
                        <tr>
                            <td><?= htmlspecialchars($p['paid_to']) ?></td>
                            <td class="num"><?= number_format((int) $p['n']) ?></td>
                            <td class="num">Rs <?= number_format((float) $p['amt']) ?></td>
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
