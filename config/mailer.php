<?php
// Email notifications. Self-contained SMTP client for Hostinger (no Composer /
// PHPMailer — same zero-dependency style as the rest of HMIS).
//
// Design rules:
//   * Email is ALWAYS best-effort. send_mail() never throws and never blocks a
//     page action: a failed send is logged to email_log and the caller moves on.
//     A refund/registration must never fail because SMTP hiccupped.
//   * Callers fire emails AFTER $pdo->commit(), never inside the transaction.
//   * config/mail.php (gitignored, like db.php) holds the real password; if it
//     is missing or 'enabled' is false, every send quietly no-ops.
//
// Requires sql/add_email_log.sql to have been run.

function mail_config(): ?array {
    static $cfg = false;
    if ($cfg === false) {
        $file = __DIR__ . '/mail.php';
        $cfg = is_file($file) ? require $file : null;
        if (is_array($cfg) && (empty($cfg['enabled']) || $cfg['password'] === 'CHANGE_ME')) {
            $cfg = null;
        }
    }
    return $cfg;
}

/**
 * Send one email over SMTP. Returns true on acceptance by the server.
 * $to may be a single address or an array of addresses (each gets its own
 * RCPT TO but one message — simple CC-style fan-out).
 */
function send_mail(PDO $pdo, $to, string $subject, string $htmlBody, string $context = ''): bool {
    $cfg = mail_config();
    $toList = array_values(array_filter(array_map('trim', (array) $to), function ($a) {
        return filter_var($a, FILTER_VALIDATE_EMAIL) !== false;
    }));

    if (!$toList) {
        return false; // nobody to send to (e.g. doctor has no email on file) — not an error
    }
    if (!$cfg) {
        log_email($pdo, $toList, $subject, $context, 'skipped', 'mail.php missing or disabled');
        return false;
    }

    $error = '';
    $ok = smtp_deliver($cfg, $toList, $subject, $htmlBody, $error);
    log_email($pdo, $toList, $subject, $context, $ok ? 'sent' : 'failed', $error);
    return $ok;
}

function log_email(PDO $pdo, array $to, string $subject, string $context, string $status, string $error): void {
    try {
        $pdo->prepare('INSERT INTO email_log (recipients, subject, context, status, error) VALUES (?, ?, ?, ?, ?)')
            ->execute([implode(', ', $to), $subject, $context, $status, $error !== '' ? $error : null]);
    } catch (Throwable $e) {
        // email_log table missing — never let logging break the page
    }
}

// ---------------------------------------------------------------------------
// Raw SMTP (implicit SSL, AUTH LOGIN) — the exact dialect Hostinger speaks on 465.
// ---------------------------------------------------------------------------
function smtp_deliver(array $cfg, array $toList, string $subject, string $htmlBody, string &$error): bool {
    $error = '';
    $fp = @stream_socket_client(
        'ssl://' . $cfg['host'] . ':' . $cfg['port'],
        $errno, $errstr, 15,
        STREAM_CLIENT_CONNECT,
        stream_context_create(['ssl' => ['SNI_enabled' => true]])
    );
    if (!$fp) {
        $error = "connect: $errno $errstr";
        return false;
    }
    stream_set_timeout($fp, 15);

    $read = function () use ($fp): string {
        $data = '';
        while (($line = fgets($fp, 512)) !== false) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') { break; } // last line of a multi-line reply
        }
        return $data;
    };
    $cmd = function (string $c, array $expect) use ($fp, $read, &$error): bool {
        fwrite($fp, $c . "\r\n");
        $resp = $read();
        $code = (int) substr($resp, 0, 3);
        if (!in_array($code, $expect, true)) {
            $error = trim(strtok($c, "\r\n")) . ' -> ' . trim($resp);
            return false;
        }
        return true;
    };

    try {
        if ((int) substr($read(), 0, 3) !== 220) { $error = 'no 220 banner'; return false; }
        if (!$cmd('EHLO hims.babymedics.com', [250])) { return false; }
        if (!$cmd('AUTH LOGIN', [334])) { return false; }
        if (!$cmd(base64_encode($cfg['username']), [334])) { $error = 'auth user rejected'; return false; }
        if (!$cmd(base64_encode($cfg['password']), [235])) { $error = 'auth password rejected'; return false; }
        if (!$cmd('MAIL FROM:<' . $cfg['from_email'] . '>', [250])) { return false; }
        foreach ($toList as $addr) {
            if (!$cmd('RCPT TO:<' . $addr . '>', [250, 251])) { return false; }
        }
        if (!$cmd('DATA', [354])) { return false; }

        $headers = [
            'From: ' . mail_header_encode($cfg['from_name']) . ' <' . $cfg['from_email'] . '>',
            'To: ' . implode(', ', $toList),
            'Subject: ' . mail_header_encode($subject),
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@babymedics.com>',
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        // Body as base64 sidesteps dot-stuffing and line-length rules entirely.
        $data = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($htmlBody), 76, "\r\n");
        fwrite($fp, $data . ".\r\n");
        $resp = $read();
        if ((int) substr($resp, 0, 3) !== 250) { $error = 'DATA -> ' . trim($resp); return false; }
        $cmd('QUIT', [221]);
        return true;
    } finally {
        fclose($fp);
    }
}

