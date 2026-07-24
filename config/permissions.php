<?php
/**
 * Resolves a user's effective permissions: role defaults from role_permissions,
 * with per-user grants/revokes from user_permission_overrides layered on top.
 * Cached in $_SESSION so it's computed once per login.
 */

// Timezone belt-and-suspenders. config/db.php (gitignored, so deploys never
// update it) is supposed to pin the MySQL session to PKT; when a live copy
// without the pin sneaks back, every NOW()-written DATETIME lands 5h in the
// past and stay durations inflate by exactly that. This file is tracked and
// loaded right after db.php on every page that writes times, so enforcing the
// pin here survives any future db.php regression. Cheap (one session var set)
// and idempotent.
if (isset($pdo) && $pdo instanceof PDO) {
    $pdo->exec("SET time_zone = '+05:00'");
}

function load_permissions(PDO $pdo, int $userId, string $baseRole): array {
    $stmt = $pdo->prepare('
        SELECT p.`key`
        FROM role_permissions rp
        JOIN permissions p ON p.id = rp.permission_id
        WHERE rp.base_role = ?
    ');
    $stmt->execute([$baseRole]);
    $keys = array_column($stmt->fetchAll(), 'key');
    $effective = array_fill_keys($keys, true);

    $stmt = $pdo->prepare('
        SELECT p.`key`, o.granted
        FROM user_permission_overrides o
        JOIN permissions p ON p.id = o.permission_id
        WHERE o.user_id = ?
    ');
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll() as $row) {
        if ((int) $row['granted'] === 1) {
            $effective[$row['key']] = true;
        } else {
            unset($effective[$row['key']]);
        }
    }

    return array_keys($effective);
}

function refresh_session_permissions(PDO $pdo): void {
    if (empty($_SESSION['user_id'])) {
        return;
    }
    $_SESSION['permissions'] = load_permissions($pdo, (int) $_SESSION['user_id'], $_SESSION['base_role'] ?? '');
}

function has_permission(string $key): bool {
    return in_array($key, $_SESSION['permissions'] ?? [], true);
}

/**
 * Human label for a permission key. Uses the live `permissions` catalog when a
 * PDO is reachable (so it never drifts from what admins see on the Permissions
 * screen); falls back to a readable de-slug of the key if the DB isn't available
 * at this point in the request.
 */
function permission_label(string $key): string {
    $pdo = $GLOBALS['pdo'] ?? null;
    if ($pdo instanceof PDO) {
        try {
            $stmt = $pdo->prepare('SELECT label FROM permissions WHERE `key` = ?');
            $stmt->execute([$key]);
            $label = $stmt->fetchColumn();
            if ($label) { return (string) $label; }
        } catch (Throwable $e) {
            // fall through to the de-slugged key
        }
    }
    // e.g. RECEPTION_CLOSE_DAY -> "Reception Close Day"
    return ucwords(strtolower(str_replace('_', ' ', $key)));
}

/**
 * Gate a page/action on a permission. On denial, render a self-contained 403
 * page (no shared head/sidebar — those load AFTER this check) that NAMES the
 * missing capability and tells the user how to get it, instead of dumping a raw
 * "Forbidden — missing permission: KEY" line. Makes an access failure
 * self-explaining rather than a database dig. Access itself is unchanged; only
 * the denial experience is.
 */
function require_permission(string $key): void {
    if (has_permission($key)) {
        return;
    }
    http_response_code(403);
    $label = permission_label($key);
    // Deny cleanly for API/fetch callers that want JSON, keep the friendly page
    // for browsers. Cheap Accept sniff; defaults to the HTML page.
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    if (stripos($accept, 'application/json') !== false && stripos($accept, 'text/html') === false) {
        header('Content-Type: application/json');
        exit(json_encode(['error' => 'forbidden', 'permission' => $key, 'label' => $label]));
    }
    $labelEsc = htmlspecialchars($label, ENT_QUOTES);
    $keyEsc   = htmlspecialchars($key, ENT_QUOTES);
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>HIMS — Access denied</title>
<style>
  :root { --teal:#0E5456; --teal-2:#1A7F7E; --ink:#0f172a; --muted:#64748b;
          --bg:#f1f5f9; --card:#fff; --border:#e2e8f0; }
  * { box-sizing: border-box; }
  body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
         padding:24px; font-family:'Inter',system-ui,-apple-system,Segoe UI,Roboto,sans-serif;
         background:var(--bg); color:var(--ink); }
  .card { background:var(--card); border:1px solid var(--border); border-radius:16px;
          box-shadow:0 10px 30px rgba(15,23,42,.08); max-width:440px; width:100%;
          padding:34px 32px; text-align:center; }
  .badge { width:56px; height:56px; border-radius:14px; margin:0 auto 18px;
           display:flex; align-items:center; justify-content:center;
           background:#FEF2F2; color:#B91C1C; }
  .badge svg { width:28px; height:28px; }
  h1 { font-size:19px; margin:0 0 8px; font-weight:700; }
  p { font-size:14px; line-height:1.55; color:var(--muted); margin:0 0 14px; }
  .perm { display:inline-block; background:var(--bg); border:1px solid var(--border);
          border-radius:8px; padding:8px 12px; font-weight:600; color:var(--ink);
          font-size:13.5px; margin:2px 0 16px; }
  .key { font-family:'Courier New',monospace; font-size:11px; color:var(--muted);
         display:block; margin-top:4px; font-weight:400; }
  .actions { display:flex; gap:10px; justify-content:center; flex-wrap:wrap; margin-top:6px; }
  .btn { display:inline-block; text-decoration:none; font-weight:600; font-size:13.5px;
         padding:10px 18px; border-radius:10px; border:1px solid var(--border);
         color:var(--ink); background:var(--card); }
  .btn.primary { background:var(--teal); border-color:var(--teal); color:#fff; }
  .btn:hover { border-color:var(--teal-2); }
</style>
</head>
<body>
  <div class="card">
    <div class="badge">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
    </div>
    <h1>You don't have access to this</h1>
    <p>This action needs the permission below. Ask an administrator to grant it to you from <strong>Staff &amp; Doctors → Permissions</strong>.</p>
    <span class="perm">{$labelEsc}<span class="key">{$keyEsc}</span></span>
    <div class="actions">
      <a class="btn" href="javascript:history.back()">Go back</a>
      <a class="btn primary" href="dashboard.php">Dashboard</a>
    </div>
  </div>
</body>
</html>
HTML;
    exit;
}
