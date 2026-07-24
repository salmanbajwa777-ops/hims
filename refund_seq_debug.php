<?php
// TEMP diagnostic — inspect refund_sequences vs the actual refunds rows, and
// AUTO-FIX the 2026 counter so the next refund number is unused. Delete after use.
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
refresh_session_permissions($pdo);
require_permission('RECEPTION_ISSUE_REFUNDS');

header('Content-Type: text/plain; charset=utf-8');

echo "=== refund_sequences ===\n";
foreach ($pdo->query('SELECT * FROM refund_sequences ORDER BY sequence_year')->fetchAll() as $r) {
    echo "year={$r['sequence_year']}  last_sequence={$r['last_sequence']}\n";
}

echo "\n=== refunds (id, refund_number, bill_id, amount, created_at) ===\n";
foreach ($pdo->query('SELECT id, refund_number, bill_id, amount, created_at FROM refunds ORDER BY id')->fetchAll() as $r) {
    // show the raw bytes/length in case of hidden whitespace
    $rn = $r['refund_number'];
    echo "id={$r['id']}  refund_number=[{$rn}] len=" . strlen($rn)
       . "  bill_id={$r['bill_id']}  amount={$r['amount']}  created={$r['created_at']}\n";
}

$year = (int) date('Y');
echo "\n=== computed max for RF-{$year}-% ===\n";
$maxStmt = $pdo->prepare("
    SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(refund_number, '-', -1) AS UNSIGNED)), 0)
    FROM refunds WHERE refund_number LIKE ?
");
$maxStmt->execute(['RF-' . $year . '-%']);
$max = (int) $maxStmt->fetchColumn();
echo "existingMax = {$max}\n";

echo "\n=== APPLYING FIX: set refund_sequences[{$year}].last_sequence = GREATEST(current, {$max}) ===\n";
$pdo->prepare('
    INSERT INTO refund_sequences (sequence_year, last_sequence)
    VALUES (?, ?)
    ON DUPLICATE KEY UPDATE last_sequence = GREATEST(last_sequence, ?)
')->execute([$year, $max, $max]);

$after = $pdo->prepare('SELECT last_sequence FROM refund_sequences WHERE sequence_year = ?');
$after->execute([$year]);
echo "after fix: last_sequence = " . (int) $after->fetchColumn() . "\n";
echo "\nNext refund number will be RF-{$year}-" . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT) . "\n";
echo "\nDONE. Delete refund_seq_debug.php now.\n";
