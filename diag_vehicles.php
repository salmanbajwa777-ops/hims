<?php
/**
 * Diagnostic: why is the Vehicles card not showing on expense_categories.php?
 *
 * Reports the exact state the page branches on — whether the vehicles table is
 * reachable, what the deployed file contains, and whether the seeded
 * sub-categories and the needs_vehicle flag are present.
 *
 * Admin-only. Delete once resolved.
 */
require_once __DIR__ . '/config/guard_admin.php';

header('Content-Type: text/plain; charset=utf-8');

echo "HIMS — vehicle setup diagnostic\n";
echo str_repeat('=', 60), "\n\n";

// 1. The exact probe expense_categories.php uses.
echo "1. vehiclesReady probe  (SELECT 1 FROM vehicles LIMIT 1)\n";
$ready = false;
try {
    $pdo->query('SELECT 1 FROM vehicles LIMIT 1');
    $ready = true;
    echo "   PASS — table reachable, the Vehicles card SHOULD render.\n";
} catch (PDOException $e) {
    echo "   FAIL — ", $e->getMessage(), "\n";
    echo "   => The card is hidden because of this.\n";
}

// 2. Does the table exist at all, and in which schema?
echo "\n2. vehicles table in information_schema\n";
try {
    $s = $pdo->query("SELECT table_schema, table_rows FROM information_schema.tables
                       WHERE table_name = 'vehicles'");
    $rows = $s->fetchAll();
    if (!$rows) { echo "   NOT FOUND in any schema — run sql/add_vehicle_expenses.sql\n"; }
    foreach ($rows as $r) { echo "   schema={$r['table_schema']}  approx_rows={$r['table_rows']}\n"; }
} catch (PDOException $e) { echo "   ERROR ", $e->getMessage(), "\n"; }

echo "\n3. Current database\n";
try { echo "   DATABASE() = ", $pdo->query('SELECT DATABASE()')->fetchColumn(), "\n"; }
catch (PDOException $e) { echo "   ERROR ", $e->getMessage(), "\n"; }

// 4. How many vehicles are actually in there.
echo "\n4. Vehicle rows\n";
if ($ready) {
    try {
        foreach ($pdo->query('SELECT id, name, registration, is_active FROM vehicles ORDER BY name')->fetchAll() as $v) {
            echo "   #{$v['id']}  {$v['name']} — {$v['registration']}  active={$v['is_active']}\n";
        }
        $c = (int) $pdo->query('SELECT COUNT(*) FROM vehicles')->fetchColumn();
        if ($c === 0) { echo "   (none yet — the card shows an empty table with an Add form)\n"; }
    } catch (PDOException $e) { echo "   ERROR ", $e->getMessage(), "\n"; }
} else { echo "   skipped\n"; }

// 5. Sub-categories seeded by the migration.
echo "\n5. expense_subcategories\n";
try {
    $rows = $pdo->query('SELECT s.id, s.name, s.tracks_fuel, s.category_id, c.name AS parent
                           FROM expense_subcategories s
                           LEFT JOIN expense_categories c ON c.id = s.category_id
                          ORDER BY s.sort_order')->fetchAll();
    if (!$rows) { echo "   EMPTY — section 5 of the migration did not seed.\n"; }
    foreach ($rows as $r) {
        echo "   #{$r['id']}  {$r['name']}  tracks_fuel={$r['tracks_fuel']}  parent={$r['parent']} (cat {$r['category_id']})\n";
    }
} catch (PDOException $e) { echo "   ERROR ", $e->getMessage(), "\n"; }

// 6. The flag that reveals the fields on the posting form.
echo "\n6. Categories with needs_vehicle = 1\n";
try {
    $rows = $pdo->query('SELECT id, name, needs_vehicle, is_active FROM expense_categories
                          WHERE needs_vehicle = 1')->fetchAll();
    if (!$rows) {
        echo "   NONE — the posting form will never show vehicle fields.\n";
        echo "   Fix: UPDATE expense_categories SET needs_vehicle = 1 WHERE name = 'Transport & Fuel';\n";
        echo "   Existing category names:\n";
        foreach ($pdo->query('SELECT id, name FROM expense_categories ORDER BY name')->fetchAll() as $c) {
            echo "      #{$c['id']}  '{$c['name']}'\n";
        }
    }
    foreach ($rows as $r) { echo "   #{$r['id']}  '{$r['name']}'  active={$r['is_active']}\n"; }
} catch (PDOException $e) {
    echo "   ERROR ", $e->getMessage(), "\n";
    echo "   => needs_vehicle column missing; section 3 of the migration did not run.\n";
}

// 7. Is the DEPLOYED file the new one?
echo "\n7. Deployed expense_categories.php\n";
$f = __DIR__ . '/expense_categories.php';
if (is_file($f)) {
    $src = file_get_contents($f);
    echo "   modified: ", date('d/m/Y H:i:s', filemtime($f)), "\n";
    echo "   size    : ", number_format(strlen($src)), " bytes\n";
    echo "   has Vehicles card : ", (str_contains($src, "section-title\">Vehicles") ? 'YES' : 'NO — OLD FILE STILL DEPLOYED'), "\n";
    echo "   has add_vehicle   : ", (str_contains($src, 'add_vehicle') ? 'YES' : 'NO'), "\n";
} else { echo "   NOT FOUND\n"; }

echo "\n8. Deployed expenses.php\n";
$f2 = __DIR__ . '/expenses.php';
if (is_file($f2)) {
    $src2 = file_get_contents($f2);
    echo "   modified: ", date('d/m/Y H:i:s', filemtime($f2)), "\n";
    echo "   has vehicleGroup  : ", (str_contains($src2, 'vehicleGroup') ? 'YES' : 'NO — OLD FILE STILL DEPLOYED'), "\n";
}

echo "\n", str_repeat('=', 60), "\nDone.\n";
