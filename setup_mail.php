<?php
/**
 * ONE-TIME mail setup page. Writes config/mail.php on the server (the gitignored
 * secrets file), sends a test email, then deletes itself on success.
 *
 * Exists so the mailbox password never has to travel through the public git
 * repo — it goes browser -> HTTPS POST -> server file, and this page is gone
 * after the first successful run. Admin login required.
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';

if (($_SESSION['base_role'] ?? '') !== 'ADMIN') {
    http_response_code(403);
    exit('Forbidden — admin only.');
}

$target = __DIR__ . '/config/mail.php';
$msg = '';
$ok = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $password = $_POST['mail_password'] ?? '';
    if ($password === '' || $password === 'CHANGE_ME') {
        $msg = 'Enter the real mailbox password.';
    } else {
        $cfg = "<?php\n"
            . "// Mail settings — created by setup_mail.php. Gitignored (like db.php).\n"
            . "return [\n"
            . "    'enabled'    => true,\n"
            . "    'host'       => 'smtp.hostinger.com',\n"
            . "    'port'       => 465,\n"
            . "    'username'   => 'info@babymedics.com',\n"
            . "    'password'   => " . var_export($password, true) . ",\n"
            . "    'from_email' => 'info@babymedics.com',\n"
            . "    'from_name'  => 'Babymedics HMIS',\n"
            . "    'admin_email'=> 'info@babymedics.com',\n"
            . "    'base_url'   => 'https://hims.babymedics.com',\n"
            . "];\n";

        if (file_put_contents($target, $cfg) === false) {
            $msg = 'Could not write config/mail.php — check folder permissions.';
        } else {
            // mail_config() caches per-request; this is a fresh request so it
            // will pick the new file up. Send a test email now.
            require_once __DIR__ . '/config/mailer.php';
            $body = '<p style="font-size:14px;color:#41504f;">Email notifications are configured and working. '
                . 'This test was sent by the one-time setup page, which has now removed itself.</p>';
            $ok = send_mail($pdo, 'info@babymedics.com', 'HMIS email setup — test OK',
                mail_template('Email Setup Complete', $body), 'setup-test');

            if ($ok) {
                @unlink(__FILE__);   // job done — remove the setup page
                $msg = 'Saved and test email sent to info@babymedics.com. This setup page has deleted itself.';
            } else {
                $err = '';
                try {
                    $last = $pdo->query("SELECT error FROM email_log ORDER BY id DESC LIMIT 1")->fetch();
                    $err = $last['error'] ?? '';
                } catch (Throwable $e) {}
                $msg = 'Config saved, but the test send FAILED: ' . htmlspecialchars($err ?: 'unknown error')
                    . '. If it says "auth password rejected", the password is wrong — fix it and submit again.';
            }
        }
    }
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Email Setup — HMIS</title>
<style>
body { font-family: Inter, Arial, sans-serif; background: #f1f5f4; display: flex; justify-content: center; padding: 60px 16px; }
.card { background: #fff; border: 1px solid #dde5e4; border-radius: 12px; padding: 28px; max-width: 460px; width: 100%; }
h1 { font-size: 19px; color: #0E5456; margin: 0 0 6px; }
p { font-size: 14px; color: #41504f; line-height: 1.5; }
label { display: block; font-size: 13px; font-weight: 600; color: #17211f; margin: 18px 0 6px; }
input[type=password], input[type=text] { width: 100%; box-sizing: border-box; padding: 10px 12px; border: 1px solid #c9d6d5; border-radius: 8px; font-size: 14px; }
button { margin-top: 18px; background: #0E5456; color: #fff; border: 0; border-radius: 8px; padding: 11px 24px; font-size: 14px; font-weight: 600; cursor: pointer; }
.msg { margin-top: 16px; padding: 12px 14px; border-radius: 8px; font-size: 13px; }
.ok { background: #e8f5f0; color: #0E5456; border: 1px solid #b6ded2; }
.bad { background: #fdecea; color: #b3261e; border: 1px solid #f5c6c0; }
</style>
</head>
<body>
<div class="card">
    <h1>Email Setup</h1>
    <p>Enter the mailbox password of <strong>info@babymedics.com</strong>. It is written to
       <code>config/mail.php</code> on this server only (never into git), a test email is sent,
       and this page removes itself.</p>
    <?php if ($msg): ?><div class="msg <?= $ok ? 'ok' : 'bad' ?>"><?= $msg ?></div><?php endif; ?>
    <?php if (!$ok): ?>
    <form method="POST">
        <input type="hidden" name="action" value="save">
        <label for="mail_password">Mailbox password</label>
        <input type="password" id="mail_password" name="mail_password" autocomplete="off" autofocus>
        <button type="submit">Save &amp; send test email</button>
    </form>
    <?php endif; ?>
</div>
</body>
</html>
