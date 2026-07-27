<?php
/**
 * Income Report — clinic revenue by source. (Accounts Phase 4, 2026-07-26)
 *
 * The first page in the app to read ALL FOUR revenue streams together. Until
 * now every report read `bills` alone and cron/daily_summary.php summed only
 * bills + admission_bills, so ER and IPD income was invisible.
 *
 * THE HEADLINE IS NOT GROSS
 *   Clinic income = gross − refunds − tax withheld − doctor shares. The
 *   doctor's cut is a pass-through, not clinic money, and the tax is deposited
 *   with the government. Showing gross as "income" would overstate what the
 *   clinic actually keeps by exactly those two figures.
 *
 * COMPARE MODE
 *   Two arbitrary ranges side by side. Deliberately NOT restricted to equal
 *   lengths — comparing a 10-day push against a full month is a legitimate
 *   question — but the per-day average is always shown so an unequal
 *   comparison cannot be misread as like-for-like.
 *
 * Cash basis (money received), matching the expense report. Gated on
 * FINANCIAL_VIEW_CLINIC_REPORTS.
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
refresh_session_permissions($pdo);
require_once __DIR__ . '/config/billing.php';
require_permission('FINANCIAL_VIEW_CLINIC_REPORTS');

$isDate = function ($s) { return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $s ?? ''); };
$mode   = in_array($_GET['mode'] ?? '', ['range', 'compare'], true) ? $_GET['mode'] : 'month';

// ---- Period A (always present) --------------------------------------------
if ($mode === 'month') {
    $month  = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m');
    $aStart = $month . '-01';
    $aEnd   = date('Y-m-t', strtotime($aStart));
    $aLabel = date('F Y', strtotime($aStart));
} else {
    $month  = date('Y-m');
    $aStart = $isDate($_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
    $aEnd   = $isDate($_GET['to'] ?? '')   ? $_GET['to']   : date('Y-m-d');
    if ($aStart > $aEnd) { [$aStart, $aEnd] = [$aEnd, $aStart]; }
    $aLabel = date('d/m/Y', strtotime($aStart)) . ' – ' . date('d/m/Y', strtotime($aEnd));
}

// ---- Period B (compare mode only) ------------------------------------------
$bStart = $bEnd = $bLabel = null;
if ($mode === 'compare') {
    $bStart = $isDate($_GET['from2'] ?? '') ? $_GET['from2'] : date('Y-m-01', strtotime($aStart . ' -1 month'));
    $bEnd   = $isDate($_GET['to2'] ?? '')   ? $_GET['to2']   : date('Y-m-t', strtotime($aStart . ' -1 month'));
    if ($bStart > $bEnd) { [$bStart, $bEnd] = [$bEnd, $bStart]; }
    $bLabel = date('d/m/Y', strtotime($bStart)) . ' – ' . date('d/m/Y', strtotime($bEnd));
}

$dayCount = function ($s, $e) { return max(1, (int) ((strtotime($e) - strtotime($s)) / 86400) + 1); };

// ---- Period chart granularity ----------------------------------------------
// The Day/Month/Year/Total chart (partials/period_chart.php) is the same
// component the doctor console uses, so "the month report" looks identical on
// both. It drives its OWN period independently of the Month/Range/Compare
// tables below — those answer "what did this range total?", the chart answers
// "how did it move day to day?".
$chartGran = in_array($_GET['gran'] ?? '', ['day', 'month', 'year', 'total'], true) ? $_GET['gran'] : 'month';
$cKeys = $cLabels = [];

if ($chartGran === 'day') {
    $cPeriod = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['period'] ?? '') ? $_GET['period'] : date('Y-m-d');
    $cStart = $cEnd = $cPeriod;
    $cBucket = 'hour';
    $cLabel = date('d/m/Y', strtotime($cPeriod));
    $cPrev = date('Y-m-d', strtotime($cPeriod . ' -1 day'));
    $cNext = $cPeriod >= date('Y-m-d') ? null : date('Y-m-d', strtotime($cPeriod . ' +1 day'));
    for ($h = 0; $h < 24; $h++) { $cKeys[] = $h; $cLabels[] = str_pad((string) $h, 2, '0', STR_PAD_LEFT); }
} elseif ($chartGran === 'month') {
    $cPeriod = preg_match('/^\d{4}-\d{2}$/', $_GET['period'] ?? '') ? $_GET['period'] : $month;
    $cStart = $cPeriod . '-01';
    $cEnd = date('Y-m-t', strtotime($cStart));
    $cBucket = 'day';
    $cLabel = date('m.Y', strtotime($cStart));
    $cPrev = date('Y-m', strtotime($cStart . ' -1 month'));
    $cNext = $cPeriod >= date('Y-m') ? null : date('Y-m', strtotime($cStart . ' +1 month'));
    $dim = (int) date('t', strtotime($cStart));
    for ($d = 1; $d <= $dim; $d++) { $cKeys[] = $d; $cLabels[] = (string) $d; }
} elseif ($chartGran === 'year') {
    $cPeriod = preg_match('/^\d{4}$/', $_GET['period'] ?? '') ? $_GET['period'] : date('Y');
    $cStart = $cPeriod . '-01-01';
    $cEnd = $cPeriod . '-12-31';
    $cBucket = 'month';
    $cLabel = (string) $cPeriod;
    $cPrev = (string) ((int) $cPeriod - 1);
    $cNext = (int) $cPeriod >= (int) date('Y') ? null : (string) ((int) $cPeriod + 1);
    for ($m = 1; $m <= 12; $m++) { $cKeys[] = $m; $cLabels[] = date('M', mktime(0, 0, 0, $m, 1)); }
} else { // total — every year the clinic has taken money in
    $yNow = (int) date('Y');
    $yFirst = $yNow;
    try {
        $fy = $pdo->query("SELECT MIN(YEAR(paid_at)) FROM bills WHERE status = 'paid' AND voided_at IS NULL")->fetchColumn();
        if ($fy && (int) $fy >= 2000 && (int) $fy <= $yNow) { $yFirst = (int) $fy; }
    } catch (PDOException $e) { /* fall back to this year alone */ }
    $cPeriod = '';
    $cStart = $yFirst . '-01-01';
    $cEnd = $yNow . '-12-31';
    $cBucket = 'year';
    $cLabel = $yFirst === $yNow ? (string) $yNow : $yFirst . ' – ' . $yNow;
    $cPrev = $cNext = null;
    for ($y = $yFirst; $y <= $yNow; $y++) { $cKeys[] = $y; $cLabels[] = (string) $y; }
}

