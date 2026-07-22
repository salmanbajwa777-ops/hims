<?php
require_once __DIR__ . '/config/auth.php';

function landing_page_for_role(string $baseRole): string {
    switch ($baseRole) {
        case 'RECEPTIONIST': return '/receptionist.php';
        case 'DOCTOR':       return '/doctor.php';
        case 'NURSE':        return '/admissions.php'; // the ward list is the nurse's home
        default:             return '/dashboard.php';
    }
}

if (is_logged_in()) {
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

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['base_role'] = $user['base_role'];
            $_SESSION['must_change_password'] = (bool) $user['must_change_password'];

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
            color: #334155;
            font-size: 14px;
        }
        label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #374151;
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
            <input type="password" id="password" name="password" required>

            <button type="submit">Sign In</button>
        </form>

        <p class="forgot">Forgot your password? Ask an administrator to reset it.</p>
    </div>
<script src="assets/js/date-picker.js"></script>
</body>
</html>
