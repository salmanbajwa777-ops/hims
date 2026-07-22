<?php
/**
 * My Profile — self-service account page for every logged-in role.
 *
 * A user edits their OWN name / email / phone here, and changes their password
 * (current password required). Email and phone are the LOGIN credentials, so
 * both are uniqueness-checked against every other user (same dedupe rule as
 * staff.php) and at least one must remain set.
 *
 * Deliberately NOT editable here: base_role, max_discount_pct, specialty,
 * documents — those stay admin-only via staff.php so nobody can self-escalate.
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';

$uid = (int) $_SESSION['user_id'];

$error = '';
$success = '';

// ---- Save details (name / email / phone) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_details') {
    $name = trim($_POST['name'] ?? '');
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
            $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
                ->execute([$uid, 'profile_updated', "Updated own profile details (name/email/phone)"]);
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
        $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
            ->execute([$uid, 'password_changed', 'Changed own password from profile page']);
        $success = 'Password updated.';
    }
}

// Fresh row for the form (after any save).
$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$uid]);
$user = $stmt->fetch();

$roleLabels = [
    'ADMIN' => 'Administrator', 'MANAGER' => 'Manager', 'DOCTOR' => 'Doctor',
    'ACCOUNTANT' => 'Accountant', 'NURSE' => 'Nurse', 'RECEPTIONIST' => 'Receptionist',
];

$pageTitle = 'My Profile';
$headExtra = <<<CSS
<style>
.header { height: 72px; position: sticky; top: 0; z-index: 20; display: flex; align-items: center; justify-content: space-between; padding: 0 32px; background: rgba(255,255,255,.80); backdrop-filter: blur(18px); border-bottom: 1px solid var(--border); }
.header-right { display: flex; align-items: center; gap: 18px; margin-left: auto; }
.header-date { font-size: 13px; color: var(--text-secondary); white-space: nowrap; }
.logout-link { font-size: 13px; color: var(--text-secondary); font-weight: 500; }

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
</style>
CSS;
require __DIR__ . '/partials/head.php';
$navActive = 'profile';
require __DIR__ . '/partials/sidebar.php';
?>
        <header class="header">
            <div class="page-title" style="font-size:16px;">My Profile</div>
            <div class="header-right">
                <span class="header-date"><?= date('D, d M Y') ?></span>
                <a class="logout-link" href="logout.php">Logout</a>
            </div>
        </header>

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
                            <input type="text" id="pf_name" name="name" value="<?= htmlspecialchars($user['name']) ?>" required>
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
                            <input type="password" id="pf_cur" name="current_password" required autocomplete="current-password">
                        </div>
                        <div class="pf-field">
                            <label for="pf_new">New password</label>
                            <input type="password" id="pf_new" name="new_password" required minlength="8" autocomplete="new-password">
                            <div class="pf-hint">At least 8 characters.</div>
                        </div>
                        <div class="pf-field">
                            <label for="pf_conf">Confirm new password</label>
                            <input type="password" id="pf_conf" name="confirm_password" required minlength="8" autocomplete="new-password">
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
                        <div class="row"><span class="k">Specialty</span><span class="v"><?= htmlspecialchars(ucfirst(strtolower($user['specialty'] ?? 'GENERAL'))) ?></span></div>
                        <?php endif; ?>
                        <div class="row"><span class="k">Member since</span><span class="v"><?= $user['created_at'] ? date('d M Y', strtotime($user['created_at'])) : '—' ?></span></div>
                    </div>
                    <div class="locked-note">Need a role or cap change? Ask an administrator<?= ($user['base_role'] === 'ADMIN') ? ' — or edit it yourself in Staff &amp; Doctors' : '' ?>.</div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