$chartData = clinic_income_buckets($pdo, $cStart, $cEnd, $cBucket);

// ---- Pull the figures ------------------------------------------------------
$revA = clinic_revenue($pdo, $aStart, $aEnd);
$shrA = clinic_doctor_shares($pdo, $aStart, $aEnd);
$aDays = $dayCount($aStart, $aEnd);
// Clinic income: what is left after the doctors and the taxman are paid.
$incomeA = max(0.0, $revA['net'] - $shrA['doctor'] - $shrA['tax']);

$revB = $shrB = null; $incomeB = 0.0; $bDays = 1;
if ($mode === 'compare') {
    $revB = clinic_revenue($pdo, $bStart, $bEnd);
    $shrB = clinic_doctor_shares($pdo, $bStart, $bEnd);
    $bDays = $dayCount($bStart, $bEnd);
    $incomeB = max(0.0, $revB['net'] - $shrB['doctor'] - $shrB['tax']);
}

// ---- CSV -------------------------------------------------------------------
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="income-' . $aStart . '_to_' . $aEnd . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Income Report', $aLabel]);
    if ($mode === 'compare') { fputcsv($out, ['Compared with', $bLabel]); }
    fputcsv($out, []);
    fputcsv($out, array_merge(['Source', 'Bills', 'Gross (Rs)'], $mode === 'compare' ? ['Bills B', 'Gross B (Rs)', 'Change (Rs)'] : []));
    foreach ($revA['streams'] as $k => $s) {
        $row = [$s['label'], $s['bills'], number_format($s['gross'], 2, '.', '')];
        if ($mode === 'compare') {
            $b = $revB['streams'][$k];
            $row[] = $b['bills'];
            $row[] = number_format($b['gross'], 2, '.', '');
            $row[] = number_format($s['gross'] - $b['gross'], 2, '.', '');
        }
        fputcsv($out, $row);
    }
    fputcsv($out, []);
    fputcsv($out, ['Gross revenue', '', number_format($revA['gross'], 2, '.', '')]);
    fputcsv($out, ['Less refunds', '', number_format(-$revA['refunds'], 2, '.', '')]);
    fputcsv($out, ['Less tax withheld', '', number_format(-$shrA['tax'], 2, '.', '')]);
    fputcsv($out, ['Less doctor shares', '', number_format(-$shrA['doctor'], 2, '.', '')]);
    fputcsv($out, ['CLINIC INCOME', '', number_format($incomeA, 2, '.', '')]);
    fputcsv($out, []);
    fputcsv($out, ['Doctor', 'Earned (Rs)', 'Tax withheld (Rs)']);
    foreach ($shrA['rows'] as $r) {
        fputcsv($out, [$r['name'], number_format($r['doctor'], 2, '.', ''), number_format($r['tax'], 2, '.', '')]);
    }
    fclose($out);
    exit;
}

