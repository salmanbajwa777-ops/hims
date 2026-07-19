<?php
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

// This page is scoped to reception work regardless of base_role, gated by the
// granular permission — mirrors how permissions.php/staff.php already gate access.
if (!has_permission('RECEPTION_REGISTER_PATIENTS')) {
    http_response_code(403);
    exit('Forbidden — reception access only.');
}

$mustChangePassword = (bool) $user['must_change_password'];
$firstName = explode(' ', trim($user['name']))[0] ?? 'there';
$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good Morning' : ($hour < 17 ? 'Good Afternoon' : 'Good Evening');

function icon(string $name, int $size = 18): string {
    $paths = [
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'users' => '<path d="M17 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'stethoscope' => '<path d="M4.8 2.3A.3.3 0 1 0 5 2H4a2 2 0 0 0-2 2v5a6 6 0 0 0 6 6v0a6 6 0 0 0 6-6V4a2 2 0 0 0-2-2h-1a.2.2 0 1 0 .3.3"/><path d="M8 15v1a6 6 0 0 0 6 6v0a6 6 0 0 0 6-6v-4"/><circle cx="20" cy="10" r="2"/>',
        'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'bed' => '<path d="M2 4v16M2 8h18a2 2 0 0 1 2 2v10M2 17h20"/><path d="M6 8V6a2 2 0 0 1 2-2h3"/>',
        'card' => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
        'wallet' => '<path d="M20 12V8a2 2 0 0 0-2-2H5a2 2 0 0 1 0-4h13a2 2 0 0 1 2 2v3"/><path d="M3 5v14a2 2 0 0 0 2 2h15a2 2 0 0 0 2-2v-4"/><circle cx="17" cy="14" r="1.5"/>',
        'file-text' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/>',
        'search' => '<circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>',
        'bell' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'dollar-sign' => '<path d="M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
        'user-plus' => '<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><path d="M20 8v6M23 11h-6"/>',
    ];
    $p = $paths[$name] ?? '';
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $p . '</svg>';
}

