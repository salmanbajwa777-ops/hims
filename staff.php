<?php
require_once __DIR__ . '/config/guard_admin.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/notify.php';

$roles = ['ADMIN', 'DOCTOR', 'MANAGER', 'ACCOUNTANT', 'NURSE', 'RECEPTIONIST'];
$docTypes = [
    'CNIC' => 'CNIC',
    'EDUCATIONAL_DEGREE' => 'Educational Degree',
    'REGISTRATION' => 'Registration (PMDC / Nursing Council / etc.)',
    'EXPERIENCE_LETTER' => 'Experience Letter',
    'CV' => 'CV',
    'OTHER' => 'Other',
];
$allowedExt = ['pdf', 'jpg', 'jpeg', 'png'];
$maxFileSize = 10 * 1024 * 1024;

// Every new staff account starts here. They are forced to change it on first sign-in.
const DEFAULT_STAFF_PASSWORD = '123456';

$error = '';
$success = '';

$uploadDir = __DIR__ . '/uploads/staff_docs/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_staff') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    // Canonical "0300…" storage so login-by-phone matches what people type.
    $phone = normalize_staff_phone(trim($_POST['phone'] ?? ''));
    $role = $_POST['base_role'] ?? '';
    // Admin may type a temporary password; blank falls back to the default.
    // Either way the user must change it on first sign-in.
    $password = trim($_POST['temp_password'] ?? '') !== '' ? trim($_POST['temp_password']) : DEFAULT_STAFF_PASSWORD;
    $maxDiscountPct = trim($_POST['max_discount_pct'] ?? '') !== '' ? (float) $_POST['max_discount_pct'] : 0;
    $specialty = ($_POST['specialty'] ?? '') === 'DENTAL' ? 'DENTAL' : 'GENERAL';
    // Consultation revenue share (doctors only; zeroed for other roles).
    // Rule: tax comes off the FULL fee first, then the share % splits the net —
    // see sql/add_consult_revenue_share.sql. Non-taxable doctors split the full fee.
    $consultSharePct = trim($_POST['consult_share_pct'] ?? '') !== '' ? (float) $_POST['consult_share_pct'] : 0;
    $consultHasTax = !empty($_POST['consult_has_tax']) ? 1 : 0;
    $consultTaxPct = $consultHasTax && trim($_POST['consult_tax_pct'] ?? '') !== '' ? (float) $_POST['consult_tax_pct'] : 0;
    if ($role !== 'DOCTOR') {
        $consultSharePct = 0; $consultHasTax = 0; $consultTaxPct = 0;
    }
    $docTypeInputs = $_POST['doc_type'] ?? [];
    $docFiles = $_FILES['doc_file'] ?? null;

    if ($name === '' || ($email === '' && $phone === '') || !in_array($role, $roles, true)) {
        $error = 'Please provide a name, at least one of email/phone, and a valid role.';
    } elseif ($maxDiscountPct < 0 || $maxDiscountPct > 100) {
        $error = 'Discount cap must be between 0 and 100.';
    } elseif ($consultSharePct < 0 || $consultSharePct > 100) {
        $error = 'Consultation share must be between 0 and 100.';
    } elseif ($consultHasTax && ($consultTaxPct <= 0 || $consultTaxPct > 100)) {
        $error = 'Enter a tax % (above 0, up to 100) when tax deduction is enabled.';
    } else {
        // Email exact; phone compared normalized across all stored formats
        // (a legacy "+92300…" row must block a new "0300…" — same login).
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND email IS NOT NULL AND email != "" LIMIT 1');
        $stmt->execute([$email]);

        if ($stmt->fetch() || staff_phone_in_use($pdo, $phone)) {
            $error = 'A user with this email or phone already exists.';
        } else {
            // Validate documents before touching the database
            $pendingDocs = [];
            $docError = '';

            if ($docFiles) {
                foreach ($docFiles['error'] as $i => $fileError) {
                    if ($fileError === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }
                    if ($fileError !== UPLOAD_ERR_OK) {
                        $docError = 'One of the documents failed to upload. Please try again.';
                        break;
                    }

                    $docType = $docTypeInputs[$i] ?? '';
                    if (!array_key_exists($docType, $docTypes)) {
                        $docError = 'Invalid document type selected.';
                        break;
                    }

                    $origName = $docFiles['name'][$i];
                    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

                    if (!in_array($ext, $allowedExt, true)) {
                        $docError = "\"$origName\" must be a PDF, JPG, or PNG file.";
                        break;
                    }
                    if ($docFiles['size'][$i] > $maxFileSize) {
                        $docError = "\"$origName\" is larger than 10MB.";
                        break;
                    }

                    $pendingDocs[] = [
                        'type' => $docType,
                        'tmp' => $docFiles['tmp_name'][$i],
                        'name' => $origName,
                        'size' => $docFiles['size'][$i],
                        'ext' => $ext,
                    ];
                }
            }

            if ($docError !== '') {
                $error = $docError;
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT);

                // Falls back to the pre-migration column set if
                // sql/add_consult_revenue_share.sql hasn't been applied yet.
                try {
                    $insert = $pdo->prepare('INSERT INTO users (name, email, phone, password, base_role, max_discount_pct, specialty, consult_share_pct, consult_has_tax, consult_tax_pct, must_change_password) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)');
                    $insert->execute([
                        $name,
                        $email !== '' ? $email : null,
                        $phone !== '' ? $phone : null,
                        $hash,
                        $role,
                        $maxDiscountPct,
                        $specialty,
                        $consultSharePct,
                        $consultHasTax,
                        $consultTaxPct,
                    ]);
                } catch (PDOException $e) {
                    $insert = $pdo->prepare('INSERT INTO users (name, email, phone, password, base_role, max_discount_pct, specialty, must_change_password) VALUES (?, ?, ?, ?, ?, ?, ?, 1)');
                    $insert->execute([
                        $name,
                        $email !== '' ? $email : null,
                        $phone !== '' ? $phone : null,
                        $hash,
                        $role,
                        $maxDiscountPct,
                        $specialty,
                    ]);
                }

                $newUserId = (int) $pdo->lastInsertId();

                $docInsert = $pdo->prepare('INSERT INTO staff_documents (user_id, doc_type, file_path, original_name, file_size, uploaded_by_id) VALUES (?, ?, ?, ?, ?, ?)');

                foreach ($pendingDocs as $doc) {
                    $storedName = $newUserId . '_' . bin2hex(random_bytes(8)) . '.' . $doc['ext'];
                    if (move_uploaded_file($doc['tmp'], $uploadDir . $storedName)) {
                        $docInsert->execute([
                            $newUserId,
                            $doc['type'],
                            $storedName,
                            $doc['name'],
                            $doc['size'],
                            $_SESSION['user_id'],
                        ]);
                    }
                }

                $log = $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)');
                $log->execute([
                    $_SESSION['user_id'],
                    'staff_created',
                    "Created user #$newUserId ($name, $role)" . (count($pendingDocs) ? ', ' . count($pendingDocs) . ' document(s) attached' : ''),
                ]);

                // Welcome email with login link + temporary password (best-effort;
                // silently skipped when the account has no email address).
                notify_staff_welcome($pdo, $newUserId, $password);

                $success = "Account created for $name. Their password is $password — they must change it on first sign-in."
                    . ($email !== '' ? ' A welcome email with the sign-in link has been sent.' : '');
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_staff') {
    $editId = (int) ($_POST['user_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    // Canonical "0300…" storage so login-by-phone matches what people type.
    $phone = normalize_staff_phone(trim($_POST['phone'] ?? ''));
    $role = $_POST['base_role'] ?? '';
    $resetPassword = ($_POST['reset_password'] ?? '') === '1';
    $maxDiscountPct = trim($_POST['max_discount_pct'] ?? '') !== '' ? (float) $_POST['max_discount_pct'] : 0;
    $specialty = ($_POST['specialty'] ?? '') === 'DENTAL' ? 'DENTAL' : 'GENERAL';
    // Consultation revenue share — same rules as add_staff (tax off the full fee
    // first, then the share split; zeroed for non-doctor roles).
    $consultSharePct = trim($_POST['consult_share_pct'] ?? '') !== '' ? (float) $_POST['consult_share_pct'] : 0;
    $consultHasTax = !empty($_POST['consult_has_tax']) ? 1 : 0;
    $consultTaxPct = $consultHasTax && trim($_POST['consult_tax_pct'] ?? '') !== '' ? (float) $_POST['consult_tax_pct'] : 0;
    if ($role !== 'DOCTOR') {
        $consultSharePct = 0; $consultHasTax = 0; $consultTaxPct = 0;
    }
    $docTypeInputs = $_POST['doc_type'] ?? [];
    $docFiles = $_FILES['doc_file'] ?? null;

    if ($editId <= 0 || $name === '' || ($email === '' && $phone === '') || !in_array($role, $roles, true)) {
        $error = 'Please provide a name, at least one of email/phone, and a valid role.';
    } elseif ($maxDiscountPct < 0 || $maxDiscountPct > 100) {
        $error = 'Discount cap must be between 0 and 100.';
    } elseif ($consultSharePct < 0 || $consultSharePct > 100) {
        $error = 'Consultation share must be between 0 and 100.';
    } elseif ($consultHasTax && ($consultTaxPct <= 0 || $consultTaxPct > 100)) {
        $error = 'Enter a tax % (above 0, up to 100) when tax deduction is enabled.';
    } else {
        // Email exact; phone compared normalized across all stored formats
        // (a legacy "+92300…" row must block a new "0300…" — same login).
        $stmt = $pdo->prepare('SELECT id FROM users WHERE id != ? AND email = ? AND email IS NOT NULL AND email != ""');
        $stmt->execute([$editId, $email]);

        if ($stmt->fetch() || staff_phone_in_use($pdo, $phone, $editId)) {
            $error = 'Another user with this email or phone already exists.';
        } else {
            $pendingDocs = [];
            $docError = '';

            if ($docFiles) {
                foreach ($docFiles['error'] as $i => $fileError) {
                    if ($fileError === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }
                    if ($fileError !== UPLOAD_ERR_OK) {
                        $docError = 'One of the documents failed to upload. Please try again.';
                        break;
                    }

                    $docType = $docTypeInputs[$i] ?? '';
                    if (!array_key_exists($docType, $docTypes)) {
                        $docError = 'Invalid document type selected.';
                        break;
                    }

                    $origName = $docFiles['name'][$i];
                    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

                    if (!in_array($ext, $allowedExt, true)) {
                        $docError = "\"$origName\" must be a PDF, JPG, or PNG file.";
                        break;
                    }
                    if ($docFiles['size'][$i] > $maxFileSize) {
                        $docError = "\"$origName\" is larger than 10MB.";
                        break;
                    }

                    $pendingDocs[] = [
                        'type' => $docType,
                        'tmp' => $docFiles['tmp_name'][$i],
                        'name' => $origName,
                        'size' => $docFiles['size'][$i],
                        'ext' => $ext,
                    ];
                }
            }

            if ($docError !== '') {
                $error = $docError;
            } else {
                // Same pre-migration fallback as add_staff: retry without the
                // revenue-share columns if the migration hasn't been applied.
                try {
                    if ($resetPassword) {
                        $hash = password_hash(DEFAULT_STAFF_PASSWORD, PASSWORD_BCRYPT);
                        $update = $pdo->prepare('UPDATE users SET name = ?, email = ?, phone = ?, base_role = ?, max_discount_pct = ?, specialty = ?, consult_share_pct = ?, consult_has_tax = ?, consult_tax_pct = ?, password = ?, must_change_password = 1 WHERE id = ?');
                        $update->execute([
                            $name,
                            $email !== '' ? $email : null,
                            $phone !== '' ? $phone : null,
                            $role,
                            $maxDiscountPct,
                            $specialty,
                            $consultSharePct,
                            $consultHasTax,
                            $consultTaxPct,
                            $hash,
                            $editId,
                        ]);
                    } else {
                        $update = $pdo->prepare('UPDATE users SET name = ?, email = ?, phone = ?, base_role = ?, max_discount_pct = ?, specialty = ?, consult_share_pct = ?, consult_has_tax = ?, consult_tax_pct = ? WHERE id = ?');
                        $update->execute([
                            $name,
                            $email !== '' ? $email : null,
                            $phone !== '' ? $phone : null,
                            $role,
                            $maxDiscountPct,
                            $specialty,
                            $consultSharePct,
                            $consultHasTax,
                            $consultTaxPct,
                            $editId,
                        ]);
                    }
                } catch (PDOException $e) {
                    if ($resetPassword) {
                        $hash = password_hash(DEFAULT_STAFF_PASSWORD, PASSWORD_BCRYPT);
                        $update = $pdo->prepare('UPDATE users SET name = ?, email = ?, phone = ?, base_role = ?, max_discount_pct = ?, specialty = ?, password = ?, must_change_password = 1 WHERE id = ?');
                        $update->execute([
                            $name,
                            $email !== '' ? $email : null,
                            $phone !== '' ? $phone : null,
                            $role,
                            $maxDiscountPct,
                            $specialty,
                            $hash,
                            $editId,
                        ]);
                    } else {
                        $update = $pdo->prepare('UPDATE users SET name = ?, email = ?, phone = ?, base_role = ?, max_discount_pct = ?, specialty = ? WHERE id = ?');
                        $update->execute([
                            $name,
                            $email !== '' ? $email : null,
                            $phone !== '' ? $phone : null,
                            $role,
                            $maxDiscountPct,
                            $specialty,
                            $editId,
                        ]);
                    }
                }

                $docInsert = $pdo->prepare('INSERT INTO staff_documents (user_id, doc_type, file_path, original_name, file_size, uploaded_by_id) VALUES (?, ?, ?, ?, ?, ?)');

                foreach ($pendingDocs as $doc) {
                    $storedName = $editId . '_' . bin2hex(random_bytes(8)) . '.' . $doc['ext'];
                    if (move_uploaded_file($doc['tmp'], $uploadDir . $storedName)) {
                        $docInsert->execute([
                            $editId,
                            $doc['type'],
                            $storedName,
                            $doc['name'],
                            $doc['size'],
                            $_SESSION['user_id'],
                        ]);
                    }
                }

                $log = $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)');
                $log->execute([
                    $_SESSION['user_id'],
                    'staff_updated',
                    "Updated user #$editId ($name, $role)" . (count($pendingDocs) ? ', ' . count($pendingDocs) . ' document(s) attached' : '') . ($resetPassword ? ', password reset to default' : ''),
                ]);

                $success = "Account updated for $name." . ($resetPassword ? ' Password reset to ' . DEFAULT_STAFF_PASSWORD . ' — they must change it on next sign-in.' : '');
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_permissions') {
    $editId = (int) ($_POST['user_id'] ?? 0);
    $checkedIds = array_map('intval', $_POST['permission_ids'] ?? []);

    $userStmt = $pdo->prepare('SELECT id, name, base_role FROM users WHERE id = ?');
    $userStmt->execute([$editId]);
    $targetUser = $userStmt->fetch();

    if (!$targetUser) {
        $error = 'Staff member not found.';
    } else {
        $roleStmt = $pdo->prepare('SELECT permission_id FROM role_permissions WHERE base_role = ?');
        $roleStmt->execute([$targetUser['base_role']]);
        $roleDefaultIds = array_map('intval', array_column($roleStmt->fetchAll(), 'permission_id'));

        $del = $pdo->prepare('DELETE FROM user_permission_overrides WHERE user_id = ?');
        $del->execute([$editId]);

        $allPermIds = array_map('intval', array_column($pdo->query('SELECT id FROM permissions')->fetchAll(), 'id'));
        $insert = $pdo->prepare('INSERT INTO user_permission_overrides (user_id, permission_id, granted) VALUES (?, ?, ?)');

        foreach ($allPermIds as $permId) {
            $isChecked = in_array($permId, $checkedIds, true);
            $isRoleDefault = in_array($permId, $roleDefaultIds, true);

            if ($isChecked && !$isRoleDefault) {
                $insert->execute([$editId, $permId, 1]);
            } elseif (!$isChecked && $isRoleDefault) {
                $insert->execute([$editId, $permId, 0]);
            }
        }

        $log = $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)');
        $log->execute([
            $_SESSION['user_id'],
            'permissions_updated',
            "Updated individual permissions for user #$editId ({$targetUser['name']})",
        ]);

        $success = "Permissions updated for {$targetUser['name']}.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_consult_types') {
    $editId = (int) ($_POST['user_id'] ?? 0);
    $rowIds = $_POST['consult_type_id'] ?? [];
    $labels = $_POST['consult_type_label'] ?? [];
    $fees = $_POST['consult_type_fee'] ?? [];
    $revisits = $_POST['consult_type_revisit'] ?? [];  // ['1' => '1', ...] keyed by row index
    $defaultIndex = (int) ($_POST['consult_type_default'] ?? -1);

    $doctorStmt = $pdo->prepare('SELECT id, name FROM users WHERE id = ? AND base_role = "DOCTOR"');
    $doctorStmt->execute([$editId]);
    $doctor = $doctorStmt->fetch();

    if (!$doctor) {
        $error = 'Doctor not found.';
    } else {
        $keepIds = [];
        $insertType = $pdo->prepare('INSERT INTO doctor_consult_types (doctor_id, label, fee, is_default, is_revisit_eligible) VALUES (?, ?, ?, ?, ?)');
        $updateType = $pdo->prepare('UPDATE doctor_consult_types SET label = ?, fee = ?, is_default = ?, is_revisit_eligible = ? WHERE id = ? AND doctor_id = ?');

        foreach ($labels as $i => $label) {
            $label = trim($label);
            $fee = trim($fees[$i] ?? '');
            if ($label === '' || $fee === '' || !is_numeric($fee) || (float) $fee < 0) {
                continue;
            }
            $isDefault = ($i === $defaultIndex) ? 1 : 0;
            $revisitOk = !empty($revisits[$i]) ? 1 : 0;   // checkbox present for this row index
            $rowId = (int) ($rowIds[$i] ?? 0);

            if ($rowId > 0) {
                $updateType->execute([$label, $fee, $isDefault, $revisitOk, $rowId, $editId]);
                $keepIds[] = $rowId;
            } else {
                $insertType->execute([$editId, $label, $fee, $isDefault, $revisitOk]);
                $keepIds[] = (int) $pdo->lastInsertId();
            }
        }

        // Ensure exactly one default: if none were marked (or all rows were new), default the first kept row.
        if ($defaultIndex < 0 && !empty($keepIds)) {
            $pdo->prepare('UPDATE doctor_consult_types SET is_default = 0 WHERE doctor_id = ?')->execute([$editId]);
            $pdo->prepare('UPDATE doctor_consult_types SET is_default = 1 WHERE id = ?')->execute([$keepIds[0]]);
        }

        if (!empty($keepIds)) {
            $placeholders = implode(',', array_fill(0, count($keepIds), '?'));
            $delete = $pdo->prepare("DELETE FROM doctor_consult_types WHERE doctor_id = ? AND id NOT IN ($placeholders)");
            $delete->execute(array_merge([$editId], $keepIds));
        } else {
            $pdo->prepare('DELETE FROM doctor_consult_types WHERE doctor_id = ?')->execute([$editId]);
        }

        $log = $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)');
        $log->execute([
            $_SESSION['user_id'],
            'consult_types_updated',
            "Updated consultation types for doctor #$editId ({$doctor['name']}), " . count($keepIds) . ' type(s) on file',
        ]);

        $success = "Consultation types updated for {$doctor['name']}.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_staff') {
    $deleteId = (int) ($_POST['user_id'] ?? 0);

    if ($deleteId === (int) $_SESSION['user_id']) {
        $error = "You can't delete your own account.";
    } else {
        $userStmt = $pdo->prepare('SELECT id, name, base_role FROM users WHERE id = ?');
        $userStmt->execute([$deleteId]);
        $targetUser = $userStmt->fetch();

        if (!$targetUser) {
            $error = 'Staff member not found.';
        } else {
            // Cascades to staff_documents, doctor_consult_types, user_permission_overrides.
            // Sets NULL on visits/patients they're linked to (their work stays, just no
            // longer attributed) and on audit_logs.user_id — see sql/add_delete_cascades.sql
            // for the FK definitions this depends on.
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$deleteId]);

            $log = $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)');
            $log->execute([
                $_SESSION['user_id'],
                'staff_deleted',
                "Deleted user #$deleteId ({$targetUser['name']}, {$targetUser['base_role']})",
            ]);

            $success = "Deleted {$targetUser['name']}.";
        }
    }
}

// Consultation revenue-share columns arrive with sql/add_consult_revenue_share.sql;
// tolerate the migration not being run yet so the page keeps working pre-migration.
try {
    $staff = $pdo->query('SELECT id, name, email, phone, base_role, must_change_password, max_discount_pct, specialty, consult_share_pct, consult_has_tax, consult_tax_pct, created_at FROM users ORDER BY name ASC')->fetchAll();
} catch (PDOException $e) {
    $staff = $pdo->query('SELECT id, name, email, phone, base_role, must_change_password, max_discount_pct, specialty, 0 AS consult_share_pct, 0 AS consult_has_tax, 0 AS consult_tax_pct, created_at FROM users ORDER BY name ASC')->fetchAll();
}
$doctors = array_values(array_filter($staff, fn($s) => $s['base_role'] === 'DOCTOR'));
$otherStaff = array_values(array_filter($staff, fn($s) => $s['base_role'] !== 'DOCTOR'));

$docCounts = [];
foreach ($pdo->query('SELECT user_id, COUNT(*) AS cnt FROM staff_documents GROUP BY user_id')->fetchAll() as $row) {
    $docCounts[(int) $row['user_id']] = (int) $row['cnt'];
}

$consultTypesByDoctor = [];
foreach ($pdo->query('SELECT id, doctor_id, label, fee, is_default, is_revisit_eligible FROM doctor_consult_types ORDER BY label')->fetchAll() as $ct) {
    $consultTypesByDoctor[(int) $ct['doctor_id']][] = $ct;
}

$categoryLabels = [
    'clinical' => 'Clinical & Nursing',
    'financial' => 'Financial',
    'admin' => 'Admin & Reception',
];

$allPermissions = $pdo->query('SELECT id, `key`, label, category FROM permissions ORDER BY category, label')->fetchAll();
$permsByCategory = [];
foreach ($allPermissions as $p) {
    $permsByCategory[$p['category']][] = $p;
}

$roleDefaultsByRole = [];
foreach ($pdo->query('SELECT base_role, permission_id FROM role_permissions')->fetchAll() as $row) {
    $roleDefaultsByRole[$row['base_role']][(int) $row['permission_id']] = true;
}

$overridesByUser = [];
foreach ($pdo->query('SELECT user_id, permission_id, granted FROM user_permission_overrides')->fetchAll() as $row) {
    $overridesByUser[(int) $row['user_id']][(int) $row['permission_id']] = (int) $row['granted'] === 1;
}

function effectivePermissionIds(string $baseRole, int $userId, array $roleDefaultsByRole, array $overridesByUser): array {
    $effective = array_fill_keys(array_keys($roleDefaultsByRole[$baseRole] ?? []), true);
    foreach ($overridesByUser[$userId] ?? [] as $permId => $granted) {
        if ($granted) {
            $effective[$permId] = true;
        } else {
            unset($effective[$permId]);
        }
    }
    return array_keys($effective);
}

function roleBadgeColor(string $role): array {
    return match ($role) {
        'ADMIN' => ['#EDE9FE', '#6D28D9'],
        'DOCTOR' => ['#E0F2F1', '#0E5456'],
        'MANAGER' => ['#FEF3C7', '#92400E'],
        'ACCOUNTANT' => ['#ECFDF5', '#047857'],
        'NURSE' => ['#FCE7F3', '#9D174D'],
        'RECEPTIONIST' => ['#F1F5F9', '#334155'],
        default => ['#F1F5F9', '#334155'],
    };
}

$pageTitle = 'Staff & Doctors';
$headExtra = <<<CSS
<style>
.header { height: 72px; position: sticky; top: 0; z-index: 20; display: flex; align-items: center; justify-content: space-between; padding: 0 32px; background: rgba(255,255,255,.80); backdrop-filter: blur(18px); border-bottom: 1px solid var(--border); }
.header-right { display: flex; align-items: center; gap: 18px; margin-left: auto; }
.header-date { font-size: 13px; color: var(--text-secondary); white-space: nowrap; }
.logout-link { font-size: 13px; color: var(--text-secondary); font-weight: 500; }

.doc-count-link { font-size: 12.5px; font-weight: 600; color: var(--primary); }
.doc-count-link.none { color: var(--text-muted); font-weight: 500; cursor: default; }
.edit-link { font-size: 12.5px; font-weight: 600; color: var(--primary); }
.edit-link:hover { text-decoration: underline; }

/* ---------- Add Staff full-page panel ---------- */
.panel-overlay { display: none; position: fixed; inset: 0; background: rgba(15,23,42,.45); z-index: 50; overflow-y: auto; padding: 40px 20px; }
.panel-overlay.open { display: block; }
.panel { max-width: 860px; margin: 0 auto; }

.form-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 20px; margin-bottom: 20px; flex-wrap: wrap; background: var(--card); border-radius: var(--radius-card); padding: 20px 24px; box-shadow: var(--shadow-sm); }
.form-header h1 { font-size: 20px; font-weight: 700; color: var(--text); }
.form-header .sub { font-size: 13px; color: var(--text-muted); margin-top: 4px; }
.form-header .close-btn {
    width: 36px; height: 36px; border-radius: 50%; background: var(--bg); border: 1px solid var(--border);
    color: var(--text-secondary); display: flex; align-items: center; justify-content: center; cursor: pointer; flex-shrink: 0;
}
.form-header .close-btn:hover { background: var(--red-bg); color: var(--red-text); }
.form-header .close-btn svg { width: 16px; height: 16px; }

