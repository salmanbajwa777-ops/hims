<?php
/**
 * Staff document storage — shared by staff.php (admin uploads for anyone) and
 * profile.php (a user uploading their own).
 *
 * Both entry points must agree on the allowed types, the size cap and the
 * stored-filename shape, so the rules live here rather than being copied.
 */

/** Document categories offered in the picker. Keys are the ENUM values. */
function staff_doc_types(): array
{
    return [
        'CNIC' => 'CNIC',
        'EDUCATIONAL_DEGREE' => 'Educational Degree',
        'REGISTRATION' => 'Registration (PMDC / Nursing Council / etc.)',
        'EXPERIENCE_LETTER' => 'Experience Letter',
        'CV' => 'CV',
        'OTHER' => 'Other',
    ];
}

const STAFF_DOC_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];
const STAFF_DOC_MAX_BYTES = 10 * 1024 * 1024;

/**
 * Absolute path to the upload directory, created on first use.
 *
 * The directory is gitignored, so its .htaccess can never arrive by deploy —
 * we write it here instead. Without it these files are fetchable directly at
 * /uploads/staff_docs/<name>, and CNIC scans are not something to leave behind
 * an unguessable filename.
 */
function staff_doc_dir(): string
{
    $dir = dirname(__DIR__) . '/uploads/staff_docs/';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $guard = $dir . '.htaccess';
    if (!is_file($guard)) {
        // Apache 2.4 syntax first, 2.2 fallback — hPanel has run both.
        file_put_contents($guard, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
    return $dir;
}

/**
 * Validate a $_FILES['doc_file'] batch against $_POST['doc_type'].
 *
 * Returns [$pendingDocs, $error]. Nothing is written to disk or the database —
 * the caller moves the files once it has an owning user id, so a failed upload
 * can never leave a half-registered row behind.
 */
function staff_docs_validate(?array $docFiles, array $docTypeInputs): array
{
    $types = staff_doc_types();
    $pending = [];

    if (!$docFiles || !isset($docFiles['error'])) {
        return [[], ''];
    }

    foreach ($docFiles['error'] as $i => $fileError) {
        if ($fileError === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($fileError === UPLOAD_ERR_INI_SIZE || $fileError === UPLOAD_ERR_FORM_SIZE) {
            return [[], 'That file is larger than the server allows (10MB max).'];
        }
        if ($fileError !== UPLOAD_ERR_OK) {
            return [[], 'One of the documents failed to upload. Please try again.'];
        }

        $docType = $docTypeInputs[$i] ?? '';
        if (!array_key_exists($docType, $types)) {
            return [[], 'Invalid document type selected.'];
        }

        $origName = $docFiles['name'][$i];
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        if (!in_array($ext, STAFF_DOC_EXTENSIONS, true)) {
            return [[], "\"$origName\" must be a PDF, JPG, or PNG file."];
        }
        if ($docFiles['size'][$i] > STAFF_DOC_MAX_BYTES) {
            return [[], "\"$origName\" is larger than 10MB."];
        }

        $pending[] = [
            'type' => $docType,
            'tmp' => $docFiles['tmp_name'][$i],
            'name' => $origName,
            'size' => $docFiles['size'][$i],
            'ext' => $ext,
        ];
    }

    return [$pending, ''];
}

/**
 * Move validated uploads into place and record them. Returns the number stored.
 *
 * $uploaderId is who performed the upload — it differs from $userId when an
 * admin uploads on someone's behalf, which is the whole point of keeping it.
 */
function staff_docs_store(PDO $pdo, array $pendingDocs, int $userId, int $uploaderId): int
{
    if (!$pendingDocs) {
        return 0;
    }
    $dir = staff_doc_dir();
    $insert = $pdo->prepare('INSERT INTO staff_documents (user_id, doc_type, file_path, original_name, file_size, uploaded_by_id) VALUES (?, ?, ?, ?, ?, ?)');
    $stored = 0;

    foreach ($pendingDocs as $doc) {
        $storedName = $userId . '_' . bin2hex(random_bytes(8)) . '.' . $doc['ext'];
        if (move_uploaded_file($doc['tmp'], $dir . $storedName)) {
            $insert->execute([$userId, $doc['type'], $storedName, $doc['name'], $doc['size'], $uploaderId]);
            $stored++;
        }
    }

    return $stored;
}

/** Human-readable file size for the document list. */
function staff_doc_size(int $bytes): string
{
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }
    return max(1, (int) round($bytes / 1024)) . ' KB';
}
