<?php
/**
 * Notification actions for the header bell.
 *
 * Two jobs:
 *   - POST action=read_all      → mark everything read, then bounce back
 *   - GET  ?open=<id>           → mark one read and forward to its link
 *
 * Both are scoped to the signed-in user inside the query itself, so an id
 * belonging to someone else is a silent no-op rather than an error — there is
 * nothing here that reveals whether another user's notification exists.
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/notifications.php';

$uid = (int) $_SESSION['user_id'];

/**
 * Only ever redirect somewhere inside this app.
 *
 * Notification links are written by our own notify_* callers, but this page
 * takes ?open= from the URL and forwards to whatever that row holds — so the
 * value is treated as untrusted. Anything protocol-relative, absolute, or
 * containing a scheme is dropped, which closes the open-redirect that would
 * otherwise let a crafted row bounce a signed-in user off-site.
 */
function notif_safe_link(?string $link): ?string
{
    if ($link === null) {
        return null;
    }
    $link = trim($link);
    if ($link === '' || str_starts_with($link, '/') || str_starts_with($link, '\\') || str_contains($link, '//')) {
        return null;
    }
    if (preg_match('~^[a-zA-Z][a-zA-Z0-9+.-]*:~', $link)) {
        return null;   // http:, javascript:, data:, …
    }
    return $link;
}

/** Where to return to after acting. Same-origin rule as above. */
$back = notif_safe_link($_GET['from'] ?? $_POST['from'] ?? null) ?? 'dashboard.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'read_all') {
    notif_mark_all_read($pdo, $uid);
    header('Location: ' . $back);
    exit;
}

if (isset($_GET['open'])) {
    $id = (int) $_GET['open'];
    notif_mark_read($pdo, $uid, $id);

    // Re-read the row to find where it points — scoped to this user, so a
    // guessed id cannot be used to discover another person's link.
    $target = null;
    try {
        $stmt = $pdo->prepare('SELECT link FROM notifications WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $uid]);
        $row = $stmt->fetch();
        $target = $row ? notif_safe_link($row['link']) : null;
    } catch (Throwable $e) {
        $target = null;
    }

    header('Location: ' . ($target ?? $back));
    exit;
}

header('Location: ' . $back);
exit;
