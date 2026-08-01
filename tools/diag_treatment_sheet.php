<?php
/**
 * Treatment-sheet migration diagnostic.
 *
 * Answers "did sql/ipd/add_ipd_treatment_sheet.sql actually land, and is the
 * doctor gate intact?" from live data instead of from the fact that phpMyAdmin
 * said "query OK".
 *
 * Every schema question is answered from information_schema, never from the
 * presence of a .sql file. On this host a migration can half-apply (the DB user
 * is denied CREATE ROUTINE, #1044) and several past "migration was run" claims
 * turned out to be wrong, so the only trustworthy answer is a query.
 *
 * The single most important row here is DOCTOR GATE. If it ever reads FAIL,
 * STAFF can approve their own medication orders and the clinical safety rule of
 * the whole feature is gone.
 *
 * Admin-only, read-only — it writes nothing.
 *
 * Usage:  tools/diag_treatment_sheet.php
 */
require_once __DIR__ . '/../config/auth.php';
require_login();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/permissions.php';
refresh_session_permissions($pdo);

if (($_SESSION['base_role'] ?? '') !== 'ADMIN') {
    http_response_code(403);
    exit('Admin only.');
}

header('Content-Type: text/plain; charset=utf-8');

$pass = 0; $fail = 0; $warn = 0;
function row(string $label, bool $ok, string $detail, bool $isWarn = false): void {
    global $pass, $fail, $warn;
    if ($ok)            { $pass++; $tag = 'PASS'; }
    elseif ($isWarn)    { $warn++; $tag = 'WARN'; }
    else                { $fail++; $tag = 'FAIL'; }
    printf("  [%s]  %-34s %s\n", $tag, $label, $detail);
}
function q(PDO $pdo, string $sql, array $p = []) {
    $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn();
}

echo "TREATMENT SHEET — MIGRATION DIAGNOSTIC\n";
echo "Database: " . q($pdo, 'SELECT DATABASE()') . "\n";
echo "Run at:   " . date('d/m/Y H:i') . " PKT\n";
echo str_repeat('=', 74) . "\n\n";

// ---------------------------------------------------------------- 1. Tables
echo "1. TABLES\n";
$tables = ['ipd_drug_formulary','patient_allergies','ipd_medication_orders',
           'ipd_medication_admins','ipd_medication_audit','ipd_frequency_times'];
$present = [];
foreach ($tables as $t) {
    $n = (int) q($pdo, 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$t]);
    if ($n) { $present[] = $t; }
    row($t, (bool) $n, $n ? 'exists' : 'MISSING — migration did not complete');
}
$allTables = count($present) === count($tables);
echo "\n";

if (!$allTables) {
    echo "STOP: " . (count($tables) - count($present)) . " table(s) missing. Re-run\n";
    echo "      sql/ipd/add_ipd_treatment_sheet.sql before going further.\n";
    exit(1);
}

