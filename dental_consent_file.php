<?php
/**
 * Serve a signed dental consent scan.
 *
 * The files live outside the web path's reach (uploads/dental_consents/ carries
 * a deny-all .htaccess written by dental_consent_dir()), so this is the only
 * way to read one — and it checks a permission first.
 *
 * Gated on DENTAL_VIEW_ACCOUNTS rather than admin-only: reception files these
 * scans and needs to reopen them, and the same people already see the account
 * the consent belongs to. A missing row and a forbidden row both return 404, so
 * this page cannot be used to probe which consent ids exist.
 *
 * RECEPTION_MANAGE_CONSENT is accepted too, because dental_consents now also
 * holds procedure consents (see sql/add_procedure_consent.sql) and the front
 * desk that filed a circumcision consent will not hold a dental permission.
 * Either key can open either kind: both are held by the same front desk, and
 * splitting the check per consent type would mean the person who uploaded a
 * scan could be unable to reopen it.
 *
 * Modelled on document.php, the app's existing private-file server.
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
refresh_session_permissions($pdo);

$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT id, scan_path, scan_original_name FROM dental_consents
                        WHERE id = ? AND voided_at IS NULL');
$stmt->execute([$id]);
$consent = $stmt->fetch();

if (!$consent || !$consent['scan_path']
    || !(has_permission('DENTAL_VIEW_ACCOUNTS') || has_permission('RECEPTION_MANAGE_CONSENT'))) {
    http_response_code(404);
    exit('Consent scan not found.');
}

// basename() on a name this application generated itself — belt and braces, so
// a stored path could never climb out of the upload directory even if the row
// were tampered with directly in the database.
$fullPath = __DIR__ . '/uploads/dental_consents/' . basename($consent['scan_path']);

if (!is_file($fullPath)) {
    http_response_code(404);
    exit('File missing on server.');
}

$ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
$mime = $ext === 'pdf' ? 'application/pdf' : ($ext === 'png' ? 'image/png' : 'image/jpeg');

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($fullPath));
header('Content-Disposition: inline; filename="' . basename($consent['scan_original_name'] ?: 'consent.' . $ext) . '"');
header('X-Content-Type-Options: nosniff');
readfile($fullPath);