form.staff-form { display: flex; flex-direction: column; gap: 20px; }

.section { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius-card); box-shadow: var(--shadow-sm); overflow: hidden; }
.section-head { display: flex; align-items: center; gap: 12px; padding: 18px 24px; border-bottom: 1px solid var(--border); }
.section-head .icon-badge { width: 34px; height: 34px; border-radius: 10px; background: var(--primary-light); color: var(--primary-dark); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.section-head .icon-badge svg { width: 17px; height: 17px; }
.section-head .titles { flex: 1; min-width: 0; }
.section-head .titles h2 { font-size: 15px; font-weight: 600; }
.section-head .titles .desc { font-size: 12.5px; color: var(--text-muted); margin-top: 1px; }
.section-head .count-chip { font-size: 11.5px; font-weight: 700; color: var(--text-secondary); background: var(--bg); border: 1px solid var(--border); border-radius: 20px; padding: 3px 10px; flex-shrink: 0; }
.section-body { padding: 22px 24px 24px; }

.field-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; }
.field { display: flex; flex-direction: column; gap: 6px; }
.field.full { grid-column: 1 / -1; }
.field label { font-size: 12.5px; font-weight: 600; color: var(--text-secondary); display: flex; align-items: center; gap: 5px; }
.field label .opt { font-weight: 500; color: var(--text-muted); }
.field input, .field select { width: 100%; padding: 10px 12px; border: 1px solid var(--border); border-radius: var(--radius-input); font-size: 13.5px; font-family: inherit; background: var(--bg); color: var(--text); }
.field input:focus, .field select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,127,126,.15); background: var(--card); }

