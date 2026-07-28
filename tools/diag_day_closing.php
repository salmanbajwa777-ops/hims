<?php
/**
 * Day-closing reconciliation diagnostic.
 *
 * Answers "why doesn't the tally add up?" with live data instead of inference:
 * for one date + one cashier it lists every money row in the business-day
 * window, marks which ones day_cash_tally() counts, and shows exactly what is
 * missed and why.
 *
 * Admin-only, read-only — it writes nothing.
 *
 * Usage:  tools/diag_day_closing.php?date=2026-07-28[&user=<id>]
 */
require_once __DIR__ . '/../config/guard_admin.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/billing.php';

header('Content-Type: text/plain; charset=utf-8');

$date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d');
$only = isset($_GET['user']) ? (int) $_GET['user'] : null;

[$winStart, $winEnd] = business_day_window($pdo, $date);
echo "DAY CLOSING DIAGNOSTIC\n";
echo "date   : $date\n";
echo "window : $winStart  ->  $winEnd\n";
echo str_repeat('=', 78) . "\n\n";

/** Does a table exist? Several money tables are migration-gated. */
$has = function (string $t) use ($pdo): bool {
    try { $pdo->query("SELECT 1 FROM `$t` LIMIT 1"); return true; }
    catch (Throwable $e) { return false; }
};

echo "TABLE AVAILABILITY (a missing table is silently skipped by the tally)\n";
foreach (['bills','admission_bills','ipd_bills','er_bills','procedure_bills',
          'dental_procedure_payments','refunds','expenses'] as $t) {
    printf("  %-28s %s\n", $t, $has($t) ? 'present' : '** MISSING — contributes 0 **');
}
echo "\n";

// ---- Which cashiers have money in this window at all? ----
$cashiers = [];
$collect = function (string $sql) use ($pdo, $winStart, $winEnd, &$cashiers) {
    try {
        $s = $pdo->prepare($sql);
        $s->execute([$winStart, $winEnd]);
        foreach ($s->fetchAll() as $r) {
            $id = (int) $r['uid'];
            if ($id) { $cashiers[$id] = true; }
        }
    } catch (Throwable $e) { /* table absent */ }
};
$collect("SELECT DISTINCT paid_by_id AS uid FROM bills WHERE paid_at >= ? AND paid_at < ?");
$collect("SELECT DISTINCT paid_by_id AS uid FROM admission_bills WHERE paid_at >= ? AND paid_at < ?");
if ($has('er_bills'))        { $collect("SELECT DISTINCT paid_by_id AS uid FROM er_bills WHERE paid_at >= ? AND paid_at < ?"); }
if ($has('procedure_bills')) { $collect("SELECT DISTINCT paid_by_id AS uid FROM procedure_bills WHERE paid_at >= ? AND paid_at < ?"); }
if ($has('ipd_bills'))       { $collect("SELECT DISTINCT paid_by_id AS uid FROM ipd_bills WHERE paid_at >= ? AND paid_at < ?"); }

$ids = $only ? [$only] : array_keys($cashiers);
if (!$ids) { echo "No money recorded in this window at all.\n"; exit; }

$nameOf = function (int $id) use ($pdo): string {
    $s = $pdo->prepare('SELECT name FROM users WHERE id = ?');
    $s->execute([$id]);
    return (string) ($s->fetchColumn() ?: "user#$id");
};

// ---- Rows the tally does NOT count, across every stream ----
echo str_repeat('=', 78) . "\nMONEY THE TALLY DOES NOT COUNT\n" . str_repeat('=', 78) . "\n";
$grandMissed = 0.0;

