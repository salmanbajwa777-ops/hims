<?php
/**
 * "Past" page OPD-visibility diagnostic.
 *
 * Answers "this patient has had consultations, why does Patient Past show only
 * admissions?" with live data instead of inference. patient_past.php's OPD
 * stream is: bills b JOIN visits v ON v.id = b.visit_id WHERE v.patient_id = ?
 * — an INNER JOIN on both sides, so a dangling visit_id or a bill that never
 * got linked to this patient's visit silently drops the row. This tool replays
 * that join with LEFT JOINs so the drop reason is visible instead of inferred.
 *
 * Admin-only, read-only — it writes nothing.
 *
 * Usage:  tools/diag_patient_past_opd.php?mrn=<mrn>
 *         tools/diag_patient_past_opd.php?id=<patient_id>
 */
require_once __DIR__ . '/config/guard_admin.php';

header('Content-Type: text/plain; charset=utf-8');

$mrn = trim($_GET['mrn'] ?? '');
$id  = (int) ($_GET['id'] ?? 0);

if ($mrn === '' && $id <= 0) {
    echo "Usage: diag_patient_past_opd.php?mrn=<mrn>  OR  ?id=<patient_id>\n";
    exit;
}

if ($id > 0) {
    $p = $pdo->prepare('SELECT * FROM patients WHERE id = ?');
    $p->execute([$id]);
} else {
    $p = $pdo->prepare('SELECT * FROM patients WHERE mrn = ?');
    $p->execute([$mrn]);
}
$patient = $p->fetch();
if (!$patient) {
    echo "No patient found for " . ($id > 0 ? "id=$id" : "mrn=$mrn") . "\n";
    exit;
}
$patientId = (int) $patient['id'];

echo "PATIENT PAST — OPD VISIBILITY DIAGNOSTIC\n";
echo "patient: {$patient['name']}  MRN {$patient['mrn']}  id=$patientId\n";
echo str_repeat('=', 78) . "\n\n";

