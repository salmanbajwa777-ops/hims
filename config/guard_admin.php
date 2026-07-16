<?php
require_once __DIR__ . '/auth.php';
require_login();

if (($_SESSION['base_role'] ?? '') !== 'ADMIN') {
    http_response_code(403);
    exit('Forbidden — admin access only.');
}
