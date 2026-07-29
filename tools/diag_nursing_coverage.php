<?php
/**
 * Nursing-record coverage diagnostic — IPD vs ER.
 *
 * Answers "which admitted patients have no nursing record, and which path lost
 * it?" with live data instead of inference.
 *
 * HIMS has THREE patient paths, and they do not carry the same clinical record:
 *
 *   er_bills        E-series walk-in. Hangs off patients.id — no visit row, and
 *                   no clinical table anywhere carries an er_bill_id. It has NO
 *                   vitals, NO care log, NO handover, NO nurse. By construction,
 *                   not by omission in the data.
 *   admissions      A-series ER short-stay. Has admission_vitals and
 *                   admission_handovers, but NO care-event table — an un-billed
 *                   nursing observation has nowhere to go. Its only substitute
 *                   is admission_services.clinical_note, which hangs off a
 *                   CHARGEABLE line.
 *   ipd_admissions  I-series In-Door. The full chart: ipd_vitals,
 *                   ipd_care_events, ipd_handovers.
 *
 * Schema questions are answered from information_schema, never from the presence
 * of a .sql file — several migrations here cannot run at all on this host
 * (the DB user is denied CREATE ROUTINE, #1044, so any CREATE PROCEDURE
 * migration fails), so "the file exists" proves nothing about the live column.
 *
 * Admin-only, read-only — it writes nothing.
 *
 * Usage:  tools/diag_nursing_coverage.php[?days=30]
 */
require_once __DIR__ . '/../config/guard_admin.php';   // also loads db.php + permissions

header('Content-Type: text/plain; charset=utf-8');

$days = isset($_GET['days']) ? max(1, (int) $_GET['days']) : 30;

echo "NURSING-RECORD COVERAGE DIAGNOSTIC  (IPD vs ER)\n";
echo "window : last $days day(s)\n";
echo "now    : " . date('Y-m-d H:i') . " (PKT)\n";
echo str_repeat('=', 78) . "\n\n";

/* ------------------------------------------------------------------ helpers */

/** Does a table exist? Every nursing table here is migration-gated. */
$hasTable = function (string $t) use ($pdo): bool {
    try { $pdo->query("SELECT 1 FROM `$t` LIMIT 1"); return true; }
    catch (Throwable $e) { return false; }
};

