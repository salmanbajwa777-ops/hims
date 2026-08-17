<?php
/**
 * Last good odometer reading for a vehicle — JSON, for the posting form.
 *
 * The form shows the previous reading so the person posting can see the trip
 * and be warned about a rollback BEFORE they submit, rather than the approver
 * discovering it afterwards.
 *
 * Uses the same last-plausible-reading walk as veh_approval_context(), so the
 * "previous" shown while typing is the same one the approval panel and the
 * report will measure against. A corrupt reading is skipped rather than
 * offered as the baseline.
 *
 * Gated on FINANCIAL_POST_EXPENSES — whoever may post the expense may see the
 * reading it is measured against.
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
require_once __DIR__ . '/config/vehicle_costs.php';
refresh_session_permissions($pdo);
require_permission('FINANCIAL_POST_EXPENSES');

header('Content-Type: application/json');

$vehicleId = (int) ($_GET['vehicle_id'] ?? 0);
if ($vehicleId <= 0) {
    echo json_encode(['ok' => false, 'reason' => 'no_vehicle']);
    exit;
}

try {
    // Newest readings first; walk back to one that forms a sane gap with its
    // own predecessor, exactly as the approval context does.
    $s = $pdo->prepare("
        SELECT meter_reading, expense_date FROM expenses
         WHERE vehicle_id = ? AND meter_reading IS NOT NULL
           AND voided_at IS NULL AND approval_status <> 'REJECTED'
         ORDER BY expense_date DESC, id DESC LIMIT 12
    ");
    $s->execute([$vehicleId]);
    $recent = $s->fetchAll();

    // This vehicle's own limit, so the warning the form shows while typing
    // matches the rule the report will actually apply to the saved row.
    $maxGap = veh_limit_for($pdo, $vehicleId);

    $prev = null;
    foreach ($recent as $i => $cand) {
        $older = $recent[$i + 1] ?? null;
        if ($older === null) { $prev = $cand; break; }
        $delta = (int) $cand['meter_reading'] - (int) $older['meter_reading'];
        if ($delta > 0 && $delta <= $maxGap) { $prev = $cand; break; }
    }

    echo json_encode([
        'ok'      => true,
        'meter'   => $prev ? (int) $prev['meter_reading'] : null,
        'date'    => $prev ? date('d/m/Y', strtotime($prev['expense_date'])) : null,
        'max_gap' => $maxGap,
        // Fleet trailing Rs/litre, so the form can flag an implausible rate
        // BEFORE submission. NULL when there is too little history to compare
        // against — the client then skips the warning rather than measuring
        // against a fabricated baseline.
        'fleet_rate' => veh_fleet_rate_per_litre($pdo, 30),
        // Already a fuel posting for this vehicle today? Drives a non-blocking
        // duplicate warning; a second genuine fill on a long-trip day is normal.
        'fuel_today' => veh_has_fuel_on_date($pdo, $vehicleId, date('Y-m-d')),
    ]);
} catch (PDOException $e) {
    // Pre-migration or a schema gap: report cleanly so the form just hides the
    // previous-reading hint instead of breaking the posting flow.
    echo json_encode(['ok' => false, 'reason' => 'unavailable']);
}