.doc-list { display: flex; flex-direction: column; gap: 10px; }
.doc-row { display: grid; grid-template-columns: 240px 1fr auto; align-items: center; gap: 12px; border: 1px solid var(--border); border-radius: 14px; padding: 10px 12px; background: var(--bg); }
.doc-row select { width: 100%; padding: 9px 12px; border: 1px solid var(--border); border-radius: 10px; font-size: 13px; font-family: inherit; background: var(--card); color: var(--text); }
.doc-row select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,127,126,.15); }
.doc-file-input { width: 100%; font-size: 12.5px; color: var(--text-secondary); }
.doc-file-input::file-selector-button {
    font-family: inherit; font-size: 12px; font-weight: 600; color: var(--primary); background: var(--primary-light);
    border: none; border-radius: 8px; padding: 7px 12px; margin-right: 10px; cursor: pointer;
}
.doc-row .remove-row { width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: var(--text-muted); cursor: pointer; flex-shrink: 0; border: none; background: transparent; }
.doc-row .remove-row:hover { background: var(--red-bg); color: var(--red-text); }
.doc-row .remove-row svg { width: 14px; height: 14px; }

.add-doc-btn { display: flex; align-items: center; justify-content: center; gap: 8px; border: 1.5px dashed var(--border-strong); border-radius: 14px; padding: 12px; font-size: 13px; font-weight: 600; color: var(--text-secondary); cursor: pointer; background: transparent; font-family: inherit; width: 100%; margin-top: 12px; }
.add-doc-btn:hover { border-color: var(--primary); color: var(--primary); background: rgba(26,127,126,.04); }
.add-doc-btn svg { width: 15px; height: 15px; }

