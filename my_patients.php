<?php
/**
 * My Patients — a doctor's own patient/visit history, date-filterable.
 *
 * Reads shift_events (event_type = 'INVOICE', stream = 'OPD') filtered to
 * doctor_id = the signed-in doctor, rather than re-deriving from visits/bills
 * directly — shift_events is the single structured event log every invoice
 * write site now feeds (see config/billing.php's log_shift_event(), and
 * sql/add_shift_events.sql). Kept deliberately clinical (no money emphasis
 * beyond the summary strip) — this is "who did I see", not a billing report.
 *
 * DOCTOR only. "View" is a placeholder for now — it will open the patient's
 * full record + past visits once that page exists.
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
refresh_session_permissions($pdo);
require_once __DIR__ . '/config/billing.php';

// Unconditional for every DOCTOR — not gated behind a revocable permission.
// "My Patients" is a doctor's own record of who they saw; it should never
// depend on an admin having granted (or a stray override having revoked) a
// permission key.
$baseRole = $_SESSION['base_role'] ?? '';
if ($baseRole !== 'DOCTOR') {
    http_response_code(403);
    exit('Forbidden — doctor console only.');
}
$doctorId = (int) $_SESSION['user_id'];

$docStmt = $pdo->prepare('SELECT name, specialty FROM users WHERE id = ?');
$docStmt->execute([$doctorId]);
$docRow = $docStmt->fetch() ?: [];
$dsUserName = (string) ($docRow['name'] ?? '');
$dsSpecialty = (string) ($docRow['specialty'] ?? '');

// ---- Date range: quick chips (today / 7d / this month / all time) or an
// explicit from/to pair, mirroring expense_report.php's range mode. ----
$isDate = fn($s) => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $s ?? '');
$range = in_array($_GET['range'] ?? '', ['today', '7d', 'month', 'all'], true) ? $_GET['range'] : '';
$today = date('Y-m-d');
if ($range === 'today') {
    $from = $to = $today;
} elseif ($range === '7d') {
    $from = date('Y-m-d', strtotime('-6 days'));
    $to = $today;
} elseif ($range === 'all') {
    $from = '2000-01-01';
    $to = $today;
} elseif ($isDate($_GET['from'] ?? '') || $isDate($_GET['to'] ?? '')) {
    $from = $isDate($_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
    $to   = $isDate($_GET['to'] ?? '')   ? $_GET['to']   : $today;
    if ($from > $to) { [$from, $to] = [$to, $from]; }
    $range = '';
} else {
    // Default landing view: this month.
    $from = date('Y-m-01');
    $to = $today;
    $range = 'month';
}
$search = trim((string) ($_GET['q'] ?? ''));

$sql = "
    SELECT se.id, se.event_type, se.note, se.occurred_at, se.business_date, se.amount, se.invoice_number, se.status,
           p.name AS patient_name, p.mrn, p.dob, p.gender,
           ob.invoice_number AS orig_invoice
    FROM shift_events se
    JOIN patients p ON p.id = se.patient_id
    LEFT JOIN refunds rf ON se.event_type = 'REFUND' AND se.reference_type = 'refund' AND rf.id = se.reference_id
    LEFT JOIN bills ob ON ob.id = rf.bill_id
    WHERE (se.event_type = 'REFUND' OR (se.stream = 'OPD' AND se.event_type IN ('INVOICE', 'DISCOUNT')))
      AND se.doctor_id = ? AND se.business_date BETWEEN ? AND ?
";
$params = [$doctorId, $from, $to];
if ($search !== '') {
    $sql .= " AND (p.name LIKE ? OR p.mrn LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
$sql .= " ORDER BY se.occurred_at DESC, se.id DESC";
$q = $pdo->prepare($sql);
$q->execute($params);
$rows = $q->fetchAll();

// Summary strip.
// Refund rows are money going back, not visits: they stay out of the visit
// counts, but net off the fee total (their amount is already negative) unless
// the refund was voided. DISCOUNT rows are informational only — the INVOICE
// amount is already what was collected after the discount, so summing them
// would double-count.
$visitRows = array_filter($rows, fn($r) => $r['event_type'] === 'INVOICE');
$uniquePatients = count(array_unique(array_map(fn($r) => $r['mrn'], $visitRows)));
$totalVisits = count($visitRows);
$feesEarned = 0.0;
$refundsTotal = 0.0;
foreach ($rows as $r) {
    if ($r['event_type'] === 'INVOICE') {
        $feesEarned += (float) $r['amount'];
    } elseif ($r['event_type'] === 'REFUND' && $r['status'] !== 'voided') {
        $feesEarned += (float) $r['amount'];
        $refundsTotal += -(float) $r['amount'];
    }
}

// Nest refunds + discounts under the invoice they belong to. A child whose
// invoice isn't in this result set (other day outside the range) can't be
// nested: a refund then stands alone as its own row (it is still money moved
// on its day); an orphan discount is dropped (informational anyway).
$invoiceIdx = [];
foreach ($rows as $i => $r) {
    if ($r['event_type'] === 'INVOICE' && $r['invoice_number'] !== null) {
        $invoiceIdx[$r['invoice_number']] = $i;
    }
}
$kids = [];       // parent row index => list of child rows
$nested = [];     // row index => true when rendered under a parent
foreach ($rows as $i => $r) {
    if ($r['event_type'] === 'INVOICE') { continue; }
    $parentKey = $r['event_type'] === 'REFUND' ? $r['orig_invoice'] : $r['invoice_number'];
    if ($parentKey !== null && isset($invoiceIdx[$parentKey])) {
        $kids[$invoiceIdx[$parentKey]][] = $r;
        $nested[$i] = true;
    } elseif ($r['event_type'] === 'DISCOUNT') {
        $nested[$i] = true; // dropped
    }
}

// Group top-level rows by business_date for the day-separator presentation.
$byDay = [];
foreach ($rows as $i => $r) {
    if (isset($nested[$i])) { continue; }
    $r['_i'] = $i;
    $byDay[$r['business_date']][] = $r;
}

function my_patients_age(?string $dob): string {
    if (!$dob) { return ''; }
    $d = strtotime($dob);
    if (!$d) { return ''; }
    return (string) (int) date('Y', time() - $d + strtotime('1970-01-01'));
}

$pageTitle = 'My Patients';
$headExtra = <<<CSS
<style>
.mp-filterbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.mp-chips { display: flex; gap: 6px; background: var(--neutral-bg); border-radius: 10px; padding: 3px; }
.mp-chip { border: 0; background: transparent; padding: 7px 13px; border-radius: 8px; font-size: 12.5px; font-weight: 600; color: var(--text-muted); cursor: pointer; text-decoration: none; }
.mp-chip.active { background: var(--card); color: var(--primary-dark); box-shadow: var(--shadow-sm); }
.mp-search { margin-left: auto; }
.mp-search input { padding: 8px 12px; border: 1px solid var(--border); border-radius: 9px; font: inherit; font-size: 13px; min-width: 200px; }
.mp-day-sep td { background: var(--neutral-bg); font-size: 11px; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; color: var(--text-muted); padding: 8px 16px; }
.mp-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
/* Refund / discount lines nested under their invoice. */
tr.mp-has-kids > td { border-bottom: 0; }
tr.mp-sub > td { padding: 5px 16px 6px 36px; font-size: 12.5px; color: var(--text-muted); }
tr.mp-sub.mp-net > td { padding-top: 3px; padding-bottom: 10px; }
tr.mp-sub.mp-net b { color: var(--text, inherit); }
tr.mp-sub.void { opacity: .55; }
tr.mp-sub > td { border-bottom: 0; }
tr.mp-sub.mp-net > td { border-bottom: 1px solid var(--border); }
.mp-neg { color: #A9111B; }
.mp-disc { color: #8A6410; }

.kpi-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 14px; }
.kpi { border: 1px solid var(--border); border-radius: 14px; padding: 16px 18px; background: var(--card); box-shadow: var(--shadow-sm); }
.kpi .k { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted); }
.kpi .v { font-size: 22px; font-weight: 800; margin-top: 4px; font-variant-numeric: tabular-nums; }
.kpi.total .v { color: var(--primary-dark); }
</style>
CSS;
require __DIR__ . '/partials/head.php';
$dsActive = 'my_patients';
$dsWaitingCount = 0;
?>
<div class="app">
<?php require __DIR__ . '/partials/doctor_sidebar.php'; ?>
<div class="main">
<?php require __DIR__ . '/partials/quick_header.php'; ?>

