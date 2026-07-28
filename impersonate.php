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

// Both start and stop are token-checked: a forged stop is less damaging than a
// forged start, but yanking an admin out mid-task is still someone else driving
// their session.
if (!imp_csrf_valid($_POST['imp_csrf'] ?? null)) {
    http_response_code(400);
    exit('Invalid request — please go back and try again.');
}

$action = $_POST['action'] ?? '';

if ($action === 'stop') {
    $landing = imp_stop($pdo);
    // '' means imp_stop() destroyed the session because the admin is no longer
    // entitled to it (demoted / deactivated / deleted mid-impersonation).
    header('Location: ' . ($landing !== '' ? $landing : '/index.php?ended=1'));
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
