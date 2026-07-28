<?php
/**
 * For every page that CALLS audit_log(), resolve its real include chain and
 * assert the helper is actually defined. A grep for "requires permissions.php"
 * gives false passes (a page may reach it transitively, or not at all), so this
 * follows require_once/require statements recursively instead.
 */
$ROOT = dirname(__DIR__, 2);

function resolve_includes(string $file, array &$seen): void {
    $real = realpath($file);
    if (!$real || isset($seen[$real])) { return; }
    $seen[$real] = true;
    $src = @file_get_contents($real);
    if ($src === false) { return; }
    // require_once __DIR__ . '/x.php'  /  require __DIR__ . "/x.php"
    if (preg_match_all('/(?:require|include)(?:_once)?\s*(?:\(\s*)?__DIR__\s*\.\s*[\'"]([^\'"]+)[\'"]/', $src, $m)) {
        foreach ($m[1] as $rel) {
            resolve_includes(dirname($real) . '/' . ltrim($rel, '/'), $seen);
        }
    }
}

$callers = [];
foreach (glob("$ROOT/*.php") as $f) { $callers[] = $f; }
foreach (glob("$ROOT/cron/*.php") as $f) { $callers[] = $f; }
foreach (glob("$ROOT/config/*.php") as $f) { $callers[] = $f; }

$bad = [];
foreach ($callers as $f) {
    $src = file_get_contents($f);
    if (!preg_match('/\baudit_log\s*\(\s*\$/', $src)) { continue; }
    $seen = [];
    resolve_includes($f, $seen);
    $defines = false;
    foreach (array_keys($seen) as $inc) {
        if (str_contains(@file_get_contents($inc) ?: '', 'function audit_log(')) { $defines = true; break; }
    }
    // db.php is gitignored; treat db.example.php as its stand-in for the chain.
    if (!$defines) {
        $has = false;
        foreach (array_keys($seen) as $inc) {
            if (basename($inc) === 'db.php') { $has = true; }
        }
        $bad[] = basename($f) . ($has ? '' : ' (db.php missing locally)');
    }
}

if ($bad) {
    echo "PAGES THAT CALL audit_log() WITHOUT REACHING ITS DEFINITION:\n";
    foreach ($bad as $b) { echo "  $b\n"; }
    exit(1);
}
echo "ok — every audit_log() caller reaches config/permissions.php\n";
