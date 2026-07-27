<?php
// TEMPORARY. Renders receptionist.php in-process and reports the real fatal.
//
// diag_recep.php proved the includes, the schema and every query are fine, so the
// fault is in the render half. Rather than keep guessing, this executes the actual
// page with display_errors on, discards its HTML, and prints only the error.
//
// A fake session is installed first so require_login() and the permission gate pass
// without a browser cookie. Read-only: output is buffered and thrown away, and the
// page performs no writes on a plain GET.
//
// Delete as soon as the 500 is fixed.

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        // Flush whatever the page had buffered so the fatal is visible.
        while (ob_get_level() > 0) { ob_end_clean(); }
        echo "\n*** FATAL ***\n{$e['message']}\n  at {$e['file']}:{$e['line']}\n";
    }
});

require_once __DIR__ . '/../config/db.php';

// Borrow a real ADMIN id so the page's guards pass.
$uid = (int) $pdo->query("SELECT id FROM users WHERE base_role = 'ADMIN' AND is_active = 1 ORDER BY id LIMIT 1")
                 ->fetchColumn();
if (!$uid) { exit("no active admin user to impersonate\n"); }

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$_SESSION['user_id']   = $uid;
$_SESSION['base_role'] = 'ADMIN';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = $_POST = [];

echo "impersonating admin user #$uid\n";
echo "rendering receptionist.php …\n\n";

ob_start();
try {
    require __DIR__ . '/../receptionist.php';
    $html = ob_get_clean();
    echo "RENDER OK — " . strlen($html) . " bytes of HTML, no fatal.\n";
    echo "If the live page still 500s, the difference is the request context\n";
    echo "(real session, cookies, POST) rather than the page code itself.\n";
} catch (Throwable $e) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    echo "*** THROWN ***\n";
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
    echo '  at ' . $e->getFile() . ':' . $e->getLine() . "\n\n";
    echo $e->getTraceAsString() . "\n";
}
