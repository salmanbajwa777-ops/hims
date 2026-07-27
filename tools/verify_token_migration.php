<?php
// One-off verifier for sql/add_token_codes.sql.
//
// Answers the only question that matters after a migration: did the columns and the
// re-keyed counter actually land in THIS database? information_schema is the authority
// — a page returning 200/302 proves nothing, since the auth guard redirects before any
// query touching the new columns ever runs.
//
// Read-only. DELETE THIS FILE once the migration is confirmed.

require_once __DIR__ . '/../config/auth.php';
require_login();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/permissions.php';

if (($_SESSION['base_role'] ?? '') !== 'ADMIN') {
    http_response_code(403);
    exit('Admin only.');
}

header('Content-Type: text/plain; charset=utf-8');

function col_exists(PDO $pdo, string $table, string $col): bool {
    $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $s->execute([$table, $col]);
    return (int) $s->fetchColumn() > 0;
}

echo "DB: " . $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n";
echo str_repeat('=', 58) . "\n";

$checks = [
    ['users',                 'token_prefix'],
    ['visits',                'token_session'],
    ['visit_queue_counters',  'token_session'],
];
$allOk = true;
foreach ($checks as [$t, $c]) {
    $ok = col_exists($pdo, $t, $c);
    $allOk = $allOk && $ok;
    printf("%-24s %-16s %s\n", $t, $c, $ok ? 'OK' : 'MISSING');
}

// The counter's PK must include token_session, or session 2 would collide with
// session 1 and hand out duplicate numbers.
$pk = $pdo->query("SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM information_schema.STATISTICS
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'visit_queue_counters'
                     AND INDEX_NAME = 'PRIMARY'")->fetchColumn();
$pkOk = $pk === 'doctor_id,visit_date,token_session';
$allOk = $allOk && $pkOk;
echo str_repeat('-', 58) . "\n";
printf("%-24s %-16s %s\n", 'counter PRIMARY KEY', '', $pkOk ? 'OK' : "WRONG ($pk)");

echo str_repeat('=', 58) . "\n";
echo $allOk ? "MIGRATION COMPLETE\n" : "MIGRATION INCOMPLETE — re-run sql/add_token_codes.sql\n";

// Prefixes in play, plus any collision needing an admin to break the tie.
if (col_exists($pdo, 'users', 'token_prefix')) {
    echo "\nDoctor prefixes:\n";
    foreach ($pdo->query("SELECT name, token_prefix FROM users
                          WHERE base_role = 'DOCTOR' ORDER BY name")->fetchAll() as $r) {
        printf("  %-10s %s\n", $r['token_prefix'] ?: '(none)', $r['name']);
    }

    $dupes = $pdo->query("SELECT token_prefix, GROUP_CONCAT(name SEPARATOR ' | ') AS docs, COUNT(*) n
                          FROM users WHERE base_role = 'DOCTOR' AND token_prefix IS NOT NULL
                          GROUP BY token_prefix HAVING n > 1")->fetchAll();
    echo "\nCollisions: " . ($dupes ? '' : "none\n");
    foreach ($dupes as $d) {
        echo "  {$d['token_prefix']} -> {$d['docs']}  (set a different prefix on staff.php)\n";
    }
}
