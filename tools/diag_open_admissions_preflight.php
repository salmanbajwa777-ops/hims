<?php
/**
 * Open-admissions pre-flight for the reception hand-off close gate.
 *
 * Lists short-stay admissions that are still ACTIVE / DISCHARGE_IN_PROGRESS /
 * PENDING_ASSIGNMENT (or DISCHARGED with the bill unfinished) and were admitted
 * BEFORE the gate start (HO_GATE_START, or the clinic_settings row 'ho_gate_start').
 * These legacy stays do NOT block a close (the gate ignores them); this lists them
 * so the owner can discharge/clean them first, and also lists the ones AFTER the
 * cutoff that WOULD block, with the receptionist who would own each.
 *
 * Admin-only, read-only - it writes nothing.
 *
 * Usage:  tools/diag_open_admissions_preflight.php
 */
require_once __DIR__ . '/../config/guard_admin.php';   // also loads db.php + permissions
require_once __DIR__ . '/../config/handover_helpers.php';

header('Content-Type: text/plain; charset=utf-8');

$start = ho_gate_start($pdo);
echo "OPEN ADMISSIONS PRE-FLIGHT\n";
echo "gate start : $start  (stays admitted before this never block a close)\n";
echo "now        : " . date('Y-m-d H:i') . " (PKT)\n";
echo str_repeat('=', 78) . "\n\n";

$openWhere = "(a.status IN ('PENDING_ASSIGNMENT','ACTIVE','DISCHARGE_IN_PROGRESS')
               OR (a.status = 'DISCHARGED' AND a.discharge_finalized_at IS NULL))";
$sel = "SELECT a.id, a.status, a.admitted_at, a.admitted_by_role, p.name AS patient_name, p.mrn,
               COALESCE(ub.name, '-') AS admitted_by, COALESCE(uv.name, '-') AS registered_by
        FROM admissions a
        JOIN visits v ON v.id = a.visit_id
        JOIN patients p ON p.id = v.patient_id
        LEFT JOIN users ub ON ub.id = a.admitted_by_id
        LEFT JOIN users uv ON uv.id = v.created_by_id
        WHERE $openWhere";

$print = function (array $rows): void {
    if (!$rows) { echo "  (none)\n"; return; }
    foreach ($rows as $r) {
        printf("  #%-6d %-22s %-19s %-10s %s (MRN %s) admitted by %s, registered by %s\n",
            $r['id'], $r['status'], $r['admitted_at'], $r['admitted_by_role'],
            $r['patient_name'], $r['mrn'], $r['admitted_by'], $r['registered_by']);
    }
};

$legacy = $pdo->prepare($sel . ' AND a.admitted_at < ? ORDER BY a.admitted_at');
$legacy->execute([$start]);
$rows = $legacy->fetchAll(PDO::FETCH_ASSOC);
echo "LEGACY open stays (admitted before the gate start): " . count($rows) . "\n";
echo "  Not blocking anyone. Discharge or close these out so they stop showing as open.\n";
$print($rows);

$live = $pdo->prepare($sel . ' AND a.admitted_at >= ? ORDER BY a.admitted_at');
$live->execute([$start]);
$rows = $live->fetchAll(PDO::FETCH_ASSOC);
echo "\nOpen stays at/after the gate start (these count; a billed bill resolves them): " . count($rows) . "\n";
$print($rows);
echo "\nDone (read-only).\n";
