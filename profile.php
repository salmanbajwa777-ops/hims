<?php
/**
 * My Profile — self-service account page for every logged-in role.
 *
 * A user edits their OWN name / email / phone here, and changes their password
 * (current password required). Email and phone are the LOGIN credentials, so
 * both are uniqueness-checked against every other user (same dedupe rule as
 * staff.php) and at least one must remain set.
 *
 * Users also upload their own documents (CNIC, degree, registration…). Those
 * are append-only: staff can add and view, but only an admin can remove one
 * from staff.php, so a record can't be quietly withdrawn after being filed.
 *
 * Deliberately NOT editable here: base_role, max_discount_pct, specialty —
 * those stay admin-only via staff.php so nobody can self-escalate.
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
// audit_log() lives in permissions.php. This page does not gate on a permission
// — everyone edits their own profile — so it had no reason to load that file
// until the audit-log refactor moved the helper there. Without it, saving a
// profile, changing a password or uploading a document fatals with
// "Call to undefined function audit_log()".
require_once __DIR__ . '/config/permissions.php';
require_once __DIR__ . '/config/staff_documents.php';

$uid = (int) $_SESSION['user_id'];

$error = '';
$success = '';

// ---- Save details (name / email / phone) ----
// Blocked while viewing as someone else: email and phone ARE the login
// credentials (see index.php), so an admin editing them here would be changing
// how that person signs in, from inside their account, with no second factor.
// Diagnosing a screen never requires this. The password form below is already
// safe — it verifies the target's current password, which the admin won't know.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_details'
    && is_impersonating()) {
    $error = 'You are viewing HIMS as ' . imp_target_name()
           . '. Their name, email and phone are their login credentials and cannot be changed from here — '
           . 'stop viewing as them and edit the account in Staff & Doctors instead.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_details') {
    // Names are stored in ALL CAPS so they read uniformly everywhere they appear.
    $name = mb_strtoupper(trim($_POST['name'] ?? ''), 'UTF-8');
    $email = trim($_POST['email'] ?? '');
    // Canonical local format ("03001234567") so login-by-phone always matches
    // what people naturally type. +92 / spaces / dashes are folded away.
    $phone = normalize_staff_phone(trim($_POST['phone'] ?? ''));

    if ($name === '' || ($email === '' && $phone === '')) {
        $error = 'A name and at least one of email / phone are required (you log in with them).';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'That email address doesn\'t look valid.';
    } else {
        // Self-excluding dedupe — email/phone are credentials. Email is an
        // exact match; phone compares NORMALIZED (login treats "+92300…" and
        // "0300…" as the same number, so uniqueness must too — legacy rows may
        // still hold +92/spaced formats).
        $clash = false;
        if ($email !== '') {
            $eStmt = $pdo->prepare('SELECT id FROM users WHERE id != ? AND email = ?');
            $eStmt->execute([$uid, $email]);
            $clash = (bool) $eStmt->fetch();
        }
        if (!$clash && staff_phone_in_use($pdo, $phone, $uid)) {
            $clash = true;
        }
        if ($clash) {
            $error = 'Another user already uses that email or phone.';
        } else {
            $pdo->prepare('UPDATE users SET name = ?, email = ?, phone = ? WHERE id = ?')
                ->execute([$name, $email !== '' ? $email : null, $phone !== '' ? $phone : null, $uid]);
            audit_log($pdo, 'profile_updated', "Updated own profile details (name/email/phone)", $uid);
            $success = 'Profile saved. Remember: you sign in with this email or phone.';
        }
    }
}

// ---- Change password (current password required) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    $pwStmt = $pdo->prepare('SELECT password FROM users WHERE id = ?');
    $pwStmt->execute([$uid]);
    $hash = (string) $pwStmt->fetchColumn();

    if (!password_verify($current, $hash)) {
        $error = 'Current password is incorrect.';
    } elseif (strlen($new) < 8) {
        $error = 'New password must be at least 8 characters.';
    } elseif ($new !== $confirm) {
        $error = 'New password and confirmation do not match.';
    } else {
        $pdo->prepare('UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?')
            ->execute([password_hash($new, PASSWORD_BCRYPT), $uid]);
        $_SESSION['must_change_password'] = false;
        audit_log($pdo, 'password_changed', 'Changed own password from profile page', $uid);
        $success = 'Password updated.';
    }
}

// ---- Upload own documents ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_docs') {
    [$pendingDocs, $docError] = staff_docs_validate($_FILES['doc_file'] ?? null, $_POST['doc_type'] ?? []);

    if ($docError !== '') {
        $error = $docError;
    } elseif (!$pendingDocs) {
        $error = 'Choose at least one file to upload.';
    } else {
        // Uploader is the user themselves — that is what distinguishes a
        // self-upload from an admin filing something on their behalf.
        $stored = staff_docs_store($pdo, $pendingDocs, $uid, $uid);
        if ($stored === 0) {
            $error = 'The upload could not be saved. Please try again.';
        } else {
            audit_log($pdo, 'document_uploaded', "Uploaded $stored document(s) to own profile", $uid);
            $success = $stored === 1 ? 'Document uploaded.' : "$stored documents uploaded.";
        }
    }
}

// Fresh row for the form (after any save).
$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$uid]);
$user = $stmt->fetch();

// Own documents, newest first. Guarded: the table arrives with
// sql/add_staff_documents.sql and the page must still render without it.
$myDocs = [];
try {
    $dStmt = $pdo->prepare('SELECT id, doc_type, original_name, file_size, uploaded_by_id, created_at FROM staff_documents WHERE user_id = ? ORDER BY created_at DESC, id DESC');
    $dStmt->execute([$uid]);
    $myDocs = $dStmt->fetchAll();
} catch (PDOException $e) {
    $myDocs = [];
}
$docTypeLabels = staff_doc_types();

$roleLabels = [
    'ADMIN' => 'Administrator', 'DOCTOR' => 'Doctor', 'STAFF' => 'Staff',
    // Legacy labels for any not-yet-migrated row.
    'MANAGER' => 'Manager', 'ACCOUNTANT' => 'Accountant',
    'NURSE' => 'Nurse', 'RECEPTIONIST' => 'Receptionist',
];
$specialtyLabels = [
    'GENERAL' => 'General', 'PEDIATRICIAN' => 'Pediatrician', 'ENT' => 'ENT Consultant',
    'DENTAL' => 'Dental Surgeon', 'PEDIATRIC_SURGEON' => 'Pediatric Surgeon',
];

$pageTitle = 'My Profile';
$headExtra = <<<CSS
<style>
/* The page header styles are gone with the header itself — the shared
   app bar brings its own from assets/app.css. */

