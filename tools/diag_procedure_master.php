<?php
/**
 * procedure_master.php 500 diagnostic.
 *
 * The catalogue page started returning HTTP 500 after a procedure was added and
 * assigned to a doctor. The page code itself is defensive — every optional
 * column is probed and degrades rather than fatals — so the fault is in the
 * DATA or the SCHEMA, not the branching. Guessing at it from the source has a
 * bad track record here; this runs the page's ACTUAL load path step by step and
 * prints the real exception instead of letting the host swallow it into a 500.
 *
 * Admin-only, read-only — it writes nothing.
 *
 * Usage:  tools/diag_procedure_master.php
 */
require_once __DIR__ . '/../config/guard_admin.php';
require_once __DIR__ . '/../config/db.php';

// The whole point: the live host has display_errors off, which is what turns a
// one-line exception into an opaque 500. Force it ON for this page only.
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=utf-8');

echo "procedure_master.php LOAD-PATH DIAGNOSTIC\n";
echo str_repeat('=', 78) . "\n\n";

// A fatal (not an exception) still dies silently, so catch it on the way out.
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        echo "\n*** FATAL ***\n{$e['message']}\n  at {$e['file']}:{$e['line']}\n";
    }
});

/** Run one step, print what happened, never abort the rest of the run. */
function step(string $label, callable $fn) {
    echo "--- $label\n";
    try {
        $out = $fn();
        echo "    OK" . ($out !== null ? " : $out" : '') . "\n\n";
    } catch (Throwable $e) {
        echo "    *** FAILED ***\n";
        echo "    " . get_class($e) . ": " . $e->getMessage() . "\n";
        echo "    at " . $e->getFile() . ":" . $e->getLine() . "\n\n";
    }
}

