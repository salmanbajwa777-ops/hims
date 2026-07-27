<?php
// TEMPORARY diagnostic for the receptionist.php 500. Delete once resolved.
//
// The live page returns 500 with an empty body, so the fatal is invisible. This runs
// the page's own includes and queries with display_errors ON and prints what breaks.
//
// Deliberately NOT gated behind require_login(): the failure may be in the auth/session
// bootstrap itself, and a guard would hide it. It exposes no patient data — only
// column presence and error text — and is deleted as soon as the page is fixed.

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        echo "\n*** FATAL ***\n{$e['message']}\n  at {$e['file']}:{$e['line']}\n";
    }
});

echo "PHP " . PHP_VERSION . "\n\n";

echo "1. config/db.php\n";
require_once __DIR__ . '/../config/db.php';
echo "   connected, DB=" . $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n";

echo "\n2. the other includes receptionist.php loads\n";
foreach (['auth', 'permissions', 'notify', 'billing', 'tokens', 'admission_actions'] as $inc) {
    require_once __DIR__ . '/../config/' . $inc . '.php';
    echo "   $inc ok\n";
}

echo "\n3. void columns (the suspected cause)\n";
foreach (['bills', 'admission_bills', 'refunds'] as $t) {
    $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $s->execute([$t, 'voided_at']);
    printf("   %-18s voided_at %s\n", $t, (int) $s->fetchColumn() ? 'present' : 'MISSING');
}

echo "\n4. token columns\n";
foreach ([['users', 'token_prefix'], ['visits', 'token_session']] as [$t, $c]) {
    $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $s->execute([$t, $c]);
    printf("   %-18s %-14s %s\n", $t, $c, (int) $s->fetchColumn() ? 'present' : 'MISSING');
}

echo "\n5. tables the queue joins\n";
foreach (['visits','patients','users','doctor_consult_types','bills','admissions','admission_bills','refunds'] as $t) {
    $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $s->execute([$t]);
    printf("   %-22s %s\n", $t, (int) $s->fetchColumn() ? 'ok' : 'MISSING');
}

echo "\n6. the exact queue query\n";
$admVoidCol = true;
try { $pdo->query('SELECT voided_at FROM admission_bills LIMIT 1'); }
catch (PDOException $e) { $admVoidCol = false; }
echo '   admission_bills.voided_at usable: ' . ($admVoidCol ? 'yes' : 'no') . "\n";
$abVoidFilter = $admVoidCol ? 'AND ab.voided_at IS NULL' : '';
try {
    $rows = $pdo->query("
        SELECT v.id AS visit_id, v.token_no, v.token_session, v.consult_status, v.disposition,
               v.created_at, v.started_at, v.finished_at, v.doctor_id,
               p.id AS patient_id, p.mrn, p.name AS patient_name, p.dob, p.phone,
               dr.name AS doctor_name, dr.token_prefix AS doctor_token_prefix,
               adm.id AS admission_id, adm.status AS admission_status,
               dct.label AS consult_label,
               b.id AS bill_id, b.grand_total, b.paid_amount, b.status AS bill_status,
               COALESCE(r.refunded, 0) AS refunded,
               ab.id AS adm_bill_id, ab.status AS adm_bill_status,
               COALESCE(ab.paid_amount, 0) AS adm_paid_amount
        FROM visits v
        JOIN patients p ON p.id = v.patient_id
        JOIN users dr ON dr.id = v.doctor_id
        JOIN doctor_consult_types dct ON dct.id = v.doctor_consult_type_id
        LEFT JOIN bills b ON b.visit_id = v.id AND b.voided_at IS NULL
        LEFT JOIN admissions adm ON adm.visit_id = v.id
        LEFT JOIN admission_bills ab ON ab.admission_id = adm.id $abVoidFilter
        LEFT JOIN (
            SELECT bill_id, SUM(amount) AS refunded FROM refunds WHERE voided_at IS NULL GROUP BY bill_id
        ) r ON r.bill_id = b.id
        WHERE v.visit_date = CURDATE()
        ORDER BY v.created_at DESC
    ")->fetchAll();
    echo '   QUERY OK, ' . count($rows) . " row(s)\n";
    foreach ($rows as $row) {
        echo '   ' . token_code($row['doctor_token_prefix'] ?? null, $row['doctor_name'] ?? '', $row['token_no'])
            . '  ' . $row['doctor_name'] . "\n";
    }
} catch (Throwable $e) {
    echo '   QUERY FAILED: ' . $e->getMessage() . "\n";
}

echo "\n7. other queries on the page\n";
foreach ([
    'admission_rates' => 'SELECT admission_type, rate_amount, rate_basis FROM admission_rates WHERE is_enabled = 1',
    'doctors'         => "SELECT id, name FROM users WHERE base_role = 'DOCTOR' ORDER BY name",
] as $label => $q) {
    try { $n = count($pdo->query($q)->fetchAll()); echo "   $label ok ($n)\n"; }
    catch (Throwable $e) { echo "   $label FAILED: " . $e->getMessage() . "\n"; }
}

echo "\n8. partials the page renders\n";
foreach (['head.php', 'sidebar.php', 'quick_header.php'] as $p) {
    printf("   %-18s %s\n", $p, is_file(__DIR__ . '/../partials/' . $p) ? 'present' : 'MISSING');
}

echo "\nDONE — if nothing failed above, the fault is in the page's HTML/render half.\n";
