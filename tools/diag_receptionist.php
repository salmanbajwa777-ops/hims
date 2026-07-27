<?php
// TEMPORARY diagnostic for the receptionist.php 500. Delete once resolved.
//
// The live page returns 500 with an empty body (display_errors off), so the fatal is
// invisible. This runs the same includes and the same queue query with errors on, and
// prints whatever breaks. Read-only — it renders nothing and writes nothing.

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');

// Catch a fatal that would otherwise produce the same blank 500 here.
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        echo "\n*** FATAL ***\n{$e['message']}\n  at {$e['file']}:{$e['line']}\n";
    }
});

echo "1. includes\n";
require_once __DIR__ . '/../config/auth.php';
require_login();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/permissions.php';
require_once __DIR__ . '/../config/notify.php';
require_once __DIR__ . '/../config/billing.php';
require_once __DIR__ . '/../config/tokens.php';
echo "   ok\n";

if (($_SESSION['base_role'] ?? '') !== 'ADMIN') {
    http_response_code(403);
    exit("admin only\n");
}

echo "2. token helpers present\n";
foreach (['token_code', 'issue_token', 'doctor_token_prefix', 'derive_token_prefix'] as $fn) {
    printf("   %-22s %s\n", $fn, function_exists($fn) ? 'yes' : 'MISSING');
}
// Anything still calling the removed label helper is the likely fatal.
printf("   %-22s %s\n", 'token_session_label', function_exists('token_session_label') ? 'yes' : 'removed (expected)');

echo "3. columns\n";
foreach ([['users', 'token_prefix'], ['visits', 'token_session']] as [$t, $c]) {
    $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $s->execute([$t, $c]);
    printf("   %-22s %s\n", "$t.$c", (int) $s->fetchColumn() ? 'ok' : 'MISSING');
}

echo "4. the queue query receptionist.php runs\n";
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
        LEFT JOIN admission_bills ab ON ab.admission_id = adm.id AND ab.voided_at IS NULL
        LEFT JOIN (
            SELECT bill_id, SUM(amount) AS refunded FROM refunds WHERE voided_at IS NULL GROUP BY bill_id
        ) r ON r.bill_id = b.id
        WHERE v.visit_date = CURDATE()
        ORDER BY v.created_at DESC
    ")->fetchAll();
    echo "   ok, " . count($rows) . " row(s)\n";
    foreach ($rows as $row) {
        echo '   token: ' . token_code($row['doctor_token_prefix'] ?? null, $row['doctor_name'] ?? '', $row['token_no'])
            . '  (' . $row['doctor_name'] . ")\n";
    }
} catch (Throwable $e) {
    echo '   QUERY FAILED: ' . $e->getMessage() . "\n";
}

echo "5. helper functions the page calls\n";
foreach (['user_holds_drawer', 'unclosed_business_days', 'refresh_session_permissions', 'has_permission'] as $fn) {
    printf("   %-30s %s\n", $fn, function_exists($fn) ? 'yes' : 'MISSING');
}

echo "\nDONE — no fatal above means the includes and query are fine.\n";
