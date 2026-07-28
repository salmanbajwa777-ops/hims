<?php
/**
 * Serve a staff document.
 *
 * Two callers: an admin reviewing anyone's file from staff.php, and a user
 * opening their OWN file from profile.php. The row's user_id is the authority —
 * a non-admin asking for someone else's id gets a 404, not a 403, so the page
 * can't be used to probe which document ids exist.
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';

$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM staff_documents WHERE id = ?');
$stmt->execute([$id]);
$doc = $stmt->fetch();

$isAdmin = ($_SESSION['base_role'] ?? '') === 'ADMIN';
if (!$doc || (!$isAdmin && (int) $doc['user_id'] !== (int) $_SESSION['user_id'])) {
    http_response_code(404);
    exit('Document not found.');
}

// basename() so a stored path can never climb out of the upload directory.
$fullPath = __DIR__ . '/uploads/staff_docs/' . basename($doc['file_path']);

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
