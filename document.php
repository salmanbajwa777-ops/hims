<?php
require_once __DIR__ . '/config/guard_admin.php';
require_once __DIR__ . '/config/db.php';

$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM staff_documents WHERE id = ?');
$stmt->execute([$id]);
$doc = $stmt->fetch();

if (!$doc) {
    http_response_code(404);
    exit('Document not found.');
}

$fullPath = __DIR__ . '/uploads/staff_docs/' . $doc['file_path'];

if (!is_file($fullPath)) {
    http_response_code(404);
    exit('File missing on server.');
}

$ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
$mime = $ext === 'pdf' ? 'application/pdf' : ($ext === 'png' ? 'image/png' : 'image/jpeg');

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($fullPath));
header('Content-Disposition: inline; filename="' . basename($doc['original_name']) . '"');
header('X-Content-Type-Options: nosniff');
readfile($fullPath);
