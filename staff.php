<?php
require_once __DIR__ . '/config/guard_admin.php';
require_once __DIR__ . '/config/db.php';

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

$error = '';
$success = '';
$tempPassword = '';

$uploadDir = __DIR__ . '/uploads/staff_docs/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_staff') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $role = $_POST['base_role'] ?? '';
    $docTypeInputs = $_POST['doc_type'] ?? [];
    $docFiles = $_FILES['doc_file'] ?? null;

    if ($name === '' || ($email === '' && $phone === '') || !in_array($role, $roles, true)) {
        $error = 'Please provide a name, at least one of email/phone, and a valid role.';
    } else {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE (email = ? AND email IS NOT NULL AND email != "") OR (phone = ? AND phone IS NOT NULL AND phone != "") LIMIT 1');
        $stmt->execute([$email, $phone]);

        if ($stmt->fetch()) {
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
                $tempPassword = substr(bin2hex(random_bytes(6)), 0, 10);
                $hash = password_hash($tempPassword, PASSWORD_BCRYPT);

                $insert = $pdo->prepare('INSERT INTO users (name, email, phone, password, base_role, must_change_password) VALUES (?, ?, ?, ?, ?, 1)');
                $insert->execute([
                    $name,
                    $email !== '' ? $email : null,
                    $phone !== '' ? $phone : null,
                    $hash,
                    $role,
                ]);

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

                $success = "Account created for $name.";
            }
        }
    }
}

$staff = $pdo->query('SELECT id, name, email, phone, base_role, must_change_password, created_at FROM users ORDER BY created_at DESC')->fetchAll();

$docCounts = [];
foreach ($pdo->query('SELECT user_id, COUNT(*) AS cnt FROM staff_documents GROUP BY user_id')->fetchAll() as $row) {
    $docCounts[(int) $row['user_id']] = (int) $row['cnt'];
}