<div class="content">
    <div class="page-head">
        <div>
            <div class="page-title">My Patients</div>
            <div class="page-sub">Every patient you've seen, by visit date</div>
        </div>
    </div>

    <form method="GET" action="my_patients.php" class="mp-filterbar">
        <div class="mp-chips">
            <a class="mp-chip <?= $range === 'today' ? 'active' : '' ?>" href="?range=today">Today</a>
            <a class="mp-chip <?= $range === '7d' ? 'active' : '' ?>" href="?range=7d">7 Days</a>
            <a class="mp-chip <?= $range === 'month' ? 'active' : '' ?>" href="?range=month">This Month</a>
            <a class="mp-chip <?= $range === 'all' ? 'active' : '' ?>" href="?range=all">All Time</a>
        </div>
        <input type="date" name="from" value="<?= htmlspecialchars($from) ?>">
        <span class="muted">to</span>
        <input type="date" name="to" value="<?= htmlspecialchars($to) ?>">
        <button type="submit" class="btn secondary">Apply</button>
        <div class="mp-search">
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search by name or MRN&hellip;">
        </div>
    </form>

    <div class="kpi-row">
        <div class="kpi total"><div class="k">Patients seen</div><div class="v"><?= $uniquePatients ?></div></div>
        <div class="kpi"><div class="k">Total visits</div><div class="v"><?= $totalVisits ?></div></div>
        <div class="kpi"><div class="k">Fees (net of refunds)</div><div class="v">Rs <?= number_format($feesEarned) ?></div></div>
        <div class="kpi"><div class="k">Refunds given</div><div class="v mp-neg">Rs <?= number_format($refundsTotal) ?></div></div>
    </div>

    <div class="card">
        <div style="overflow-x:auto;">
        <table>
            <thead>
                <tr><th>Patient</th><th>Invoice</th><th class="mp-num">Fee</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
                <?php if (!$rows): ?>
                <tr><td colspan="5" class="empty">No visits in this range.</td></tr>
                <?php endif; ?>
                <?php foreach ($byDay as $day => $dayRows): ?>
                <tr class="mp-day-sep"><td colspan="5"><?= date('l, d M Y', strtotime($day)) ?></td></tr>
                <?php foreach ($dayRows as $r): $age = my_patients_age($r['dob']); ?>
                <?php
                $isRefund = $r['event_type'] === 'REFUND';
                $myKids = $kids[$r['_i']] ?? [];
                $refunded = 0.0; $discounted = 0.0;
                foreach ($myKids as $k) {
                    if ($k['status'] === 'voided') { continue; }
                    if ($k['event_type'] === 'REFUND') { $refunded += -(float) $k['amount']; }
                    else { $discounted += -(float) $k['amount']; }
                }
                $net = (float) $r['amount'] - $refunded;
                ?>
                <tr<?= $isRefund ? ' style="background:var(--red-bg, #F9D9D6);' . ($r['status'] === 'voided' ? 'opacity:.55;' : '') . '"' : '' ?><?= $myKids ? ' class="mp-has-kids"' : '' ?>>
                    <td style="font-weight:600;"><?= htmlspecialchars($r['patient_name']) ?>
                        <?php if ($isRefund): ?><span class="muted" style="font-size:11px;font-weight:700;color:#A9111B;">REFUND<?= $r['orig_invoice'] ? ' of ' . htmlspecialchars($r['orig_invoice']) : '' ?><?= $r['note'] ? ' &middot; ' . htmlspecialchars($r['note']) : '' ?></span><?php endif; ?>
                        <div class="muted mono" style="font-size:11.5px;"><?= htmlspecialchars($r['mrn']) ?><?= $age !== '' ? " &middot; {$age}y" : '' ?><?= $r['gender'] ? ' &middot; ' . htmlspecialchars($r['gender']) : '' ?></div>
                    </td>
                    <td class="mono"><?= htmlspecialchars($r['invoice_number'] ?? '—') ?></td>
                    <td class="mp-num">Rs <?= number_format((float) $r['amount']) ?></td>
                    <td><span class="status-pill <?= htmlspecialchars($r['status']) ?>"><?= htmlspecialchars(ucfirst($r['status'])) ?></span></td>
                    <td><span class="muted" title="Patient record view is coming soon">View</span></td>
                </tr>
                <?php foreach ($myKids as $k): $kRef = $k['event_type'] === 'REFUND'; $kVoid = $k['status'] === 'voided'; ?>
                <tr class="mp-sub<?= $kVoid ? ' void' : '' ?>">
                    <td colspan="5">
                        <?php if ($kRef): ?>
                        &#8617; Refunded <b class="mp-neg">&minus;Rs <?= number_format(-(float) $k['amount']) ?></b>
                        <?php if ($k['business_date'] !== $r['business_date']): ?>on <?= date('d M', strtotime($k['business_date'])) ?> <?php endif; ?>at <?= date('g:i A', strtotime($k['occurred_at'])) ?>
                        &middot; <span class="mono"><?= htmlspecialchars($k['invoice_number'] ?? '') ?></span>
                        <?= $k['note'] ? '&middot; ' . htmlspecialchars($k['note']) : '' ?>
                        <?php else: ?>
                        % Discount <b class="mp-disc">&minus;Rs <?= number_format(-(float) $k['amount']) ?></b>
                        <?= $k['note'] ? '&middot; ' . htmlspecialchars($k['note']) : '' ?>
                        <?php endif; ?>
                        <?= $kVoid ? '&middot; <i>voided</i>' : '' ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if ($refunded > 0): ?>
                <tr class="mp-sub mp-net">
                    <td colspan="3">Net received: <b>Rs <?= number_format(max($net, 0)) ?></b></td>
                    <td colspan="2"><span class="status-pill pending"><?= $net <= 0 ? 'Fully refunded' : 'Partially refunded' ?></span></td>
                </tr>
                <?php elseif ($myKids): ?>
                <tr class="mp-sub mp-net">
                    <td colspan="3">Net received: <b>Rs <?= number_format($net) ?></b></td>
                    <td colspan="2"><?php if ($discounted > 0): ?><span class="status-pill pending">Discounted</span><?php endif; ?></td>
                </tr>
                <?php endif; ?>
                <?php endforeach; ?>
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