// 1. Partial admission payments left at status='finalized'.
//    admission_discharge.php records paid_amount then sets status='finalized'
//    (not 'paid') until a write-off is approved — so real cash sits invisible.
try {
    $s = $pdo->prepare("
        SELECT ab.invoice_number, ab.paid_amount, ab.grand_total, ab.payment_method,
               ab.finalized_by_id, ab.status, a.status AS adm_status, p.name AS patient
        FROM admission_bills ab
        JOIN admissions a ON a.id = ab.admission_id
        JOIN visits v ON v.id = a.visit_id
        JOIN patients p ON p.id = v.patient_id
        WHERE ab.status = 'finalized' AND ab.paid_amount > 0
          AND ab.voided_at IS NULL
        ORDER BY ab.id DESC
    ");
    $s->execute();
    $rows = $s->fetchAll();
    echo "\n[1] PARTIAL ADMISSION PAYMENTS STILL AWAITING WRITE-OFF APPROVAL\n";
    echo "    status='finalized' + paid_amount>0. Cash was taken; the tally counts\n";
    echo "    only status='paid', so none of this appears in any shift close.\n\n";
    if (!$rows) {
        echo "    (none)\n";
    } else {
        $sum = 0.0;
        foreach ($rows as $r) {
            $who = $r['finalized_by_id'] ? $nameOf((int) $r['finalized_by_id']) : '—';
            printf("    %-16s %-22s %-8s Rs %10s of %-10s  collected by %s\n",
                $r['invoice_number'], mb_substr($r['patient'], 0, 22),
                $r['payment_method'] ?: '?',
                number_format((float) $r['paid_amount'], 2),
                number_format((float) $r['grand_total'], 2), $who);
            if (strtolower((string) $r['payment_method']) === 'cash') { $sum += (float) $r['paid_amount']; }
        }
        printf("\n    CASH not counted anywhere: Rs %s  (%d bill(s))\n", number_format($sum, 2), count($rows));
        $grandMissed += $sum;
    }
} catch (Throwable $e) {
    echo "    [error: " . $e->getMessage() . "]\n";
}

// 2. Paid rows with no drawer attribution — counted for nobody.
echo "\n[2] PAID ROWS WITH NO paid_by_id (belong to no one's shift)\n\n";
foreach ([['bills','consultation'], ['admission_bills','admission'],
          ['er_bills','ER'], ['procedure_bills','procedure'], ['ipd_bills','IPD']] as [$tbl, $label]) {
    if (!$has($tbl)) { continue; }
    try {
        $s = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(paid_amount),0) total
                            FROM `$tbl`
                            WHERE status='paid' AND voided_at IS NULL
                              AND paid_at >= ? AND paid_at < ?
                              AND (paid_by_id IS NULL OR paid_by_id = 0)");
        $s->execute([$winStart, $winEnd]);
        $r = $s->fetch();
        if ((int) $r['n'] > 0) {
            printf("    %-18s %d row(s)  Rs %s  ** ORPHANED **\n",
                   $label, (int) $r['n'], number_format((float) $r['total'], 2));
            $grandMissed += (float) $r['total'];
        } else {
            printf("    %-18s none\n", $label);
        }
    } catch (Throwable $e) { printf("    %-18s [%s]\n", $label, $e->getMessage()); }
}

// 3. Paid money that fell OUTSIDE the business-day window for its date.
echo "\n[3] PAID ON $date BUT OUTSIDE THE BUSINESS-DAY WINDOW\n";
echo "    (would land on a different closing than the operator expects)\n\n";
foreach ([['bills','consultation'], ['admission_bills','admission'],
          ['er_bills','ER'], ['procedure_bills','procedure']] as [$tbl, $label]) {
    if (!$has($tbl)) { continue; }
    try {
        $s = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(paid_amount),0) total
                            FROM `$tbl`
                            WHERE status='paid' AND voided_at IS NULL
                              AND DATE(paid_at) = ?
                              AND NOT (paid_at >= ? AND paid_at < ?)");
        $s->execute([$date, $winStart, $winEnd]);
        $r = $s->fetch();
        printf("    %-18s %d row(s)  Rs %s%s\n", $label, (int) $r['n'],
               number_format((float) $r['total'], 2),
               (int) $r['n'] > 0 ? '   <-- outside window' : '');
    } catch (Throwable $e) { printf("    %-18s [%s]\n", $label, $e->getMessage()); }
}

// ---- Every ER / admission / procedure / IPD bill on this date, and where it landed ----
echo "\n" . str_repeat('=', 78) . "\nEVERY NON-CONSULT BILL ON $date — AND WHICH CLOSING IT LANDS ON\n"
   . str_repeat('=', 78) . "\n";
echo "Answers \"where is my ER / discharge bill?\" directly. A bill is missing from\n";
echo "a shift close for exactly one of: wrong cashier, outside the window, not\n";
echo "'paid', voided, or no paid_by_id at all.\n";

