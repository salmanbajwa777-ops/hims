<?php
require_once __DIR__ . '/config/auth.php';

/**
 * Where a user lands after login. Only DOCTOR and ADMIN are role-driven now;
 * STAFF is one role covering every desk/ward worker, so their home is chosen by
 * what they can actually DO (permissions), not by a sub-role that no longer
 * exists. Reception work-queue if they register patients; the ward list if they
 * do admissions; dashboard otherwise. Requires session permissions to be loaded
 * (they are — refresh_session_permissions runs right before this is called on
 * login, and auth.php has restored them for an already-logged-in visitor).
 */
function landing_page_for_role(string $baseRole): string {
    if ($baseRole === 'DOCTOR') { return '/doctor.php'; }
    if ($baseRole === 'ADMIN')  { return '/dashboard.php'; }
    // STAFF (and any legacy value) — permission-driven.
    if (function_exists('has_permission')) {
        if (has_permission('RECEPTION_REGISTER_PATIENTS')) { return '/receptionist.php'; }
        if (has_permission('NURSING_RECORD_ADMISSIONS'))   { return '/admissions.php'; }
    }
    return '/dashboard.php';
}

if (is_logged_in()) {
    // has_permission() reads $_SESSION['permissions'], already populated at login;
    // load the helper so landing_page_for_role() can call it for STAFF routing.
    require_once __DIR__ . '/config/permissions.php';
    header('Location: ' . landing_page_for_role($_SESSION['base_role'] ?? ''));
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/config/db.php';

    $identifier = trim($_POST['identifier'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($identifier === '' || $password === '') {
        $error = 'Please enter your email/phone and password.';
    } else {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? OR phone = ? LIMIT 1');
        $stmt->execute([$identifier, $identifier]);
        $user = $stmt->fetch();

        // Phone logins are format-forgiving: "03001234567", "+92 300 1234567"
        // and "0300-1234567" are the same number. If the exact match missed and
        // the identifier looks like a phone, compare normalized digits against
        // every stored phone (staff table is small). Also covers legacy rows
        // saved in +92/spaced formats before normalization existed.
        if (!$user) {
            $normId = normalize_staff_phone($identifier);
            if ($normId !== '' && strlen($normId) >= 7 && !str_contains($identifier, '@')) {
                foreach ($pdo->query("SELECT * FROM users WHERE phone IS NOT NULL AND phone != ''") as $candidate) {
                    if (normalize_staff_phone($candidate['phone']) === $normId) {
                        $user = $candidate;
                        break;
                    }
                }
            }
        }

        if ($user && password_verify($password, $user['password']) && (int) ($user['is_active'] ?? 1) === 0) {
            // Account exists and the password is correct, but an admin has
            // deactivated it. Don't reveal the credentials were valid.
            $error = 'This account has been deactivated. Please contact an administrator.';
        } elseif ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['base_role'] = $user['base_role'];
            $_SESSION['must_change_password'] = (bool) $user['must_change_password'];
            // Fresh shift login → the doctor-timings popup fires again on the
            // reception console (it sets this flag after showing once).
            unset($_SESSION['timings_popup_shown']);

            require_once __DIR__ . '/config/permissions.php';
            refresh_session_permissions($pdo);

            header('Location: ' . landing_page_for_role($user['base_role']));
            exit;
        } else {
            $error = 'Invalid credentials.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HIMS — Login</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-dark: #0E5456;
            --primary: #1A7F7E;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: 'Inter', system-ui, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--primary-dark), var(--primary));
        }
        .login-card {
            background: #fff;
            border-radius: 20px;
            padding: 40px;
            width: 100%;
            max-width: 380px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.2);
        }
        .login-card h1 {
            margin: 0 0 4px;
            font-size: 22px;
            color: #111827;
        }
        .login-card p.subtitle {
            margin: 0 0 24px;
            color: #0A0F1A;
            font-size: 14px;
        }
        label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #0A0F1A;
            margin-bottom: 6px;
        }
        input[type="text"], input[type="password"] {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #D1D5DB;
            border-radius: 12px;
            font-size: 14px;
            margin-bottom: 18px;
        }
        input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(26,127,126,.15);
        }
        button {
            width: 100%;
            padding: 11px;
            border: none;
            border-radius: 14px;
            background: linear-gradient(135deg, var(--primary-dark), var(--primary));
            color: #fff;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
        }
        button:hover { opacity: 0.92; }
        .error {
            background: #FEF2F2;
            color: #B91C1C;
            padding: 10px 12px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 18px;
        }
        .forgot {
            display: block;
            text-align: center;
            margin-top: 16px;
            font-size: 13px;
            color: #1A7F7E;
            text-decoration: none;
        }
        .pw-wrap { position: relative; margin-bottom: 18px; }
        .pw-wrap input { padding-right: 42px; margin-bottom: 0; }
        .pw-eye {
            position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
            background: none; border: none; cursor: pointer; color: #111827;
            padding: 4px; width: auto; margin: 0; display: flex;
        }
        .pw-eye:hover { opacity: 1; color: var(--primary); }
        .pw-eye svg { width: 18px; height: 18px; }
    </style>
</head>
<body>
    <div class="login-card">
        <h1>HIMS</h1>
        <p class="subtitle">Sign in to your account</p>

        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="index.php">
            <label for="identifier">Email or Phone</label>
            <input type="text" id="identifier" name="identifier" required autofocus>

            <label for="password">Password</label>
            <div class="pw-wrap">
                <input type="password" id="password" name="password" required>
                <button type="button" class="pw-eye" onclick="pwToggle('password', this)" aria-label="Show password" tabindex="-1"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>
            </div>

            <button type="submit">Sign In</button>
        </form>
        <script>
        function pwToggle(id, btn) {
            var i = document.getElementById(id);
            i.type = i.type === 'password' ? 'text' : 'password';
            btn.style.color = i.type === 'text' ? 'var(--primary)' : '#111827';
        }
        </script>

        <p class="forgot">Forgot your password? Ask an administrator to reset it.</p>
    </div>
<script src="assets/js/date-picker.js"></script>
</body>
</html>