// Placeholder data — Patients (Phase 2), Billing (Phase 4) and Short-stay (Phase 5)
// tables don't exist yet per HMIS-PHP-PLAN.md. Replace with real queries once those
// phases land; until then this page is a UI shell only.
$stats = [
    ['label' => 'Cash Tally Today', 'value' => 'Rs 0', 'icon' => 'dollar-sign'],
    ['label' => 'New Admissions', 'value' => '0', 'icon' => 'bed'],
    ['label' => 'OPD Patients Today', 'value' => '0', 'icon' => 'users'],
    ['label' => 'Discharges Today', 'value' => '0', 'icon' => 'file-text'],
];
$opdQueue = [];
$doctorSchedule = [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>HIMS — Reception Desk</title>
<style>
:root {
    --primary-dark: #0E5456;
    --primary: #1A7F7E;
    --primary-light: #E0F2F1;
    --green: #10B981;
    --amber: #F59E0B;
    --red: #DC2626;
    --bg: #F8FAFC;
    --card: #FFFFFF;
    --text: #0F172A;
    --text-secondary: #64748B;
    --text-muted: #94A3B8;
    --border: #E2E8F0;
    --shadow-sm: 0 2px 8px rgba(15,23,42,.05);
    --shadow-md: 0 10px 25px rgba(15,23,42,.08);
    --shadow-lg: 0 18px 40px rgba(15,23,42,.12);
    --radius-card: 20px;
    --radius-input: 12px;
    --radius-btn: 14px;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Inter', system-ui, -apple-system, "Segoe UI", sans-serif;
    background: var(--bg);
    color: var(--text);
    font-size: 14px;
    line-height: 1.5;
}
a { text-decoration: none; color: inherit; }

.app { display: grid; grid-template-columns: 280px 1fr; min-height: 100vh; }
.main { display: flex; flex-direction: column; min-width: 0; }
.content { padding: 28px 32px 60px; display: flex; flex-direction: column; gap: 24px; }

/* ---------- Sidebar ---------- */
.sidebar {
    background: var(--card);
    border-right: 1px solid var(--border);
    padding: 24px 16px;
    position: sticky;
    top: 0;
    height: 100vh;
    overflow-y: auto;
}
.sidebar-brand {
    display: flex; align-items: center; gap: 10px;
    padding: 0 8px 24px; font-weight: 700; font-size: 18px;
}
.sidebar-brand .logo-mark {
    width: 34px; height: 34px; border-radius: 10px;
    background: linear-gradient(135deg, var(--primary-dark), var(--primary));
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-weight: 700; font-size: 14px;
}
.nav-group { margin-bottom: 18px; }
.nav-group-label {
    font-size: 11px; font-weight: 600; letter-spacing: .06em;
    color: var(--text-muted); padding: 0 12px 8px; text-transform: uppercase;
}
.nav-item {
    display: flex; align-items: center; gap: 10px;
    padding: 9px 12px; border-radius: 12px;
    color: var(--text-secondary); font-weight: 500; font-size: 13.5px;
    transition: background .15s ease;
}
.nav-item:hover { background: #F8FAFC; }
.nav-item.active {
    background: var(--primary-light); color: var(--primary-dark);
    font-weight: 600; position: relative;
}
.nav-item.active::before {
    content: ""; position: absolute; left: -16px; top: 8px; bottom: 8px;
    width: 3px; background: var(--primary); border-radius: 0 3px 3px 0;
}
.nav-item.disabled { opacity: .45; cursor: not-allowed; }
.nav-icon {
    width: 28px; height: 28px; border-radius: 8px; background: #F1F5F9;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; color: var(--text-secondary);
}
.nav-icon svg { width: 15px; height: 15px; }
.nav-item.active .nav-icon { background: #fff; color: var(--primary-dark); }

/* ---------- Header ---------- */
.header {
    height: 72px; position: sticky; top: 0; z-index: 20;
    display: flex; align-items: center; justify-content: space-between;
    padding: 0 32px; background: rgba(255,255,255,.80); backdrop-filter: blur(18px);
    border-bottom: 1px solid var(--border);
}
.header-title { font-size: 16px; font-weight: 700; }
.search-box { flex: 1; max-width: 420px; margin: 0 32px; position: relative; }
.search-box input {
    width: 100%; padding: 9px 14px 9px 38px; border-radius: var(--radius-input);
    border: 1px solid var(--border); background: #F8FAFC; font-size: 13.5px; color: var(--text);
}
.search-box input:focus { outline: none; border-color: var(--primary); background: #fff; }
.search-box .icon { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: var(--text-muted); display: flex; }
.search-box .icon svg { width: 15px; height: 15px; }
.header-right { display: flex; align-items: center; gap: 18px; }
.icon-btn {
    width: 38px; height: 38px; border-radius: 12px; border: 1px solid var(--border);
    background: #fff; display: flex; align-items: center; justify-content: center;
    color: var(--text-secondary); position: relative; cursor: pointer;
}
.icon-btn svg { width: 17px; height: 17px; }
.icon-btn .dot {
    position: absolute; top: 6px; right: 6px; width: 7px; height: 7px; border-radius: 50%;
    background: var(--red); border: 1.5px solid #fff;
}
.header-date { font-size: 13px; color: var(--text-secondary); white-space: nowrap; }
.avatar {
    width: 38px; height: 38px; border-radius: 50%;
    background: linear-gradient(135deg, var(--primary-dark), var(--primary));
    color: #fff; display: flex; align-items: center; justify-content: center;
    font-weight: 600; font-size: 13px;
}
.logout-link { font-size: 13px; color: var(--text-secondary); font-weight: 500; }

/* ---------- Hero ---------- */
.hero {
    background: linear-gradient(135deg, var(--primary-dark), var(--primary));
    border-radius: var(--radius-card); padding: 32px 36px; min-height: 160px;
    display: flex; align-items: center; justify-content: space-between;
    flex-wrap: wrap; gap: 24px; color: #fff; position: relative; overflow: hidden;
}
.hero::before, .hero::after { content: ""; position: absolute; border-radius: 50%; background: rgba(255,255,255,.08); }
.hero::before { width: 220px; height: 220px; top: -80px; right: 120px; }
.hero::after { width: 140px; height: 140px; bottom: -60px; right: -20px; }
.hero-greeting .eyebrow { font-size: 14px; opacity: .85; font-weight: 500; }
.hero-greeting h1 { font-size: 30px; font-weight: 700; margin: 4px 0 8px; }
.hero-greeting .date { font-size: 13.5px; opacity: .85; }

/* ---------- Quick Actions ---------- */
.quick-actions { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }
.action-tile {
    background: var(--card); border: 1px solid var(--border); border-radius: 16px;
    min-height: 100px; display: flex; flex-direction: column; align-items: center;
    justify-content: center; gap: 8px; cursor: pointer;
    transition: transform .2s ease, box-shadow .2s ease, background .2s ease;
    text-align: center; padding: 10px;
}
.action-tile:hover { transform: scale(1.03); box-shadow: var(--shadow-md); background: linear-gradient(160deg, #fff, var(--primary-light)); }
.action-tile .icon { color: var(--primary-dark); }
.action-tile .icon svg { width: 24px; height: 24px; }
.action-tile .label { font-size: 12.5px; font-weight: 600; color: var(--text); }

/* ---------- Stat cards ---------- */
.grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 18px; }
.kpi-card {
    background: var(--card); border-radius: var(--radius-card); padding: 20px 22px;
    box-shadow: var(--shadow-sm); border: 1px solid var(--border);
    transition: transform .25s ease, box-shadow .25s ease;
}
.kpi-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-md); }
.kpi-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
.kpi-icon {
    width: 42px; height: 42px; border-radius: 12px; display: flex; align-items: center; justify-content: center;
    background: var(--primary-light); color: var(--primary-dark);
}
.kpi-icon svg { width: 20px; height: 20px; }
.kpi-value { font-size: 28px; font-weight: 700; margin-bottom: 2px; }
.kpi-label { font-size: 13px; color: var(--text-secondary); }

/* ---------- Section shell ---------- */
.section-title { font-size: 18px; font-weight: 600; margin-bottom: 2px; }
.section-sub { font-size: 12.5px; color: var(--text-muted); margin-bottom: 16px; }
.card { background: var(--card); border-radius: var(--radius-card); border: 1px solid var(--border); box-shadow: var(--shadow-sm); padding: 22px 24px; }
.row-2 { display: grid; grid-template-columns: 1.3fr 1fr; gap: 20px; align-items: start; }

/* ---------- Queue / list ---------- */
table { width: 100%; border-collapse: collapse; }
th { text-align: left; font-size: 11.5px; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted); padding: 0 10px 10px; font-weight: 600; }
td { padding: 12px 10px; border-top: 1px solid var(--border); font-size: 13.5px; }
.status-pill { font-size: 11.5px; font-weight: 600; padding: 3px 9px; border-radius: 20px; white-space: nowrap; }
.status-pill.waiting { background: #FFFBEB; color: #92400E; }
.status-pill.in-consult { background: #ECFDF5; color: #047857; }
.status-pill.done { background: #F1F5F9; color: var(--text-secondary); }
.empty-state { padding: 32px 10px; text-align: center; color: var(--text-muted); font-size: 13px; }

/* ---------- Doctor schedule ---------- */
.sched-list { display: flex; flex-direction: column; gap: 4px; }
.sched-item { display: flex; align-items: center; gap: 12px; padding: 12px 4px; border-bottom: 1px solid var(--border); }
.sched-item:last-child { border-bottom: none; }
.doc-avatar { width: 34px; height: 34px; border-radius: 50%; background: var(--primary-light); color: var(--primary-dark); display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; flex-shrink: 0; }
.sched-text { flex: 1; min-width: 0; }
.sched-name { font-size: 13.5px; font-weight: 600; }
.sched-time { font-size: 12px; color: var(--text-muted); }

/* ---------- Password nag ---------- */
.nag-banner {
    background: #FFFBEB; border: 1px solid #FDE68A; border-radius: 14px;
    padding: 14px 18px; display: flex; align-items: center; justify-content: space-between; gap: 12px;
    font-size: 13.5px; color: #92400E;
}
.nag-banner a { font-weight: 700; text-decoration: underline; }

/* ---------- Build notice ---------- */
.build-notice {
    background: #F1F5F9; border: 1px dashed var(--border); border-radius: 14px;
    padding: 12px 18px; font-size: 12.5px; color: var(--text-secondary);
}

@media (max-width: 1200px) {
    .grid-4, .quick-actions { grid-template-columns: repeat(2, 1fr); }
    .row-2 { grid-template-columns: 1fr; }
}
@media (max-width: 900px) {
    .app { grid-template-columns: 1fr; }
    .sidebar { display: none; }
}
</style>
</head>
<body>
<div class="app">

    <aside class="sidebar">
        <div class="sidebar-brand">
            <div class="logo-mark">H</div>
            HIMS
        </div>

        <div class="nav-group">
            <div class="nav-group-label">Overview</div>
            <a class="nav-item active" href="receptionist.php"><span class="nav-icon"><?= icon('grid') ?></span> Dashboard</a>
        </div>

        <div class="nav-group">
            <div class="nav-group-label">Reception</div>
            <a class="nav-item" href="patients.php"><span class="nav-icon"><?= icon('users') ?></span> Patients</a>
            <a class="nav-item disabled" href="#"><span class="nav-icon"><?= icon('calendar') ?></span> Appointments / OPD</a>
            <a class="nav-item disabled" href="#"><span class="nav-icon"><?= icon('bed') ?></span> Admissions</a>
            <a class="nav-item disabled" href="#"><span class="nav-icon"><?= icon('card') ?></span> Billing &amp; Cash</a>
            <a class="nav-item disabled" href="#"><span class="nav-icon"><?= icon('file-text') ?></span> Discharge</a>
        </div>

        <div class="nav-group">
            <div class="nav-group-label">Analytics</div>
            <a class="nav-item disabled" href="#"><span class="nav-icon"><?= icon('wallet') ?></span> Reports</a>
        </div>
    </aside>

    <div class="main">
        <header class="header">
            <div class="header-title">Reception Desk</div>

            <div class="search-box">
                <span class="icon"><?= icon('search') ?></span>
                <input type="text" placeholder="Search patients by name, phone, father's name..." disabled>
            </div>

            <div class="header-right">
                <button class="icon-btn"><?= icon('bell', 17) ?><span class="dot"></span></button>
                <span class="header-date"><?= date('D, d M Y') ?></span>
                <div class="avatar"><?= strtoupper(substr($firstName, 0, 1)) ?></div>
                <a class="logout-link" href="logout.php">Logout</a>
            </div>
        </header>

        <div class="content">

            <?php if ($mustChangePassword): ?>
            <div class="nag-banner">
                <span>You're signed in with a temporary password. Please set a new one to secure your account.</span>
                <a href="change-password.php">Change password now &rarr;</a>
            </div>
            <?php endif; ?>

            <div class="build-notice">
                Patient registration, OPD/billing and admissions aren't built yet (Phases 2, 4 &amp; 5 of
                the HMIS build plan) — the stats, queue and nav items below are placeholders until that
                data exists.
            </div>

            <!-- Hero -->
            <section class="hero">
                <div class="hero-greeting">
                    <div class="eyebrow"><?= $greeting ?></div>
                    <h1><?= htmlspecialchars($user['name']) ?></h1>
                    <div class="date"><?= date('l') ?>, <?= date('d F Y') ?></div>
                </div>
            </section>

            <!-- Quick Actions -->
            <div>
                <div class="section-title">Quick Actions</div>
                <div class="section-sub">Jump straight into the most common reception tasks</div>
                <div class="quick-actions">
                    <a class="action-tile" href="patients.php"><span class="icon"><?= icon('users', 24) ?></span><span class="label">Patients</span></a>
                    <a class="action-tile" href="patients.php"><span class="icon"><?= icon('user-plus', 24) ?></span><span class="label">+ Add Patient</span></a>
                    <div class="action-tile"><span class="icon"><?= icon('dollar-sign', 24) ?></span><span class="label">Post Expense</span></div>
                </div>
            </div>

            <!-- Stat cards -->
            <div class="grid-4">
                <?php foreach ($stats as $s): ?>
                <div class="kpi-card">
                    <div class="kpi-top">
                        <div class="kpi-icon"><?= icon($s['icon'], 20) ?></div>
                    </div>
                    <div class="kpi-value"><?= htmlspecialchars($s['value']) ?></div>
                    <div class="kpi-label"><?= htmlspecialchars($s['label']) ?></div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="row-2">
                <!-- OPD Queue -->
                <div class="card">
                    <div class="section-title">OPD Queue</div>
                    <div class="section-sub">Today's patients, in check-in order</div>
                    <?php if (empty($opdQueue)): ?>
                        <div class="empty-state">No patients in queue yet.</div>
                    <?php else: ?>
                    <table>
                        <thead><tr><th>Token</th><th>Patient</th><th>Doctor</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($opdQueue as $q): ?>
                            <tr>
                                <td><?= htmlspecialchars($q['token']) ?></td>
                                <td><?= htmlspecialchars($q['patient']) ?></td>
                                <td><?= htmlspecialchars($q['doctor']) ?></td>
                                <td><span class="status-pill <?= htmlspecialchars($q['status_class']) ?>"><?= htmlspecialchars($q['status_label']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>

                <!-- Doctor Schedule -->
                <div class="card">
                    <div class="section-title">Doctor Schedule</div>
                    <div class="section-sub">Who's in today</div>
                    <?php if (empty($doctorSchedule)): ?>
                        <div class="empty-state">No schedule configured yet.</div>
                    <?php else: ?>
                    <div class="sched-list">
                        <?php foreach ($doctorSchedule as $d): ?>
                            <div class="sched-item">
                                <div class="doc-avatar"><?= strtoupper(substr($d['name'], 0, 1)) ?></div>
                                <div class="sched-text">
                                    <div class="sched-name"><?= htmlspecialchars($d['name']) ?></div>
                                    <div class="sched-time"><?= htmlspecialchars($d['time']) ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
</div>
<script src="assets/js/date-picker.js"></script>
</body>
</html>
