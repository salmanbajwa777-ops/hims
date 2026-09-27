<?php
/**
 * My Shift Log — a staff member's own invoice/discount/refund/discharge
 * activity for TODAY's business day only.
 *
 * "Shift" in HIMS is per-cashier-per-business-day (business_day($pdo), see
 * shift_closing.php / Day Closing), not a clock-in/out window — so this page
 * has no date range control. It always shows the current business day,
 * scoped to actor_id = the signed-in user, and resets when their next Day
 * Closing opens. Reads shift_events (see sql/add_shift_events.sql), the
 * single structured event log every invoice/discount/refund/discharge write
 * site feeds via config/billing.php's log_shift_event().
 *
 * Row 1 for an encounter is always its INVOICE event; a later discount,
 * refund or discharge on that same patient/invoice is its OWN row, never
 * merged — same-patient rows are visually grouped, not folded together.
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
refresh_session_permissions($pdo);
require_once __DIR__ . '/config/billing.php';

// Unconditional for every STAFF member on reception duty — not gated behind
// a revocable permission. "My Shift Log" is a cashier's own record of what
// they rang up today; it should never depend on an admin having granted (or
// a stray override having revoked) a permission key.
$baseRole = $_SESSION['base_role'] ?? '';
if ($baseRole !== 'STAFF') {
    http_response_code(403);
    exit('Forbidden — reception console only.');
}

$uid = (int) $_SESSION['user_id'];
$businessDate = business_day($pdo);

$eventFilter = in_array($_GET['type'] ?? '', ['INVOICE', 'DISCOUNT', 'REFUND', 'DISCHARGE', 'WRITEOFF'], true)
    ? $_GET['type'] : '';

$sql = "
    SELECT se.*, p.name AS patient_name, p.mrn
    FROM shift_events se
    JOIN patients p ON p.id = se.patient_id
    WHERE se.actor_id = ? AND se.business_date = ?
";
$params = [$uid, $businessDate];
if ($eventFilter !== '') {
    $sql .= " AND se.event_type = ?";
    $params[] = $eventFilter;
}
$sql .= " ORDER BY se.occurred_at DESC, se.id DESC";
$q = $pdo->prepare($sql);
$q->execute($params);
$rows = $q->fetchAll();

// Summary strip (over the FULL day, ignoring the type filter — the filter is
// for scanning, not for re-defining what "today" means).
$allToday = $pdo->prepare("SELECT event_type, amount FROM shift_events WHERE actor_id = ? AND business_date = ?");
$allToday->execute([$uid, $businessDate]);
$allToday = $allToday->fetchAll();
$eventCount = count($allToday);
$invoiceCount = count(array_filter($allToday, fn($r) => $r['event_type'] === 'INVOICE'));
$refundCount = count(array_filter($allToday, fn($r) => $r['event_type'] === 'REFUND'));
$netCollected = array_sum(array_column($allToday, 'amount'));

// Mark rows whose patient_id repeats elsewhere in the visible list, so the
// template can draw a same-patient rail — cosmetic grouping only, no new query.
$patientCounts = array_count_values(array_column($rows, 'patient_id'));

// Is today's Day Closing already signed? Informational only — this page
// never blocks on it.
$closedStmt = $pdo->prepare('SELECT 1 FROM shift_closings WHERE cashier_id = ? AND closing_date = ?');
try {
    $closedStmt->execute([$uid, $businessDate]);
    $isClosed = (bool) $closedStmt->fetchColumn();
} catch (PDOException $e) {
    $isClosed = false; // shift_closings not migrated yet — degrade quietly
}

$EVENT_LABELS = [
    'INVOICE' => 'Invoice raised', 'DISCOUNT' => 'Discount applied',
    'REFUND' => 'Refund issued', 'DISCHARGE' => 'Discharged', 'WRITEOFF' => 'Written off',
];
$EVENT_CLASS = [
    'INVOICE' => 'msl-invoice', 'DISCOUNT' => 'msl-discount',
    'REFUND' => 'msl-refund', 'DISCHARGE' => 'msl-discharge', 'WRITEOFF' => 'msl-refund',
];

$pageTitle = 'My Shift Log';
$headExtra = <<<CSS
<style>
.msl-banner { display: flex; align-items: center; gap: 8px; background: var(--primary-light); color: var(--primary-dark); border-radius: 10px; padding: 8px 13px; font-size: 12.5px; font-weight: 700; width: fit-content; }
.msl-banner .sep { opacity: .5; font-weight: 400; }
.msl-chips { display: flex; gap: 6px; background: var(--neutral-bg); border-radius: 10px; padding: 3px; flex-wrap: wrap; }
.msl-chip { border: 0; background: transparent; padding: 7px 13px; border-radius: 8px; font-size: 12.5px; font-weight: 600; color: var(--text-muted); cursor: pointer; text-decoration: none; white-space: nowrap; }
.msl-chip.active { background: var(--card); color: var(--primary-dark); box-shadow: var(--shadow-sm); }
.msl-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.msl-num.neg { color: var(--danger); }
.msl-tag { font-weight: 700; font-size: 11px; padding: 3px 9px; border-radius: 7px; display: inline-block; white-space: nowrap; }
.msl-invoice { background: var(--ok-bg); color: var(--ok); }
.msl-discount { background: #f3ecfa; color: #7c4ea8; }
.msl-refund { background: var(--danger-bg); color: var(--danger); }
.msl-discharge { background: var(--primary-light); color: var(--primary-dark); }
.msl-rail { position: relative; }
.msl-rail.linked::before { content: ""; position: absolute; left: -10px; top: 0; bottom: 0; width: 2px; background: var(--border); }
.kpi-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 14px; }
.kpi { border: 1px solid var(--border); border-radius: 14px; padding: 16px 18px; background: var(--card); box-shadow: var(--shadow-sm); }
.kpi .k { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted); }
.kpi .v { font-size: 22px; font-weight: 800; margin-top: 4px; font-variant-numeric: tabular-nums; }
.kpi.total .v { color: var(--primary-dark); }
</style>
CSS;
require __DIR__ . '/partials/head.php';
$navActive = 'my_shift_log';
require __DIR__ . '/partials/sidebar.php';
?>
<div class="content">
    <div class="page-head">
        <div>
            <div class="page-title">My Shift Log</div>
            <div class="page-sub">Everything you've billed, discounted, refunded or discharged today</div>
        </div>
    </div>

    <div class="msl-banner">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
        Today's shift &middot; <?= date('l, d M Y', strtotime($businessDate)) ?>
        <span class="sep">&middot;</span> <?= $isClosed ? 'closed' : 'not yet closed' ?>
    </div>

    <div class="kpi-row">
        <div class="kpi total"><div class="k">Events logged</div><div class="v"><?= $eventCount ?></div></div>
        <div class="kpi"><div class="k">Invoices raised</div><div class="v"><?= $invoiceCount ?></div></div>
        <div class="kpi"><div class="k">Refunds issued</div><div class="v"><?= $refundCount ?></div></div>
        <div class="kpi"><div class="k">Net logged</div><div class="v">Rs <?= number_format($netCollected) ?></div></div>
    </div>

    <div class="msl-chips">
        <a class="msl-chip <?= $eventFilter === '' ? 'active' : '' ?>" href="?">All events</a>
        <a class="msl-chip <?= $eventFilter === 'INVOICE' ? 'active' : '' ?>" href="?type=INVOICE">Invoices</a>
        <a class="msl-chip <?= $eventFilter === 'DISCOUNT' ? 'active' : '' ?>" href="?type=DISCOUNT">Discounts</a>
        <a class="msl-chip <?= $eventFilter === 'REFUND' ? 'active' : '' ?>" href="?type=REFUND">Refunds</a>
        <a class="msl-chip <?= $eventFilter === 'DISCHARGE' ? 'active' : '' ?>" href="?type=DISCHARGE">Discharges</a>
    </div>

    <div class="card">
        <div style="overflow-x:auto;">
        <table>
            <thead>
                <tr><th>Time</th><th>Event</th><th>Patient</th><th>Reference</th><th class="msl-num">Amount</th></tr>
            </thead>
            <tbody>
                <?php if (!$rows): ?>
                <tr><td colspan="5" class="empty">No activity logged yet today.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r): $linked = ($patientCounts[$r['patient_id']] ?? 0) > 1; ?>
                <tr>
                    <td class="muted mono"><?= date('g:i A', strtotime($r['occurred_at'])) ?></td>
                    <td><span class="msl-tag <?= $EVENT_CLASS[$r['event_type']] ?? '' ?>"><?= htmlspecialchars($EVENT_LABELS[$r['event_type']] ?? $r['event_type']) ?></span></td>
                    <td class="msl-rail <?= $linked ? 'linked' : '' ?>" style="font-weight:600;">
                        <?= htmlspecialchars($r['patient_name']) ?>
                        <div class="muted mono" style="font-size:11.5px;font-weight:400;"><?= htmlspecialchars($r['mrn']) ?></div>
                    </td>
                    <td class="mono muted"><?= htmlspecialchars($r['invoice_number'] ?? '—') ?><?= $r['note'] ? ' — ' . htmlspecialchars($r['note']) : '' ?></td>
                    <td class="msl-num <?= (float) $r['amount'] < 0 ? 'neg' : '' ?>">
                        <?= (float) $r['amount'] < 0 ? '&minus; ' : '' ?>Rs <?= number_format(abs((float) $r['amount'])) ?>
                    </td>
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