// ------------------------------------------------------- 2. Critical columns
echo "2. COLUMNS THAT CARRY THE RULES\n";
$cols = [
    ['ipd_medication_orders', 'approval_status',       'the doctor gate'],
    ['ipd_medication_orders', 'approved_by_id',        'who signed'],
    ['ipd_medication_orders', 'discontinued_reason',   'why a drug stopped'],
    ['ipd_medication_orders', 'generic_name_snapshot', 'generic, kept separate'],
    ['ipd_medication_orders', 'brand_name_snapshot',   'brand, kept separate'],
    ['ipd_medication_orders', 'allergy_override_reason','signed allergy override'],
    ['ipd_drug_formulary',    'brand_name',            'primary trade name'],
    ['ipd_drug_formulary',    'allergy_group',         'cross-reactivity bucket'],
    ['ipd_medication_admins', 'slot_kind',             'scheduled / PRN / STAT'],
];
foreach ($cols as [$t, $c, $why]) {
    $n = (int) q($pdo, 'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$t, $c]);
    row("$t.$c", (bool) $n, $n ? $why : "MISSING — $why");
}
echo "\n";

// ------------------------------------------------------------- 3. Enum shape
echo "3. ENUMS\n";
$enums = [
    ['ipd_medication_orders', 'route',     ['PO','IV','IM','SC','PR','PV','TOP','NEB','SL','NG']],
    ['ipd_medication_orders', 'frequency', ['OD','BD','TDS','QID','Q6H','Q8H','STAT','PRN']],
    ['ipd_medication_admins', 'status',    ['PENDING','GIVEN','HELD','MISSED','CANCELLED']],
];
foreach ($enums as [$t, $c, $vals]) {
    $type = (string) q($pdo, 'SELECT column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$t, $c]);
    $missing = array_values(array_filter($vals, fn($v) => stripos($type, "'$v'") === false));
    row("$t.$c", !$missing, $missing ? 'missing: ' . implode(',', $missing) : count($vals) . ' values present');
}
$uq = (int) q($pdo, "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'ipd_medication_admins' AND index_name = 'uq_slot'");
row('uq_slot (double-tap guard)', $uq === 3, "$uq of 3 columns");
echo "\n";

// ----------------------------------------------------------------- 4. Seeds
echo "4. SEED DATA\n";
$drugs = (int) q($pdo, 'SELECT COUNT(*) FROM ipd_drug_formulary');
row('formulary drugs', $drugs >= 45, "$drugs seeded (expect >= 45)");
$hi = (int) q($pdo, 'SELECT COUNT(*) FROM ipd_drug_formulary WHERE is_high_alert = 1');
row('high-alert flagged', $hi >= 12, "$hi drugs (expect >= 12)");
$brands = (int) q($pdo, "SELECT COUNT(*) FROM ipd_drug_formulary WHERE brand_name IS NOT NULL AND brand_name <> ''");
row('drugs with a brand name', $brands >= 40, "$brands carry brand_name (expect >= 40)");
$freqs = (int) q($pdo, 'SELECT COUNT(*) FROM ipd_frequency_times');
row('frequency codes', $freqs >= 8, "$freqs seeded (expect 8)");
$tds = (string) q($pdo, "SELECT times_csv FROM ipd_frequency_times WHERE code = 'TDS'");
row('TDS clock times', $tds === '08:00,14:00,20:00', $tds ?: 'not set');
echo "\n";

// ------------------------------------------------------- 5. THE DOCTOR GATE
echo "5. THE DOCTOR GATE  <-- the one that matters\n";
$perms = (int) q($pdo, "SELECT COUNT(*) FROM permissions WHERE `key` IN
    ('IPD_VIEW_TREATMENT_SHEET','IPD_WRITE_MED_ORDER','IPD_APPROVE_MED_ORDER',
     'IPD_ADMINISTER_MED','IPD_DISCONTINUE_MED','IPD_MANAGE_FORMULARY','IPD_MANAGE_ALLERGIES')");
row('permissions created', $perms === 7, "$perms of 7");

$staffGate = (int) q($pdo, "SELECT COUNT(*) FROM role_permissions rp
    JOIN permissions p ON p.id = rp.permission_id
    WHERE rp.base_role = 'STAFF' AND p.`key` IN ('IPD_APPROVE_MED_ORDER','IPD_DISCONTINUE_MED')");
row('STAFF cannot approve/discontinue', $staffGate === 0,
    $staffGate === 0 ? 'gate intact (0 grants)' : "*** $staffGate GRANT(S) — STAFF CAN SELF-APPROVE ***");

$docGate = (int) q($pdo, "SELECT COUNT(*) FROM role_permissions rp
    JOIN permissions p ON p.id = rp.permission_id
    WHERE rp.base_role = 'DOCTOR' AND p.`key` IN ('IPD_APPROVE_MED_ORDER','IPD_DISCONTINUE_MED')");
row('DOCTOR can approve/discontinue', $docGate === 2, "$docGate of 2 grants");

$staffWrite = (int) q($pdo, "SELECT COUNT(*) FROM role_permissions rp
    JOIN permissions p ON p.id = rp.permission_id
    WHERE rp.base_role = 'STAFF' AND p.`key` IN ('IPD_WRITE_MED_ORDER','IPD_ADMINISTER_MED')");
row('STAFF can write + administer', $staffWrite === 2, "$staffWrite of 2 grants");

$formAdmin = (int) q($pdo, "SELECT COUNT(*) FROM role_permissions rp
    JOIN permissions p ON p.id = rp.permission_id
    WHERE p.`key` = 'IPD_MANAGE_FORMULARY' AND rp.base_role <> 'ADMIN'");
row('formulary is admin-only', $formAdmin === 0, $formAdmin === 0 ? 'no non-admin grants' : "$formAdmin non-admin grant(s)");

// A per-user override can silently reopen the gate the role grants closed.
$override = (int) q($pdo, "SELECT COUNT(*) FROM user_permission_overrides o
    JOIN permissions p ON p.id = o.permission_id
    JOIN users u ON u.id = o.user_id
    WHERE o.granted = 1 AND u.base_role = 'STAFF'
      AND p.`key` IN ('IPD_APPROVE_MED_ORDER','IPD_DISCONTINUE_MED')");
row('no per-user gate overrides', $override === 0,
    $override === 0 ? 'no STAFF user was granted approve' : "*** $override STAFF USER(S) GRANTED APPROVE ***");
echo "\n";

// ------------------------------------------------------------- 6. Live usage
echo "6. LIVE USAGE\n";
$openAdm = (int) q($pdo, "SELECT COUNT(*) FROM ipd_admissions WHERE status <> 'DISCHARGED'");
$orders  = (int) q($pdo, 'SELECT COUNT(*) FROM ipd_medication_orders');
$pending = (int) q($pdo, "SELECT COUNT(*) FROM ipd_medication_orders WHERE approval_status = 'PENDING' AND status = 'ACTIVE'");
$slots   = (int) q($pdo, 'SELECT COUNT(*) FROM ipd_medication_admins');
$allerg  = (int) q($pdo, 'SELECT COUNT(*) FROM patient_allergies WHERE is_active = 1');

echo "  currently admitted (IPD)     : $openAdm\n";
echo "  medication orders written    : $orders\n";
echo "  awaiting doctor approval     : $pending\n";
echo "  administration slots         : $slots\n";
echo "  active patient allergies     : $allerg\n";

if ($orders === 0) {
    echo "\n  (No orders yet — expected immediately after the migration.)\n";
}

// Admitted patients with no approved sheet: the real operational number.
if ($openAdm > 0) {
    $unsigned = (int) q($pdo, "
        SELECT COUNT(*) FROM ipd_admissions a
        WHERE a.status <> 'DISCHARGED'
          AND NOT EXISTS (
              SELECT 1 FROM ipd_medication_orders o
              WHERE o.admission_id = a.id AND o.approval_status = 'APPROVED' AND o.status = 'ACTIVE'
          )");
    echo "\n  in-patients with NO approved treatment sheet: $unsigned of $openAdm\n";
    if ($unsigned > 0) {
        echo "  (These now show the warning banner + ward-list flag.)\n";
        $st = $pdo->query("
            SELECT a.id, p.name, p.mrn, a.room_category, a.room_no
            FROM ipd_admissions a
            JOIN visits v ON v.id = a.visit_id
            JOIN patients p ON p.id = v.patient_id
            WHERE a.status <> 'DISCHARGED'
              AND NOT EXISTS (SELECT 1 FROM ipd_medication_orders o
                              WHERE o.admission_id = a.id AND o.approval_status = 'APPROVED' AND o.status = 'ACTIVE')
            ORDER BY a.admitted_at LIMIT 15");
        foreach ($st->fetchAll() as $r) {
            printf("      #%-4d %-28s %-10s %s %s\n", $r['id'], $r['name'], $r['mrn'], $r['room_category'], $r['room_no']);
        }
    }
}

echo "\n" . str_repeat('=', 74) . "\n";
printf("PASS: %d   WARN: %d   FAIL: %d\n", $pass, $warn, $fail);
if ($fail === 0) {
    echo "\nMigration verified. The treatment sheet is live and the doctor gate holds.\n";
} else {
    echo "\n*** $fail check(s) FAILED — do not rely on the treatment sheet until fixed. ***\n";
}
