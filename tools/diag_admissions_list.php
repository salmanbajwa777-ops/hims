<?php
/**
 * Admissions-list visibility diagnostic.
 *
 * Answers "I admitted this patient as ROUTINE, why isn't it in the list?" with
 * live data instead of inference. Takes every recent admissions row and replays
 * the exact filters admissions.php applies, reporting which one drops it.
 *
 * admissions.php shows a row only if ALL of these hold:
 *   1. the admissions row exists
 *   2. its visit_id resolves        (INNER JOIN visits  — a dangling id drops it)
 *   3. that visit's patient exists  (INNER JOIN patients — ditto)
 *   4. status <> 'DISCHARGED' OR discharge_finalized_at >= CURDATE()
 *
 * Admin-only, read-only — it writes nothing.
 *
 * Usage:  tools/diag_admissions_list.php[?days=7][&mrn=<mrn>]
 */
require_once __DIR__ . '/../config/guard_admin.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: text/plain; charset=utf-8');

$days = isset($_GET['days']) ? max(1, (int) $_GET['days']) : 7;
$mrn  = trim($_GET['mrn'] ?? '');

echo "ADMISSIONS LIST DIAGNOSTIC\n";
echo "window : last $days day(s)" . ($mrn !== '' ? "   mrn=$mrn" : '') . "\n";
echo "today  : " . date('Y-m-d H:i') . " (PKT)\n";
echo str_repeat('=', 78) . "\n\n";

/* ---------------------------------------------------------------- 1. counts */
echo "ROW COUNTS BY TYPE AND STATUS (all time)\n";
$counts = $pdo->query("
    SELECT admission_type, status, COUNT(*) n
    FROM admissions GROUP BY admission_type, status ORDER BY admission_type, status
")->fetchAll();
if (!$counts) {
    echo "  ** the admissions table is EMPTY — nothing was ever inserted **\n";
} else {
    foreach ($counts as $c) {
        printf("  %-14s %-22s %d\n", $c['admission_type'], $c['status'], $c['n']);
    }
}
echo "\n";

/* ------------------------------------------------- 2. replay the page filter */
// LEFT JOINs on purpose: the page uses INNER JOINs, so a NULL here is exactly
// the row the page silently drops. That is the whole point of this tool.
$sql = "
    SELECT a.id, a.visit_id, a.admission_type, a.status,
           a.admitted_at, a.discharge_finalized_at,
           a.assigned_nurse_id, a.admitting_doctor_id, a.admitting_doctor_manual,
           v.id AS v_ok, v.patient_id,
           p.id AS p_ok, p.mrn, p.name AS pname
    FROM admissions a
    LEFT JOIN visits   v ON v.id = a.visit_id
    LEFT JOIN patients p ON p.id = v.patient_id
    WHERE a.admitted_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
";
$params = [$days];
if ($mrn !== '') { $sql .= " AND p.mrn = ? "; $params[] = $mrn; }
$sql .= " ORDER BY a.admitted_at DESC LIMIT 200";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

echo "RECENT ADMISSIONS — why each does or does not appear\n";
echo str_repeat('-', 78) . "\n";

if (!$rows) {
    echo "  No admissions rows in this window.\n";
    echo "  => The admit never committed. The list is innocent; look at the\n";
    echo "     admit POST (config/admission_actions.php) and the audit_log for\n";
    echo "     'patient_admitted'. A rolled-back transaction leaves NO row.\n\n";
}

$hidden = 0;
foreach ($rows as $r) {
    $why = [];
    if (!$r['v_ok']) { $why[] = 'visit_id ' . (int) $r['visit_id'] . ' MISSING (INNER JOIN visits drops it)'; }
    if ($r['v_ok'] && !$r['p_ok']) { $why[] = 'patient MISSING (INNER JOIN patients drops it)'; }
    // The date filter: discharged before today falls off the list by design.
    if ($r['status'] === 'DISCHARGED') {
        $fin = $r['discharge_finalized_at'];
        if ($fin === null) {
            $why[] = "status=DISCHARGED but discharge_finalized_at IS NULL — "
                   . "NULL >= CURDATE() is never true, so it is hidden PERMANENTLY";
        } elseif (substr($fin, 0, 10) < date('Y-m-d')) {
            $why[] = "discharged $fin (before today) — hidden by design";
        }
    }

    $visible = !$why;
    if (!$visible) { $hidden++; }

    printf("%s adm#%-5d %-12s %-20s %s\n",
        $visible ? '[SHOWN ]' : '[HIDDEN]',
        $r['id'], $r['admission_type'], $r['status'],
        ($r['mrn'] ?? '?') . ' ' . ($r['pname'] ?? '(no patient)'));
    printf("         admitted %s   visit %s\n",
        $r['admitted_at'], $r['visit_id'] ?? 'NULL');
    foreach ($why as $w) { echo "         -> $w\n"; }
}

echo str_repeat('-', 78) . "\n";
printf("%d row(s) examined, %d hidden.\n\n", count($rows), $hidden);

/* ------------------------------------------- 3. orphan check across all time */
echo "INTEGRITY — admissions whose JOINs cannot resolve (any date)\n";
$orph = $pdo->query("
    SELECT COUNT(*) FROM admissions a
    LEFT JOIN visits v ON v.id = a.visit_id
    WHERE v.id IS NULL
")->fetchColumn();
$orphP = $pdo->query("
    SELECT COUNT(*) FROM admissions a
    JOIN visits v ON v.id = a.visit_id
    LEFT JOIN patients p ON p.id = v.patient_id
    WHERE p.id IS NULL
")->fetchColumn();
printf("  admissions with no visit   : %d%s\n", $orph, $orph ? '  ** these can NEVER show **' : '');
printf("  admissions with no patient : %d%s\n", $orphP, $orphP ? '  ** these can NEVER show **' : '');
echo "\n";

/* --------------------------------------------- 4. DISCHARGED-with-NULL-final */
$badFin = $pdo->query("
    SELECT COUNT(*) FROM admissions
    WHERE status = 'DISCHARGED' AND discharge_finalized_at IS NULL
")->fetchColumn();
printf("  DISCHARGED with NULL discharge_finalized_at : %d%s\n", $badFin,
    $badFin ? '  ** permanently hidden from the list **' : '');
echo "\n";

/* ------------------------------------------------------ 5. audit-log compare */
// If the audit log recorded an admit but no row survives, the transaction was
// rolled back after the log call — a very different bug from a filter drop.
echo "AUDIT LOG — 'patient_admitted' entries in the window\n";
try {
    $al = $pdo->prepare("
        SELECT created_at, details FROM audit_logs
        WHERE action = 'patient_admitted'
          AND created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
        ORDER BY created_at DESC LIMIT 50
    ");
    $al->execute([$days]);
    $logs = $al->fetchAll();
    printf("  %d logged admit(s) vs %d admissions row(s) in window\n", count($logs), count($rows));
    if (count($logs) > count($rows)) {
        echo "  ** MORE logged admits than rows — some admits did not persist **\n";
    }
    foreach (array_slice($logs, 0, 10) as $l) {
        echo "    {$l['created_at']}  {$l['details']}\n";
    }
} catch (Throwable $e) {
    echo "  (audit_log unavailable: " . $e->getMessage() . ")\n";
}
echo "\nDone. Read-only — nothing was modified.\n";
