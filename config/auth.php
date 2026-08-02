<?php
// All dates/times in HMIS are Pakistan Standard Time (UTC+5).
date_default_timezone_set('Asia/Karachi');

if (session_status() === PHP_SESSION_NONE) {
    // Harden the session cookie BEFORE it is issued — these have no effect
    // once session_start() has run.
    //
    //   httponly  the cookie is the credential and no script needs to read it;
    //             this keeps an injected script from exfiltrating it.
    //   samesite  Lax stops the cookie riding along on a cross-site POST, which
    //             is a partial mitigation for the app-wide lack of CSRF tokens.
    //             Lax rather than Strict so an ordinary link into the app from
    //             an email still lands logged in.
    //   secure    only when the request actually arrived over HTTPS, so a
    //             plain-HTTP local or staging run does not silently fail to set
    //             a cookie at all and lock everyone out.
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $https,
    ]);

    session_start();
}

// "View as staff" helpers. Loaded here — the one file every logged-in page
// already requires — so is_impersonating() is always in scope for the banner in
// partials/head.php and for audit tagging, without editing 48 pages. This file
// only declares functions and reads $_SESSION; it opens no DB connection.
require_once __DIR__ . '/impersonation.php';

function require_login() {
    if (empty($_SESSION['user_id'])) {
        header('Location: /index.php');
        exit;
    }
}

function is_logged_in() {
    return !empty($_SESSION['user_id']);
}

// Canonical staff phone for storage: local Pakistani format, digits only,
// leading 0 (e.g. "03001234567"). Accepts anything a person might type —
// "+92 300 1234567", "92-300-1234567", "0300 1234567" — and folds the 92
// country code back to the leading 0. Non-PK numbers just get non-digits
// stripped. Empty in → empty out.
function normalize_staff_phone(string $raw): string {
    $digits = preg_replace('/\D+/', '', $raw);
    if ($digits === '') {
        return '';
    }
    if (str_starts_with($digits, '0092')) {
        $digits = substr($digits, 4);
    } elseif (str_starts_with($digits, '92') && strlen($digits) > 10) {
        $digits = substr($digits, 2);
    }
    if ($digits !== '' && $digits[0] !== '0') {
        $digits = '0' . $digits;
    }
    return $digits;
}

// Does any OTHER user already hold this phone number, in ANY stored format?
// $normalizedPhone must already be normalize_staff_phone()'d; $excludeId = 0
// when adding a new user. Compares normalized-to-normalized so a legacy
// "+92300…" row still blocks a new "0300…" claim (they're the same login).
function staff_phone_in_use(PDO $pdo, string $normalizedPhone, int $excludeId = 0): bool {
    if ($normalizedPhone === '') {
        return false;
    }
    $stmt = $pdo->prepare('SELECT phone FROM users WHERE id != ? AND phone IS NOT NULL AND phone != ""');
    $stmt->execute([$excludeId]);
    foreach ($stmt->fetchAll() as $row) {
        if (normalize_staff_phone((string) $row['phone']) === $normalizedPhone) {
            return true;
        }
    }
    return false;
}
