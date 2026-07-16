<?php
require_once __DIR__ . '/auth.php';
require_login();

if (($_SESSION['base_role'] ?? '') !== 'ADMIN') {
    http_response_code(403);
    exit('Forbidden — admin access only.');
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/permissions.php';
refresh_session_permissions($pdo);
