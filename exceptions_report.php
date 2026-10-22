<?php
/**
 * Revenue Exceptions — read-only review list for SHORT-STAY ER admissions.
 *
 * Shows records that NEED A LOOK, not proof of wrongdoing: every one of these
 * has an innocent explanation somewhere (a patient who really could not pay, a
 * clerk who really did hit the wrong bill, a doctor who really did admit
 * first). The page exists so a manager can see patterns per person and ask.
 *
 * Detectors live in config/revenue_exceptions.php (shared with the nightly
 * email) and every schema-dependent read is guarded there, so this page never
 * 500s on a database that is a migration behind.
 *
 * Access: admin always; anyone granted FINANCIAL_VIEW_REVENUE_EXCEPTIONS
 * (MANAGER by default — sql/add_revenue_exceptions_permission.sql). Read-only.
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
refresh_session_permissions($pdo);
if (($_SESSION['base_role'] ?? '') !== 'ADMIN') {
    require_permission('FINANCIAL_VIEW_REVENUE_EXCEPTIONS');
}
require_once __DIR__ . '/config/revenue_exceptions.php';

$isDate = function ($s) { return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $s); };
$from = $isDate($_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
$to   = $isDate($_GET['to'] ?? '')   ? $_GET['to']   : date('Y-m-d');
if ($from > $to) { [$from, $to] = [$to, $from]; }
$rangeLabel = date('d/m/Y', strtotime($from)) . ' – ' . date('d/m/Y', strtotime($to));

$d = rx_collect($pdo, $from, $to);
$c = rx_counts($d);
$matrix = rx_user_matrix($d);

// ER-direct detectors (config/er_direct_exceptions.php). Failure here only
// drops these cards and shows a note; it never takes the page down.
$erxKinds = [
    'er_register_mismatch' => ['ER register does not match bills', 'Closings where the receptionist declared a nurse-register count different from the ER bills the system shows they raised.',
        'A mismatch can be an honest miscount, a bill raised on another device or a register line for a patient who left unserved. It is a prompt to compare the paper with the bills, not a finding against the person. Closings made before the register field existed carry no count and are never listed.'],
    'er_no_register' => ['Closed with no register kept', 'Closings where &ldquo;no register kept&rdquo; was ticked on a day that had ER bills.',
        'Ticking this may be true (the nurse kept no register that day) and is the honest way to say so. It is listed so a habit is visible, not because one tick is wrong. Closings made before the field existed are not listed.'],
    'er_void_waive_rate' => ['High ER void / waive rate', 'People with at least 2 voided or waived ER bills making up 25% or more of the ER bills they raised.',
        'A void is the correct way to reverse a mistake and a waiver the correct way to record a full discount; a small number of bills makes a rate swing easily. Bills with no recorded raiser are grouped under &ldquo;Unknown (not recorded)&rdquo; and that row is never one person.'],
    'er_session_skipped' => ['ER session billed with an earlier one missing', 'A bill marked session N for a patient that day with an earlier session not billed.',
        'The earlier session may have been given at another site or earlier bills may sit on a different patient record. Only bills that carry a session number are checked; older bills carry none and are never listed.'],
    'er_repeat_beyond_advice' => ['More ER bills than advised sessions', 'Same patient, same day: more non-voided ER bills than the advised sessions per day.',
        'The advice may have been changed by the doctor on the day, or a repeat may be a different complaint. Only days where an advice total was recorded are checked; the person shown is who raised the last bill of the day.'],
];
$erx = []; $erxNotes = []; $erxOk = false;
try {
    require_once __DIR__ . '/config/er_direct_exceptions.php';
    $erxClosing = erx_closing_ready($pdo);
    $erxBill = erx_bill_ready($pdo);
    if (!$erxClosing) { $erxNotes[] = 'ER register checks: the ER-direct migration has not been run yet (shift close register columns missing), so the register cards are empty.'; }
    if (!$erxBill) { $erxNotes[] = 'ER session checks: the ER-direct migration has not been run yet (ER bill session columns missing), so the session cards are empty.'; }
    $erx = erx_all($pdo, $from, $to);
    $erxOk = true;
} catch (Throwable $ex) {
    $erxNotes[] = 'ER-direct checks could not be read just now; those cards are skipped.';
    $erx = [];
}
$erxRows = function ($k) use ($erx) { return is_array($erx[$k] ?? null) ? $erx[$k] : []; };
$erxName = function ($r) { return rx_user($r['user_name'] ?? null); };
$erxMatrix = [];
foreach ($erxKinds as $k => $_meta) {
    foreach ($erxRows($k) as $r) {
        $nm = $erxName($r);
        if (!isset($erxMatrix[$nm])) { $erxMatrix[$nm] = array_fill_keys(array_keys($erxKinds), 0); }
        $erxMatrix[$nm][$k]++;
    }
}
uasort($erxMatrix, function ($a, $b) { return array_sum($b) <=> array_sum($a); });

$e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES); };
$dt = function ($s) { return $s ? date('d/m/Y H:i', strtotime((string) $s)) : '—'; };
$rs = function ($n) { return 'Rs ' . number_format((float) $n); };
$pat = function ($r) use ($e) {
    return '<span style="font-weight:600;">' . $e(rx_upper($r['patient_name'])) . '</span> <span class="muted mono">' . $e($r['mrn'] ?? '') . '</span>';
};
$userCell = function ($name) use ($e) {
    return $name === RX_UNKNOWN ? '<span class="muted" style="font-style:italic;">' . $e($name) . '</span>' : $e($name);
};
$apprTag = function ($status, $by = null) use ($e) {
    if ($status === null) { return '<span class="muted">—</span>'; }
    $t = '<span class="tag">' . $e(strtoupper($status)) . '</span>';
    if ($status === 'APPROVED' && $by) { $t .= ' <span class="muted" style="font-size:11.5px;">by ' . $e($by) . '</span>'; }
    return $t;
};
$kindLabel = function ($k) { return str_replace('_', ' ', (string) $k); };
$recSummary = rx_reception_summary($d['reception']);
$recReasons = ['Doctor did not mark it', 'Emergency - doctor busy', 'Walk-in direct admit', 'Other'];
$recLateRows = array_filter($d['reception'], fn($r) => $r['late']);
$adminRows = array_filter($d['admit'], fn($r) => $r['late_candidate']);
$roleCounts = [];
foreach ($d['admit'] as $r) {
    $k = (string) $r['role'];
    $roleCounts[$k] = ($roleCounts[$k] ?? ['n' => 0, 'late' => 0]);
    $roleCounts[$k]['n']++;
    if ($r['late_candidate']) { $roleCounts[$k]['late']++; }
}
ksort($roleCounts);

$pageTitle = 'Revenue Exceptions';
$headExtra = <<<CSS
<style>
.range-form { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.range-form input[type=date] { padding: 9px 12px; border: 1px solid var(--border); border-radius: 10px; font: inherit; font-size: 13.5px; background: #CCD0CF; }
.kpi-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 14px; margin-bottom: 18px; }
.kpi { border: 1px solid var(--border); border-radius: 14px; padding: 14px 16px; background: var(--card); box-shadow: var(--shadow-sm); }
.kpi .k { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted); }
.kpi .v { font-size: 24px; font-weight: 800; margin-top: 4px; font-variant-numeric: tabular-nums; }
.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.caveat { margin: 0 0 12px; padding: 10px 14px; border-radius: 10px; background: #CCD0CF; border: 1px solid #CCD0CF; color: #4A5C6A; font-size: 12.5px; line-height: 1.5; }
.tag { font-size: 11px; font-weight: 700; padding: 2px 9px; border-radius: 20px; background: var(--primary-light); color: var(--primary-dark); white-space: nowrap; display: inline-block; margin: 1px 2px 1px 0; }
.empty { padding: 20px 10px; }
@media print { .sidebar, .mobile-bar, .range-form, .nav-group { display: none !important; } .main { margin: 0 !important; } }
</style>
CSS;
require __DIR__ . '/partials/head.php';
$navActive = 'exceptions_report';
require __DIR__ . '/partials/sidebar.php';
?>
        <div class="content">
            <div class="page-head">
                <div>
                    <div class="page-title">Revenue Exceptions — <?= $e($rangeLabel) ?></div>
                    <div class="page-sub">Short-stay ER admissions: records that need a look</div>
                </div>
                <form class="range-form" method="GET" action="exceptions_report.php">
                    <input type="date" name="from" value="<?= $e($from) ?>" max="<?= date('Y-m-d') ?>">
                    <input type="date" name="to" value="<?= $e($to) ?>" max="<?= date('Y-m-d') ?>">
                    <button type="submit" class="btn secondary">View</button>
                    <button type="button" class="btn" onclick="window.print()">Print</button>
                </form>
            </div>

            <div class="caveat"><strong>Read this first.</strong> Everything below is a list of records that need a look, <strong>not proof of wrongdoing</strong>. Each has ordinary explanations (a patient who could not pay, a bill raised on the wrong patient, a doctor who admitted before reception was free). Older records predate some of these checks and may have no data: a blank person is shown as &ldquo;<?= $e(RX_UNKNOWN) ?>&rdquo; and is never attributed to anyone. Use the counts to start a conversation, not to conclude one.</div>

            <?php foreach ($d['notes'] as $n): ?>
            <div class="caveat" style="background:#CCD0CF;border-color:#CCD0CF;color:#4A5C6A;"><?= $e($n) ?></div>
            <?php endforeach; ?>
            <?php foreach ($erxNotes as $n): ?>
            <div class="caveat" style="background:#CCD0CF;border-color:#CCD0CF;color:#4A5C6A;"><?= $e($n) ?></div>
            <?php endforeach; ?>

            <div class="kpi-row">
                <div class="kpi"><div class="k">Short paid</div><div class="v"><?= $c['short'] ?></div></div>
                <div class="kpi"><div class="k">Zero / discounted</div><div class="v"><?= $c['zero'] ?></div></div>
                <div class="kpi"><div class="k">No bill (closed)</div><div class="v"><?= $c['unbilled_closed'] ?></div></div>
                <div class="kpi"><div class="k">No bill (open now)</div><div class="v"><?= $c['unbilled_active'] ?></div></div>
                <div class="kpi"><div class="k">Voided bills</div><div class="v"><?= $c['voids'] ?></div></div>
                <div class="kpi"><div class="k">ER waived</div><div class="v"><?= $c['waived'] ?></div></div>
                <div class="kpi"><div class="k">Services removed or voided</div><div class="v"><?= $c['removed'] ?></div></div>
                <div class="kpi"><div class="k">Late-admit candidates</div><div class="v"><?= $c['late'] ?></div></div>
                <div class="kpi"><div class="k">Awaiting approval</div><div class="v"><?= $c['approvals_pending'] ?></div></div>
                <div class="kpi"><div class="k">Hand-overs not accepted</div><div class="v"><?= $c['ho_unaccepted'] ?></div></div>
                <div class="kpi"><div class="k">Orphaned open stays</div><div class="v"><?= $c['ho_orphans'] ?></div></div>
                <div class="kpi"><div class="k">Admitted by reception</div><div class="v"><?= $c['reception_admits'] ?></div></div>
                <?php foreach ($erxKinds as $k => $meta): ?>
                <div class="kpi"><div class="k"><?= $e($meta[0]) ?></div><div class="v"><?= count($erxRows($k)) ?></div></div>
                <?php endforeach; ?>
            </div>

            <!-- Per-user patterns -->
            <div class="card">
                <div class="section-title">Counts by person</div>
                <div class="section-sub">Who each record below is attributed to (the person who finalised, voided, waived, removed or admitted). A single count means little; a pattern is what is worth a conversation.</div>
                <div style="overflow-x:auto;">
                <table>
                    <thead><tr><th>Person</th><?php foreach ($matrix['cols'] as $label): ?><th class="num"><?= $e($label) ?></th><?php endforeach; ?></tr></thead>
                    <tbody>
                        <?php if (!$matrix['rows']): ?><tr><td colspan="<?= count($matrix['cols']) + 1 ?>" class="muted empty">Nothing in this range.</td></tr><?php endif; ?>
                        <?php foreach ($matrix['rows'] as $name => $row): ?>
                        <tr>
                            <td style="font-weight:600;"><?= $userCell($name) ?></td>
                            <?php foreach ($matrix['cols'] as $k => $label): ?><td class="num"><?= $row[$k] ?: '<span class="muted">·</span>' ?></td><?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>

            <!-- ER-direct counts by person -->
            <div class="card">
                <div class="section-title">ER-direct counts by person</div>
                <div class="section-sub">Paper-advice ER visits (no admission): who raised or closed each record in the ER-direct cards further down.</div>
                <div style="overflow-x:auto;">
                <table>
                    <thead><tr><th>Person</th><?php foreach ($erxKinds as $meta): ?><th class="num"><?= $e($meta[0]) ?></th><?php endforeach; ?></tr></thead>
                    <tbody>
                        <?php if (!$erxMatrix): ?><tr><td colspan="<?= count($erxKinds) + 1 ?>" class="muted empty">Nothing in this range.</td></tr><?php endif; ?>
                        <?php foreach ($erxMatrix as $name => $row): ?>
                        <tr>
                            <td style="font-weight:600;"><?= $userCell($name) ?></td>
                            <?php foreach ($erxKinds as $k => $meta): ?><td class="num"><?= $row[$k] ?: '<span class="muted">·</span>' ?></td><?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>

            <!-- ER-direct cards -->
            <?php foreach ($erxKinds as $k => $meta): $rowsK = $erxRows($k); ?>
            <div class="card">
                <div class="section-title">ER-direct: <?= $e($meta[0]) ?></div>
                <div class="section-sub"><?= count($rowsK) ?> record(s) in this range. <?= $meta[1] ?></div>
                <p class="caveat"><?= $meta[2] ?></p>
                <div style="overflow-x:auto;"><table>
                    <thead><tr><th>Date</th><th>Person</th><th>What was found</th></tr></thead>
                    <tbody>
                    <?php if (!$rowsK): ?><tr><td colspan="3" class="muted empty">None in this range<?= $erxOk ? '' : ' (check unavailable)' ?>.</td></tr><?php endif; ?>
                    <?php foreach ($rowsK as $r): ?>
                    <tr>
                        <td class="muted"><?= $e($r['date'] ?? '') ?></td>
                        <td><?= $userCell($erxName($r)) ?></td>
                        <td><?= $e($r['detail'] ?? '') ?><?php if (!empty($r['closing_number'])): ?> <span class="muted mono"><?= $e($r['closing_number']) ?></span><?php endif; ?><?php if (!empty($r['note'])): ?> <span class="muted">&mdash; note: <?= $e($r['note']) ?></span><?php endif; ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div>
            <?php endforeach; ?>

            <!-- A Awaiting approval -->
            <div class="card">
                <div class="section-title">Awaiting manager/admin approval</div>
                <div class="section-sub"><?= $c['approvals_pending'] ?> request(s) not yet decided. Live snapshot: not limited to the date range.</div>
                <p class="caveat">A request is raised on purpose when a stay is closed as left-before-treatment, finalised short, or at Rs 0 / with a discount: it means the system is working. Age is what is worth watching; the requester is who asked, not who is at fault. A request raised before approvals were recorded does not appear here.</p>
                <div style="overflow-x:auto;"><table>
                    <thead><tr><th>Requested</th><th class="num">Age (hours)</th><th>Kind</th><th>Patient</th><th>Requested by</th><th>Reason given</th></tr></thead>
                    <tbody>
                    <?php if (!$d['approvals_pending']): ?><tr><td colspan="6" class="muted empty">Nothing waiting.</td></tr><?php endif; ?>
                    <?php foreach ($d['approvals_pending'] as $r): ?>
                    <tr>
                        <td class="muted"><?= $dt($r['requested_at']) ?></td>
                        <td class="num" style="font-weight:700;"><?= $r['age_hours'] === null ? '—' : $e($r['age_hours']) ?></td>
                        <td><span class="tag"><?= $e($kindLabel($r['kind'])) ?></span></td>
                        <td><?= $pat($r) ?></td>
                        <td><?= $userCell($r['user_name']) ?></td>
                        <td class="muted"><?= $e($r['reason'] ?? '') ?: '—' ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div>

            <!-- B Hand-overs not accepted -->
            <div class="card">
                <div class="section-title">Hand-overs nobody has accepted</div>
                <div class="section-sub"><?= $c['ho_unaccepted'] ?> pass(es) waiting more than 12 hours. Live snapshot.</div>
                <p class="caveat">A pass is a receptionist handing an open stay to the next shift. One still waiting after 12 hours may simply mean the next shift has not started or was told verbally; it is a prompt to check the stay has an owner, not a finding against the sender. If the migration has not been run this list is empty and says so above.</p>
                <div style="overflow-x:auto;"><table>
                    <thead><tr><th>Passed</th><th class="num">Hours waiting</th><th>Patient</th><th>Passed by</th><th>Passed to</th><th>Note</th></tr></thead>
                    <tbody>
                    <?php if (!$d['ho_unaccepted']): ?><tr><td colspan="6" class="muted empty">None waiting.</td></tr><?php endif; ?>
                    <?php foreach ($d['ho_unaccepted'] as $r): ?>
                    <tr>
                        <td class="muted"><?= $dt($r['passed_at']) ?></td>
                        <td class="num" style="font-weight:700;"><?= (int) $r['age_hours'] ?></td>
                        <td><?= $pat($r) ?></td>
                        <td><?= $userCell(rx_user($r['from_name'])) ?></td>
                        <td><?= ($r['to_name'] ?? '') !== '' ? $e($r['to_name']) : '<span class="muted">Anyone on the next shift</span>' ?></td>
                        <td class="muted"><?= $e($r['note'] ?? '') ?: '—' ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div>

            <!-- C Orphaned open admissions -->
            <div class="card">
                <div class="section-title">Open stays whose owner holds no cash drawer</div>
                <div class="section-sub"><?= $c['ho_orphans'] ?> open admission(s). Live snapshot.</div>
                <p class="caveat">Each open stay is owned by one receptionist who must bill or pass it at close. These are owned by someone who holds no cash drawer (or is no longer active), so no close will ever ask about them. That is a gap in the process, not a person to blame. Ownership comes from the hand-over record, or from who registered the visit when there is none; if the drawer-holder list cannot be read the card is skipped rather than flagging every stay.</p>
                <div style="overflow-x:auto;"><table>
                    <thead><tr><th>Admitted</th><th>Patient</th><th>Status</th><th>Admitted by (role)</th></tr></thead>
                    <tbody>
                    <?php if (!$d['ho_orphans']): ?><tr><td colspan="4" class="muted empty">None.</td></tr><?php endif; ?>
                    <?php foreach ($d['ho_orphans'] as $r): ?>
                    <tr>
                        <td class="muted"><?= $dt($r['admitted_at']) ?></td>
                        <td><?= $pat($r) ?></td>
                        <td><span class="tag"><?= $e(str_replace('_', ' ', $r['status'])) ?></span></td>
                        <td><span class="tag"><?= $e($r['admitted_by_role']) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div>

            <!-- D Admitted by reception -->
            <div class="card">
                <div class="section-title">Admitted by reception</div>
                <div class="section-sub"><?= $c['reception_admits'] ?> admission(s) marked by reception in this range; <?= $c['reception_late'] ?> with an earlier record more than <?= RX_LATE_GAP_MIN ?> minutes before the admit clock.</div>
                <p class="caveat">Doctors are encouraged to mark admissions themselves; when reception does it, they pick a reason. Each reason is as picked or typed at the time and may be perfectly good (an emergency where the doctor was with another patient). Admissions made before the reason was required have none and are not listed, so a missing reason is never read as a missed one. The gap runs to the earliest ER payment or doctor visit for the same patient in the previous <?= RX_ER_LOOKBACK_H ?> hours; the patient may simply have waited.</p>
                <div style="overflow-x:auto;"><table>
                    <thead><tr><th>Person</th><?php foreach ($recReasons as $rr): ?><th class="num"><?= $e($rr) ?></th><?php endforeach; ?><th class="num">Total</th><th class="num">With late gap</th></tr></thead>
                    <tbody>
                    <?php if (!$recSummary): ?><tr><td colspan="<?= count($recReasons) + 3 ?>" class="muted empty">None in this range.</td></tr><?php endif; ?>
                    <?php foreach ($recSummary as $nm => $row): ?>
                    <tr>
                        <td style="font-weight:600;"><?= $userCell($nm) ?></td>
                        <?php foreach ($recReasons as $rr): ?><td class="num"><?= !empty($row[$rr]) ? (int) $row[$rr] : '<span class="muted">·</span>' ?></td><?php endforeach; ?>
                        <td class="num" style="font-weight:700;"><?= (int) $row['_total'] ?></td>
                        <td class="num"><?= $row['_late'] ? (int) $row['_late'] : '<span class="muted">·</span>' ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                <?php if ($recLateRows): ?>
                <div style="overflow-x:auto;margin-top:14px;"><table>
                    <thead><tr><th>Admitted</th><th>Patient</th><th>Admitted by</th><th>Named doctor</th><th>Reason given</th><th class="num">Minutes earlier</th></tr></thead>
                    <tbody>
                    <?php foreach ($recLateRows as $r): ?>
                    <tr>
                        <td class="muted"><?= $dt($r['admitted_at']) ?></td>
                        <td><?= $pat($r) ?></td>
                        <td><?= $userCell($r['user_name']) ?></td>
                        <td><?= ($r['doctor_name'] ?? '') !== '' ? $e(rx_upper($r['doctor_name'])) : '<span class="muted">—</span>' ?></td>
                        <td class="muted"><?= $e($r['reason']) ?></td>
                        <td class="num" style="font-weight:700;"><?= (int) $r['gap_min'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                <?php endif; ?>
            </div>

            <!-- 1 Short payments -->
            <div class="card">
                <div class="section-title">1. Finalised bills paid short</div>
                <div class="section-sub"><?= $c['short'] ?> bill(s) where amount paid is below the bill total; <?= $c['short_unresolved'] ?> still have a shortfall with no approved write-off or approved request. Rows with an approved request stay listed and show their approval.</div>
                <p class="caveat">A shortfall may be waiting for a manager&rsquo;s write-off approval, or may have been written off legitimately. Rows covered by a recorded write-off are marked so.</p>
                <div style="overflow-x:auto;"><table>
                    <thead><tr><th>When</th><th>Invoice</th><th>Patient</th><th>Status</th><th class="num">Total</th><th class="num">Paid</th><th class="num">Written off</th><th class="num">Unresolved</th><th>Approval</th><th>Finalised by</th></tr></thead>
                    <tbody>
                    <?php if (!$d['short']): ?><tr><td colspan="10" class="muted empty">None in this range.</td></tr><?php endif; ?>
                    <?php foreach ($d['short'] as $r): ?>
                    <tr>
                        <td class="muted"><?= $dt($r['at_time']) ?></td>
                        <td class="mono"><?= $e($r['invoice_number']) ?></td>
                        <td><?= $pat($r) ?></td>
                        <td><span class="tag"><?= $e(strtoupper($r['status'])) ?></span></td>
                        <td class="num"><?= $rs($r['total']) ?></td>
                        <td class="num"><?= $rs($r['paid']) ?></td>
                        <td class="num"><?= $r['written_off'] > 0 ? $rs($r['written_off']) : '<span class="muted">—</span>' ?></td>
                        <td class="num" style="font-weight:700;"><?= $r['unresolved'] > 0.009 ? $rs($r['unresolved']) : '<span class="muted">covered</span>' ?></td>
                        <td><?= $apprTag($r['approval_status'] ?? null, $r['approved_by'] ?? null) ?></td>
                        <td><?= $userCell($r['user_name']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div>

            <!-- 2 Zero / discounted -->
            <div class="card">
                <div class="section-title">2. Bills finalised at Rs 0 or with a discount</div>
                <div class="section-sub"><?= $c['zero'] ?> bill(s).</div>
                <p class="caveat">Discounts and Rs 0 bills are allowed in some cases (charity, staff family, an error corrected). The Approval column shows the manager&rsquo;s decision where one was recorded (pending, approved or rejected); an approved row stays listed so the pattern stays visible. A blank means no approval was recorded (older bills, or approvals not yet migrated), not that none was needed.</p>
                <div style="overflow-x:auto;"><table>
                    <thead><tr><th>When</th><th>Invoice</th><th>Patient</th><th>Flags</th><th class="num">Subtotal</th><th class="num">Discount</th><th class="num">Total</th><th>Approval</th><th>Finalised by</th></tr></thead>
                    <tbody>
                    <?php if (!$d['zero']): ?><tr><td colspan="9" class="muted empty">None in this range.</td></tr><?php endif; ?>
                    <?php foreach ($d['zero'] as $r): ?>
                    <tr>
                        <td class="muted"><?= $dt($r['at_time']) ?></td>
                        <td class="mono"><?= $e($r['invoice_number']) ?></td>
                        <td><?= $pat($r) ?></td>
                        <td><?php foreach ($r['flags'] as $f): ?><span class="tag"><?= $e($f) ?></span><?php endforeach; ?></td>
                        <td class="num"><?= $rs($r['subtotal']) ?></td>
                        <td class="num"><?= $r['discount'] > 0 ? $rs($r['discount']) : '<span class="muted">—</span>' ?></td>
                        <td class="num" style="font-weight:700;"><?= $rs($r['total']) ?></td>
                        <td><?= $apprTag($r['approval_status'] ?? null, $r['approved_by'] ?? null) ?></td>
                        <td><?= $userCell($r['user_name']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div>

            <!-- 3 Closed with no bill -->
            <div class="card">
                <div class="section-title">3. Discharged or discharging with no bill</div>
                <div class="section-sub"><?= $c['unbilled_closed'] ?> admission(s) whose stay ended in this range and have no bill, or only the empty draft raised at admission (no lines, no total, nothing paid).</div>
                <p class="caveat">A discharge in progress may simply not have reached billing yet. &ldquo;Admitted by&rdquo; is who opened the admission, not who skipped the bill.</p>
                <div style="overflow-x:auto;"><table>
                    <thead><tr><th>Admitted</th><th>Stay ended</th><th class="num">Hours open</th><th>Patient</th><th>Status</th><th>Admitted by</th></tr></thead>
                    <tbody>
                    <?php if (!$d['unbilled_closed']): ?><tr><td colspan="6" class="muted empty">None in this range.</td></tr><?php endif; ?>
                    <?php foreach ($d['unbilled_closed'] as $r): ?>
                    <tr>
                        <td class="muted"><?= $dt($r['admitted_at']) ?></td>
                        <td class="muted"><?= $dt($r['closed_at']) ?></td>
                        <td class="num"><?= $r['hours_open'] === null ? '—' : $e($r['hours_open']) ?></td>
                        <td><?= $pat($r) ?></td>
                        <td><span class="tag"><?= $e(str_replace('_', ' ', $r['status'])) ?></span></td>
                        <td><?= $userCell($r['user_name']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div>

            <!-- 4 Active no bill -->
            <div class="card">
                <div class="section-title">4. Open admissions with no bill</div>
                <div class="section-sub"><?= $c['unbilled_active'] ?> admission(s) active right now with no bill or only the empty admit-time draft. Live snapshot: not limited to the date range.</div>
                <p class="caveat">A bill is normally raised at discharge, so a patient still in the room having no bill is expected. Hours open is what is worth watching.</p>
                <div style="overflow-x:auto;"><table>
                    <thead><tr><th>Admitted</th><th>Patient</th><th class="num">Hours open</th><th>Admitted by</th></tr></thead>
                    <tbody>
                    <?php if (!$d['unbilled_active']): ?><tr><td colspan="4" class="muted empty">None open.</td></tr><?php endif; ?>
                    <?php foreach ($d['unbilled_active'] as $r): ?>
                    <tr>
                        <td class="muted"><?= $dt($r['admitted_at']) ?></td>
                        <td><?= $pat($r) ?></td>
                        <td class="num" style="font-weight:700;"><?= $r['hours_open'] === null ? '—' : $e($r['hours_open']) ?></td>
                        <td><?= $userCell($r['user_name']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div>

            <!-- 5 Voids / waivers -->
            <div class="card">
                <div class="section-title">5. Voided and waived bills</div>
                <div class="section-sub"><?= $c['voids'] ?> voided and <?= $c['waived'] ?> waived (Rs 0 ER) in this range, attributed to the person who voided or raised them.</div>
                <p class="caveat">A void is the correct way to reverse a mistake; a waiver is the correct way to record a full discount. Look for clusters, not single rows. A void with no recorded person is shown as unknown.</p>
                <div style="overflow-x:auto;"><table>
                    <thead><tr><th>When</th><th>Type</th><th>Invoice</th><th>Patient</th><th class="num">Amount</th><th>Reason</th><th>By</th></tr></thead>
                    <tbody>
                    <?php if (!$d['voids']): ?><tr><td colspan="7" class="muted empty">None in this range.</td></tr><?php endif; ?>
                    <?php foreach ($d['voids'] as $r): ?>
                    <tr>
                        <td class="muted"><?= $dt($r['at_time']) ?></td>
                        <td><span class="tag"><?= $e($r['kind']) ?></span></td>
                        <td class="mono"><?= $e($r['invoice_number']) ?></td>
                        <td><?= $pat($r) ?></td>
                        <td class="num"><?= $rs($r['amount']) ?></td>
                        <td class="muted"><?= $e($r['reason'] ?? '') ?: '—' ?></td>
                        <td><?= $userCell($r['user_name']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div>

            <!-- 6 Admission timing -->
            <div class="card">
                <div class="section-title">6. Who admitted, and late-admission candidates</div>
                <div class="section-sub"><?= count($d['admit']) ?> admission(s) in this range.
                    <?php foreach ($roleCounts as $role => $rc): ?>
                        <span class="tag"><?= $e($role) ?>: <?= $rc['n'] ?></span>
                    <?php endforeach; ?>
                </div>
                <p class="caveat">A late-admission candidate is an admission where a vitals reading, a logged service, or a paid ER bill for the same patient (up to <?= RX_ER_LOOKBACK_H ?> hours before) is dated more than <?= RX_LATE_GAP_MIN ?> minutes <em>earlier</em> than the admission time. The admission clock may have started late, or the earlier record may belong to a normal ER visit first. Doctor-marked admissions are not suspect by themselves; doctors often admit first and reception follows.</p>
                <div style="overflow-x:auto;"><table>
                    <thead><tr><th>Admitted</th><th>Patient</th><th>Marked by</th><th>Role</th><th>Earliest record</th><th>Source</th><th class="num">Minutes earlier</th></tr></thead>
                    <tbody>
                    <?php if (!$adminRows): ?><tr><td colspan="7" class="muted empty">No candidates in this range.</td></tr><?php endif; ?>
                    <?php foreach ($adminRows as $r): ?>
                    <tr>
                        <td class="muted"><?= $dt($r['admitted_at']) ?></td>
                        <td><?= $pat($r) ?></td>
                        <td><?= $userCell($r['user_name']) ?></td>
                        <td><span class="tag"><?= $e($r['role']) ?></span></td>
                        <td class="muted"><?= $dt($r['first_evidence']) ?></td>
                        <td><span class="tag"><?= $e($r['evidence_source']) ?></span></td>
                        <td class="num" style="font-weight:700;"><?= (int) $r['gap_min'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div>

            <!-- 7 Removed services -->
            <div class="card">
                <div class="section-title">7. Services removed or voided from an admission</div>
                <div class="section-sub"><?= $c['removed'] ?> removal(s)/void(s) logged in this range.</div>
                <p class="caveat">A removed service (before the void migration) is deleted outright, so the audit log text is the only trace: it names the service number and admission, not the service or its price. A voided service keeps its row; the log text carries its name, amount and the reason given. Removing a mis-entered line is normal. Entries from before the audit line existed are not listed.</p>
                <div style="overflow-x:auto;"><table>
                    <thead><tr><th>When</th><th>Removed/voided by</th><th>Audit log text</th></tr></thead>
                    <tbody>
                    <?php if (!$d['removed']): ?><tr><td colspan="3" class="muted empty">None in this range.</td></tr><?php endif; ?>
                    <?php foreach ($d['removed'] as $r): ?>
                    <tr>
                        <td class="muted"><?= $dt($r['at_time']) ?></td>
                        <td><?= $userCell($r['user_name']) ?></td>
                        <td><?= $e($r['details']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div>
        </div>
    </div>
</div>
<script src="assets/js/date-picker.js?v=<?= @filemtime(__DIR__ . "/assets/js/date-picker.js") ?: 1 ?>"></script>
</body>
</html>
