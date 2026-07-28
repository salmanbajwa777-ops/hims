<?php
/**
 * Start / stop "View as staff". POST-only (a GET would let a stray link or a
 * prefetch flip who you are).
 *
 * start — requires ADMIN; guarded by config/guard_admin.php.
 * stop  — must NOT use guard_admin: while impersonating, the session's
 *         base_role is the TARGET's, so an admin viewing a receptionist would
 *         be locked out of the very endpoint that gives them their own account
 *         back. Authorisation for stop is the presence of a parked admin
 *         identity, which only imp_start() can create.
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
require_once __DIR__ . '/config/impersonation.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /staff.php');
    exit;
}

$action = $_POST['action'] ?? '';

if ($action === 'stop') {
    $landing = imp_stop($pdo);
    header('Location: ' . $landing);
    exit;
}

if ($action === 'start') {
    // Full admin check for starting (see file header for why stop differs).
    if (($_SESSION['base_role'] ?? '') !== 'ADMIN' || is_impersonating()) {
        http_response_code(403);
        exit('Forbidden — admin access only.');
    }

    $targetId = (int) ($_POST['user_id'] ?? 0);
    $error = imp_start($pdo, $targetId);

    if ($error !== '') {
        $_SESSION['flash_error'] = $error;
        header('Location: /staff.php');
        exit;
    }

    // Land where the target lands at their own login, so the admin sees the
    // first screen that staff member actually sees.
    require_once __DIR__ . '/config/landing.php';
    header('Location: ' . landing_page_for_role($_SESSION['base_role'] ?? ''));
    exit;
}

header('Location: /staff.php');
exit;
