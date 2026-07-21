<?php
/**
 * Bookings — placeholder.
 *
 * Scheduled appointments have no table, no capture form and no queue handoff
 * yet. The header advertises the destination so the layout is settled; this
 * page says plainly that the feature is not built rather than showing an empty
 * table that reads like a bug.
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
refresh_session_permissions($pdo);

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header('Location: /index.php');
    exit;
}

if (!has_permission('RECEPTION_REGISTER_PATIENTS') && ($_SESSION['base_role'] ?? '') !== 'ADMIN') {
    http_response_code(403);
    exit('Forbidden — reception access only.');
}

$firstName = explode(' ', trim($user['name']))[0] ?? 'there';
$qhActive = 'bookings';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>HIMS — Bookings</title>
<style>
:root {
    --primary-dark: #0E5456; --primary: #1A7F7E; --primary-light: #E0F2F1;
    --green: #10B981; --amber: #F59E0B; --red: #DC2626;
    --bg: #F8FAFC; --card: #FFFFFF;
    --text: #0F172A; --text-secondary: #334155; --text-muted: #64748B;
    --border: #E2E8F0;
    --shadow-sm: 0 2px 8px rgba(15,23,42,.05);
    --radius-card: 20px; --radius-input: 12px; --radius-btn: 14px;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Inter', system-ui, -apple-system, "Segoe UI", sans-serif;
    background: var(--bg); color: var(--text); font-size: 14px; line-height: 1.5;
}
a { text-decoration: none; color: inherit; }

.content { padding: 28px 32px 60px; display: flex; flex-direction: column; gap: 20px; }
.page-title { font-size: 22px; font-weight: 700; letter-spacing: -.02em; }
.page-sub { font-size: 13.5px; color: var(--text-muted); }

.card {
    background: var(--card); border: 1px solid var(--border);
    border-radius: var(--radius-card); box-shadow: var(--shadow-sm);
    padding: 56px 32px; text-align: center;
}
.card .mark {
    width: 54px; height: 54px; border-radius: 16px; margin: 0 auto 18px;
    background: rgba(37,99,235,.10); color: #1D4ED8;
    display: flex; align-items: center; justify-content: center;
}
.card .mark svg { width: 26px; height: 26px; }
.card h2 { font-size: 17px; font-weight: 650; margin-bottom: 8px; }
.card p { color: var(--text-muted); max-width: 52ch; margin: 0 auto 6px; font-size: 13.5px; }
.card .cta {
    display: inline-flex; align-items: center; gap: 8px; margin-top: 20px;
    height: 40px; padding: 0 18px; border-radius: var(--radius-btn);
    background: linear-gradient(135deg, var(--primary-dark), var(--primary));
    color: #fff; font-weight: 600; font-size: 13.5px;
}
</style>
</head>
<body>

<?php require __DIR__ . '/partials/quick_header.php'; ?>

<div class="content">
    <div>
        <div class="page-title">Bookings</div>
        <div class="page-sub">Scheduled appointments</div>
    </div>

    <div class="card">
        <div class="mark">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18"/>
            </svg>
        </div>
        <h2>Bookings aren't built yet</h2>
        <p>Patients are registered as walk-ins today &mdash; every visit joins the queue the moment it is created.</p>
        <p>Scheduling ahead needs an appointment record, a slot calendar per doctor, and a handoff into the day's queue on arrival.</p>
        <a class="cta" href="receptionist.php">Go to today's queue</a>
    </div>
</div>

</body>
</html>