foreach ([['er_bills','ER service','E'], ['admission_bills','Admission/discharge','A'],
          ['procedure_bills','Procedure','P'], ['ipd_bills','In-Door (IPD)','I']] as [$tbl, $label, $series]) {
    echo "\n--- $label ($tbl) ---\n";
    if (!$has($tbl)) {
        echo "    ** TABLE MISSING — the tally silently counts 0 for this stream. **\n";
        echo "    ** Run the matching sql/add_*.sql migration.                     **\n";
        continue;
    }
    try {
        // Everything on this calendar date regardless of status/window, so a
        // bill that "disappeared" is still listed with the reason why.
        $s = $pdo->prepare("SELECT invoice_number, status, payment_method, paid_amount,
                                   grand_total, paid_at, paid_by_id, voided_at
                            FROM `$tbl`
                            WHERE DATE(COALESCE(paid_at, created_at)) = ?
                            ORDER BY COALESCE(paid_at, created_at)");
        $s->execute([$date]);
        $rows = $s->fetchAll();
    } catch (Throwable $e) {
        // created_at may not exist on every table.
        try {
            $s = $pdo->prepare("SELECT invoice_number, status, payment_method, paid_amount,
                                       grand_total, paid_at, paid_by_id, voided_at
                                FROM `$tbl` WHERE DATE(paid_at) = ? ORDER BY paid_at");
            $s->execute([$date]);
            $rows = $s->fetchAll();
        } catch (Throwable $e2) { echo "    [error: " . $e2->getMessage() . "]\n"; continue; }
    }

    if (!$rows) { echo "    (no $label bills on this date)\n"; continue; }

    foreach ($rows as $r) {
        $why = [];
        if ($r['voided_at'])                       { $why[] = 'VOIDED'; }
        if (!in_array($r['status'], ['paid','finalized'], true)) { $why[] = "status='{$r['status']}'"; }
        if ($r['status'] === 'finalized' && (float) $r['paid_amount'] <= 0) { $why[] = 'nothing paid yet'; }
        if (!$r['paid_at'])                        { $why[] = 'no paid_at'; }
        elseif ($r['paid_at'] < $winStart || $r['paid_at'] >= $winEnd) {
            $why[] = 'OUTSIDE window (business day ' . business_day($pdo, $r['paid_at']) . ')';
        }
        if (!$r['paid_by_id'])                     { $why[] = 'NO paid_by_id — no shift owns it'; }

        $landed = $why ? ('NOT COUNTED: ' . implode(', ', $why))
                       : ('counts on ' . $nameOf((int) $r['paid_by_id']) . "'s $date close");
        printf("    %-14s %-10s %-6s Rs %10s  %s\n",
            $r['invoice_number'], $r['status'], $r['payment_method'] ?: '?',
            number_format((float) $r['paid_amount'], 2), $landed);
    }
}

// ---- Per-cashier tally breakdown ----
echo "\n" . str_repeat('=', 78) . "\nPER-CASHIER TALLY (what day_cash_tally actually returns)\n"
   . str_repeat('=', 78) . "\n";
foreach ($ids as $uid) {
    $t = day_cash_tally($pdo, $date, $uid);
    echo "\n" . $nameOf($uid) . "  (user #$uid)\n" . str_repeat('-', 60) . "\n";
    $line = function ($label, $cash, $cashN, $onl, $onlN) {
        printf("  %-22s cash Rs %10s (%2d)   online Rs %10s (%2d)\n",
            $label, number_format($cash, 2), $cashN, number_format($onl, 2), $onlN);
    };
    $line('Consultations', $t['cash_consult_total'], $t['cash_consult_count'],
                           $t['online_consult_total'], $t['online_consult_count']);
    $line('  ER (in admission)', $t['cash_er_total'], $t['cash_er_count'],
                           $t['online_er_total'], $t['online_er_count']);
    $line('  Procedures (ditto)', $t['cash_procedure_total'], $t['cash_procedure_count'],
                           $t['online_procedure_total'], $t['online_procedure_count']);
    $line('  Dental (ditto)', $t['cash_dental_total'], $t['cash_dental_count'],
                           $t['online_dental_total'], $t['online_dental_count']);
    $line('Admissions TOTAL', $t['cash_admission_total'], $t['cash_admission_count'],
                           $t['online_admission_total'], $t['online_admission_count']);
    printf("  %-22s Rs %10s (%d)\n", 'Cash refunds out', $t['cash_refund_total'], $t['cash_refund_count']);
    printf("  %-22s Rs %10s (%d)\n", 'Counter expenses', $t['expense_total'], $t['expense_count']);
    printf("  %-22s Rs %10s\n", 'EXPECTED IN HAND', number_format($t['expected_cash'], 2));

    // Independent recomputation — if this disagrees, the arithmetic is wrong.
    $check = $t['cash_consult_total'] + $t['cash_admission_total']
           - $t['cash_refund_total'] - $t['expense_total'];
    if (abs($check - $t['expected_cash']) > 0.01) {
        printf("  ** ARITHMETIC MISMATCH: independent sum = Rs %s **\n", number_format($check, 2));
    }
    // Admissions bucket must equal its own parts plus folded streams.
    $folded = $t['cash_er_total'] + $t['cash_procedure_total'] + $t['cash_dental_total'];
    if ($t['cash_admission_total'] + 0.01 < $folded) {
        echo "  ** FOLD ERROR: admission bucket is smaller than the streams folded into it **\n";
    }
}

echo "\n" . str_repeat('=', 78) . "\n";
printf("TOTAL CASH UNACCOUNTED FOR ACROSS ALL STREAMS: Rs %s\n", number_format($grandMissed, 2));
echo str_repeat('=', 78) . "\n";
