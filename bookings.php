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
$qhBrand  = false; // the sidebar already carries the HIMS mark

$pageTitle = 'Bookings';
// Page-specific: this placeholder card centers its content and overrides the
// base .card padding. Everything else (tokens, reset, body, .content,
// .page-title, .page-sub, base .card shell) comes from app.css.
$headExtra = <<<CSS
<style>
.card { padding: 56px 32px; text-align: center; }
.card .mark {
    width: 54px; height: 54px; border-radius: 16px; margin: 0 auto 18px;
    background: var(--primary-light); color: var(--primary);
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
CSS;
require __DIR__ . '/partials/head.php';
$navActive = 'bookings';
require __DIR__ . '/partials/sidebar.php';
?>
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
    </div>
</div>

</body>
</html>