.profile-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; align-items: start; }
@media (max-width: 860px) { .profile-grid { grid-template-columns: 1fr; } }

.id-strip { display: flex; align-items: center; gap: 16px; margin-bottom: 18px; }
.id-strip .avatar { width: 56px; height: 56px; border-radius: 50%; background: var(--primary-light); color: var(--primary-dark); display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 800; flex-shrink: 0; }
.id-strip .nm { font-size: 18px; font-weight: 700; }
.role-tag { display: inline-block; font-size: 11px; font-weight: 700; padding: 2px 10px; border-radius: 20px; background: var(--primary-light); color: var(--primary-dark); margin-top: 3px; }

.pf-field { margin-bottom: 14px; }
.pf-field label { display: block; font-size: 12.5px; font-weight: 600; color: var(--text-secondary); margin-bottom: 6px; }
.pf-field input { width: 100%; padding: 10px 12px; border: 1px solid var(--border); border-radius: var(--radius-input); font: inherit; font-size: 14px; background: var(--bg); }
.pf-field input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,127,126,.15); background: #fff; }
.pf-hint { font-size: 11.5px; color: var(--text-muted); margin-top: 4px; }

.locked-list { display: flex; flex-direction: column; gap: 8px; font-size: 13px; }
.locked-list .row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid var(--border); }
.locked-list .row:last-child { border-bottom: none; }
.locked-list .k { color: var(--text-muted); }
.locked-list .v { font-weight: 600; }
.locked-note { font-size: 11.5px; color: var(--text-muted); margin-top: 10px; }

