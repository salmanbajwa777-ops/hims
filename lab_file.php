<?php
/** Serves an attached lab PDF. Admin only; uploads/ is not web-reachable. */
require_once __DIR__ . '/config/guard_admin.php';
require_once __DIR__ . '/config/lab.php';
$st = $pdo->prepare('SELECT * FROM lab_result_files WHERE id = ?');
$st->execute([(int) ($_GET['id'] ?? 0)]);
$f = $st->fetch();
$path = $f ? lab_upload_dir() . '/' . basename($f['stored_name']) : '';
if (!$f || !is_file($path)) { http_response_code(404); exit('File not found.'); }
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $f['original_name']) . '"');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($path));
readfile($path);
