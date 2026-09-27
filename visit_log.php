<?php
/**
 * Visit Log — every invoice raised across the clinic, admin-wide.
 *
 * Reads shift_events (event_type = 'INVOICE'), the single structured event
 * log every invoice write site feeds via config/billing.php's
 * log_shift_event() (see sql/add_shift_events.sql). Filterable by date
 * range, doctor, registering/billing staff, stream (OPD/ER/IPD/Procedure/
 * Dental) and status (paid/pending/voided/partial — denormalized on the row
 * at write time and kept in sync by the void_*() helpers).
 *
 * Admin-only: gated the same way as discount_report.php / income_report.php.
 */
require_once __DIR__ . '/config/guard_admin.php';
require_once __DIR__ . '/config/billing.php';

$isDate = fn($s) => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $s ?? '');
$from = $isDate($_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
$to   = $isDate($_GET['to'] ?? '')   ? $_GET['to']   : date('Y-m-d');
if ($from > $to) { [$from, $to] = [$to, $from]; }

$doctorId = (int) ($_GET['doctor_id'] ?? 0);
$staffId  = (int) ($_GET['staff_id'] ?? 0);
$stream   = in_array($_GET['stream'] ?? '', ['OPD', 'ER', 'IPD', 'PROCEDURE', 'DENTAL'], true) ? $_GET['stream'] : '';
$status   = in_array($_GET['status'] ?? '', ['paid', 'pending', 'voided', 'partial'], true) ? $_GET['status'] : '';
$search   = trim((string) ($_GET['q'] ?? ''));

$STREAM_LABELS = ['OPD' => 'OPD', 'ER' => 'ER Service', 'IPD' => 'In-Door', 'PROCEDURE' => 'Procedure', 'DENTAL' => 'Dental'];
$STATUS_LABELS = ['paid' => 'Paid', 'pending' => 'Pending', 'voided' => 'Voided', 'partial' => 'Partial'];

$sql = "
    SELECT se.id, se.occurred_at, se.business_date, se.amount, se.invoice_number, se.status, se.stream,
           p.name AS patient_name, p.mrn,
           du.name AS doctor_name,
           su.name AS staff_name
    FROM shift_events se
    JOIN patients p ON p.id = se.patient_id
    LEFT JOIN users du ON du.id = se.doctor_id
    LEFT JOIN users su ON su.id = se.actor_id
    WHERE se.event_type = 'INVOICE' AND se.business_date BETWEEN ? AND ?
";
$params = [$from, $to];
if ($doctorId > 0) { $sql .= " AND se.doctor_id = ?"; $params[] = $doctorId; }
if ($staffId > 0)  { $sql .= " AND se.actor_id = ?";  $params[] = $staffId; }
if ($stream !== '') { $sql .= " AND se.stream = ?";   $params[] = $stream; }
if ($status !== '') { $sql .= " AND se.status = ?";   $params[] = $status; }
if ($search !== '') {
    $sql .= " AND (p.name LIKE ? OR p.mrn LIKE ? OR se.invoice_number LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
$sql .= " ORDER BY se.occurred_at DESC, se.id DESC LIMIT 500";
$q = $pdo->prepare($sql);
$q->execute($params);
$rows = $q->fetchAll();

// Summary strip. A separate lightweight query (no LIMIT) so the KPIs reflect
// the whole filtered range, not just the 500 rows rendered below.
$sumSql = "SELECT COUNT(*) AS n, COUNT(DISTINCT se.patient_id) AS patients,
                  SUM(CASE WHEN se.status IN ('pending','partial') THEN 1 ELSE 0 END) AS unpaid,
                  SUM(CASE WHEN se.status <> 'voided' THEN se.amount ELSE 0 END) AS total
           FROM shift_events se JOIN patients p ON p.id = se.patient_id
           WHERE se.event_type = 'INVOICE' AND se.business_date BETWEEN ? AND ?";
$sumParams = [$from, $to];
if ($doctorId > 0) { $sumSql .= " AND se.doctor_id = ?"; $sumParams[] = $doctorId; }
if ($staffId > 0)  { $sumSql .= " AND se.actor_id = ?";  $sumParams[] = $staffId; }
if ($stream !== '') { $sumSql .= " AND se.stream = ?";   $sumParams[] = $stream; }
if ($status !== '') { $sumSql .= " AND se.status = ?";   $sumParams[] = $status; }
if ($search !== '') {
    $sumSql .= " AND (p.name LIKE ? OR p.mrn LIKE ? OR se.invoice_number LIKE ?)";
    $sumParams[] = "%$search%"; $sumParams[] = "%$search%"; $sumParams[] = "%$search%";
}
$sumStmt = $pdo->prepare($sumSql);
$sumStmt->execute($sumParams);
$summary = $sumStmt->fetch() ?: ['n' => 0, 'patients' => 0, 'unpaid' => 0, 'total' => 0];

$doctorList = $pdo->query("SELECT id, name FROM users WHERE base_role = 'DOCTOR' ORDER BY name")->fetchAll();
$staffList = $pdo->query("SELECT DISTINCT u.id, u.name FROM users u JOIN shift_events se ON se.actor_id = u.id ORDER BY u.name")->fetchAll();

$pageTitle = 'Visit Log';
$headExtra = <<<CSS
<style>
.vl-filterbar { display: flex; flex-direction: column; gap: 10px; background: var(--card); border: 1px solid var(--border); border-radius: 14px; padding: 14px; }
.vl-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.vl-row input[type=date], .vl-row select, .vl-row input[type=text] { padding: 8px 11px; border: 1px solid var(--border); border-radius: 9px; font: inherit; font-size: 12.5px; background: #fff; }
.vl-row input[type=text] { flex: 1; min-width: 180px; }
.vl-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.vl-stream { font-weight: 700; font-size: 11px; padding: 3px 9px; border-radius: 7px; display: inline-block; white-space: nowrap; }
.vl-stream-OPD { background: var(--primary-light); color: var(--primary-dark); }
.vl-stream-ER { background: var(--danger-bg); color: var(--danger); }
.vl-stream-IPD { background: #eef0fb; color: #4c5bb2; }
.vl-stream-DENTAL { background: #f3ecfa; color: #7c4ea8; }
.vl-stream-PROCEDURE { background: #fdf0e3; color: #c07018; }
.kpi-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 14px; }
.kpi { border: 1px solid var(--border); border-radius: 14px; padding: 16px 18px; background: var(--card); box-shadow: var(--shadow-sm); }
.kpi .k { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted); }
.kpi .v { font-size: 22px; font-weight: 800; margin-top: 4px; font-variant-numeric: tabular-nums; }
.kpi.total .v { color: var(--primary-dark); }
</style>
CSS;
require __DIR__ . '/partials/head.php';
$navActive = 'visit_log';
require __DIR__ . '/partials/sidebar.php';
?>
<div class="content">
    <div class="page-head">
        <div>
            <div class="page-title">Visit Log</div>
            <div class="page-sub">Every invoice raised across the clinic &mdash; filter by date, doctor, registering staff, or invoice type</div>
        </div>
    </div>

    <form method="GET" action="visit_log.php" class="vl-filterbar">
        <div class="vl-row">
            <input type="date" name="from" value="<?= htmlspecialchars($from) ?>">
            <span class="muted">to</span>
            <input type="date" name="to" value="<?= htmlspecialchars($to) ?>">
            <select name="doctor_id">
                <option value="0">All doctors</option>
                <?php foreach ($doctorList as $d): ?>
                <option value="<?= (int) $d['id'] ?>" <?= $doctorId === (int) $d['id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="staff_id">
                <option value="0">All staff</option>
                <?php foreach ($staffList as $s): ?>
                <option value="<?= (int) $s['id'] ?>" <?= $staffId === (int) $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="stream">
                <option value="">All types</option>
                <?php foreach ($STREAM_LABELS as $key => $label): ?>
                <option value="<?= $key ?>" <?= $stream === $key ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
            <select name="status">
                <option value="">All statuses</option>
                <?php foreach ($STATUS_LABELS as $key => $label): ?>
                <option value="<?= $key ?>" <?= $status === $key ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="vl-row">
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search patient, MRN or invoice #&hellip;">
            <button type="submit" class="btn secondary">Apply</button>
            <a href="visit_log.php" class="btn">Clear filters</a>
        </div>
    </form>

    <div class="kpi-row">
        <div class="kpi total"><div class="k">Invoices</div><div class="v"><?= (int) $summary['n'] ?></div></div>
        <div class="kpi"><div class="k">Unique patients</div><div class="v"><?= (int) $summary['patients'] ?></div></div>
        <div class="kpi"><div class="k">Pending / unpaid</div><div class="v"><?= (int) $summary['unpaid'] ?></div></div>
        <div class="kpi"><div class="k">Total billed</div><div class="v">Rs <?= number_format((float) $summary['total']) ?></div></div>
    </div>

    <div class="card">
        <div style="overflow-x:auto;">
        <table>
            <thead>
                <tr><th>Invoice #</th><th>Patient</th><th>Type</th><th>Doctor</th><th>Registered by</th><th class="vl-num">Fee</th><th>Status</th></tr>
            </thead>
            <tbody>
                <?php if (!$rows): ?>
                <tr><td colspan="7" class="empty">No invoices match these filters.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="mono"><?= htmlspecialchars($r['invoice_number'] ?? '—') ?></td>
                    <td style="font-weight:600;"><?= htmlspecialchars($r['patient_name']) ?>
                        <div class="muted mono" style="font-size:11.5px;font-weight:400;"><?= htmlspecialchars($r['mrn']) ?></div>
                    </td>
                    <td><span class="vl-stream vl-stream-<?= htmlspecialchars($r['stream']) ?>"><?= htmlspecialchars($STREAM_LABELS[$r['stream']] ?? $r['stream']) ?></span></td>
                    <td class="muted"><?= htmlspecialchars($r['doctor_name'] ?? '—') ?></td>
                    <td class="muted"><?= htmlspecialchars($r['staff_name'] ?? '—') ?></td>
                    <td class="vl-num">Rs <?= number_format((float) $r['amount']) ?></td>
                    <td><span class="status-pill <?= htmlspecialchars($r['status']) ?>"><?= htmlspecialchars($STATUS_LABELS[$r['status']] ?? $r['status']) ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <div style="padding:12px 16px;font-size:12px;color:var(--text-muted);display:flex;justify-content:space-between;">
            <span>Showing <?= count($rows) ?> of <?= (int) $summary['n'] ?> invoice<?= (int) $summary['n'] === 1 ? '' : 's' ?><?= count($rows) >= 500 ? ' (capped at 500 — narrow the filters to see more)' : '' ?></span>
            <span>Sorted by visit date, newest first</span>
        </div>
    </div>
</div>
    </div>
</div>
</body>
</html>
