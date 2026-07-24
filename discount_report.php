<?php
/**
 * Discount Report — month-end category attribution.
 *
 * Every discounted rupee traced back to its category (Family & Friends /
 * Charity / Loyalty …), split by billing area: consultations (from
 * visits.category_discount_amount) and admission services / procedures (from
 * admission_bill_items.discount_amount, split on service_type). All figures
 * come from billing-time snapshots — editing a category's rates never changes
 * a past month's report. Invoice-level drill-down below the summary.
 */
require_once __DIR__ . '/config/guard_admin.php';

// Month picker — defaults to the current month; value format YYYY-MM.
$month = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m');
$monthStart = $month . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));
$monthLabel = date('F Y', strtotime($monthStart));

// ---- Summary: per category × area ----
// Consultations: visits dated in the month carrying a category snapshot.
$consult = $pdo->prepare('
    SELECT v.discount_category_id AS cat_id, dc.name AS cat_name,
           COUNT(*) AS n, COALESCE(SUM(v.category_discount_amount), 0) AS amt
    FROM visits v
    JOIN discount_categories dc ON dc.id = v.discount_category_id
    LEFT JOIN bills b ON b.visit_id = v.id
    WHERE v.visit_date BETWEEN ? AND ? AND v.category_discount_amount > 0
      AND b.voided_at IS NULL
    GROUP BY v.discount_category_id, dc.name
');
$consult->execute([$monthStart, $monthEnd]);
$consultRows = $consult->fetchAll();

// Admission lines: month taken from the bill's creation date (when the
// discharge bill was raised — the moment the discount was granted).
$adm = $pdo->prepare('
    SELECT ab.discount_category_id AS cat_id, dc.name AS cat_name,
           abi.service_type,
           COUNT(*) AS n, COALESCE(SUM(abi.discount_amount), 0) AS amt
    FROM admission_bill_items abi
    JOIN admission_bills ab ON ab.id = abi.admission_bill_id
    JOIN discount_categories dc ON dc.id = ab.discount_category_id
    WHERE DATE(ab.created_at) BETWEEN ? AND ? AND abi.discount_amount > 0
      AND ab.voided_at IS NULL
    GROUP BY ab.discount_category_id, dc.name, abi.service_type
');
$adm->execute([$monthStart, $monthEnd]);
$admRows = $adm->fetchAll();

// Merge into one matrix: [cat_id => [name, consult, er, proc, total]].
$matrix = [];
foreach ($consultRows as $r) {
    $matrix[$r['cat_id']] = ['name' => $r['cat_name'], 'consult' => (float) $r['amt'], 'consult_n' => (int) $r['n'], 'er' => 0.0, 'er_n' => 0, 'proc' => 0.0, 'proc_n' => 0];
}
foreach ($admRows as $r) {
    if (!isset($matrix[$r['cat_id']])) {
        $matrix[$r['cat_id']] = ['name' => $r['cat_name'], 'consult' => 0.0, 'consult_n' => 0, 'er' => 0.0, 'er_n' => 0, 'proc' => 0.0, 'proc_n' => 0];
    }
    $key = $r['service_type'] === 'PROCEDURE' ? 'proc' : 'er';
    $matrix[$r['cat_id']][$key] += (float) $r['amt'];
    $matrix[$r['cat_id']][$key . '_n'] += (int) $r['n'];
}
uasort($matrix, fn($a, $b) => strcmp($a['name'], $b['name']));
$grand = ['consult' => 0.0, 'er' => 0.0, 'proc' => 0.0];
foreach ($matrix as $m) { $grand['consult'] += $m['consult']; $grand['er'] += $m['er']; $grand['proc'] += $m['proc']; }
$grandTotal = $grand['consult'] + $grand['er'] + $grand['proc'];

// ---- Drill-down: every discounted invoice of the month ----
$consultDetail = $pdo->prepare('
    SELECT v.visit_date AS d, b.invoice_number, p.name AS patient_name, p.mrn,
           du.name AS doctor_name, dc.name AS cat_name,
           v.fee, v.discount_pct, v.category_discount_pct, v.category_discount_amount AS amt
    FROM visits v
    JOIN discount_categories dc ON dc.id = v.discount_category_id
    JOIN patients p ON p.id = v.patient_id
    LEFT JOIN bills b ON b.visit_id = v.id
    LEFT JOIN users du ON du.id = v.doctor_id
    WHERE v.visit_date BETWEEN ? AND ? AND v.category_discount_amount > 0
      AND b.voided_at IS NULL
    ORDER BY v.visit_date, v.id
');
$consultDetail->execute([$monthStart, $monthEnd]);
$consultDetail = $consultDetail->fetchAll();

$admDetail = $pdo->prepare('
    SELECT DATE(ab.created_at) AS d, ab.invoice_number, p.name AS patient_name, p.mrn,
           dc.name AS cat_name,
           SUM(CASE WHEN abi.service_type = \'PROCEDURE\' THEN abi.discount_amount ELSE 0 END) AS proc_amt,
           SUM(CASE WHEN abi.service_type <> \'PROCEDURE\' OR abi.service_type IS NULL THEN abi.discount_amount ELSE 0 END) AS er_amt,
           SUM(abi.discount_amount) AS amt
    FROM admission_bill_items abi
    JOIN admission_bills ab ON ab.id = abi.admission_bill_id
    JOIN discount_categories dc ON dc.id = ab.discount_category_id
    JOIN admissions a ON a.id = ab.admission_id
    JOIN visits v ON v.id = a.visit_id
    JOIN patients p ON p.id = v.patient_id
    WHERE DATE(ab.created_at) BETWEEN ? AND ? AND abi.discount_amount > 0
      AND ab.voided_at IS NULL
    GROUP BY ab.id, d, ab.invoice_number, p.name, p.mrn, dc.name
    ORDER BY d, ab.id
');
$admDetail->execute([$monthStart, $monthEnd]);
$admDetail = $admDetail->fetchAll();

$pageTitle = 'Discount Report';
$headExtra = <<<CSS
<style>
.header { height: 72px; position: sticky; top: 0; z-index: 20; display: flex; align-items: center; justify-content: space-between; padding: 0 32px; background: rgba(255,255,255,.80); backdrop-filter: blur(18px); border-bottom: 1px solid var(--border); }
.header-right { display: flex; align-items: center; gap: 18px; margin-left: auto; }
.header-date { font-size: 13px; color: var(--text-secondary); white-space: nowrap; }
.logout-link { font-size: 13px; color: var(--text-secondary); font-weight: 500; }

.month-form { display: flex; align-items: center; gap: 10px; }
.month-form input[type=month] { padding: 9px 12px; border: 1px solid var(--border); border-radius: 10px; font: inherit; font-size: 13.5px; background: #fff; }
.month-form input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,127,126,.15); }

.kpi-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 14px; margin-bottom: 18px; }
.kpi { border: 1px solid var(--border); border-radius: 14px; padding: 16px 18px; background: var(--card); box-shadow: var(--shadow-sm); }
.kpi .k { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted); }
.kpi .v { font-size: 22px; font-weight: 800; margin-top: 4px; font-variant-numeric: tabular-nums; }
.kpi.total .v { color: var(--primary-dark); }

.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
tr.grand-row td { font-weight: 800; border-top: 2px solid var(--border); }
.cat-tag { font-size: 11px; font-weight: 700; padding: 2px 9px; border-radius: 20px; background: var(--primary-light); color: var(--primary-dark); white-space: nowrap; }
.print-btn { margin-left: 10px; }
@media print {
    .sidebar, .mobile-bar, .header, .month-form, .print-btn, .nav-group { display: none !important; }
    .main { margin: 0 !important; }
    .card { box-shadow: none !important; border: 1px solid #ccc; }
}
</style>
CSS;
require __DIR__ . '/partials/head.php';
$navActive = 'discount_report';
require __DIR__ . '/partials/sidebar.php';
?>
        <header class="header">
            <div class="page-title" style="font-size:16px;">Discount Report</div>
            <div class="header-right">
                <span class="header-date"><?= date('D, d/m/Y') ?></span>
                <a class="logout-link" href="logout.php">Logout</a>
            </div>
        </header>

        <div class="content">
            <div class="page-head">
                <div>
                    <div class="page-title">Discount Report — <?= htmlspecialchars($monthLabel) ?></div>
                    <div class="page-sub">Every discounted rupee attributed to its category, from billing-time snapshots</div>
                </div>
                <form class="month-form" method="GET" action="discount_report.php">
                    <input type="month" name="month" value="<?= htmlspecialchars($month) ?>" max="<?= date('Y-m') ?>">
                    <button type="submit" class="btn secondary">View</button>
                    <button type="button" class="btn print-btn" onclick="window.print()">Print</button>
                </form>
            </div>

            <div class="kpi-row">
                <div class="kpi total"><div class="k">Total discounted</div><div class="v">Rs <?= number_format($grandTotal) ?></div></div>
                <div class="kpi"><div class="k">Consultations</div><div class="v">Rs <?= number_format($grand['consult']) ?></div></div>
                <div class="kpi"><div class="k">ER Services</div><div class="v">Rs <?= number_format($grand['er']) ?></div></div>
                <div class="kpi"><div class="k">Procedures</div><div class="v">Rs <?= number_format($grand['proc']) ?></div></div>
            </div>

            <!-- Category × area matrix -->
            <div class="card">
                <div class="section-title">By Category</div>
                <div class="section-sub">Rupees given per category, split by billing area. Counts are discounted invoices/lines.</div>
                <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr><th>Category</th><th class="num">Consultations</th><th class="num">ER Services</th><th class="num">Procedures</th><th class="num">Total</th></tr>
                    </thead>
                    <tbody>
                        <?php if (!$matrix): ?>
                        <tr><td colspan="5" class="muted" style="padding:20px 10px;">No category discounts were given in <?= htmlspecialchars($monthLabel) ?>.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($matrix as $m): $rowTotal = $m['consult'] + $m['er'] + $m['proc']; ?>
                        <tr>
                            <td><span class="cat-tag"><?= htmlspecialchars($m['name']) ?></span></td>
                            <td class="num">Rs <?= number_format($m['consult']) ?> <span class="muted">(<?= (int) $m['consult_n'] ?>)</span></td>
                            <td class="num">Rs <?= number_format($m['er']) ?> <span class="muted">(<?= (int) $m['er_n'] ?>)</span></td>
                            <td class="num">Rs <?= number_format($m['proc']) ?> <span class="muted">(<?= (int) $m['proc_n'] ?>)</span></td>
                            <td class="num" style="font-weight:700;">Rs <?= number_format($rowTotal) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if ($matrix): ?>
                        <tr class="grand-row">
                            <td>Total</td>
                            <td class="num">Rs <?= number_format($grand['consult']) ?></td>
                            <td class="num">Rs <?= number_format($grand['er']) ?></td>
                            <td class="num">Rs <?= number_format($grand['proc']) ?></td>
                            <td class="num">Rs <?= number_format($grandTotal) ?></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>

            <!-- Consultation drill-down -->
            <div class="card">
                <div class="section-title">Discounted Consultations</div>
                <div class="section-sub"><?= count($consultDetail) ?> invoice<?= count($consultDetail) === 1 ? '' : 's' ?> carried a category discount this month</div>
                <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr><th>Date</th><th>Invoice</th><th>Patient</th><th>Doctor</th><th>Category</th><th class="num">Fee</th><th class="num">Total Disc %</th><th class="num">Category Rs</th></tr>
                    </thead>
                    <tbody>
                        <?php if (!$consultDetail): ?>
                        <tr><td colspan="8" class="muted" style="padding:20px 10px;">None this month.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($consultDetail as $r): ?>
                        <tr>
                            <td class="muted"><?= date('d/m', strtotime($r['d'])) ?></td>
                            <td class="mono"><?= htmlspecialchars($r['invoice_number'] ?? '—') ?></td>
                            <td style="font-weight:600;"><?= htmlspecialchars($r['patient_name']) ?> <span class="muted mono"><?= htmlspecialchars($r['mrn']) ?></span></td>
                            <td class="muted"><?= htmlspecialchars($r['doctor_name'] ?? '—') ?></td>
                            <td><span class="cat-tag"><?= htmlspecialchars($r['cat_name']) ?></span></td>
                            <td class="num">Rs <?= number_format((float) $r['fee']) ?></td>
                            <td class="num"><?= rtrim(rtrim(number_format((float) $r['discount_pct'], 2), '0'), '.') ?>%</td>
                            <td class="num" style="font-weight:700;">Rs <?= number_format((float) $r['amt']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>

            <!-- Admission drill-down -->
            <div class="card">
                <div class="section-title">Discounted Admission Bills</div>
                <div class="section-sub"><?= count($admDetail) ?> admission bill<?= count($admDetail) === 1 ? '' : 's' ?> carried service/procedure discounts this month</div>
                <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr><th>Date</th><th>Invoice</th><th>Patient</th><th>Category</th><th class="num">ER Services Rs</th><th class="num">Procedures Rs</th><th class="num">Total Rs</th></tr>
                    </thead>
                    <tbody>
                        <?php if (!$admDetail): ?>
                        <tr><td colspan="7" class="muted" style="padding:20px 10px;">None this month.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($admDetail as $r): ?>
                        <tr>
                            <td class="muted"><?= date('d/m', strtotime($r['d'])) ?></td>
                            <td class="mono"><?= htmlspecialchars($r['invoice_number']) ?></td>
                            <td style="font-weight:600;"><?= htmlspecialchars($r['patient_name']) ?> <span class="muted mono"><?= htmlspecialchars($r['mrn']) ?></span></td>
                            <td><span class="cat-tag"><?= htmlspecialchars($r['cat_name']) ?></span></td>
                            <td class="num">Rs <?= number_format((float) $r['er_amt']) ?></td>
                            <td class="num">Rs <?= number_format((float) $r['proc_amt']) ?></td>
                            <td class="num" style="font-weight:700;">Rs <?= number_format((float) $r['amt']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