function roleBadgeColor(string $role): array {
    return match ($role) {
        'ADMIN' => ['#EDE9FE', '#6D28D9'],
        'DOCTOR' => ['#DBEAFE', '#1E3A8A'],
        'MANAGER' => ['#FEF3C7', '#92400E'],
        'ACCOUNTANT' => ['#ECFDF5', '#047857'],
        'NURSE' => ['#FCE7F3', '#9D174D'],
        'RECEPTIONIST' => ['#F1F5F9', '#334155'],
        default => ['#F1F5F9', '#334155'],
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>HIMS — Staff &amp; Doctors</title>
<style>
:root {
    --primary-dark: #1E3A8A;
    --primary: #2563EB;
    --primary-light: #DBEAFE;
    --green: #10B981;
    --green-bg: #ECFDF5;
    --green-text: #047857;
    --amber: #F59E0B;
    --amber-bg: #FFFBEB;
    --amber-text: #92400E;
    --red: #DC2626;
    --red-bg: #FEF2F2;
    --red-text: #B91C1C;
    --bg: #F8FAFC;
    --card: #FFFFFF;
    --text: #0F172A;
    --text-secondary: #64748B;
    --text-muted: #94A3B8;
    --border: #E2E8F0;
    --border-strong: #CBD5E1;
    --shadow-sm: 0 2px 8px rgba(15,23,42,.05);
    --shadow-md: 0 10px 25px rgba(15,23,42,.08);
    --radius-card: 20px;
    --radius-input: 12px;
    --radius-btn: 14px;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Inter', system-ui, -apple-system, "Segoe UI", sans-serif; background: var(--bg); color: var(--text); font-size: 14px; line-height: 1.5; }
a { text-decoration: none; color: inherit; }
.app { display: grid; grid-template-columns: 280px 1fr; min-height: 100vh; }
.main { display: flex; flex-direction: column; min-width: 0; }
.content { padding: 28px 32px 60px; display: flex; flex-direction: column; gap: 24px; }

.sidebar { background: var(--card); border-right: 1px solid var(--border); padding: 24px 16px; position: sticky; top: 0; height: 100vh; overflow-y: auto; }
.sidebar-brand { display: flex; align-items: center; gap: 10px; padding: 0 8px 24px; font-weight: 700; font-size: 18px; }
.sidebar-brand .logo-mark { width: 34px; height: 34px; border-radius: 10px; background: linear-gradient(135deg, var(--primary-dark), var(--primary)); display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 700; font-size: 14px; }
.nav-group { margin-bottom: 18px; }
.nav-group-label { font-size: 11px; font-weight: 600; letter-spacing: .06em; color: var(--text-muted); padding: 0 12px 8px; text-transform: uppercase; }
.nav-item { display: flex; align-items: center; gap: 10px; padding: 9px 12px; border-radius: 12px; color: var(--text-secondary); font-weight: 500; font-size: 13.5px; transition: background .15s ease; }
.nav-item:hover { background: #F8FAFC; }
.nav-item.active { background: var(--primary-light); color: var(--primary-dark); font-weight: 600; position: relative; }
.nav-item.active::before { content: ""; position: absolute; left: -16px; top: 8px; bottom: 8px; width: 3px; background: var(--primary); border-radius: 0 3px 3px 0; }
.nav-icon { width: 28px; height: 28px; border-radius: 8px; background: #F1F5F9; display: flex; align-items: center; justify-content: center; flex-shrink: 0; color: var(--text-secondary); }
.nav-icon svg { width: 15px; height: 15px; }
.nav-item.active .nav-icon { background: #fff; color: var(--primary-dark); }

.header { height: 72px; position: sticky; top: 0; z-index: 20; display: flex; align-items: center; justify-content: space-between; padding: 0 32px; background: rgba(255,255,255,.80); backdrop-filter: blur(18px); border-bottom: 1px solid var(--border); }
.header-right { display: flex; align-items: center; gap: 18px; margin-left: auto; }
.header-date { font-size: 13px; color: var(--text-secondary); white-space: nowrap; }
.logout-link { font-size: 13px; color: var(--text-secondary); font-weight: 500; }

.page-title { font-size: 22px; font-weight: 700; }
.page-sub { font-size: 13px; color: var(--text-muted); margin-top: 2px; }
.page-head { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; }

.card { background: var(--card); border-radius: var(--radius-card); border: 1px solid var(--border); box-shadow: var(--shadow-sm); padding: 22px 24px; }
.section-title { font-size: 16px; font-weight: 600; margin-bottom: 2px; }
.section-sub { font-size: 12.5px; color: var(--text-muted); margin-bottom: 16px; }

.btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: var(--radius-btn); border: none; background: linear-gradient(135deg, var(--primary-dark), var(--primary)); color: #fff; font-size: 13.5px; font-weight: 600; cursor: pointer; font-family: inherit; }
.btn:hover { opacity: .92; }
.btn.secondary { background: #fff; color: var(--text-secondary); border: 1px solid var(--border); }

.alert { border-radius: 14px; padding: 14px 18px; font-size: 13.5px; margin-bottom: 4px; }
.alert.error { background: var(--red-bg); color: var(--red-text); }
.alert.success { background: var(--green-bg); color: var(--green-text); }
.alert .temp-pass { font-weight: 700; font-family: 'Courier New', monospace; background: #fff; padding: 2px 8px; border-radius: 6px; margin-left: 4px; }

table { width: 100%; border-collapse: collapse; }
th { text-align: left; font-size: 11.5px; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted); padding: 0 10px 10px; font-weight: 600; }
td { padding: 12px 10px; border-top: 1px solid var(--border); font-size: 13.5px; }
.person { display: flex; align-items: center; gap: 10px; font-weight: 600; }
.person-avatar { width: 32px; height: 32px; border-radius: 50%; background: var(--primary-light); color: var(--primary-dark); display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; flex-shrink: 0; }
.role-badge { font-size: 11.5px; font-weight: 600; padding: 3px 10px; border-radius: 20px; display: inline-block; }
.status-pill { font-size: 11px; font-weight: 600; padding: 3px 9px; border-radius: 20px; }
.status-pill.pending { background: var(--amber-bg); color: var(--amber-text); }
.status-pill.active { background: var(--green-bg); color: var(--green-text); }
.muted { color: var(--text-muted); font-size: 12.5px; }
.doc-count-link { font-size: 12.5px; font-weight: 600; color: var(--primary); }
.doc-count-link.none { color: var(--text-muted); font-weight: 500; cursor: default; }

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
.field input:focus, .field select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(37,99,235,.15); background: var(--card); }

.doc-list { display: flex; flex-direction: column; gap: 10px; }
.doc-row { display: grid; grid-template-columns: 240px 1fr auto; align-items: center; gap: 12px; border: 1px solid var(--border); border-radius: 14px; padding: 10px 12px; background: var(--bg); }
.doc-row select { width: 100%; padding: 9px 12px; border: 1px solid var(--border); border-radius: 10px; font-size: 13px; font-family: inherit; background: var(--card); color: var(--text); }
.doc-row select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(37,99,235,.15); }
.doc-file-input { width: 100%; font-size: 12.5px; color: var(--text-secondary); }
.doc-file-input::file-selector-button {
    font-family: inherit; font-size: 12px; font-weight: 600; color: var(--primary); background: var(--primary-light);
    border: none; border-radius: 8px; padding: 7px 12px; margin-right: 10px; cursor: pointer;
}
.doc-row .remove-row { width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: var(--text-muted); cursor: pointer; flex-shrink: 0; border: none; background: transparent; }
.doc-row .remove-row:hover { background: var(--red-bg); color: var(--red-text); }
.doc-row .remove-row svg { width: 14px; height: 14px; }

.add-doc-btn { display: flex; align-items: center; justify-content: center; gap: 8px; border: 1.5px dashed var(--border-strong); border-radius: 14px; padding: 12px; font-size: 13px; font-weight: 600; color: var(--text-secondary); cursor: pointer; background: transparent; font-family: inherit; width: 100%; margin-top: 12px; }
.add-doc-btn:hover { border-color: var(--primary); color: var(--primary); background: rgba(37,99,235,.04); }
.add-doc-btn svg { width: 15px; height: 15px; }

.info-banner { display: flex; gap: 12px; align-items: flex-start; background: var(--primary-light); border-radius: 14px; padding: 14px 16px; color: var(--primary-dark); font-size: 12.5px; }
.info-banner svg { width: 16px; height: 16px; flex-shrink: 0; margin-top: 1px; }

.form-footer { position: sticky; bottom: 0; display: flex; align-items: center; justify-content: flex-end; gap: 10px; background: var(--card); border-top: 1px solid var(--border); padding: 16px 24px; border-radius: var(--radius-card); box-shadow: var(--shadow-md); margin-top: 4px; }

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

@media (max-width: 900px) {
    .app { grid-template-columns: 1fr; }
    .sidebar { display: none; }
}
@media (max-width: 720px) {
    .field-grid { grid-template-columns: 1fr; }
    .doc-row { grid-template-columns: 1fr; }
    .doc-row .remove-row { justify-self: end; }
}
</style>
</head>
<body>
<div class="app">
    <aside class="sidebar">
        <div class="sidebar-brand"><div class="logo-mark">H</div>HIMS</div>
        <div class="nav-group">
            <div class="nav-group-label">Overview</div>
            <a class="nav-item" href="dashboard.php"><span class="nav-icon">▦</span> Dashboard</a>
        </div>
        <div class="nav-group">
            <div class="nav-group-label">Management</div>
            <a class="nav-item active" href="staff.php"><span class="nav-icon">👥</span> Staff &amp; Doctors</a>
        </div>
    </aside>

    <div class="main">
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
                <div class="alert success">
                    <?= htmlspecialchars($success) ?> Temporary password:
                    <span class="temp-pass"><?= htmlspecialchars($tempPassword) ?></span>
                    — share this with them securely. They'll be asked to set a new password on first login.
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="section-title">All Accounts</div>
                <div class="section-sub"><?= count($staff) ?> total</div>
                <table>
                    <thead>
                        <tr><th>Name</th><th>Contact</th><th>Role</th><th>Status</th><th>Documents</th><th>Added</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($staff as $s): ?>
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
                            <td><span class="role-badge" style="background:<?= $bg ?>;color:<?= $fg ?>;"><?= htmlspecialchars($s['base_role']) ?></span></td>
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
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$staff): ?>
                        <tr><td colspan="6" class="muted" style="text-align:center;padding:24px;">No staff added yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Add Staff / Doctor full panel -->
<div class="panel-overlay" id="addPanelOverlay">
    <div class="panel">
        <div class="form-header">
            <div>
                <h1>Add Doctor / Staff</h1>
                <div class="sub">Create a login and file their onboarding documents in one go.</div>
            </div>
            <button type="button" class="close-btn" id="closeAddPanel" aria-label="Close">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
            </button>
        </div>

        <form class="staff-form" method="POST" action="staff.php" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add_staff">

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
                            <label for="phone">Phone <span class="opt">(optional if email set)</span></label>
                            <input type="text" id="phone" name="phone" placeholder="03xxxxxxxxx">
                        </div>
                        <div class="field">
                            <label for="base_role">Role</label>
                            <select id="base_role" name="base_role" required>
                                <?php foreach ($roles as $r): ?>
                                <option value="<?= $r ?>"><?= ucfirst(strtolower($r)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="section">
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

            <div class="info-banner">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
                <span>A temporary password will be generated on save. They'll be required to set a new one on first sign-in. Documents are stored privately and only visible to admins.</span>
            </div>

            <div class="form-footer">
                <button type="button" class="btn secondary" id="cancelAddPanel">Cancel</button>
                <button type="submit" class="btn">Create Account</button>
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

<script>
const addPanelOverlay = document.getElementById('addPanelOverlay');
document.getElementById('openAddPanel').addEventListener('click', () => addPanelOverlay.classList.add('open'));
document.getElementById('closeAddPanel').addEventListener('click', () => addPanelOverlay.classList.remove('open'));
document.getElementById('cancelAddPanel').addEventListener('click', () => addPanelOverlay.classList.remove('open'));
<?php if ($error || $success): ?>addPanelOverlay.classList.add('open');<?php endif; ?>

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
</script>
</body>
</html>
