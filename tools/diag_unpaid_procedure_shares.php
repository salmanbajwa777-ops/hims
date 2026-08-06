<?php
/**
 * Unpaid PROCEDURE shares — the backlog left by the payout bug (2026-08-06).
 *
 * THE BUG: payout_earning_lines() collected OPD consultations and IPD daily
 * rounds and nothing else, while doctor_share_statement.php has shown procedure
 * earnings since add_procedure_bills.sql went live. So every payout settled
 * since procedures started billing paid the consultation share and silently
 * dropped the procedure share. The doctor could SEE the money on the statement
 * and never be paid it — which is how it was caught (Dr Riaz Khan, July 2026:
 * Rs 3,808 disbursed against Rs 5,908 earned).
 *
 * This lists every paid, un-voided procedure line that has NO payout line
 * against it, grouped by doctor and month — i.e. exactly what a new payout for
 * that month would now pick up. It is the number to expect BEFORE creating the
 * catch-up payouts, and the way to confirm the backlog is empty afterwards.
 *
 * NOT a fix script, deliberately: the money must move through create_payout()
 * so it is frozen, numbered, audited and posted as a Doctor Shares expense like
 * every other payout. Hand-inserting rows here would produce paid money with no
 * DP- record behind it. Run this, then create one payout per doctor/month below.
 *
 * Admin-only, read-only — it writes nothing.
 *
 * Usage:  tools/diag_unpaid_procedure_shares.php
 */
require_once __DIR__ . '/../config/guard_admin.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/billing.php';

header('Content-Type: text/plain; charset=utf-8');

echo "UNPAID PROCEDURE SHARES\n";
echo "=======================\n\n";

// Does the ENUM already carry 'PROCEDURE'? If not, the migration has not run and
// no catch-up payout can be created yet — say so up front rather than printing a
// backlog the user cannot act on.
$enumReady = false;
try {
    $col = $pdo->query("
        SELECT column_type FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'doctor_payout_lines'
          AND column_name = 'source_type'
    ")->fetchColumn();
    $enumReady = $col !== false && stripos((string) $col, 'PROCEDURE') !== false;
    echo 'Migration sql/add_procedure_payout_lines.sql: '
       . ($enumReady ? "APPLIED\n\n" : "*** NOT APPLIED ***\n"
         . "  Run it before creating any catch-up payout. Until then a procedure\n"
         . "  line would be written with an empty source_type and collide.\n\n");
} catch (PDOException $e) {
    echo "Could not read the column definition: " . $e->getMessage() . "\n\n";
}

$dispCol = procedure_disposables_column($pdo) ? 'i.disposables_cost' : '0';
$args    = ['i.amount', 'i.doctor_share_pct', 'i.has_tax', 'i.tax_percent'];
$docSql  = doctor_split_sql(...[...$args, 'doctor', $dispCol]);

try {
    $q = $pdo->query("
        SELECT u.name AS doctor, u.id AS doctor_id,
               DATE_FORMAT(pb.paid_at, '%Y-%m') AS month,
               COUNT(*)                  AS lines_count,
               COALESCE(SUM(i.amount),0) AS collected,
               COALESCE(SUM($docSql),0)  AS doctor_share
        FROM procedure_bill_items i
        JOIN procedure_bills pb ON pb.id = i.procedure_bill_id
        JOIN users u            ON u.id = pb.doctor_id
        WHERE pb.status = 'paid' AND pb.voided_at IS NULL
          AND NOT EXISTS (
              SELECT 1 FROM doctor_payout_lines l
              WHERE l.source_type = 'PROCEDURE' AND l.source_id = i.id
                AND l.line_kind = 'EARNING'
          )
        GROUP BY u.id, u.name, month
        HAVING doctor_share > 0
        ORDER BY month DESC, u.name
    ");
    $rows = $q->fetchAll();
} catch (PDOException $e) {
    echo "Query failed: " . $e->getMessage() . "\n";
    echo "(Has sql/add_procedure_bills.sql been run?)\n";
    exit;
}

if (!$rows) {
    echo "No unpaid procedure shares. Every paid procedure line is on a payout.\n";
    exit;
}

printf("%-28s %-9s %7s %12s %12s\n", 'DOCTOR', 'MONTH', 'LINES', 'COLLECTED', 'OWED');
echo str_repeat('-', 72) . "\n";
$total = 0.0;
foreach ($rows as $r) {
    printf("%-28s %-9s %7d %12s %12s\n",
        substr($r['doctor'], 0, 28), $r['month'], (int) $r['lines_count'],
        number_format((float) $r['collected'], 2),
        number_format((float) $r['doctor_share'], 2));
    $total += (float) $r['doctor_share'];
}
echo str_repeat('-', 72) . "\n";
printf("%-47s %24s\n", 'TOTAL STILL OWED', number_format($total, 2));

echo "\nTO CLEAR THIS BACKLOG\n";
echo "---------------------\n";
if (!$enumReady) {
    echo "1. Run sql/add_procedure_payout_lines.sql FIRST (see above).\n";
}
echo ($enumReady ? "1" : "2") . ". On Doctor Payouts, create a payout for each doctor/month listed.\n";
echo "   Consultations already settled on an earlier payout are skipped by the\n";
echo "   UNIQUE guard, so a re-run of an already-paid month pays ONLY the\n";
echo "   procedure lines it missed — no double payment.\n";
echo ($enumReady ? "2" : "3") . ". Re-run this tool. A clean list means the backlog is cleared.\n";