.doc-card { grid-column: 1 / -1; }
.doc-list { display: flex; flex-direction: column; gap: 8px; margin-bottom: 18px; }
.doc-row { display: flex; align-items: center; gap: 12px; padding: 10px 12px; border: 1px solid var(--border); border-radius: var(--radius-input); background: var(--bg); }
.doc-row .doc-ico { width: 32px; height: 32px; border-radius: 8px; background: var(--primary-light); color: var(--primary-dark); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.doc-row .doc-ico svg { width: 17px; height: 17px; }
.doc-row .doc-main { min-width: 0; flex: 1; }
.doc-row .doc-name { font-size: 13.5px; font-weight: 600; }
/* The stored filename is the long, unpredictable part — clip it rather than the
   document type, which is what identifies the row at a glance. */
.doc-row .doc-meta { font-size: 11.5px; color: var(--text-muted); margin-top: 2px; overflow-wrap: anywhere; }
.doc-row .doc-open { font-size: 12.5px; font-weight: 600; color: var(--primary); flex-shrink: 0; }
.doc-empty { font-size: 13px; color: var(--text-muted); padding: 14px 0 18px; }

.doc-up-row { display: flex; gap: 10px; align-items: center; margin-bottom: 10px; }
.doc-up-row select { padding: 9px 10px; border: 1px solid var(--border); border-radius: var(--radius-input); font: inherit; font-size: 13.5px; background: var(--bg); flex-shrink: 0; }
.doc-up-row input[type="file"] { flex: 1; min-width: 0; font-size: 13px; }
.doc-rm { background: none; border: none; color: var(--text-muted); cursor: pointer; font-size: 20px; line-height: 1; padding: 0 6px; }
.doc-rm:hover { color: #b3261e; }
.doc-add { background: none; border: 1px dashed var(--border); color: var(--text-secondary); border-radius: var(--radius-input); padding: 8px 14px; font: inherit; font-size: 13px; font-weight: 600; cursor: pointer; }
.doc-add:hover { border-color: var(--primary); color: var(--primary); }
@media (max-width: 620px) { .doc-up-row { flex-wrap: wrap; } .doc-up-row select { width: 100%; } }

.pw-wrap { position: relative; }
.pw-wrap input { padding-right: 42px; }
.pw-eye { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: var(--text-muted); padding: 4px; display: flex; }
.pw-eye:hover { color: var(--primary); }
.pw-eye svg { width: 18px; height: 18px; }
</style>
CSS;
require __DIR__ . '/partials/head.php';
$navActive = 'profile';
require __DIR__ . '/partials/sidebar.php';
?>
        <?php /* The page's own mini-header (title + date + Logout) is gone: the
                 shared app bar above carries date and Logout on every page,
                 and the title is repeated in .page-head just below. */ ?>

        <div class="content">
            <div class="page-head">
                <div>
                    <div class="page-title">My Profile</div>
                    <div class="page-sub">Your own account details — name, contact, and password</div>
                </div>
            </div>

            <?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($success): ?><div class="alert success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

            <div class="id-strip">
                <span class="avatar"><?= htmlspecialchars(strtoupper(substr($user['name'], 0, 1))) ?></span>
                <div>
                    <div class="nm"><?= htmlspecialchars($user['name']) ?></div>
                    <span class="role-tag"><?= htmlspecialchars($roleLabels[$user['base_role']] ?? $user['base_role']) ?></span>
                </div>
            </div>

            <div class="profile-grid">
                <!-- Details -->
                <div class="card">
                    <div class="section-title">Account Details</div>
                    <div class="section-sub">Your email or phone is what you sign in with — keep at least one set.</div>
                    <form method="POST" action="profile.php">
                        <input type="hidden" name="action" value="save_details">
                        <div class="pf-field">
                            <label for="pf_name">Full name</label>
                            <input type="text" id="pf_name" name="name" class="uc" value="<?= htmlspecialchars($user['name']) ?>" required>
                        </div>
                        <div class="pf-field">
                            <label for="pf_email">Email</label>
                            <input type="email" id="pf_email" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>" placeholder="name@example.com">
                            <div class="pf-hint">Used to sign in and for system emails.</div>
                        </div>
                        <div class="pf-field">
                            <label for="pf_phone">Phone</label>
                            <input type="text" id="pf_phone" name="phone" value="<?= htmlspecialchars($user['phone'] ?? '') ?>" placeholder="03001234567">
                            <div class="pf-hint">Also accepted at the login screen — saved as 0300… (spaces, dashes and +92 are cleaned up automatically).</div>
                        </div>
                        <div style="display:flex;justify-content:flex-end;margin-top:6px;">
                            <button type="submit" class="btn">Save details</button>
                        </div>
                    </form>
                </div>

                <!-- Password -->
                <div class="card">
                    <div class="section-title">Change Password</div>
                    <div class="section-sub">Your current password is required to set a new one.</div>
                    <form method="POST" action="profile.php" autocomplete="off">
                        <input type="hidden" name="action" value="change_password">
                        <div class="pf-field">
                            <label for="pf_cur">Current password</label>
                            <div class="pw-wrap">
                                <input type="password" id="pf_cur" name="current_password" required autocomplete="current-password">
                                <button type="button" class="pw-eye" onclick="pwToggle('pf_cur', this)" aria-label="Show password" tabindex="-1"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>
                            </div>
                        </div>
                        <div class="pf-field">
                            <label for="pf_new">New password</label>
                            <div class="pw-wrap">
                                <input type="password" id="pf_new" name="new_password" required minlength="8" autocomplete="new-password">
                                <button type="button" class="pw-eye" onclick="pwToggle('pf_new', this)" aria-label="Show password" tabindex="-1"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>
                            </div>
                            <div class="pf-hint">At least 8 characters.</div>
                        </div>
                        <div class="pf-field">
                            <label for="pf_conf">Confirm new password</label>
                            <div class="pw-wrap">
                                <input type="password" id="pf_conf" name="confirm_password" required minlength="8" autocomplete="new-password">
                                <button type="button" class="pw-eye" onclick="pwToggle('pf_conf', this)" aria-label="Show password" tabindex="-1"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>
                            </div>
                        </div>
                        <div style="display:flex;justify-content:flex-end;margin-top:6px;">
                            <button type="submit" class="btn">Update password</button>
                        </div>
                    </form>
                </div>

                <!-- Managed-by-admin (read-only) -->
                <div class="card">
                    <div class="section-title">Managed by Administration</div>
                    <div class="section-sub">These are set from Staff &amp; Doctors, not here.</div>
                    <div class="locked-list">
                        <div class="row"><span class="k">Role</span><span class="v"><?= htmlspecialchars($roleLabels[$user['base_role']] ?? $user['base_role']) ?></span></div>
                        <div class="row"><span class="k">Discount cap</span><span class="v"><?= rtrim(rtrim(number_format((float) ($user['max_discount_pct'] ?? 0), 2), '0'), '.') ?>%</span></div>
                        <?php if ($user['base_role'] === 'DOCTOR'): ?>
                        <div class="row"><span class="k">Specialty</span><span class="v"><?= htmlspecialchars($specialtyLabels[$user['specialty'] ?? 'GENERAL'] ?? ucfirst(strtolower($user['specialty'] ?? 'GENERAL'))) ?></span></div>
                        <?php endif; ?>
                        <div class="row"><span class="k">Member since</span><span class="v"><?= $user['created_at'] ? date('d/m/Y', strtotime($user['created_at'])) : '—' ?></span></div>
                    </div>
                    <div class="locked-note">Need a role or cap change? Ask an administrator<?= ($user['base_role'] === 'ADMIN') ? ' — or edit it yourself in Staff &amp; Doctors' : '' ?>.</div>
                </div>

                <!-- Documents -->
                <div class="card doc-card">
                    <div class="section-title">My Documents</div>
                    <div class="section-sub">Your CNIC, degrees, registration and experience letters. PDF, JPG or PNG, up to 10MB each.</div>

                    <?php if ($myDocs): ?>
                        <div class="doc-list">
                            <?php foreach ($myDocs as $d): ?>
                                <div class="doc-row">
                                    <span class="doc-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></span>
                                    <div class="doc-main">
                                        <div class="doc-name"><?= htmlspecialchars($docTypeLabels[$d['doc_type']] ?? $d['doc_type']) ?></div>
                                        <div class="doc-meta">
                                            <?= htmlspecialchars($d['original_name']) ?> · <?= staff_doc_size((int) $d['file_size']) ?>
                                            <?php if ($d['created_at']): ?> · <?= date('d/m/Y', strtotime($d['created_at'])) ?><?php endif; ?>
                                            <?php if ((int) $d['uploaded_by_id'] !== $uid): ?> · added by administration<?php endif; ?>
                                        </div>
                                    </div>
                                    <a class="doc-open" href="document.php?id=<?= (int) $d['id'] ?>" target="_blank" rel="noopener">View</a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="doc-empty">You haven't uploaded any documents yet.</div>
                    <?php endif; ?>

                    <form method="POST" action="profile.php" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="upload_docs">
                        <div id="docRows">
                            <div class="doc-up-row">
                                <select name="doc_type[]">
                                    <?php foreach ($docTypeLabels as $k => $label): ?>
                                        <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="file" name="doc_file[]" accept=".pdf,.jpg,.jpeg,.png">
                            </div>
                        </div>
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:6px;gap:12px;flex-wrap:wrap;">
                            <button type="button" class="doc-add" onclick="addDocRow()">+ Add another</button>
                            <button type="submit" class="btn">Upload</button>
                        </div>
                        <div class="pf-hint" style="margin-top:10px;">Uploaded documents stay on your record — ask an administrator if something needs removing.</div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
function pwToggle(id, btn) {
    var i = document.getElementById(id);
    i.type = i.type === 'password' ? 'text' : 'password';
    btn.style.color = i.type === 'text' ? 'var(--primary)' : '';
}

// Repeatable upload rows. Cloning the first row keeps the <option> list in one
// place (PHP) rather than duplicating the document types here in JS.
function addDocRow() {
    var rows = document.getElementById('docRows');
    var clone = rows.firstElementChild.cloneNode(true);
    clone.querySelector('input[type="file"]').value = '';
    clone.querySelector('select').selectedIndex = 0;
    if (!clone.querySelector('.doc-rm')) {
        var rm = document.createElement('button');
        rm.type = 'button';
        rm.className = 'doc-rm';
        rm.innerHTML = '&times;';
        rm.setAttribute('aria-label', 'Remove this row');
        rm.onclick = function () { clone.remove(); };
        clone.appendChild(rm);
    }
    rows.appendChild(clone);
}
</script>
</body>
</html>