.info-banner { display: flex; gap: 12px; align-items: flex-start; background: var(--primary-light); border-radius: 14px; padding: 14px 16px; color: var(--primary-dark); font-size: 12.5px; }
.info-banner svg { width: 16px; height: 16px; flex-shrink: 0; margin-top: 1px; }

.form-footer { position: sticky; bottom: 0; display: flex; align-items: center; justify-content: flex-end; gap: 10px; background: var(--card); border-top: 1px solid var(--border); padding: 16px 24px; border-radius: var(--radius-card); box-shadow: var(--shadow-md); margin-top: 4px; }

/* ---------- Permissions panel ---------- */
.perm-category { margin-bottom: 4px; }
.perm-category-head { font-size: 12.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted); padding: 14px 4px 8px; border-top: 1px solid var(--border); }
.perm-category:first-child .perm-category-head { border-top: none; padding-top: 0; }
.perm-row { display: flex; align-items: center; gap: 12px; padding: 10px 4px; border-radius: 10px; }
.perm-row:hover { background: var(--bg); }
.perm-row label { font-size: 13.5px; color: var(--text); cursor: pointer; flex: 1; }
.perm-row input[type="checkbox"] { width: 18px; height: 18px; accent-color: var(--primary); cursor: pointer; flex-shrink: 0; }
.perm-key { font-size: 11px; color: var(--text-muted); font-family: 'Courier New', monospace; }