$pageTitle = 'Income Report';
$headExtra = <<<CSS
<style>
.header { height: 72px; position: sticky; top: 0; z-index: 20; display: flex; align-items: center; justify-content: space-between; padding: 0 32px; background: rgba(255,255,255,.80); backdrop-filter: blur(18px); border-bottom: 1px solid var(--border); }
.header-right { display: flex; align-items: center; gap: 18px; margin-left: auto; }
.header-date { font-size: 13px; color: var(--text-secondary); white-space: nowrap; }
.logout-link { font-size: 13px; color: var(--text-secondary); font-weight: 500; }

.month-form { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.month-form input[type=month], .month-form input[type=date] { padding: 9px 12px; border: 1px solid var(--border); border-radius: 10px; font: inherit; font-size: 13.5px; background: #fff; }
.month-form input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,127,126,.15); }
.month-form .to-sep { font-size: 12.5px; color: var(--text-muted); margin: 0 2px; }
.mode-fields { display: inline-flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.vs-sep { font-size: 12px; font-weight: 700; color: var(--text-muted); }
.mode-tabs { display: inline-flex; border: 1px solid var(--border); border-radius: 10px; overflow: hidden; background: #fff; }
.mode-tab { border: 0; background: transparent; font: inherit; font-size: 12.5px; font-weight: 600; padding: 9px 13px; cursor: pointer; color: var(--text-secondary); }
.mode-tab.on { background: var(--primary, #1A7F7E); color: #fff; }

.kpi-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 14px; margin-bottom: 18px; }
.kpi { border: 1px solid var(--border); border-radius: 14px; padding: 16px 18px; background: var(--card); box-shadow: var(--shadow-sm); }
.kpi .k { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted); }
.kpi .v { font-size: 22px; font-weight: 800; margin-top: 4px; font-variant-numeric: tabular-nums; }
.kpi .sub { font-size: 11.5px; color: var(--text-muted); margin-top: 3px; }
.kpi.total .v { color: var(--primary-dark); }

.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
tr.grand-row td { font-weight: 800; border-top: 2px solid var(--border); }
.up { color: #067647; font-weight: 700; }
.down { color: #b42318; font-weight: 700; }
.flat { color: var(--text-muted); }

/* Waterfall from gross to what the clinic keeps. */
.ladder { list-style: none; margin: 0; padding: 0; max-width: 520px; }
.ladder li { display: flex; align-items: center; justify-content: space-between; gap: 14px; padding: 9px 0; font-size: 13.5px; border-bottom: 1px solid var(--border); }
.ladder li.deduct .amt { color: #b42318; }
.ladder li.final { border-bottom: 0; border-top: 2px solid var(--border); font-weight: 800; font-size: 15px; padding-top: 12px; }
.ladder .amt { font-variant-numeric: tabular-nums; white-space: nowrap; }
.ladder .lbl small { display: block; font-size: 11.5px; color: var(--text-muted); font-weight: 400; }

/* Donut + legend — inline SVG, no chart library. */
.pie-wrap { display: flex; align-items: center; gap: 28px; flex-wrap: wrap; }
.pie { width: 210px; height: 210px; flex: 0 0 auto; transform: rotate(-90deg); }
.pie:hover .slice { opacity: .45; }
.pie .slice { transition: opacity .12s; }
.pie .slice:hover { opacity: 1; }
.pie-total { transform: rotate(90deg); transform-origin: 21px 21px; text-anchor: middle; font-size: 6px; font-weight: 800; fill: var(--text-primary, #16211C); }
.pie-cap { transform: rotate(90deg); transform-origin: 21px 21px; text-anchor: middle; font-size: 2.4px; font-weight: 600; fill: var(--text-muted); letter-spacing: .04em; }
.pie-legend { list-style: none; margin: 0; padding: 0; flex: 1 1 260px; min-width: 240px; }
.pie-legend li { display: flex; align-items: center; gap: 9px; padding: 5px 0; font-size: 12.5px; border-bottom: 1px solid var(--border); }
.pie-legend li:last-child { border-bottom: 0; }
.pie-legend .dot { width: 10px; height: 10px; border-radius: 3px; flex: 0 0 auto; }
.pie-legend .nm { flex: 1 1 auto; }
.pie-legend .vl { font-variant-numeric: tabular-nums; font-weight: 700; white-space: nowrap; }
.pie-legend .pc { font-variant-numeric: tabular-nums; color: var(--text-muted); width: 46px; text-align: right; }

/* Grouped bars: one pair per source, A beside B. */
.cbars { display: flex; align-items: flex-end; gap: 18px; height: 210px; padding: 10px 2px 0; overflow-x: auto; }
.cbars .grp { flex: 1 1 0; min-width: 78px; display: flex; flex-direction: column; justify-content: flex-end; height: 100%; }
.cbars .pair { display: flex; align-items: flex-end; justify-content: center; gap: 6px; height: 100%; }
.cbars .bar { width: 26px; border-radius: 5px 5px 0 0; min-height: 2px; }
.cbars .bar.a { background: var(--primary, #1A7F7E); }
.cbars .bar.b { background: #B4C4BC; }
.cbars .lbl { font-size: 10.5px; color: var(--text-muted); text-align: center; margin-top: 7px; line-height: 1.3; }
.legend-key { display: flex; gap: 16px; font-size: 12px; margin-bottom: 4px; }
.legend-key span { display: inline-flex; align-items: center; gap: 6px; }
.legend-key i { width: 11px; height: 11px; border-radius: 3px; display: inline-block; }

.note { border-left: 3px solid var(--primary); padding: 10px 14px; background: var(--primary-light, rgba(26,127,126,.08)); border-radius: 0 10px 10px 0; font-size: 12.5px; line-height: 1.55; margin-bottom: 14px; }

@media print {
    .sidebar, .mobile-bar, .header, .month-form, .print-btn, .nav-group, .mode-tabs, .appbar { display: none !important; }
    .main { margin: 0 !important; }
    .card { box-shadow: none !important; border: 1px solid #ccc; break-inside: avoid; }
    .pie, .pie-legend .dot, .cbars .bar, .legend-key i { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>
CSS;
require __DIR__ . '/partials/head.php';
$navActive = 'income_report';
require __DIR__ . '/partials/sidebar.php';

$money = function ($n) { return 'Rs ' . number_format($n); };
$delta = function ($a, $b) {
    $d = $a - $b;
    if (abs($d) < 0.5) { return '<span class="flat">—</span>'; }
    return '<span class="' . ($d > 0 ? 'up' : 'down') . '">' . ($d > 0 ? '+' : '−') . 'Rs ' . number_format(abs($d)) . '</span>';
};
$periodQs = $mode === 'compare'
    ? 'mode=compare&from=' . urlencode($aStart) . '&to=' . urlencode($aEnd) . '&from2=' . urlencode($bStart) . '&to2=' . urlencode($bEnd)
    : ($mode === 'range' ? 'mode=range&from=' . urlencode($aStart) . '&to=' . urlencode($aEnd) : 'month=' . urlencode($month));

$pieColors = ['#0E5456', '#1A7F7E', '#2E9E86', '#7FB069', '#C7B446', '#E08D3C'];
?>
        <header class="header">
            <div class="page-title" style="font-size:16px;">Income Report</div>
            <div class="header-right">
                <span class="header-date"><?= date('D, d/m/Y') ?></span>
                <a class="logout-link" href="logout.php">Logout</a>
            </div>
        </header>

        <div class="content">
            <div class="page-head">
                <div>
                    <div class="page-title">Income Report — <?= htmlspecialchars($aLabel) ?></div>
                    <div class="page-sub">
                        Money actually received, across every billing stream
                        <?= $mode === 'compare' ? ' · compared with ' . htmlspecialchars($bLabel) : '' ?>
                    </div>
                </div>
                <form class="month-form" method="GET" action="income_report.php" id="periodForm">
                    <div class="mode-tabs">
                        <button type="button" class="mode-tab<?= $mode === 'month' ? ' on' : '' ?>" data-mode="month">Month</button>
                        <button type="button" class="mode-tab<?= $mode === 'range' ? ' on' : '' ?>" data-mode="range">Date range</button>
                        <button type="button" class="mode-tab<?= $mode === 'compare' ? ' on' : '' ?>" data-mode="compare">Compare</button>
                    </div>
                    <input type="hidden" name="mode" id="modeField" value="<?= htmlspecialchars($mode) ?>">

                    <span class="mode-fields" data-for="month"<?= $mode !== 'month' ? ' style="display:none;"' : '' ?>>
                        <input type="month" name="month" value="<?= htmlspecialchars($month) ?>" max="<?= date('Y-m') ?>">
                    </span>
                    <span class="mode-fields" data-for="range"<?= $mode !== 'range' ? ' style="display:none;"' : '' ?>>
                        <input type="date" name="from" value="<?= htmlspecialchars($aStart) ?>" max="<?= date('Y-m-d') ?>">
                        <span class="to-sep">to</span>
                        <input type="date" name="to" value="<?= htmlspecialchars($aEnd) ?>" max="<?= date('Y-m-d') ?>">
                    </span>
                    <span class="mode-fields" data-for="compare"<?= $mode !== 'compare' ? ' style="display:none;"' : '' ?>>
                        <input type="date" name="from" value="<?= htmlspecialchars($aStart) ?>" max="<?= date('Y-m-d') ?>">
                        <span class="to-sep">to</span>
                        <input type="date" name="to" value="<?= htmlspecialchars($aEnd) ?>" max="<?= date('Y-m-d') ?>">
                        <span class="vs-sep">vs</span>
                        <input type="date" name="from2" value="<?= htmlspecialchars($bStart ?? date('Y-m-01', strtotime('-1 month'))) ?>" max="<?= date('Y-m-d') ?>">
                        <span class="to-sep">to</span>
                        <input type="date" name="to2" value="<?= htmlspecialchars($bEnd ?? date('Y-m-t', strtotime('-1 month'))) ?>" max="<?= date('Y-m-d') ?>">
                    </span>

                    <button type="submit" class="btn secondary">View</button>
                    <a class="btn secondary" href="income_report.php?<?= $periodQs ?>&amp;export=csv">CSV</a>
                    <button type="button" class="btn print-btn" onclick="window.print()">Print</button>
                </form>
            </div>

            <div class="kpi-row">
                <div class="kpi total">
                    <div class="k">Clinic income</div>
                    <div class="v"><?= $money($incomeA) ?></div>
                    <div class="sub">after doctors &amp; tax</div>
                </div>
                <div class="kpi">
                    <div class="k">Gross received</div>
                    <div class="v"><?= $money($revA['gross']) ?></div>
                    <div class="sub"><?= number_format($revA['bills']) ?> bill<?= $revA['bills'] === 1 ? '' : 's' ?></div>
                </div>
                <div class="kpi">
                    <div class="k">Doctor shares</div>
                    <div class="v"><?= $money($shrA['doctor']) ?></div>
                    <div class="sub">earned, not clinic money</div>
                </div>
                <div class="kpi">
                    <div class="k">Tax withheld</div>
                    <div class="v"><?= $money($shrA['tax']) ?></div>
                    <div class="sub">to deposit</div>
                </div>
                <?php if ($mode === 'compare'): ?>
                <div class="kpi">
                    <div class="k">vs period B</div>
                    <div class="v" style="font-size:19px;"><?= $delta($incomeA, $incomeB) ?></div>
                    <div class="sub">clinic income</div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Day / Month / Year / Total trend — the shared period chart -->
            <div class="card">
                <div class="section-title">Clinic Income Trend</div>
                <div class="section-sub">
                    What the clinic kept after doctors and tax, bucketed over time.
                    This chart carries its own period — it is independent of the range picked above.
                </div>
                <?php
                $pcBuckets   = $chartData;
                $pcKeys      = $cKeys;
                $pcLabels    = $cLabels;
                $pcGran      = $chartGran;
                $pcPeriodLbl = $cLabel;
                $pcSeriesLbl = 'Clinic income';
                $pcUnit      = 'PKR';
                // Keep whatever range/compare the tables are showing when the
                // chart's own tabs and arrows are used.
                $chartQs = function (array $extra) use ($periodQs) {
                    return 'income_report.php?' . $periodQs . '&' . http_build_query($extra);
                };
                $pcPrevUrl = $cPrev !== null ? $chartQs(['gran' => $chartGran, 'period' => $cPrev]) : null;
                $pcNextUrl = $cNext !== null ? $chartQs(['gran' => $chartGran, 'period' => $cNext]) : null;
                $pcTabUrl  = function (string $g) use ($chartQs) { return $chartQs(['gran' => $g]); };
                $pcTipFmt  = null;
                require __DIR__ . '/partials/period_chart.php';
                ?>
            </div>

            <!-- What the clinic actually keeps -->
            <div class="card">
                <div class="section-title">From Gross to Clinic Income</div>
                <div class="note">
                    A doctor's share is money passing <em>through</em> the clinic, and withheld tax is
                    deposited with the government. Neither is clinic income, so both come off before
                    the bottom line. Operating costs (salaries, rent, utilities) are on the
                    <a href="expense_report.php">Expense Report</a>; profit &amp; loss combines the two.
                </div>
                <ul class="ladder">
                    <li><span class="lbl">Gross received<small><?= number_format($revA['bills']) ?> paid bills across all streams</small></span>
                        <span class="amt"><?= $money($revA['gross']) ?></span></li>
                    <li class="deduct"><span class="lbl">Less refunds</span>
                        <span class="amt">−<?= $money($revA['refunds']) ?></span></li>
                    <li class="deduct"><span class="lbl">Less tax withheld<small>deposited on the doctors' behalf</small></span>
                        <span class="amt">−<?= $money($shrA['tax']) ?></span></li>
                    <li class="deduct"><span class="lbl">Less doctor shares<small>earned this period, whether or not yet paid out</small></span>
                        <span class="amt">−<?= $money($shrA['doctor']) ?></span></li>
                    <li class="final"><span class="lbl">Clinic income</span>
                        <span class="amt"><?= $money($incomeA) ?></span></li>
                </ul>
            </div>

            <?php
            // ---- Source pie ----
            $slices = [];
            foreach ($revA['streams'] as $s) {
                if ($s['gross'] > 0) { $slices[] = $s; }
            }
            ?>
            <?php if ($slices && $revA['gross'] > 0): ?>
            <div class="card">
                <div class="section-title">Where the Income Came From</div>
                <div class="section-sub">Gross receipts by billing stream. Voided bills excluded.</div>
                <div class="pie-wrap">
                    <svg class="pie" viewBox="0 0 42 42" width="210" height="210" role="img" aria-label="Income by source">
                        <?php $off = 25; foreach ($slices as $i => $s):
                            $pct = $s['gross'] / $revA['gross'] * 100;
                            $col = $pieColors[$i % count($pieColors)]; ?>
                        <circle class="slice" cx="21" cy="21" r="15.915" fill="transparent"
                                stroke="<?= $col ?>" stroke-width="6"
                                stroke-dasharray="<?= number_format($pct, 3, '.', '') ?> <?= number_format(100 - $pct, 3, '.', '') ?>"
                                stroke-dashoffset="<?= number_format($off, 3, '.', '') ?>">
                            <title><?= htmlspecialchars($s['label']) ?>: <?= $money($s['gross']) ?> (<?= number_format($pct, 1) ?>%)</title>
                        </circle>
                        <?php $off -= $pct; if ($off < 0) { $off += 100; } endforeach; ?>
                        <text x="21" y="20" class="pie-total"><?= number_format($revA['gross'] / 1000, 0) ?>k</text>
                        <text x="21" y="24.5" class="pie-cap">gross</text>
                    </svg>
                    <ul class="pie-legend">
                        <?php foreach ($slices as $i => $s): ?>
                        <li>
                            <span class="dot" style="background:<?= $pieColors[$i % count($pieColors)] ?>;"></span>
                            <span class="nm"><?= htmlspecialchars($s['label']) ?></span>
                            <span class="vl"><?= $money($s['gross']) ?></span>
                            <span class="pc"><?= number_format($s['gross'] / $revA['gross'] * 100, 1) ?>%</span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($mode === 'compare'): ?>
            <!-- Grouped bars: A vs B per source -->
            <div class="card">
                <div class="section-title">Period Comparison</div>
                <div class="section-sub">
                    <?= htmlspecialchars($aLabel) ?> (<?= $aDays ?> days) against <?= htmlspecialchars($bLabel) ?> (<?= $bDays ?> days).
                    <?= $aDays !== $bDays ? '<strong>The periods are different lengths</strong> — compare the per-day averages below, not the totals.' : '' ?>
                </div>
                <div class="legend-key">
                    <span><i style="background:var(--primary,#1A7F7E);"></i> <?= htmlspecialchars($aLabel) ?></span>
                    <span><i style="background:#B4C4BC;"></i> <?= htmlspecialchars($bLabel) ?></span>
                </div>
                <?php
                $maxBar = 1.0;
                foreach ($revA['streams'] as $k => $s) {
                    $maxBar = max($maxBar, $s['gross'], $revB['streams'][$k]['gross']);
                }
                $maxBar = max($maxBar, $incomeA, $incomeB, $revA['gross'], $revB['gross']);
                ?>
                <div class="cbars">
                    <?php
                    // Totals first, then each stream — the headline pair reads before the detail.
                    $groups = [['Gross', $revA['gross'], $revB['gross']], ['Clinic income', $incomeA, $incomeB]];
                    foreach ($revA['streams'] as $k => $s) {
                        $groups[] = [$s['label'], $s['gross'], $revB['streams'][$k]['gross']];
                    }
                    foreach ($groups as $g): ?>
                    <div class="grp">
                        <div class="pair">
                            <div class="bar a" style="height:<?= number_format($g[1] / $maxBar * 100, 2) ?>%;" title="<?= $money($g[1]) ?>"></div>
                            <div class="bar b" style="height:<?= number_format($g[2] / $maxBar * 100, 2) ?>%;" title="<?= $money($g[2]) ?>"></div>
                        </div>
                        <div class="lbl"><?= htmlspecialchars($g[0]) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card">
                <div class="section-title">Comparison Detail</div>
                <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Source</th>
                            <th class="num"><?= htmlspecialchars($aLabel) ?></th>
                            <th class="num">per day</th>
                            <th class="num"><?= htmlspecialchars($bLabel) ?></th>
                            <th class="num">per day</th>
                            <th class="num">Change</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($revA['streams'] as $k => $s): $b = $revB['streams'][$k]; ?>
                        <tr>
                            <td><?= htmlspecialchars($s['label']) ?></td>
                            <td class="num"><?= $money($s['gross']) ?></td>
                            <td class="num"><?= $money($s['gross'] / $aDays) ?></td>
                            <td class="num"><?= $money($b['gross']) ?></td>
                            <td class="num"><?= $money($b['gross'] / $bDays) ?></td>
                            <td class="num"><?= $delta($s['gross'] / $aDays, $b['gross'] / $bDays) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="grand-row">
                            <td>Clinic income</td>
                            <td class="num"><?= $money($incomeA) ?></td>
                            <td class="num"><?= $money($incomeA / $aDays) ?></td>
                            <td class="num"><?= $money($incomeB) ?></td>
                            <td class="num"><?= $money($incomeB / $bDays) ?></td>
                            <td class="num"><?= $delta($incomeA / $aDays, $incomeB / $bDays) ?></td>
                        </tr>
                    </tbody>
                </table>
                </div>
                <div class="muted-note" style="margin-top:10px;">Change compares per-day averages, so unequal periods stay comparable.</div>
            </div>
            <?php endif; ?>

            <!-- By source -->
            <div class="card">
                <div class="section-title">By Source</div>
                <div class="section-sub">Cash and digital split per stream, as received.</div>
                <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr><th>Source</th><th class="num">Bills</th><th class="num">Cash</th><th class="num">Digital</th><th class="num">Gross</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($revA['streams'] as $s): ?>
                        <tr>
                            <td><?= htmlspecialchars($s['label']) ?></td>
                            <td class="num"><?= number_format($s['bills']) ?></td>
                            <td class="num"><?= $money($s['cash']) ?></td>
                            <td class="num"><?= $money($s['online']) ?></td>
                            <td class="num"><?= $money($s['gross']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="grand-row">
                            <td>Total</td>
                            <td class="num"><?= number_format($revA['bills']) ?></td>
                            <td class="num"><?= $money($revA['cash']) ?></td>
                            <td class="num"><?= $money($revA['online']) ?></td>
                            <td class="num"><?= $money($revA['gross']) ?></td>
                        </tr>
                    </tbody>
                </table>
                </div>
            </div>

            <?php if ($shrA['rows']): ?>
            <!-- Per doctor -->
            <div class="card">
                <div class="section-title">Earned by Doctor</div>
                <div class="section-sub">
                    OPD consultations plus in-door ward rounds, on each doctor's own share and tax rate.
                    Tax withheld is what the clinic deposits — a doctor who self-deposits shows zero.
                </div>
                <div style="overflow-x:auto;">
                <table>
                    <thead><tr><th>Doctor</th><th class="num">Earned</th><th class="num">Tax withheld</th></tr></thead>
                    <tbody>
                        <?php foreach ($shrA['rows'] as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars($r['name']) ?></td>
                            <td class="num"><?= $money($r['doctor']) ?></td>
                            <td class="num"><?= $r['tax'] > 0 ? $money($r['tax']) : '<span class="muted">self-deposits</span>' ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="grand-row">
                            <td>Total</td>
                            <td class="num"><?= $money($shrA['doctor']) ?></td>
                            <td class="num"><?= $money($shrA['tax']) ?></td>
                        </tr>
                    </tbody>
                </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<script>
// Month | Date range | Compare switch. Only the active mode's inputs submit —
// disabled inputs are not sent, so a hidden field can never override the mode
// actually chosen.
(function () {
    var form  = document.getElementById('periodForm');
    var field = document.getElementById('modeField');
    if (!form || !field) return;
    var tabs = form.querySelectorAll('.mode-tab');
    var sets = form.querySelectorAll('.mode-fields');

    function apply(mode) {
        field.value = mode;
        tabs.forEach(function (t) { t.classList.toggle('on', t.getAttribute('data-mode') === mode); });
        sets.forEach(function (s) {
            var on = s.getAttribute('data-for') === mode;
            s.style.display = on ? '' : 'none';
            // Also reach the hidden native input behind a dd/mm/yyyy proxy
            // (date-picker.js) — that is the field that actually submits.
            s.querySelectorAll('input').forEach(function (i) {
                i.disabled = !on;
                if (i._native) i._native.disabled = !on;
            });
        });
    }
    tabs.forEach(function (t) {
        t.addEventListener('click', function () { apply(t.getAttribute('data-mode')); });
    });
    apply(field.value);
})();
</script>
<script src="assets/js/date-picker.js?v=<?= @filemtime(__DIR__ . "/assets/js/date-picker.js") ?: 1 ?>"></script>
</body>
</html>