/* ------------------------------------------------- 1. every visit this patient has */
echo "ALL VISITS FOR THIS PATIENT\n";
$visits = $pdo->prepare('SELECT id, visit_date, doctor_id, consult_status, disposition, created_at
                            FROM visits WHERE patient_id = ? ORDER BY id DESC');
$visits->execute([$patientId]);
$visitRows = $visits->fetchAll();
if (!$visitRows) {
    echo "  ** NO visits rows for this patient at all — nothing to bill in OPD, this\n";
    echo "     is consistent with 'no consultations show' if they truly never had one. **\n\n";
} else {
    foreach ($visitRows as $v) {
        printf("  visit#%-6d date=%-12s doctor_id=%-6s status=%-12s disposition=%-14s created=%s\n",
            $v['id'], $v['visit_date'], $v['doctor_id'] ?? 'NULL', $v['consult_status'] ?? '?',
            $v['disposition'] ?? '?', $v['created_at']);
    }
    echo "\n";
}

/* ---------------------------------- 2. replay patient_past.php's OPD join, LEFT JOIN style */
echo "REPLAY OF patient_past.php OPD QUERY (LEFT JOIN so drops are visible)\n";
echo str_repeat('-', 78) . "\n";
$sql = "
    SELECT b.id AS bill_id, b.invoice_number, b.visit_id AS b_visit_id, b.status, b.grand_total,
           v.id AS v_ok, v.patient_id AS v_patient_id
      FROM bills b
      LEFT JOIN visits v ON v.id = b.visit_id
     WHERE b.visit_id IN (SELECT id FROM visits WHERE patient_id = ?)
        OR v.patient_id = ?
     ORDER BY b.id DESC
";
try {
    $st = $pdo->prepare($sql);
    $st->execute([$patientId, $patientId]);
    $billRows = $st->fetchAll();
} catch (PDOException $e) {
    echo "  ** bills table query failed: " . $e->getMessage() . " **\n";
    $billRows = [];
}

if (!$billRows) {
    echo "  No bills row references any visit belonging to this patient.\n";
    echo "  => Either no OPD bill was ever created (checkout.php never run for their\n";
    echo "     visit), or the bill points at a visit_id that belongs to a DIFFERENT\n";
    echo "     patient (see orphan/mismatch check below).\n\n";
} else {
    foreach ($billRows as $b) {
        $why = [];
        if (!$b['v_ok']) { $why[] = 'visit_id ' . ($b['b_visit_id'] ?? 'NULL') . ' MISSING (JOIN visits drops it)'; }
        elseif ((int) $b['v_patient_id'] !== $patientId) {
            $why[] = "bill's visit belongs to patient_id={$b['v_patient_id']}, NOT $patientId "
                   . "— this bill will show up on THAT patient's Past page instead";
        }
        $visible = !$why;
        printf("%s bill#%-6d inv=%-14s visit_id=%-6s status=%-10s total=%s\n",
            $visible ? '[SHOWN ]' : '[HIDDEN]',
            $b['bill_id'], $b['invoice_number'], $b['b_visit_id'] ?? 'NULL', $b['status'], $b['grand_total']);
        foreach ($why as $w) { echo "         -> $w\n"; }
    }
    echo "\n";
}

/* ------------------------------------------- 3. direct count: what the live page actually gets */
echo "EXACT COUNT patient_past.php's real query returns\n";
$exact = $pdo->prepare("SELECT COUNT(*) FROM bills b JOIN visits v ON v.id = b.visit_id WHERE v.patient_id = ?");
$exact->execute([$patientId]);
printf("  bills JOIN visits WHERE visits.patient_id = %d  =>  %d row(s)\n\n", $patientId, (int) $exact->fetchColumn());

/* ------------------------------------------------------ 4. table existence / structural sanity */
echo "STRUCTURAL SANITY\n";
foreach (['bills', 'visits'] as $t) {
    try {
        $n = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        echo "  table `$t` exists, $n row(s) total\n";
    } catch (PDOException $e) {
        echo "  ** table `$t` MISSING or inaccessible: " . $e->getMessage() . " **\n";
    }
}
echo "\n";

/* ------------------------------------------ 5. orphan bills across the whole database */
echo "GLOBAL ORPHAN CHECK — bills whose visit_id does not resolve (any patient)\n";
$orph = $pdo->query("
    SELECT COUNT(*) FROM bills b LEFT JOIN visits v ON v.id = b.visit_id WHERE v.id IS NULL
")->fetchColumn();
printf("  bills with no matching visit : %d%s\n", $orph, $orph ? '  ** these can NEVER show on ANY Past page **' : '');
echo "\n";

/* ------------------------------------------ 6. what patient_past.php ACTUALLY contains on disk */
echo "LIVE FILE CHECK — what patient_past.php actually is on this server right now\n";
$ppPath = __DIR__ . '/patient_past.php';
if (!file_exists($ppPath)) {
    echo "  ** patient_past.php DOES NOT EXIST at $ppPath **\n";
} else {
    $content = file_get_contents($ppPath);
    echo "  file size    : " . strlen($content) . " bytes\n";
    echo "  mtime        : " . date('Y-m-d H:i:s', filemtime($ppPath)) . " (server local time)\n";
    echo "  md5          : " . md5($content) . "\n";
    echo "  has marker   : " . (strpos($content, 'DEPLOY-CHECK-v2') !== false ? 'YES' : 'NO') . "\n";
    echo "  has OPD pull : " . (strpos($content, "'kind' => 'opd'") !== false ? 'YES' : 'NO') . "\n";
    // First 500 bytes so a totally different/old file becomes obvious at a glance.
    echo "  --- first 300 chars ---\n";
    echo "  " . str_replace("\n", "\n  ", substr($content, 0, 300)) . "\n";
}
echo "\nDone. Read-only — nothing was modified.\n";