/* ---------- Document viewer panel ---------- */
.docs-panel-overlay { display: none; position: fixed; inset: 0; background: rgba(15,23,42,.45); z-index: 50; align-items: center; justify-content: center; padding: 20px; }
.docs-panel-overlay.open { display: flex; }
.docs-panel { background: var(--card); border-radius: 20px; padding: 24px; width: 100%; max-width: 460px; box-shadow: var(--shadow-md); max-height: 80vh; overflow-y: auto; }
.docs-panel h2 { font-size: 16px; margin-bottom: 2px; }
.docs-panel .sub { font-size: 12.5px; color: var(--text-muted); margin-bottom: 16px; }
.doc-view-row { display: flex; align-items: center; gap: 10px; padding: 10px 0; border-top: 1px solid var(--border); }
.doc-view-row:first-of-type { border-top: none; }
.doc-view-row .thumb { width: 34px; height: 34px; border-radius: 8px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; font-size: 9.5px; font-weight: 700; background: var(--red-bg); color: var(--red-text); }
.doc-view-row .thumb.img { background: var(--primary-light); color: var(--primary-dark); }
.doc-view-row .meta { flex: 1; min-width: 0; }
.doc-view-row .dtype { font-size: 12.5px; font-weight: 600; }
.doc-view-row .fname { font-size: 11.5px; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.doc-view-row a.open-link { font-size: 12px; font-weight: 600; color: var(--primary); flex-shrink: 0; }

@media (max-width: 720px) {
    .doc-row { grid-template-columns: 1fr; }
    .doc-row .remove-row { justify-self: end; }
}
</style>
CSS;
require __DIR__ . '/partials/head.php';
$navActive = 'staff';
require __DIR__ . '/partials/sidebar.php';
?>
        <header class="header">
            <div class="page-title" style="font-size:16px;">Staff &amp; Doctors</div>
            <div class="header-right">
                <span class="header-date"><?= date('D, d M Y') ?></span>
                <a class="logout-link" href="logout.php">Logout</a>
            </div>
        </header>

        <div class="content">
            <div class="page-head">
                <div>
                    <div class="page-title">Staff &amp; Doctors</div>
                    <div class="page-sub">Add and manage accounts for doctors, nurses, and other staff</div>
                </div>
                <button class="btn" id="openAddPanel">+ Add Doctor / Staff</button>
            </div>

            <?php if ($error): ?>
                <div class="alert error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <?php
            function renderStaffGroup(string $title, array $people, array $docCounts, array $roleDefaultsByRole, array $overridesByUser, array $consultTypesByDoctor): void {
            ?>
            <div class="card">
                <div class="section-title"><?= htmlspecialchars($title) ?></div>
                <div class="section-sub"><?= count($people) ?> total</div>
                <table>
                    <thead>
                        <tr><th>Name</th><th>Contact</th><th>Role</th><th>Status</th><th>Documents</th><th>Added</th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($people as $s): ?>
                        <?php
                        [$bg, $fg] = roleBadgeColor($s['base_role']);
                        $count = $docCounts[(int) $s['id']] ?? 0;
                        ?>
                        <tr>
                            <td>
                                <div class="person">
                                    <span class="person-avatar"><?= htmlspecialchars(strtoupper(substr($s['name'], 0, 1))) ?></span>
                                    <?= htmlspecialchars($s['name']) ?>
                                </div>
                            </td>
                            <td class="muted"><?= htmlspecialchars($s['email'] ?: $s['phone'] ?: '—') ?></td>
                            <td>
                                <span class="role-badge" style="background:<?= $bg ?>;color:<?= $fg ?>;"><?= htmlspecialchars($s['base_role']) ?></span>
                                <?php if ($s['base_role'] === 'DOCTOR' && (float) ($s['consult_share_pct'] ?? 0) > 0): ?>
                                <div class="muted" style="font-size:11.5px; margin-top:4px;">
                                    Share <?= rtrim(rtrim(number_format((float) $s['consult_share_pct'], 2), '0'), '.') ?>%<?php if ((int) ($s['consult_has_tax'] ?? 0) === 1): ?> · tax <?= rtrim(rtrim(number_format((float) $s['consult_tax_pct'], 2), '0'), '.') ?>%<?php else: ?> · no tax<?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($s['must_change_password']): ?>
                                    <span class="status-pill pending">Pending first login</span>
                                <?php else: ?>
                                    <span class="status-pill active">Active</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($count > 0): ?>
                                    <a class="doc-count-link" href="#" data-user-id="<?= (int) $s['id'] ?>" data-user-name="<?= htmlspecialchars($s['name']) ?>" onclick="openDocsPanel(this.dataset.userId, this.dataset.userName); return false;"><?= $count ?> file<?= $count === 1 ? '' : 's' ?></a>
                                <?php else: ?>
                                    <span class="doc-count-link none">None</span>
                                <?php endif; ?>
                            </td>
                            <td class="muted"><?= date('d M Y', strtotime($s['created_at'])) ?></td>
                            <td>
                                <a href="#" class="edit-link"
                                   data-id="<?= (int) $s['id'] ?>"
                                   data-name="<?= htmlspecialchars($s['name'], ENT_QUOTES) ?>"
                                   data-email="<?= htmlspecialchars($s['email'] ?? '', ENT_QUOTES) ?>"
                                   data-phone="<?= htmlspecialchars($s['phone'] ?? '', ENT_QUOTES) ?>"
                                   data-role="<?= htmlspecialchars($s['base_role'], ENT_QUOTES) ?>"
                                   data-discount="<?= htmlspecialchars((string) $s['max_discount_pct'], ENT_QUOTES) ?>"
                                   data-specialty="<?= htmlspecialchars($s['specialty'], ENT_QUOTES) ?>"
                                   data-sharepct="<?= htmlspecialchars((string) $s['consult_share_pct'], ENT_QUOTES) ?>"
                                   data-hastax="<?= (int) $s['consult_has_tax'] ?>"
                                   data-taxpct="<?= htmlspecialchars((string) $s['consult_tax_pct'], ENT_QUOTES) ?>"
                                   onclick="openEditPanel(this.dataset); return false;">Edit</a>
                                &nbsp;·&nbsp;
                                <?php
                                $effectiveIds = effectivePermissionIds($s['base_role'], (int) $s['id'], $roleDefaultsByRole, $overridesByUser);
                                ?>
                                <a href="#" class="edit-link"
                                   data-id="<?= (int) $s['id'] ?>"
                                   data-name="<?= htmlspecialchars($s['name'], ENT_QUOTES) ?>"
                                   data-role="<?= htmlspecialchars($s['base_role'], ENT_QUOTES) ?>"
                                   data-permission-ids="<?= htmlspecialchars(json_encode($effectiveIds), ENT_QUOTES) ?>"
                                   onclick="openPermissionsPanel(this.dataset); return false;">Permissions</a>
                                <?php if ($s['base_role'] === 'DOCTOR'): ?>
                                &nbsp;·&nbsp;
                                <a href="#" class="edit-link"
                                   data-id="<?= (int) $s['id'] ?>"
                                   data-name="<?= htmlspecialchars($s['name'], ENT_QUOTES) ?>"
                                   data-types="<?= htmlspecialchars(json_encode($consultTypesByDoctor[(int) $s['id']] ?? []), ENT_QUOTES) ?>"
                                   onclick="openConsultTypesPanel(this.dataset); return false;">Consult Types</a>
                                <?php endif; ?>
                                &nbsp;·&nbsp;
                                <form method="POST" action="staff.php" style="display:inline;" onsubmit="return confirm('Permanently delete <?= htmlspecialchars(addslashes($s['name'])) ?>? This removes their documents, permissions and consultation types, and can\'t be undone.');">
                                    <input type="hidden" name="action" value="delete_staff">
                                    <input type="hidden" name="user_id" value="<?= (int) $s['id'] ?>">
                                    <button type="submit" class="edit-link" style="background:none;border:none;padding:0;font:inherit;cursor:pointer;color:var(--red-text);">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$people): ?>
                        <tr><td colspan="7" class="muted" style="text-align:center;padding:24px;">None yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php
            }
            renderStaffGroup('Doctors', $doctors, $docCounts, $roleDefaultsByRole, $overridesByUser, $consultTypesByDoctor);
            renderStaffGroup('Staff', $otherStaff, $docCounts, $roleDefaultsByRole, $overridesByUser, $consultTypesByDoctor);
            ?>
        </div>
    </div>
</div>