/** Does a column exist? Answered from information_schema, scoped to THIS db. */
$hasCol = function (string $t, string $c) use ($pdo): bool {
    try {
        $s = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $s->execute([$t, $c]);
        return (int) $s->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
};

/** The live ENUM definition of a column, or '' if unavailable. */
$colType = function (string $t, string $c) use ($pdo): string {
    try {
        $s = $pdo->prepare(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $s->execute([$t, $c]);
        return (string) ($s->fetchColumn() ?: '');
    } catch (Throwable $e) { return ''; }
};

/** Scalar query guarded so a missing table prints a note instead of fatalling. */
$scalar = function (string $sql, array $args = []) use ($pdo) {
    try {
        $s = $pdo->prepare($sql);
        $s->execute($args);
        return $s->fetchColumn();
    } catch (Throwable $e) { return null; }
};

$n = function ($v): string { return $v === null ? '  n/a' : sprintf('%5d', (int) $v); };

/* ------------------------------------------------------ 1. schema reality check */

echo "1. SCHEMA REALITY CHECK  (information_schema — the only authority)\n";
echo str_repeat('-', 78) . "\n";

$tables = [
    'admissions'          => 'ER short-stay core',
    'admission_vitals'    => 'ER vitals',
    'admission_handovers' => 'ER handovers',
    'admission_services'  => 'ER chargeable services',
    'ipd_admissions'      => 'IPD core',
    'ipd_vitals'          => 'IPD vitals',
    'ipd_care_events'     => 'IPD care flow-sheet',
    'ipd_handovers'       => 'IPD handovers',
    'ipd_services'        => 'IPD chargeable services',
    'er_bills'            => 'E-series walk-in bill',
];
foreach ($tables as $t => $label) {
    printf("  %-22s %-26s %s\n", $t, $label,
        $hasTable($t) ? 'present' : '** MISSING — not migrated **');
}
echo "\n";

echo "  Migration-gated columns:\n";
$cols = [
    ['admission_services', 'clinical_note',
     'ER service note — add_service_clinical_note.sql uses CREATE PROCEDURE,'],
    ['ipd_admissions',     'assigned_nurse_id', 'IPD primary nurse'],
    ['admissions',         'assigned_nurse_id', 'ER primary nurse'],
];
foreach ($cols as [$t, $c, $label]) {
    printf("    %-22s %-20s %s\n", "$t.$c", $hasCol($t, $c) ? 'PRESENT' : '** ABSENT **', $label);
}
if (!$hasCol('admission_services', 'clinical_note')) {
    echo "      -> which this DB user cannot run (CREATE ROUTINE denied, #1044).\n";
    echo "         admission.php:109 falls back to a no-note INSERT, so ER service\n";
    echo "         notes are being SILENTLY DISCARDED on every save.\n";
}
echo "\n";

$ev = $colType('ipd_care_events', 'event_type');
if ($ev === '') {
    echo "  ipd_care_events.event_type : n/a (table or column missing)\n";
} else {
    $hasService = stripos($ev, "'SERVICE'") !== false;
    echo "  ipd_care_events.event_type : " . ($hasService ? "includes SERVICE" : "** SERVICE MISSING **") . "\n";
    echo "    $ev\n";
    if (!$hasService) {
        echo "      -> add_ipd_care_service_events.sql has NOT been applied; logged\n";
        echo "         services never appear in the clinical timeline (the mirror\n";
        echo "         write in config/ipd_billing.php is caught and dropped).\n";
    }
}
echo "\n";

/* ---------------------------------------------------------- 2. volume by path */

echo "2. VOLUME BY PATH  (last $days days)\n";
echo str_repeat('-', 78) . "\n";

$erBills = $scalar(
    "SELECT COUNT(*) FROM er_bills
     WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
       AND voided_at IS NULL AND status <> 'voided'", [$days]);
$erStays = $scalar(
    "SELECT COUNT(*) FROM admissions
     WHERE admitted_at >= DATE_SUB(NOW(), INTERVAL ? DAY)", [$days]);
$ipdStays = $scalar(
    "SELECT COUNT(*) FROM ipd_admissions
     WHERE admitted_at >= DATE_SUB(NOW(), INTERVAL ? DAY)", [$days]);

printf("  %-34s %s\n", 'E-series walk-in bills (er_bills)',   $n($erBills));
printf("  %-34s %s\n", 'A-series ER short-stays (admissions)', $n($erStays));
printf("  %-34s %s\n", 'I-series In-Door stays (ipd_admissions)', $n($ipdStays));
echo "\n";

/* --------------------------------------------------------- 3. the coverage gap */

echo "3. COVERAGE GAP — stays carrying NO nursing record\n";
echo str_repeat('-', 78) . "\n";

echo "  E-series walk-in (er_bills)\n";
printf("    %-40s %s of %s\n", 'with no vitals / care log / handover',
    $n($erBills), $n($erBills));
echo "      STRUCTURAL, not a data problem: no clinical table carries an\n";
echo "      er_bill_id. 100% by construction. Every walk-in ER patient in the\n";
echo "      window above has no clinical record of any kind.\n\n";

echo "  A-series ER short-stay (admissions)\n";
$erNoVitals = $scalar(
    "SELECT COUNT(*) FROM admissions a
     WHERE a.admitted_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
       AND NOT EXISTS (SELECT 1 FROM admission_vitals v WHERE v.admission_id = a.id)", [$days]);
$erNoHandover = $scalar(
    "SELECT COUNT(*) FROM admissions a
     WHERE a.admitted_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
       AND NOT EXISTS (SELECT 1 FROM admission_handovers h WHERE h.admission_id = a.id)", [$days]);
printf("    %-40s %s of %s\n", 'with no vitals recorded',   $n($erNoVitals),   $n($erStays));
printf("    %-40s %s of %s\n", 'with no handover recorded', $n($erNoHandover), $n($erStays));
printf("    %-40s %s of %s\n", 'with no care-event log',    $n($erStays),      $n($erStays));
echo "      There is no care-event table on this path at all — an un-billed\n";
echo "      nursing observation cannot be recorded anywhere.\n\n";

echo "  I-series In-Door (ipd_admissions)\n";
$ipdNoVitals = $scalar(
    "SELECT COUNT(*) FROM ipd_admissions a
     WHERE a.admitted_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
       AND NOT EXISTS (SELECT 1 FROM ipd_vitals v WHERE v.admission_id = a.id)", [$days]);
$ipdNoCare = $scalar(
    "SELECT COUNT(*) FROM ipd_admissions a
     WHERE a.admitted_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
       AND NOT EXISTS (SELECT 1 FROM ipd_care_events c WHERE c.admission_id = a.id)", [$days]);
$ipdNoHandover = $scalar(
    "SELECT COUNT(*) FROM ipd_admissions a
     WHERE a.admitted_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
       AND NOT EXISTS (SELECT 1 FROM ipd_handovers h WHERE h.admission_id = a.id)", [$days]);
printf("    %-40s %s of %s\n", 'with no vitals recorded',   $n($ipdNoVitals),   $n($ipdStays));
printf("    %-40s %s of %s\n", 'with no care event logged', $n($ipdNoCare),     $n($ipdStays));
printf("    %-40s %s of %s\n", 'with no handover recorded', $n($ipdNoHandover), $n($ipdStays));
echo "\n";

/* ------------------------------------------------------------- 4. vitals cadence */

echo "4. VITALS CADENCE — does the 30-min nudge change behaviour?\n";
echo str_repeat('-', 78) . "\n";
echo "  ER short-stay shows a 'next set is due (every 30 min)' banner.\n";
echo "  IPD has no cadence nudge at all. Gaps below are minutes between\n";
echo "  consecutive readings on the same stay.\n\n";

$cadence = function (string $table, string $fk) use ($pdo, $days) {
    // Self-join each reading to the one immediately before it on the same stay.
    $sql = "
        SELECT COUNT(*) AS n,
               ROUND(AVG(gap)) AS avg_gap,
               MAX(gap) AS max_gap,
               SUM(gap <= 35) AS within_35
        FROM (
            SELECT TIMESTAMPDIFF(MINUTE, prev.recorded_at, v.recorded_at) AS gap
            FROM `$table` v
            JOIN `$fk` a ON a.id = v.admission_id
            JOIN `$table` prev ON prev.admission_id = v.admission_id
                              AND prev.recorded_at < v.recorded_at
            LEFT JOIN `$table` mid ON mid.admission_id = v.admission_id
                              AND mid.recorded_at > prev.recorded_at
                              AND mid.recorded_at < v.recorded_at
            WHERE mid.id IS NULL
              AND a.admitted_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ) g";
    try {
        $s = $pdo->prepare($sql);
        $s->execute([$days]);
        return $s->fetch();
    } catch (Throwable $e) { return null; }
};

foreach ([['ER short-stay', 'admission_vitals', 'admissions'],
          ['In-Door (IPD)', 'ipd_vitals', 'ipd_admissions']] as [$label, $vt, $at]) {
    $c = $cadence($vt, $at);
    if (!$c || (int) $c['n'] === 0) {
        printf("  %-16s no consecutive readings in the window\n", $label);
        continue;
    }
    printf("  %-16s intervals %4d   avg %5s min   max %6s min   within 35 min: %d%%\n",
        $label, (int) $c['n'], $c['avg_gap'] ?? '?', $c['max_gap'] ?? '?',
        (int) round(100 * (int) $c['within_35'] / max(1, (int) $c['n'])));
}
echo "\n";

/* ------------------------------------------------- 5. orphaned nurse assignment */

echo "5. PRIMARY NURSE — assigned, then never shown\n";
echo str_repeat('-', 78) . "\n";

if ($hasCol('ipd_admissions', 'assigned_nurse_id')) {
    $ipdWithNurse = $scalar(
        "SELECT COUNT(*) FROM ipd_admissions
         WHERE admitted_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
           AND assigned_nurse_id IS NOT NULL", [$days]);
    printf("  IPD stays with a primary nurse recorded   %s of %s\n", $n($ipdWithNurse), $n($ipdStays));
    echo "    ipd_admissions.assigned_nurse_id is WRITTEN at admit (mandatory) and\n";
    echo "    indexed by idx_ipd_admission_nurse, but NO page reads it — neither\n";
    echo "    ipd_admissions.php nor ipd_admission.php displays or reassigns it,\n";
    echo "    and the IPD handover never updates it, so it also goes stale.\n";
} else {
    echo "  ipd_admissions.assigned_nurse_id ABSENT — add_ipd_assigned_nurse.sql not run.\n";
}
echo "\n";
if ($hasCol('admissions', 'assigned_nurse_id')) {
    $erWithNurse = $scalar(
        "SELECT COUNT(*) FROM admissions
         WHERE admitted_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
           AND assigned_nurse_id IS NOT NULL", [$days]);
    printf("  ER stays with a primary nurse recorded    %s of %s\n", $n($erWithNurse), $n($erStays));
    echo "    ER does the opposite: shown on the board and the stay page, and kept\n";
    echo "    current on handover.\n";
}
echo "\n";

/* ------------------------------------------------- 6. un-billed care capture */

echo "6. UN-BILLED CARE CAPTURE — what ER structurally cannot record\n";
echo str_repeat('-', 78) . "\n";

if ($hasCol('admission_services', 'clinical_note')) {
    $erSvc = $scalar(
        "SELECT COUNT(*) FROM admission_services s
         JOIN admissions a ON a.id = s.admission_id
         WHERE a.admitted_at >= DATE_SUB(NOW(), INTERVAL ? DAY)", [$days]);
    $erSvcNoted = $scalar(
        "SELECT COUNT(*) FROM admission_services s
         JOIN admissions a ON a.id = s.admission_id
         WHERE a.admitted_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
           AND s.clinical_note IS NOT NULL AND s.clinical_note <> ''", [$days]);
    printf("  ER service lines                         %s\n", $n($erSvc));
    printf("    ...carrying a clinical note            %s\n", $n($erSvcNoted));
} else {
    echo "  ER service lines carrying a clinical note   n/a — the column does not\n";
    echo "    exist on this database, so ER has NO clinical free-text at all.\n";
}
echo "    Either way this note hangs off a CHARGEABLE line: nursing care that\n";
echo "    costs nothing cannot be written down on the ER path.\n\n";

$ipdCare = $scalar(
    "SELECT COUNT(*) FROM ipd_care_events c
     JOIN ipd_admissions a ON a.id = c.admission_id
     WHERE a.admitted_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
       AND c.event_type IN ('NURSING_CARE','OBSERVATION','MEDICATION')", [$days]);
printf("  IPD un-billed care events (nursing/obs/med) %s\n", $n($ipdCare));
echo "    This is the volume ER would have lost over the same window had those\n";
echo "    patients been on the ER path instead.\n\n";

echo str_repeat('=', 78) . "\n";
echo "Read-only. Nothing above was modified.\n";
