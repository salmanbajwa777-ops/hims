<?php
/**
 * ONE-TIME login rescue page — the admin is locked out (password column was
 * hand-edited to plain text in phpMyAdmin, and a later hash paste may have
 * been mangled), so this page cannot require login. It is gated by a secret
 * URL key instead, shows the admin rows it found (id / name / email / phone /
 * whether the stored password is a valid bcrypt hash), and sets a chosen
 * admin's password to a NEW value hashed server-side by PHP itself — no
 * copy-pasting hashes through phpMyAdmin.
 *
 * DELETES ITSELF after a successful reset. If the deploy user can't unlink,
 * it renames the key requirement moot by refusing further runs via a marker.
 *
 * Usage: https://<site>/login_fix.php?key=FIX-HIMS-2026
 */
require_once __DIR__ . '/config/db.php';

const FIX_KEY = 'FIX-HIMS-2026';

if (($_GET['key'] ?? '') !== FIX_KEY) {
    http_response_code(404);
    exit('Not found.');
}

$msg = '';
$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = (int) ($_POST['user_id'] ?? 0);
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if ($userId <= 0 || strlen($new) < 8) {
        $msg = 'Pick a user and enter a password of at least 8 characters.';
    } elseif ($new !== $confirm) {
        $msg = 'Passwords do not match.';
    } else {
        $stmt = $pdo->prepare('SELECT id, name FROM users WHERE id = ? AND base_role = "ADMIN"');
        $stmt->execute([$userId]);
        $target = $stmt->fetch();
        if (!$target) {
            $msg = 'That user is not an admin.';
        } else {
            $pdo->prepare('UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_BCRYPT), $userId]);
            $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
                ->execute([$userId, 'password_rescue', 'Password reset via one-time login_fix page']);
            $done = true;
            $msg = 'Password set for ' . htmlspecialchars($target['name']) . '. Log in now — this page has deleted itself.';
            @unlink(__FILE__);   // job done — remove the rescue page
        }
    }
}

// Admin rows + whether each stored password is a plausible bcrypt hash.
$admins = [];
if (!$done) {
    foreach ($pdo->query('SELECT id, name, email, phone, password FROM users WHERE base_role = "ADMIN" ORDER BY id') as $a) {
        $a['hash_ok'] = (bool) preg_match('/^\$2y\$\d{2}\$.{53}$/', (string) $a['password']);
        unset($a['password']);
        $admins[] = $a;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HIMS — Login Fix</title>
    <style>
        :root { --primary-dark: #0E5456; --primary: #1A7F7E; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'Inter', system-ui, sans-serif; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #F8FAFC; padding: 20px; }
        .card { background: #fff; border-radius: 20px; padding: 36px; width: 100%; max-width: 520px; box-shadow: 0 10px 25px rgba(15,23,42,.08); border: 1px solid #E2E8F0; }
        h1 { margin: 0 0 4px; font-size: 20px; color: #0F172A; }
        p.sub { margin: 0 0 20px; color: #334155; font-size: 13.5px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; margin-bottom: 18px; }
        th { text-align: left; font-size: 11px; text-transform: uppercase; color: #64748B; padding: 0 8px 8px; }
        td { padding: 8px; border-top: 1px solid #E2E8F0; }
        .ok { color: #047857; font-weight: 700; }
        .bad { color: #B91C1C; font-weight: 700; }
        label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin: 12px 0 6px; }
        input, select { width: 100%; padding: 10px 12px; border: 1px solid #E2E8F0; border-radius: 12px; font-size: 14px; font-family: inherit; }
        input:focus, select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,127,126,.15); }
        button { width: 100%; margin-top: 18px; padding: 11px; border: none; border-radius: 14px; background: linear-gradient(135deg, var(--primary-dark), var(--primary)); color: #fff; font-size: 14px; font-weight: 600; cursor: pointer; }
        .msg { background: #FFFBEB; color: #92400E; padding: 10px 12px; border-radius: 10px; font-size: 13px; margin-bottom: 14px; }
        .msg.done { background: #ECFDF5; color: #047857; }
        .pw-wrap { position: relative; }
        .pw-wrap input { padding-right: 42px; }
        .pw-eye { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: #64748B; padding: 4px; width: auto; margin: 0; display: flex; }
        .pw-eye svg { width: 18px; height: 18px; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Admin Login Fix</h1>
        <p class="sub">One-time rescue: sets a fresh, properly hashed password for an admin account. This page deletes itself after use.</p>

        <?php if ($msg): ?><div class="msg <?= $done ? 'done' : '' ?>"><?= $msg ?></div><?php endif; ?>

        <?php if ($done): ?>
            <a href="index.php" style="display:block;text-align:center;font-weight:600;color:var(--primary);">Go to login &rarr;</a>
        <?php else: ?>
        <table>
            <thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Phone</th><th>Password stored</th></tr></thead>
            <tbody>
                <?php foreach ($admins as $a): ?>
                <tr>
                    <td><?= (int) $a['id'] ?></td>
                    <td><?= htmlspecialchars($a['name']) ?></td>
                    <td><?= htmlspecialchars($a['email'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($a['phone'] ?? '—') ?></td>
                    <td class="<?= $a['hash_ok'] ? 'ok' : 'bad' ?>"><?= $a['hash_ok'] ? 'Valid hash' : 'BROKEN — reset needed' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <form method="POST" action="login_fix.php?key=<?= FIX_KEY ?>">
            <label>Admin account</label>
            <select name="user_id" required>
                <?php foreach ($admins as $a): ?>
                <option value="<?= (int) $a['id'] ?>"><?= htmlspecialchars($a['name']) ?> (#<?= (int) $a['id'] ?>)</option>
                <?php endforeach; ?>
            </select>
            <label>New password (min 8 chars)</label>
            <div class="pw-wrap">
                <input type="password" name="new_password" id="np" required minlength="8">
                <button type="button" class="pw-eye" onclick="tg('np', this)" aria-label="Show password"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>
            </div>
            <label>Confirm password</label>
            <div class="pw-wrap">
                <input type="password" name="confirm_password" id="cp" required minlength="8">
                <button type="button" class="pw-eye" onclick="tg('cp', this)" aria-label="Show password"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>
            </div>
            <button type="submit">Set password</button>
        </form>
        <script>
        function tg(id, btn) {
            var i = document.getElementById(id);
            i.type = i.type === 'password' ? 'text' : 'password';
            btn.style.color = i.type === 'text' ? '#1A7F7E' : '#64748B';
        }
        </script>
        <?php endif; ?>
    </div>
</body>
</html>
