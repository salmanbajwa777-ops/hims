<?php
/**
 * Tax Deposit Register — per doctor, per month. (Accounts Phase 6, 2026-07-26)
 *
 * What the clinic withheld and must deposit, for whoever does the filing.
 *
 * READS SETTLED PAYOUTS, NOT LIVE TABLES. A filing must not drift: recomputing
 * from bills would change a past month whenever something is voided or a
 * doctor's rate is edited. doctor_payouts froze those figures at payout time,
 * so this register reports exactly what was withheld against money actually
 * paid — which is what a return needs.
 *
 * SELF-DEPOSITING DOCTORS are listed as such, never as a zero row. DR SALMAN A
 * BAJWA deposits his own tax (consult_has_tax = 0); a bare zero would read as
 * missing data and invite someone to start withholding tax the clinic does not
 * owe.
 *
 * CNIC/NTN are deliberately absent — handled manually outside the system.
 *
 * Gated on FINANCIAL_VIEW_ALL_COMMISSIONS.
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
refresh_session_permissions($pdo);
require_permission('FINANCIAL_VIEW_ALL_COMMISSIONS');

$year = preg_match('/^\d{4}$/', $_GET['year'] ?? '') ? (int) $_GET['year'] : (int) date('Y');

// Settled payouts only. A draft has not paid anything, so it has withheld
// nothing — including it would overstate the deposit due.
$rows = []; $ready = true;
try {
    $q = $pdo->prepare("
        SELECT u.id AS doctor_id, u.name AS doctor_name,
               u.consult_has_tax,
               DATE_FORMAT(p.period_end, '%Y-%m') AS ym,
               COALESCE(SUM(p.gross_amount), 0)  AS gross,
               COALESCE(SUM(p.tax_withheld), 0)  AS tax,
               COALESCE(SUM(p.net_paid), 0)      AS net,
               COUNT(*)                          AS payouts
        FROM doctor_payouts p
        JOIN users u ON u.id = p.doctor_id
        WHERE p.status = 'paid' AND YEAR(p.period_end) = ?
        GROUP BY u.id, u.name, u.consult_has_tax, ym
        ORDER BY u.name, ym
    ");
    $q->execute([$year]);
    $rows = $q->fetchAll();
} catch (PDOException $e) { $ready = false; }

// Pivot into doctor -> month, so a doctor reads as one row across the year.
$byDoctor = []; $byMonth = []; $totalTax = $totalGross = $totalNet = 0.0;
foreach ($rows as $r) {
    $id = (int) $r['doctor_id'];
    if (!isset($byDoctor[$id])) {
        $byDoctor[$id] = ['name' => $r['doctor_name'], 'self' => (int) $r['consult_has_tax'] === 0,
                          'months' => [], 'gross' => 0.0, 'tax' => 0.0, 'net' => 0.0];
    }
    $byDoctor[$id]['months'][$r['ym']] = ['gross' => (float) $r['gross'], 'tax' => (float) $r['tax'], 'net' => (float) $r['net']];
    $byDoctor[$id]['gross'] += (float) $r['gross'];
    $byDoctor[$id]['tax']   += (float) $r['tax'];
    $byDoctor[$id]['net']   += (float) $r['net'];
    $byMonth[$r['ym']] = ($byMonth[$r['ym']] ?? 0) + (float) $r['tax'];
    $totalGross += (float) $r['gross'];
    $totalTax   += (float) $r['tax'];
    $totalNet   += (float) $r['net'];
}
ksort($byMonth);

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="tax-register-' . $year . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Tax Deposit Register', $year]);
    fputcsv($out, ['Basis', 'Settled payouts only — tax withheld against money actually paid']);
    fputcsv($out, []);
    fputcsv($out, ['Doctor', 'Month', 'Gross fees (Rs)', 'Tax withheld (Rs)', 'Net paid (Rs)']);
    foreach ($byDoctor as $d) {
        foreach ($d['months'] as $ym => $m) {
            fputcsv($out, [$d['name'] . ($d['self'] ? ' (self-deposits)' : ''), $ym,
                           number_format($m['gross'], 2, '.', ''),
                           number_format($m['tax'], 2, '.', ''),
                           number_format($m['net'], 2, '.', '')]);
        }
    }
    fputcsv($out, []);
    fputcsv($out, ['TOTAL', $year, number_format($totalGross, 2, '.', ''), number_format($totalTax, 2, '.', ''), number_format($totalNet, 2, '.', '')]);
    fclose($out);
    exit;
}

$pageTitle = 'Tax Register';
$headExtra = <<<CSS
<style>
.header { height: 72px; position: sticky; top: 0; z-index: 20; display: flex; align-items: center; justify-content: space-between; padding: 0 32px; background: rgba(255,255,255,.80); backdrop-filter: blur(18px); border-bottom: 1px solid var(--border); }
.header-right { display: flex; align-items: center; gap: 18px; margin-left: auto; }
.header-date { font-size: 13px; color: var(--text-secondary); white-space: nowrap; }
.logout-link { font-size: 13px; color: var(--text-secondary); font-weight: 500; }
.month-form { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.month-form input { padding: 9px 12px; border: 1px solid var(--border); border-radius: 10px; font: inherit; font-size: 13.5px; background: #fff; width: 110px; }
.kpi-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 14px; margin-bottom: 18px; }
.kpi { border: 1px solid var(--border); border-radius: 14px; padding: 16px 18px; background: var(--card); box-shadow: var(--shadow-sm); }
.kpi .k { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted); }
.kpi .v { font-size: 22px; font-weight: 800; margin-top: 4px; font-variant-numeric: tabular-nums; }
.kpi .sub { font-size: 11.5px; color: var(--text-muted); margin-top: 3px; }
.kpi.total .v { color: var(--primary-dark); }
.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
tr.grand-row td { font-weight: 800; border-top: 2px solid var(--border); }
tr.doc-row td { font-weight: 700; background: rgba(0,0,0,.02); }
.self { font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 20px; background: rgba(100,116,139,.15); color: #475569; }
.note { border-left: 3px solid var(--primary); padding: 10px 14px; background: var(--primary-light, rgba(26,127,126,.08)); border-radius: 0 10px 10px 0; font-size: 12.5px; line-height: 1.55; margin-bottom: 14px; }
.warn-note { border-left: 3px solid #b54708; background: #fffaeb; padding: 10px 14px; border-radius: 0 10px 10px 0; font-size: 12.5px; margin-bottom: 14px; }
@media print {
    .sidebar, .mobile-bar, .header, .month-form, .print-btn, .nav-group, .appbar { display: none !important; }
    .main { margin: 0 !important; }
    .card { box-shadow: none !important; border: 1px solid #ccc; break-inside: avoid; }
}
</style>
CSS;
require __DIR__ . '/partials/head.php';
$navActive = 'tax_register';
require __DIR__ . '/partials/sidebar.php';
$money = function ($n) { return 'Rs ' . number_format($n); };
?>
        <header class="header">
            <div class="page-title" style="font-size:16px;">Tax Register</div>
            <div class="header-right">
                <span class="header-date"><?= date('D, d/m/Y') ?></span>
                <a class="logout-link" href="logout.php">Logout</a>
            </div>
        </header>

        <div class="content">
            <div class="page-head">
                <div>
                    <div class="page-title">Tax Deposit Register — <?= $year ?></div>
                    <div class="page-sub">Withheld from doctors' shares and payable by the clinic</div>
                </div>
                <form class="month-form" method="GET" action="tax_register.php">
                    <input type="number" name="year" value="<?= $year ?>" min="2020" max="<?= (int) date('Y') ?>">
                    <button type="submit" class="btn secondary">View</button>
                    <a class="btn secondary" href="tax_register.php?year=<?= $year ?>&amp;export=csv">CSV</a>
                    <button type="button" class="btn print-btn" onclick="window.print()">Print</button>
                </form>
            </div>

            <?php if (!$ready): ?>
            <div class="warn-note">
                <strong>Not set up yet.</strong> Run <code>sql/add_doctor_payouts.sql</code>, then settle a
                payout — this register reports from settled payouts, not from live bills.
            </div>
            <?php endif; ?>

            <div class="kpi-row">
                <div class="kpi total">
                    <div class="k">Tax withheld <?= $year ?></div>
                    <div class="v"><?= $money($totalTax) ?></div>
                    <div class="sub">to deposit</div>
                </div>
                <div class="kpi">
                    <div class="k">Gross fees</div>
                    <div class="v"><?= $money($totalGross) ?></div>
                    <div class="sub">the tax was taken from</div>
                </div>
                <div class="kpi">
                    <div class="k">Net paid to doctors</div>
                    <div class="v"><?= $money($totalNet) ?></div>
                </div>
                <div class="kpi">
                    <div class="k">Doctors</div>
                    <div class="v"><?= count($byDoctor) ?></div>
                    <div class="sub">with settled payouts</div>
                </div>
            </div>

            <div class="note">
                Figures come from <strong>settled payouts</strong>, not from live bills. A payout freezes what was
                withheld at the moment it was paid, so a bill voided later — or a change to a doctor's
                percentage — cannot alter a month you have already filed.
            </div>

            <div class="card">
                <div class="section-title">By Doctor and Month</div>
                <div class="section-sub">Tax withheld is what the clinic deposits on the doctor's behalf.</div>
                <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr><th>Doctor / month</th><th class="num">Gross fees</th><th class="num">Tax withheld</th><th class="num">Net paid</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($byDoctor as $d): ?>
                        <tr class="doc-row">
                            <td><?= htmlspecialchars($d['name']) ?>
                                <?php if ($d['self']): ?><span class="self">self-deposits</span><?php endif; ?></td>
                            <td class="num"><?= $money($d['gross']) ?></td>
                            <td class="num"><?= $d['self'] ? '<span class="muted">—</span>' : $money($d['tax']) ?></td>
                            <td class="num"><?= $money($d['net']) ?></td>
                        </tr>
                        <?php foreach ($d['months'] as $ym => $m): ?>
                        <tr>
                            <td style="padding-left:26px;" class="muted"><?= date('F Y', strtotime($ym . '-01')) ?></td>
                            <td class="num"><?= $money($m['gross']) ?></td>
                            <td class="num"><?= $d['self'] ? '<span class="muted">—</span>' : $money($m['tax']) ?></td>
                            <td class="num"><?= $money($m['net']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endforeach; ?>
                        <?php if (!$byDoctor): ?>
                        <tr><td colspan="4" class="muted" style="padding:20px 10px;">No settled payouts in <?= $year ?>.</td></tr>
                        <?php endif; ?>
                        <?php if ($byDoctor): ?>
                        <tr class="grand-row">
                            <td>Total <?= $year ?></td>
                            <td class="num"><?= $money($totalGross) ?></td>
                            <td class="num"><?= $money($totalTax) ?></td>
                            <td class="num"><?= $money($totalNet) ?></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>

            <?php if ($byMonth): ?>
            <div class="card">
                <div class="section-title">Monthly Deposit Summary</div>
                <div class="section-sub">All doctors combined — the figure to deposit for each month.</div>
                <div style="overflow-x:auto;">
                <table>
                    <thead><tr><th>Month</th><th class="num">Tax to deposit</th></tr></thead>
                    <tbody>
                        <?php foreach ($byMonth as $ym => $t): ?>
                        <tr><td><?= date('F Y', strtotime($ym . '-01')) ?></td><td class="num"><?= $money($t) ?></td></tr>
                        <?php endforeach; ?>
                        <tr class="grand-row"><td>Total</td><td class="num"><?= $money($totalTax) ?></td></tr>
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
