<?php
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

$landingPage = match ($_SESSION['base_role'] ?? '') {
    'RECEPTIONIST' => 'receptionist.php',
    'DOCTOR'       => 'doctor.php',
    default        => 'dashboard.php',
};

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!password_verify($current, $user['password'])) {
        $error = 'Current password is incorrect.';
    } elseif (strlen($new) < 8) {
        $error = 'New password must be at least 8 characters.';
    } elseif ($new !== $confirm) {
        $error = 'New password and confirmation do not match.';
    } else {
        $hash = password_hash($new, PASSWORD_BCRYPT);
        $update = $pdo->prepare('UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?');
        $update->execute([$hash, $user['id']]);
        $_SESSION['must_change_password'] = false;
        $success = 'Password updated successfully.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HIMS — Change Password</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --primary-dark: #0E5456; --primary: #1A7F7E; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: 'Inter', system-ui, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #F8FAFC;
        }
        .card {
            background: #fff;
            border-radius: 20px;
            padding: 40px;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 10px 25px rgba(15,23,42,.08);
            border: 1px solid #E2E8F0;
        }
        .card h1 { margin: 0 0 4px; font-size: 20px; color: #0F172A; }
        .card p.subtitle { margin: 0 0 24px; color: #334155; font-size: 13.5px; }
        label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px; }
        input[type="password"] {
            width: 100%; padding: 10px 12px; border: 1px solid #E2E8F0; border-radius: 12px;
            font-size: 14px; margin-bottom: 16px;
        }
        input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,127,126,.15); }
        button {
            width: 100%; padding: 11px; border: none; border-radius: 14px;
            background: linear-gradient(135deg, var(--primary-dark), var(--primary));
            color: #fff; font-size: 14px; font-weight: 600; cursor: pointer;
        }
        button:hover { opacity: .92; }
        .error { background: #FEF2F2; color: #B91C1C; padding: 10px 12px; border-radius: 10px; font-size: 13px; margin-bottom: 16px; }
        .success { background: #ECFDF5; color: #047857; padding: 10px 12px; border-radius: 10px; font-size: 13px; margin-bottom: 16px; }
        .back-link { display: block; text-align: center; margin-top: 16px; font-size: 13px; color: #1A7F7E; }
        .pw-wrap { position: relative; margin-bottom: 16px; }
        .pw-wrap input { padding-right: 42px; margin-bottom: 0; }
        .pw-eye { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: #64748B; padding: 4px; width: auto; margin: 0; display: flex; }
        .pw-eye:hover { color: var(--primary); }
        .pw-eye svg { width: 18px; height: 18px; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Change Password</h1>
        <p class="subtitle">Update your account password</p>

        <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="success"><?= htmlspecialchars($success) ?> <a href="<?= $landingPage ?>">Go to dashboard &rarr;</a></div><?php endif; ?>

        <?php if (!$success): ?>
        <form method="POST" action="change-password.php">
            <label for="current_password">Current Password</label>
            <div class="pw-wrap">
                <input type="password" id="current_password" name="current_password" required>
                <button type="button" class="pw-eye" onclick="pwToggle('current_password', this)" aria-label="Show password" tabindex="-1"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>
            </div>

            <label for="new_password">New Password</label>
            <div class="pw-wrap">
                <input type="password" id="new_password" name="new_password" required minlength="8">
                <button type="button" class="pw-eye" onclick="pwToggle('new_password', this)" aria-label="Show password" tabindex="-1"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>
            </div>

            <label for="confirm_password">Confirm New Password</label>
            <div class="pw-wrap">
                <input type="password" id="confirm_password" name="confirm_password" required minlength="8">
                <button type="button" class="pw-eye" onclick="pwToggle('confirm_password', this)" aria-label="Show password" tabindex="-1"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>
            </div>

            <button type="submit">Update Password</button>
        </form>
        <script>
        function pwToggle(id, btn) {
            var i = document.getElementById(id);
            i.type = i.type === 'password' ? 'text' : 'password';
            btn.style.color = i.type === 'text' ? 'var(--primary)' : '#64748B';
        }
        </script>
        <a class="back-link" href="<?= $landingPage ?>">&larr; Back to dashboard</a>
        <?php endif; ?>
    </div>
<script src="assets/js/date-picker.js"></script>
</body>
</html>