// RFC 2047 encoding so names/subjects survive non-ASCII (e.g. "Rs" symbols, Urdu names).
function mail_header_encode(string $s): string {
    return preg_match('/[\x80-\xFF]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
}

// ---------------------------------------------------------------------------
// Shared HTML wrapper — teal-branded, table-based (email clients ignore modern CSS).
// ---------------------------------------------------------------------------
function mail_template(string $title, string $bodyHtml, string $footerNote = ''): string {
    $base = (mail_config() ?? [])['base_url'] ?? 'https://hims.babymedics.com';
    $foot = $footerNote !== '' ? $footerNote : 'This is an automated notification from the Babymedics HMIS.';
    return '<!doctype html><html><body style="margin:0;padding:0;background:#f1f5f4;font-family:Arial,Helvetica,sans-serif;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px;">'
        . '<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#ffffff;border-radius:10px;overflow:hidden;border:1px solid #dde5e4;">'
        . '<tr><td style="background:#0E5456;padding:18px 24px;">'
        . '<span style="color:#ffffff;font-size:17px;font-weight:bold;letter-spacing:.3px;">Babymedics HMIS</span></td></tr>'
        . '<tr><td style="padding:24px;">'
        . '<h2 style="margin:0 0 14px;font-size:18px;color:#0E5456;">' . htmlspecialchars($title) . '</h2>'
        . $bodyHtml
        . '</td></tr>'
        . '<tr><td style="padding:14px 24px;background:#f7fafa;border-top:1px solid #e5ecec;">'
        . '<p style="margin:0;font-size:12px;color:#6b7c7b;">' . $foot . '<br>'
        . '<a href="' . htmlspecialchars($base) . '" style="color:#1A7F7E;">' . htmlspecialchars($base) . '</a></p>'
        . '</td></tr></table></td></tr></table></body></html>';
}

// A key/value detail table used inside most notifications.
function mail_kv(array $rows): string {
    $html = '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin:0 0 14px;">';
    foreach ($rows as $k => $v) {
        $html .= '<tr>'
            . '<td style="padding:7px 10px;border:1px solid #e5ecec;background:#f7fafa;font-size:13px;color:#41504f;width:40%;">' . htmlspecialchars($k) . '</td>'
            . '<td style="padding:7px 10px;border:1px solid #e5ecec;font-size:13px;color:#17211f;font-weight:bold;">' . htmlspecialchars((string) $v) . '</td>'
            . '</tr>';
    }
    return $html . '</table>';
}

// ---------------------------------------------------------------------------
// Recipient helpers
// ---------------------------------------------------------------------------
function user_email(PDO $pdo, ?int $userId): ?string {
    if (!$userId) { return null; }
    $stmt = $pdo->prepare('SELECT email FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $email = $stmt->fetchColumn();
    return ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) ? $email : null;
}

function admin_alert_email(): ?string {
    return (mail_config() ?? [])['admin_email'] ?? null;
}
