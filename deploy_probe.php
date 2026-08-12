<?php
/**
 * Deploy probe — reports what is ACTUALLY on the server for a few files.
 *
 * Exists because a green Actions run is not proof a given file was uploaded: a
 * previously-failed run poisons .ftp-deploy-sync-state.json and later runs skip
 * files they believe are already present. New files still upload, which makes
 * the deploy look healthy while modified files stay stale.
 *
 * No login required BY DESIGN — the whole point is to check deployment when the
 * page you are debugging is behind a login you cannot script. It exposes only
 * file sizes, mtimes and a yes/no marker check; no data, no config, no source.
 *
 * DELETE THIS FILE once the vehicles question is closed.
 */
header('Content-Type: text/plain; charset=utf-8');

echo "HIMS deploy probe — ", date('d/m/Y H:i:s'), " PKT\n";
echo str_repeat('=', 58), "\n\n";

$checks = [
    'expense_categories.php' => 'section-title">Vehicles',
    'expenses.php'           => 'id="postToggle"',
    'vehicle_report.php'     => 'Fuel cost / km',
    'vehicle_last_meter.php' => 'last PLAUSIBLE reading',
    'config/vehicle_costs.php' => 'function veh_metrics',
];

foreach ($checks as $file => $marker) {
    $p = __DIR__ . '/' . $file;
    printf("%-26s ", $file);
    if (!is_file($p)) { echo "MISSING\n"; continue; }
    $src = file_get_contents($p);
    printf("%7s bytes  %s  marker:%s\n",
        number_format(strlen($src)),
        date('d/m H:i', filemtime($p)),
        str_contains($src, $marker) ? 'YES' : 'NO  <-- STALE FILE');
}

echo "\n", str_repeat('-', 58), "\n";
echo "Marker YES = the deployed file contains the expected new code.\n";
echo "Marker NO  = an old copy is still on the server.\n";
