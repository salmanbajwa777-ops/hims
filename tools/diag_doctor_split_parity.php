<?php
/**
 * doctor_split() vs doctor_split_sql() parity check.
 *
 * The money rule is written twice — once in PHP for per-row work, once as a SQL
 * expression for set-based aggregation — and the two MUST agree, because the
 * Doctor Share Statement sums the SQL form over the per-line snapshot columns
 * while the P&L and the doctor-facing exposure figure use the PHP form. If they
 * disagree, those reports disagree about the same rows and the drift is silent:
 * both numbers look plausible.
 *
 * They HAVE drifted once. The SQL form clamped only $disposables while the PHP
 * form clamped all four inputs, so an out-of-range share/tax percentage, a
 * negative amount, or — worst — a NULL tax_percent or NULL disposables produced
 * different money. A NULL is the dangerous case: in SQL it makes the whole
 * arithmetic expression NULL, silently zeroing a doctor's share for that line.
 *
 * This turns the "keep them in lockstep" comment into a check you can run.
 * It compares the two forms over EVERY live row that feeds a share figure and
 * reports any pair differing by more than the 0.009 money epsilon.
 *
 * Admin-only, read-only — it writes nothing.
 *
 * Usage:  tools/diag_doctor_split_parity.php[?limit=2000]
 */
require_once __DIR__ . '/../config/guard_admin.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/billing.php';

header('Content-Type: text/plain; charset=utf-8');

$limit = max(1, min(50000, (int) ($_GET['limit'] ?? 5000)));
$EPS   = 0.009;   // the money epsilon used throughout the billing code

echo "DOCTOR SPLIT PARITY — PHP vs SQL\n";
echo "epsilon : $EPS\n";
echo "limit   : $limit rows per source\n";
echo str_repeat('=', 78) . "\n\n";

/** Does a table exist? Several money tables are migration-gated. */
$has = function (string $t) use ($pdo): bool {
    try { $pdo->query("SELECT 1 FROM `$t` LIMIT 1"); return true; }
    catch (PDOException $e) { return false; }
};

$parts = ['tax', 'doctor', 'clinic', 'clinic_net', 'disposables'];

/**
 * One comparison source: a query returning the four (or five) split inputs per
 * row, evaluated BOTH ways. The SQL side is computed by the database using the
 * very expression the reports use, so this tests the emitted SQL, not a copy.
 */
$sources = [];

// --- Consultations -------------------------------------------------------
$sources['consultation bills'] = [
    'sql' => "
        SELECT b.id,
               b.paid_amount        AS amt,
               dr.consult_share_pct AS share,
               dr.consult_has_tax   AS has_tax,
               dr.consult_tax_pct   AS tax,
               0                    AS disposables,
               " . implode(', ', array_map(
                   fn($p) => doctor_split_sql('b.paid_amount', 'dr.consult_share_pct', 'dr.consult_has_tax', 'dr.consult_tax_pct', $p) . " AS sql_$p",
                   $parts
               )) . "
          FROM bills b
          JOIN visits v ON v.id = b.visit_id
          JOIN users dr ON dr.id = v.doctor_id
         WHERE b.voided_at IS NULL
         ORDER BY b.id DESC
         LIMIT $limit",
    'requires' => ['bills', 'visits', 'users'],
];

// --- Procedure bill items (per-line snapshots) ---------------------------
if ($has('procedure_bill_items')) {
    $dispCol = procedure_disposables_column($pdo) ? 'i.disposables_cost' : '0';
    $args    = ['i.amount', 'i.doctor_share_pct', 'i.has_tax', 'i.tax_percent'];
    $sources['procedure bill items'] = [
        'sql' => "
            SELECT i.id,
                   i.amount            AS amt,
                   i.doctor_share_pct  AS share,
                   i.has_tax           AS has_tax,
                   i.tax_percent       AS tax,
                   $dispCol            AS disposables,
                   " . implode(', ', array_map(
                       fn($p) => doctor_split_sql(...[...$args, $p, $dispCol]) . " AS sql_$p",
                       $parts
                   )) . "
              FROM procedure_bill_items i
             ORDER BY i.id DESC
             LIMIT $limit",
        'requires' => ['procedure_bill_items'],
    ];
}

// --- IPD consultant rounds ----------------------------------------------
if ($has('ipd_doctor_visits')) {
    $sources['IPD round visits'] = [
        'sql' => "
            SELECT dv.id,
                   dv.visit_charge      AS amt,
                   dr.consult_share_pct AS share,
                   dr.consult_has_tax   AS has_tax,
                   dr.consult_tax_pct   AS tax,
                   0                    AS disposables,
                   " . implode(', ', array_map(
                       fn($p) => doctor_split_sql('dv.visit_charge', 'dr.consult_share_pct', 'dr.consult_has_tax', 'dr.consult_tax_pct', $p) . " AS sql_$p",
                       $parts
                   )) . "
              FROM ipd_doctor_visits dv
              JOIN users dr ON dr.id = dv.doctor_id
             WHERE dv.is_paid = 1
             ORDER BY dv.id DESC
             LIMIT $limit",
        'requires' => ['ipd_doctor_visits', 'users'],
    ];
}

$grandRows = 0;
$grandBad  = 0;

foreach ($sources as $label => $src) {
    foreach ($src['requires'] as $t) {
        if (!$has($t)) {
            printf("%-24s SKIPPED (no table `%s`)\n\n", $label, $t);
            continue 2;
        }
    }

    try {
        $rows = $pdo->query($src['sql'])->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        printf("%-24s SKIPPED (%s)\n\n", $label, $e->getMessage());
        continue;
    }

    $bad = 0;
    foreach ($rows as $r) {
        // The PHP form is typed float, so a NULL column arrives as 0.0 — which
        // is exactly what the SQL form's COALESCE(...,0) produces. That
        // equivalence is precisely what this check is asserting.
        $php = doctor_split(
            (float) $r['amt'],
            (float) $r['share'],
            (bool) $r['has_tax'],
            (float) $r['tax'],
            (float) $r['disposables']
        );

        foreach ($parts as $p) {
            $diff = abs($php[$p] - (float) $r["sql_$p"]);
            if ($diff >= $EPS) {
                if ($bad === 0) {
                    printf("%-24s MISMATCHES\n", $label);
                    printf("  %-10s %-12s %14s %14s %10s\n", 'ROW', 'PART', 'PHP', 'SQL', 'DIFF');
                }
                $bad++;
                printf("  %-10s %-12s %14.4f %14.4f %10.4f\n",
                    $r['id'], $p, $php[$p], (float) $r["sql_$p"], $diff);
                if ($bad > 40) { echo "  ... further mismatches suppressed\n"; break 2; }
            }
        }
    }

    $grandRows += count($rows);
    $grandBad  += $bad;

    if ($bad === 0) {
        printf("%-24s OK — %d rows agree\n\n", $label, count($rows));
    } else {
        printf("  %d mismatching values over %d rows\n\n", $bad, count($rows));
    }
}

echo str_repeat('=', 78) . "\n";
if ($grandBad === 0) {
    printf("PASS — %d rows checked, the two forms agree everywhere.\n", $grandRows);
} else {
    printf("FAIL — %d mismatching values over %d rows.\n", $grandBad, $grandRows);
    echo "The Doctor Share Statement and the P&L are reporting different money\n";
    echo "for these rows. Fix doctor_split_sql() to mirror doctor_split(), then\n";
    echo "re-run. Do NOT pay out against a share statement until this passes.\n";
}