// ---------------------------------------------------------------------------
// 1. Schema. Which optional columns actually exist on the live database?
//    procedure_master.php branches on exactly these three probes.
// ---------------------------------------------------------------------------
echo "SCHEMA — procedure_master columns\n";
try {
    $cols = $pdo->query('
        SELECT column_name, column_type, is_nullable, column_default
          FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = \'procedure_master\'
         ORDER BY ordinal_position
    ')->fetchAll();
    foreach ($cols as $c) {
        printf("  %-22s %-42s %s%s\n",
            $c['column_name'], $c['column_type'],
            $c['is_nullable'] === 'YES' ? 'NULL' : 'NOT NULL',
            $c['column_default'] !== null ? " default={$c['column_default']}" : ''
        );
    }
    // The one that must be GONE. If it is still here AND still NOT NULL with no
    // default, every INSERT from the current code fails in strict mode.
    $names = array_column($cols, 'column_name');
    echo "\n  category present : " . (in_array('category', $names, true) ? '** YES — drop_dental_category.sql NOT run **' : 'no (correct)') . "\n";
    foreach (['has_disposables', 'is_dental', 'has_lab_component', 'default_lab_charge', 'consent_template'] as $c) {
        printf("  %-20s : %s\n", $c, in_array($c, $names, true) ? 'present' : 'ABSENT (page degrades)');
    }
} catch (Throwable $e) {
    echo "  *** " . $e->getMessage() . "\n";
}
echo "\n";

// ---------------------------------------------------------------------------
// 2. The three migration probes, exactly as the page calls them.
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../config/billing.php';
require_once __DIR__ . '/../config/consent.php';

step('procedure_disposables_flag($pdo)', fn() => var_export(procedure_disposables_flag($pdo), true));
step("column_exists(\$pdo, 'procedure_master', 'is_dental')", fn() => var_export(column_exists($pdo, 'procedure_master', 'is_dental'), true));
step('consent_column_live($pdo)', fn() => var_export(consent_column_live($pdo), true));

// ---------------------------------------------------------------------------
// 3. The page's four load queries, in order. This is where a 500 lives.
// ---------------------------------------------------------------------------
$procedures = [];
$doctors = [];
$assignRows = [];

step("SELECT * FROM procedure_master ORDER BY name", function () use ($pdo, &$procedures) {
    $procedures = $pdo->query('SELECT * FROM procedure_master ORDER BY name')->fetchAll();
    return count($procedures) . ' row(s)';
});

step("SELECT id, name, specialty FROM users WHERE base_role = 'DOCTOR'", function () use ($pdo, &$doctors) {
    $doctors = $pdo->query('SELECT id, name, specialty FROM users WHERE base_role = "DOCTOR" ORDER BY name')->fetchAll();
    return count($doctors) . ' doctor(s)';
});

step('JOIN doctor_procedures -> procedure_master (the assignments query)', function () use ($pdo, &$assignRows) {
    $assignRows = $pdo->query('
        SELECT dp.*, pm.name AS procedure_name, pm.fee AS master_fee, pm.is_active AS master_active
          FROM doctor_procedures dp
          JOIN procedure_master pm ON pm.id = dp.procedure_master_id
         ORDER BY pm.name
    ')->fetchAll();
    return count($assignRows) . ' assignment(s)';
});

// ---------------------------------------------------------------------------
// 4. json_encode of the two payloads the page inlines into <script>.
//    json_encode returns FALSE on malformed UTF-8 rather than throwing, which
//    would emit `const ASSIGNMENTS = ;` — a JS syntax error, not a 500, but it
//    breaks the assignment editor silently. Worth knowing either way.
// ---------------------------------------------------------------------------
echo "--- json_encode payloads (invalid UTF-8 in a procedure name shows up here)\n";
$assignmentsByDoctor = [];
foreach ($assignRows as $r) {
    $assignmentsByDoctor[(int) $r['doctor_id']][] = [
        'id' => (int) $r['id'],
        'procedure_id' => (int) $r['procedure_master_id'],
        'fee' => $r['fee'],
        'share' => $r['doctor_share_pct'],
        'has_tax' => (int) $r['has_tax'],
        'tax_pct' => $r['tax_percent'],
    ];
}
$procsJson = [];
foreach ($procedures as $p) {
    $procsJson[] = ['id' => (int) $p['id'], 'name' => $p['name'], 'fee' => (float) $p['fee'], 'active' => (int) $p['is_active']];
}
$flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
foreach (['PROCEDURES' => $procsJson, 'ASSIGNMENTS' => $assignmentsByDoctor ?: new stdClass()] as $label => $payload) {
    $json = json_encode($payload, $flags);
    printf("    %-12s %s\n", $label, $json === false
        ? '*** json_encode FAILED: ' . json_last_error_msg() . ' ***'
        : 'OK (' . strlen($json) . ' bytes)');
}
echo "\n";

// ---------------------------------------------------------------------------
// 5. Orphans + duplicates. The JOIN above hides an assignment whose procedure
//    was deleted; the UNIQUE key makes a duplicate impossible to save but not
//    impossible to already have.
// ---------------------------------------------------------------------------
echo "DATA INTEGRITY — doctor_procedures\n";
try {
    $orphans = $pdo->query('
        SELECT dp.id, dp.doctor_id, dp.procedure_master_id
          FROM doctor_procedures dp
          LEFT JOIN procedure_master pm ON pm.id = dp.procedure_master_id
         WHERE pm.id IS NULL
    ')->fetchAll();
    echo '  orphaned assignments (procedure deleted) : ' . count($orphans) . "\n";
    foreach ($orphans as $o) {
        echo "    dp#{$o['id']} doctor={$o['doctor_id']} -> missing procedure {$o['procedure_master_id']}\n";
    }

    $dupes = $pdo->query('
        SELECT doctor_id, procedure_master_id, COUNT(*) n
          FROM doctor_procedures
         GROUP BY doctor_id, procedure_master_id HAVING n > 1
    ')->fetchAll();
    echo '  duplicate (doctor, procedure) pairs      : ' . count($dupes) . "\n";
    foreach ($dupes as $d) {
        echo "    doctor={$d['doctor_id']} procedure={$d['procedure_master_id']} x{$d['n']}\n";
    }

    $noDoc = $pdo->query('
        SELECT dp.id, dp.doctor_id
          FROM doctor_procedures dp
          LEFT JOIN users u ON u.id = dp.doctor_id
         WHERE u.id IS NULL
    ')->fetchAll();
    echo '  assignments whose doctor is gone         : ' . count($noDoc) . "\n";
    foreach ($noDoc as $o) {
        echo "    dp#{$o['id']} -> missing user {$o['doctor_id']}\n";
    }
} catch (Throwable $e) {
    echo '  *** ' . $e->getMessage() . "\n";
}
echo "\n";

// ---------------------------------------------------------------------------
// 6. The partials the page renders through. A fatal in the sidebar or head is
//    indistinguishable from one in the page when all you have is a 500.
// ---------------------------------------------------------------------------
echo "PARTIALS / PERMISSIONS\n";
step('refresh_session_permissions($pdo)', function () use ($pdo) {
    require_once __DIR__ . '/../config/permissions.php';
    refresh_session_permissions($pdo);
    return count($_SESSION['permissions'] ?? []) . ' permission(s) in session';
});

echo "\nIf every step above says OK, the failure is in the HTML render below the\n";
echo "queries — re-run procedure_master.php itself with display_errors on.\n";
