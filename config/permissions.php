<?php
/**
 * Resolves a user's effective permissions: role defaults from role_permissions,
 * with per-user grants/revokes from user_permission_overrides layered on top.
 * Cached in $_SESSION so it's computed once per login.
 */

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

function require_permission(string $key): void {
    if (!has_permission($key)) {
        http_response_code(403);
        exit('Forbidden — missing permission: ' . htmlspecialchars($key));
    }
}
