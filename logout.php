<?php
/**
 * End the session.
 *
 * session_destroy() ALONE is not a logout. It discards the server-side data but
 * leaves $_SESSION populated for the rest of this request and — more
 * importantly — leaves the session cookie sitting in the browser. On the
 * Android WebView wrapper, which has no browser chrome and no way for a user to
 * clear a cookie by hand, that cookie is the credential.
 *
 * So do all three, in the order PHP's own documentation prescribes:
 *   1. empty the array,
 *   2. expire the cookie in the browser,
 *   3. destroy the server-side store.
 */
require_once __DIR__ . '/config/auth.php';

// 1. Nothing left for anything later in this request to read.
$_SESSION = [];

// 2. Expire the cookie itself. Without this the browser keeps presenting the
//    same session id; PHP starts a fresh empty session against it and the id is
//    never rotated, so it remains a valid handle to reattach to.
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $params['path'] ?: '/',
        'domain'   => $params['domain'] ?? '',
        'secure'   => $params['secure'] ?? false,
        'httponly' => $params['httponly'] ?? true,
        'samesite' => ($params['samesite'] ?? '') ?: 'Lax',
    ]);
}

// 3. Now discard the server-side store.
session_destroy();

header('Location: /index.php');
exit;