<!-- Add Staff / Doctor full panel -->
<div class="panel-overlay" id="addPanelOverlay">
    <div class="panel">
        <div class="form-header">
            <div>
                <h1 id="panelTitle">Add Doctor / Staff</h1>
                <div class="sub" id="panelSub">Create a login and file their onboarding documents in one go.</div>
            </div>
            <button type="button" class="close-btn" id="closeAddPanel" aria-label="Close">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
            </button>
        </div>

        <form class="staff-form" method="POST" action="staff.php" enctype="multipart/form-data" id="staffForm">
            <input type="hidden" name="action" id="formAction" value="add_staff">
            <input type="hidden" name="user_id" id="formUserId" value="">

            <div class="section">
                <div class="section-head">
                    <div class="icon-badge">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    </div>
                    <div class="titles">
                        <h2>Basic Information</h2>
                        <div class="desc">Who they are and how they'll sign in</div>
                    </div>
                </div>
                <div class="section-body">
                    <div class="field-grid">
                        <div class="field full">
                            <label for="name">Full Name</label>
                            <input type="text" id="name" name="name" required autofocus>
                        </div>
                        <div class="field">
                            <label for="email">Email</label>
                            <input type="text" id="email" name="email" placeholder="doctor@example.com">
                        </div>
                        <div class="field">
                            <label for="phone">Phone <span class="opt">(this is their login ID — optional if email set)</span></label>
                            <input type="text" id="phone" name="phone" placeholder="03xxxxxxxxx">
                        </div>
                        <div class="field" id="tempPasswordField">
                            <label for="temp_password">Temporary Password <span class="opt">(blank = <?= DEFAULT_STAFF_PASSWORD ?>; emailed to them, changed on first sign-in)</span></label>
                            <input type="text" id="temp_password" name="temp_password" placeholder="<?= DEFAULT_STAFF_PASSWORD ?>" autocomplete="off">
                        </div>
                        <div class="field" id="passwordField" style="display:none;">
                            <label>Password</label>
                            <label style="display:flex; align-items:center; gap:8px; font-weight:500; cursor:pointer;">
                                <input type="checkbox" id="reset_password" name="reset_password" value="1" style="width:auto; margin:0;">
                                <span>Reset password back to <strong><?= DEFAULT_STAFF_PASSWORD ?></strong></span>
                            </label>
                        </div>
                        <div class="field">
                            <label for="base_role">Role</label>
                            <select id="base_role" name="base_role" required>
                                <?php foreach ($roles as $r): ?>
                                <option value="<?= $r ?>"><?= ucfirst(strtolower($r)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="max_discount_pct">Max Discount They Can Apply <span class="opt">(0 = none)</span></label>
                            <div style="position:relative;">
                                <input type="number" id="max_discount_pct" name="max_discount_pct" value="0" min="0" max="100" step="0.5" style="padding-right:34px;">
                                <span style="position:absolute; right:12px; top:50%; transform:translateY(-50%); font-size:13px; color:var(--text-muted); font-weight:600;">%</span>
                            </div>
                        </div>
                        <div class="field" id="specialtyField" style="display:none;">
                            <label for="specialty">Specialty <span class="opt">(controls the invoice icon printed for their visits)</span></label>
                            <select id="specialty" name="specialty">
                                <option value="GENERAL">General</option>
                                <option value="DENTAL">Dental</option>
                            </select>
                        </div>
                        <div class="field" id="consultShareField" style="display:none;">
                            <label for="consult_share_pct">Consultation Revenue Share <span class="opt">(doctor's % of each consultation fee; clinic keeps the rest)</span></label>
                            <div style="position:relative;">
                                <input type="number" id="consult_share_pct" name="consult_share_pct" value="0" min="0" max="100" step="0.01" style="padding-right:34px;">
                                <span style="position:absolute; right:12px; top:50%; transform:translateY(-50%); font-size:13px; color:var(--text-muted); font-weight:600;">%</span>
                            </div>
                        </div>
                        <div class="field" id="consultTaxField" style="display:none;">
                            <label>Tax Deduction <span class="opt">(taken off the full fee first, then the share is split)</span></label>
                            <div style="display:flex; align-items:center; gap:10px;">
                                <label style="display:flex; align-items:center; gap:8px; font-weight:500; cursor:pointer; white-space:nowrap;">
                                    <input type="checkbox" id="consult_has_tax" name="consult_has_tax" value="1" style="width:15px; height:15px; margin:0; accent-color:var(--primary);">
                                    <span>Taxable</span>
                                </label>
                                <div style="position:relative; flex:1; display:none;" id="consultTaxPctWrap">
                                    <input type="number" id="consult_tax_pct" name="consult_tax_pct" min="0" max="100" step="0.01" placeholder="Tax %" style="padding-right:34px; width:100%;">
                                    <span style="position:absolute; right:12px; top:50%; transform:translateY(-50%); font-size:13px; color:var(--text-muted); font-weight:600;">%</span>
                                </div>
                            </div>
                            <div class="opt" id="consultShareHint" style="margin-top:6px; font-size:12px; color:var(--text-muted);"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="section" id="docsSection">
                <div class="section-head">
                    <div class="icon-badge">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/></svg>
                    </div>
                    <div class="titles">
                        <h2>Documents</h2>
                        <div class="desc">Optional — attach any that apply. PDF or JPG/PNG, up to 10MB each</div>
                    </div>
                </div>
                <div class="section-body">
                    <div id="existingDocsWrap" style="display:none; margin-bottom: 16px;">
                        <div class="desc" style="margin-bottom: 8px;">Already on file</div>
                        <div id="existingDocsList" class="doc-list"></div>
                    </div>
                    <div class="doc-list" id="docList">
                        <div class="doc-row">
                            <select name="doc_type[]">
                                <?php foreach ($docTypes as $val => $label): ?>
                                <option value="<?= $val ?>"><?= htmlspecialchars($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="file" class="doc-file-input" name="doc_file[]" accept=".pdf,.jpg,.jpeg,.png">
                            <button type="button" class="remove-row" onclick="removeDocRow(this)" aria-label="Remove document">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                            </button>
                        </div>
                    </div>
                    <button type="button" class="add-doc-btn" id="addDocBtn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                        Add another document
                    </button>
                </div>
            </div>

            <div class="info-banner" id="infoBanner">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
                <span id="infoBannerText">Their password will be <strong><?= DEFAULT_STAFF_PASSWORD ?></strong>. Tell them their login ID (phone or email) and this password — they'll be asked to change it on first sign-in. Documents are stored privately and only visible to admins.</span>
            </div>

            <div class="form-footer">
                <button type="button" class="btn secondary" id="cancelAddPanel">Cancel</button>
                <button type="submit" class="btn" id="submitBtn">Create Account</button>
            </div>
        </form>
    </div>
</div>

<!-- Document viewer panel -->
<div class="docs-panel-overlay" id="docsPanelOverlay">
    <div class="docs-panel">
        <h2 id="docsPanelTitle">Documents</h2>
        <div class="sub">Uploaded onboarding documents</div>
        <div id="docsPanelBody"></div>
    </div>
</div>

<!-- Individual Permissions panel -->
<div class="panel-overlay" id="permPanelOverlay">
    <div class="panel">
        <div class="form-header">
            <div>
                <h1 id="permPanelTitle">Permissions</h1>
                <div class="sub" id="permPanelSub">Grant or revoke individual permissions for this person.</div>
            </div>
            <button type="button" class="close-btn" id="closePermPanel" aria-label="Close">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
            </button>
        </div>

        <form method="POST" action="staff.php" id="permForm">
            <input type="hidden" name="action" value="edit_permissions">
            <input type="hidden" name="user_id" id="permFormUserId" value="">

            <div class="section">
                <div class="section-body" id="permCategoryList">
                    <?php foreach ($permsByCategory as $cat => $perms): ?>
                    <div class="perm-category" data-category="<?= htmlspecialchars($cat, ENT_QUOTES) ?>">
                        <div class="perm-category-head"><?= htmlspecialchars($categoryLabels[$cat] ?? ucfirst($cat)) ?></div>
                        <?php foreach ($perms as $p): ?>
                        <div class="perm-row">
                            <input type="checkbox" class="perm-checkbox" id="staffPerm_<?= (int) $p['id'] ?>" name="permission_ids[]" value="<?= (int) $p['id'] ?>">
                            <label for="staffPerm_<?= (int) $p['id'] ?>">
                                <?= htmlspecialchars($p['label']) ?>
                                <div class="perm-key"><?= htmlspecialchars($p['key']) ?></div>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="info-banner">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
                <span>Checked items are pre-filled from their role's defaults. Uncheck to revoke, or check extra ones to grant beyond the role default — changes take effect immediately.</span>
            </div>

            <div class="form-footer">
                <button type="button" class="btn secondary" id="cancelPermPanel">Cancel</button>
                <button type="submit" class="btn">Save Permissions</button>
            </div>
        </form>
    </div>
</div>

<!-- Doctor Consultation Types panel -->
<div class="panel-overlay" id="consultPanelOverlay">
    <div class="panel">
        <div class="form-header">
            <div>
                <h1 id="consultPanelTitle">Consultation Types</h1>
                <div class="sub">Types and fees shown on the registration form when this doctor is selected.</div>
            </div>
            <button type="button" class="close-btn" id="closeConsultPanel" aria-label="Close">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
            </button>
        </div>

        <form method="POST" action="staff.php" id="consultForm">
            <input type="hidden" name="action" value="save_consult_types">
            <input type="hidden" name="user_id" id="consultFormUserId" value="">

            <div class="section">
                <div class="section-body">
                    <div class="doc-list" id="consultTypeList"></div>
                    <button type="button" class="add-doc-btn" id="addConsultTypeBtn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                        Add another consultation type
                    </button>
                </div>
            </div>

            <div class="info-banner">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
                <span>Pick one type as the default — it's pre-selected on the registration form, but reception can still change it. If only one type exists, it's used automatically.</span>
            </div>

            <div class="form-footer">
                <button type="button" class="btn secondary" id="cancelConsultPanel">Cancel</button>
                <button type="submit" class="btn">Save Consultation Types</button>
            </div>
        </form>
    </div>
</div>

<script>
const addPanelOverlay = document.getElementById('addPanelOverlay');
const staffForm = document.getElementById('staffForm');
const formAction = document.getElementById('formAction');
const formUserId = document.getElementById('formUserId');
const panelTitle = document.getElementById('panelTitle');
const panelSub = document.getElementById('panelSub');
const docsSection = document.getElementById('docsSection');
const infoBannerText = document.getElementById('infoBannerText');
const submitBtn = document.getElementById('submitBtn');
const passwordField = document.getElementById('passwordField');
const tempPasswordField = document.getElementById('tempPasswordField');
const specialtyField = document.getElementById('specialtyField');
const baseRoleSelect = document.getElementById('base_role');
const consultShareField = document.getElementById('consultShareField');
const consultTaxField = document.getElementById('consultTaxField');
const consultShareInput = document.getElementById('consult_share_pct');
const consultHasTaxCb = document.getElementById('consult_has_tax');
const consultTaxPctWrap = document.getElementById('consultTaxPctWrap');
const consultTaxPctInput = document.getElementById('consult_tax_pct');
const consultShareHint = document.getElementById('consultShareHint');

function updateSpecialtyVisibility() {
    const isDoctor = baseRoleSelect.value === 'DOCTOR';
    specialtyField.style.display = isDoctor ? '' : 'none';
    consultShareField.style.display = isDoctor ? '' : 'none';
    consultTaxField.style.display = isDoctor ? '' : 'none';
}
baseRoleSelect.addEventListener('change', updateSpecialtyVisibility);

// Live payout preview on a Rs 1,000 example: tax comes off the FULL fee first,
// then the share % splits what's left (matches the server-side rule).
function refreshConsultShareHint() {
    consultTaxPctWrap.style.display = consultHasTaxCb.checked ? '' : 'none';
    if (!consultHasTaxCb.checked) consultTaxPctInput.value = '';

    const share = parseFloat(consultShareInput.value);
    if (isNaN(share) || share <= 0) { consultShareHint.textContent = ''; return; }
    const tax = consultHasTaxCb.checked ? (parseFloat(consultTaxPctInput.value) || 0) : 0;
    const net = 1000 * (1 - tax / 100);
    const docCut = Math.round(net * share / 100);
    consultShareHint.textContent = 'On a Rs 1,000 fee: '
        + (tax > 0 ? 'Rs ' + Math.round(1000 - net) + ' tax withheld first, then ' : '')
        + 'doctor gets Rs ' + docCut + ', clinic keeps Rs ' + (Math.round(net) - docCut) + '.';
}
consultShareInput.addEventListener('input', refreshConsultShareHint);
consultHasTaxCb.addEventListener('change', refreshConsultShareHint);
consultTaxPctInput.addEventListener('input', refreshConsultShareHint);

const DEFAULT_PASSWORD = <?= json_encode(DEFAULT_STAFF_PASSWORD) ?>;
const ADD_INFO_HTML = "If they have an email on file, a welcome email with the sign-in link and their temporary password is sent automatically — they'll be asked to change it on first sign-in. Documents are stored privately and only visible to admins.";
const EDIT_INFO_HTML = "You can attach additional documents at any time. Existing documents are kept — new ones are added alongside them. Tick the password box only if they've forgotten theirs and need it set back to <strong>" + DEFAULT_PASSWORD + "</strong>. Documents are stored privately and only visible to admins.";

function resetToAddMode() {
    staffForm.reset();
    formAction.value = 'add_staff';
    formUserId.value = '';
    panelTitle.textContent = 'Add Doctor / Staff';
    panelSub.textContent = "Create a login and file their onboarding documents in one go.";
    docsSection.style.display = '';
    passwordField.style.display = 'none';
    tempPasswordField.style.display = '';
    infoBannerText.innerHTML = ADD_INFO_HTML;
    submitBtn.textContent = 'Create Account';
    document.getElementById('existingDocsWrap').style.display = 'none';
    document.getElementById('specialty').value = 'GENERAL';
    consultShareInput.value = '0';
    consultHasTaxCb.checked = false;
    consultTaxPctInput.value = '';
    updateSpecialtyVisibility();
    refreshConsultShareHint();
}

function openEditPanel(data) {
    staffForm.reset();
    formAction.value = 'edit_staff';
    formUserId.value = data.id;
    document.getElementById('name').value = data.name || '';
    document.getElementById('email').value = data.email || '';
    document.getElementById('phone').value = data.phone || '';
    document.getElementById('base_role').value = data.role || '';
    document.getElementById('max_discount_pct').value = data.discount || '0';
    document.getElementById('specialty').value = data.specialty || 'GENERAL';
    consultShareInput.value = data.sharepct || '0';
    consultHasTaxCb.checked = data.hastax === '1';
    consultTaxPctInput.value = consultHasTaxCb.checked ? (data.taxpct || '') : '';
    panelTitle.textContent = 'Edit Doctor / Staff';
    panelSub.textContent = 'Update their details and manage their documents.';
    docsSection.style.display = '';
    passwordField.style.display = '';
    tempPasswordField.style.display = 'none';
    document.getElementById('reset_password').checked = false;
    infoBannerText.innerHTML = EDIT_INFO_HTML;
    submitBtn.textContent = 'Save Changes';
    updateSpecialtyVisibility();
    refreshConsultShareHint();
    renderExistingDocs(data.id);
    addPanelOverlay.classList.add('open');
}

function renderExistingDocs(userId) {
    const wrap = document.getElementById('existingDocsWrap');
    const list = document.getElementById('existingDocsList');
    list.innerHTML = '';

    const docs = (typeof staffDocuments !== 'undefined' ? staffDocuments[userId] : null) || [];
    if (!docs.length) {
        wrap.style.display = 'none';
        return;
    }

    docs.forEach(doc => {
        const row = document.createElement('div');
        row.className = 'doc-row';
        row.style.gridTemplateColumns = '1fr auto';

        const meta = document.createElement('div');
        meta.style.fontSize = '13px';
        meta.innerHTML = '<strong>' + doc.type + '</strong> &middot; ' + doc.name;

        const link = document.createElement('a');
        link.className = 'open-link';
        link.href = 'document.php?id=' + encodeURIComponent(doc.id);
        link.target = '_blank';
        link.rel = 'noopener';
        link.textContent = 'Open';
        link.style.fontSize = '12px';
        link.style.fontWeight = '600';
        link.style.color = 'var(--primary)';

        row.appendChild(meta);
        row.appendChild(link);
        list.appendChild(row);
    });

    wrap.style.display = '';
}

document.getElementById('openAddPanel').addEventListener('click', () => { resetToAddMode(); addPanelOverlay.classList.add('open'); });
document.getElementById('closeAddPanel').addEventListener('click', () => addPanelOverlay.classList.remove('open'));
document.getElementById('cancelAddPanel').addEventListener('click', () => addPanelOverlay.classList.remove('open'));

const permPanelOverlay = document.getElementById('permPanelOverlay');
const permForm = document.getElementById('permForm');
const permFormUserId = document.getElementById('permFormUserId');
const permPanelTitle = document.getElementById('permPanelTitle');
const permPanelSub = document.getElementById('permPanelSub');

function openPermissionsPanel(data) {
    permForm.reset();
    permFormUserId.value = data.id;
    permPanelTitle.textContent = 'Permissions — ' + (data.name || '');
    permPanelSub.textContent = 'Base role: ' + (data.role || '') + '. Grant or revoke individual permissions.';

    let grantedIds = [];
    try {
        grantedIds = JSON.parse(data.permissionIds || '[]').map(String);
    } catch (e) {
        grantedIds = [];
    }

    document.querySelectorAll('.perm-checkbox').forEach(cb => {
        cb.checked = grantedIds.includes(cb.value);
    });

    permPanelOverlay.classList.add('open');
}

document.getElementById('closePermPanel').addEventListener('click', () => permPanelOverlay.classList.remove('open'));
document.getElementById('cancelPermPanel').addEventListener('click', () => permPanelOverlay.classList.remove('open'));

<?php if (($error || $success) && ($_POST['action'] ?? '') === 'edit_permissions'): ?>permPanelOverlay.classList.add('open');
<?php elseif ($error || $success): ?>addPanelOverlay.classList.add('open');
<?php endif; ?>

const docTypeOptions = <?= json_encode($docTypes) ?>;

document.getElementById('addDocBtn').addEventListener('click', () => {
    const row = document.createElement('div');
    row.className = 'doc-row';

    const select = document.createElement('select');
    select.name = 'doc_type[]';
    for (const [val, label] of Object.entries(docTypeOptions)) {
        const opt = document.createElement('option');
        opt.value = val;
        opt.textContent = label;
        select.appendChild(opt);
    }

    const fileInput = document.createElement('input');
    fileInput.type = 'file';
    fileInput.className = 'doc-file-input';
    fileInput.name = 'doc_file[]';
    fileInput.accept = '.pdf,.jpg,.jpeg,.png';

    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'remove-row';
    removeBtn.setAttribute('aria-label', 'Remove document');
    removeBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg>';
    removeBtn.addEventListener('click', () => row.remove());

    row.appendChild(select);
    row.appendChild(fileInput);
    row.appendChild(removeBtn);
    document.getElementById('docList').appendChild(row);
});

function removeDocRow(btn) {
    const list = document.getElementById('docList');
    if (list.children.length > 1) {
        btn.closest('.doc-row').remove();
    }
}

const staffDocuments = <?php
$byUser = [];
foreach ($pdo->query('SELECT id, user_id, doc_type, original_name, file_size FROM staff_documents ORDER BY created_at DESC')->fetchAll() as $d) {
    $byUser[(int) $d['user_id']][] = [
        'id' => (int) $d['id'],
        'type' => $docTypes[$d['doc_type']] ?? $d['doc_type'],
        'name' => $d['original_name'],
        'ext' => strtolower(pathinfo($d['original_name'], PATHINFO_EXTENSION)),
        'size' => round($d['file_size'] / 1024) . ' KB',
    ];
}
echo json_encode($byUser);
?>;

const docsPanelOverlay = document.getElementById('docsPanelOverlay');

function openDocsPanel(userId, name) {
    document.getElementById('docsPanelTitle').textContent = name + "'s Documents";
    const body = document.getElementById('docsPanelBody');
    body.innerHTML = '';

    const docs = staffDocuments[userId] || [];
    docs.forEach(doc => {
        const isImg = doc.ext === 'jpg' || doc.ext === 'jpeg' || doc.ext === 'png';

        const row = document.createElement('div');
        row.className = 'doc-view-row';

        const thumb = document.createElement('span');
        thumb.className = 'thumb' + (isImg ? ' img' : '');
        thumb.textContent = doc.ext.toUpperCase();

        const meta = document.createElement('div');
        meta.className = 'meta';
        const dtype = document.createElement('div');
        dtype.className = 'dtype';
        dtype.textContent = doc.type;
        const fname = document.createElement('div');
        fname.className = 'fname';
        fname.textContent = doc.name + ' · ' + doc.size;
        meta.appendChild(dtype);
        meta.appendChild(fname);

        const link = document.createElement('a');
        link.className = 'open-link';
        link.href = 'document.php?id=' + encodeURIComponent(doc.id);
        link.target = '_blank';
        link.rel = 'noopener';
        link.textContent = 'Open';

        row.appendChild(thumb);
        row.appendChild(meta);
        row.appendChild(link);
        body.appendChild(row);
    });

    docsPanelOverlay.classList.add('open');
}

docsPanelOverlay.addEventListener('click', (e) => {
    if (e.target === docsPanelOverlay) docsPanelOverlay.classList.remove('open');
});

// ---------- Doctor Consultation Types panel ----------
const consultPanelOverlay = document.getElementById('consultPanelOverlay');
const consultForm = document.getElementById('consultForm');
const consultFormUserId = document.getElementById('consultFormUserId');
const consultPanelTitle = document.getElementById('consultPanelTitle');
const consultTypeList = document.getElementById('consultTypeList');

function consultTypeRow(row) {
    row = row || { id: '', label: '', fee: '', is_default: 0, is_revisit_eligible: 1 };
    const wrap = document.createElement('div');
    wrap.className = 'doc-row';
    wrap.style.gridTemplateColumns = '28px 1fr 140px 150px auto';

    const radioWrap = document.createElement('label');
    radioWrap.style.display = 'flex';
    radioWrap.style.alignItems = 'center';
    radioWrap.style.justifyContent = 'center';
    radioWrap.title = 'Default type';
    const radio = document.createElement('input');
    radio.type = 'radio';
    radio.name = 'consult_type_default_radio';
    radio.checked = Number(row.is_default) === 1;
    radio.style.width = '16px';
    radio.style.height = '16px';
    radio.style.accentColor = 'var(--primary)';
    radio.addEventListener('change', updateDefaultIndexes);
    radioWrap.appendChild(radio);

    const idInput = document.createElement('input');
    idInput.type = 'hidden';
    idInput.name = 'consult_type_id[]';
    idInput.value = row.id;

    const labelInput = document.createElement('input');
    labelInput.type = 'text';
    labelInput.name = 'consult_type_label[]';
    labelInput.placeholder = 'e.g. New Consultation';
    labelInput.value = row.label;
    labelInput.style.width = '100%';
    labelInput.style.padding = '9px 12px';
    labelInput.style.border = '1px solid var(--border)';
    labelInput.style.borderRadius = '10px';
    labelInput.style.fontSize = '13px';
    labelInput.style.fontFamily = 'inherit';
    labelInput.style.background = 'var(--card)';

    const feeWrap = document.createElement('div');
    feeWrap.style.position = 'relative';
    const feeInput = document.createElement('input');
    feeInput.type = 'number';
    feeInput.name = 'consult_type_fee[]';
    feeInput.placeholder = '0';
    feeInput.min = '0';
    feeInput.step = '1';
    feeInput.value = row.fee;
    feeInput.style.width = '100%';
    feeInput.style.padding = '9px 30px 9px 12px';
    feeInput.style.border = '1px solid var(--border)';
    feeInput.style.borderRadius = '10px';
    feeInput.style.fontSize = '13px';
    feeInput.style.fontFamily = 'inherit';
    feeInput.style.background = 'var(--card)';
    const rsLabel = document.createElement('span');
    rsLabel.textContent = 'Rs';
    rsLabel.style.cssText = 'position:absolute; right:10px; top:50%; transform:translateY(-50%); font-size:11px; color:var(--text-muted); font-weight:600;';
    feeWrap.appendChild(feeInput);
    feeWrap.appendChild(rsLabel);

    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'remove-row';
    removeBtn.setAttribute('aria-label', 'Remove consultation type');
    removeBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg>';
    removeBtn.addEventListener('click', () => { wrap.remove(); updateDefaultIndexes(); });

    // Revisit-eligible toggle: ticked = a real consultation (follow-up discounts
    // apply); unticked = a procedure-like type (always full fee, no revisit).
    const revisitWrap = document.createElement('label');
    revisitWrap.style.cssText = 'display:flex; align-items:center; gap:6px; font-size:11.5px; color:var(--text-secondary); white-space:nowrap;';
    revisitWrap.title = 'Tick for consultations (follow-up discounts apply). Untick for procedures.';
    const revisitCb = document.createElement('input');
    revisitCb.type = 'checkbox';
    revisitCb.checked = Number(row.is_revisit_eligible) !== 0;
    revisitCb.style.cssText = 'width:15px; height:15px; accent-color:var(--primary);';
    const revisitTxt = document.createElement('span');
    revisitTxt.textContent = 'Follow-up disc.';
    revisitWrap.appendChild(revisitCb);
    revisitWrap.appendChild(revisitTxt);

    wrap.appendChild(radioWrap);
    wrap.appendChild(labelInput);
    wrap.appendChild(feeWrap);
    wrap.appendChild(revisitWrap);
    wrap.appendChild(removeBtn);
    wrap._idInput = idInput;
    wrap._radio = radio;
    wrap._revisitCb = revisitCb;
    wrap.appendChild(idInput);
    return wrap;
}

// Single hidden field carrying the index (in DOM order) of the row whose radio is checked.
let defaultIndexInput;
function updateDefaultIndexes() {
    const rows = Array.from(consultTypeList.children);
    const checkedIndex = rows.findIndex(r => r._radio && r._radio.checked);
    defaultIndexInput.value = checkedIndex;
}

document.getElementById('addConsultTypeBtn').addEventListener('click', () => {
    consultTypeList.appendChild(consultTypeRow());
});

// Checkboxes only submit when checked, which would break alignment with the
// parallel label[]/fee[] arrays. So on submit, stamp each row's checkbox with
// its DOM-order index name (consult_type_revisit[IDX]); the PHP reads that map.
consultForm.addEventListener('submit', () => {
    Array.from(consultTypeList.children).forEach((rowEl, idx) => {
        if (rowEl._revisitCb) { rowEl._revisitCb.name = 'consult_type_revisit[' + idx + ']'; }
    });
});

function openConsultTypesPanel(data) {
    consultForm.reset();
    consultFormUserId.value = data.id;
    consultPanelTitle.textContent = 'Consultation Types — ' + (data.name || '');
    consultTypeList.innerHTML = '';

    if (!defaultIndexInput) {
        defaultIndexInput = document.createElement('input');
        defaultIndexInput.type = 'hidden';
        defaultIndexInput.name = 'consult_type_default';
        consultForm.insertBefore(defaultIndexInput, consultForm.firstChild);
    }

    let types = [];
    try { types = JSON.parse(data.types || '[]'); } catch (e) { types = []; }

    if (types.length === 0) {
        consultTypeList.appendChild(consultTypeRow());
    } else {
        types.forEach(t => consultTypeList.appendChild(consultTypeRow(t)));
    }
    updateDefaultIndexes();

    consultPanelOverlay.classList.add('open');
}

document.getElementById('closeConsultPanel').addEventListener('click', () => consultPanelOverlay.classList.remove('open'));
document.getElementById('cancelConsultPanel').addEventListener('click', () => consultPanelOverlay.classList.remove('open'));

<?php if (($error || $success) && ($_POST['action'] ?? '') === 'save_consult_types'): ?>consultPanelOverlay.classList.add('open');
<?php endif; ?>
</script>
<script src="assets/js/date-picker.js"></script>
</body>
</html>
