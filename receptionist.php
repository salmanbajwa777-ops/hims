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

// ---------------- Today's work queue ----------------
// Every visit registered today, newest first. Registration raises the bill and takes
// payment up front (see patients.php), so there is no unpaid state here — the money
// columns report what was collected, net of any refunds.
$todayRows = $pdo->query("
    SELECT v.id AS visit_id, v.token_no, v.consult_status, v.disposition, v.created_at,
           v.started_at, v.finished_at,
           p.id AS patient_id, p.mrn, p.name AS patient_name, p.dob, p.phone,
           dr.name AS doctor_name,
           dct.label AS consult_label,
           b.id AS bill_id, b.grand_total, b.paid_amount, b.status AS bill_status,
           COALESCE(r.refunded, 0) AS refunded
    FROM visits v
    JOIN patients p ON p.id = v.patient_id
    JOIN users dr ON dr.id = v.doctor_id
    JOIN doctor_consult_types dct ON dct.id = v.doctor_consult_type_id
    LEFT JOIN bills b ON b.visit_id = v.id
    LEFT JOIN (
        SELECT bill_id, SUM(amount) AS refunded FROM refunds GROUP BY bill_id
    ) r ON r.bill_id = b.id
    WHERE v.visit_date = CURDATE()
    ORDER BY v.created_at DESC
")->fetchAll();

$countWaiting = 0;
$countInConsult = 0;
$countAdmitted = 0;
$grossCollected = 0.0;
$totalRefunded = 0.0;
$longestWaitMins = 0;

foreach ($todayRows as $row) {
    if ($row['consult_status'] === 'WAITING') {
        $countWaiting++;
        $waited = (int) round((time() - strtotime($row['created_at'])) / 60);
        $longestWaitMins = max($longestWaitMins, $waited);
    } elseif ($row['consult_status'] === 'IN_CONSULT') {
        $countInConsult++;
    }
    if ($row['disposition'] === 'SHORT_STAY') {
        $countAdmitted++;
    }
    $grossCollected += (float) $row['paid_amount'];
    $totalRefunded += (float) $row['refunded'];
}
$netCollected = $grossCollected - $totalRefunded;

$stats = [
    ['label' => 'Registered Today', 'value' => (string) count($todayRows), 'icon' => 'users'],
    ['label' => 'Waiting', 'value' => (string) $countWaiting, 'icon' => 'calendar'],
    ['label' => 'In Consult', 'value' => (string) $countInConsult, 'icon' => 'stethoscope'],
    ['label' => 'Collected (net)', 'value' => 'Rs ' . number_format($netCollected), 'icon' => 'dollar-sign'],
];

// Doctors seeing patients today, with how many each has left to see.
$doctorSchedule = $pdo->query("
    SELECT dr.name,
           SUM(v.consult_status <> 'DONE') AS pending,
           COUNT(*) AS total
    FROM visits v JOIN users dr ON dr.id = v.doctor_id
    WHERE v.visit_date = CURDATE()
    GROUP BY dr.id, dr.name
    ORDER BY pending DESC, dr.name
")->fetchAll();
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
    --text-secondary: #334155;
    --text-muted: #64748B;
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
.status-pill { font-size: 11.5px; font-weight: 600; padding: 3px 9px; border-radius: 20px; white-space: nowrap; display: inline-block; }
.status-pill.waiting, .status-pill.wait { background: #FFFBEB; color: #92400E; }
.status-pill.in-consult, .status-pill.active { background: #ECFDF5; color: #047857; }
.status-pill.done { background: #F1F5F9; color: var(--text-secondary); }
.status-pill.stay { background: #EDE7FB; color: #6D28D9; }

/* ---------- Today / Patients view toggle ---------- */
.view-tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--border); }
.view-tab { padding: 9px 16px; font-size: 13.5px; font-weight: 600; color: var(--text-secondary);
            border-bottom: 2px solid transparent; margin-bottom: -1px; }
.view-tab.active { color: var(--primary-dark); border-bottom-color: var(--primary); }
.view-tab:hover { color: var(--primary-dark); }

/* ---------- Today work queue ---------- */
.queue-scroll { overflow-x: auto; }
.queue-table { width: 100%; border-collapse: collapse; min-width: 900px; }
.queue-table th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .05em;
                  color: var(--text-muted); font-weight: 600; padding: 0 10px 10px; }
.queue-table td { padding: 11px 10px; border-top: 1px solid var(--border); vertical-align: middle; }
.queue-table .ta-r { text-align: right; }
.qrow { position: relative; }
/* Left severity stripe: consultation state at a glance. box-shadow rather than a
   pseudo-element so it can't be clipped by the horizontal scroll container. */
.qrow td:first-child { box-shadow: inset 3px 0 0 0 transparent; }
.qrow.s-wait td:first-child { box-shadow: inset 3px 0 0 0 var(--amber); }
.qrow.s-active td:first-child { box-shadow: inset 3px 0 0 0 var(--primary); }
.qrow.s-done td:first-child { box-shadow: inset 3px 0 0 0 #CBD5E1; }
.qrow.s-stay td:first-child { box-shadow: inset 3px 0 0 0 #6D28D9; }
.qrow.voided { opacity: .6; }
.qrow .tok { font-variant-numeric: tabular-nums; font-weight: 700; font-size: 16px; padding-left: 14px; }
.qrow .tok small { display: block; font-size: 10px; font-weight: 600; letter-spacing: .05em;
                   color: var(--text-muted); }
.q-name { font-weight: 600; font-size: 13.5px; }
.q-doc { font-weight: 600; font-size: 13px; }
.q-meta { font-size: 11.5px; color: var(--text-muted); }
.mono { font-variant-numeric: tabular-nums; }
.struck { text-decoration: line-through; color: var(--text-muted); }
.q-acts { display: inline-flex; gap: 6px; flex-wrap: wrap; justify-content: flex-end; }
.qa { border: 1px solid var(--border); background: var(--card); color: var(--text); border-radius: 8px;
      padding: 5px 11px; font: 600 12px inherit; font-family: inherit; cursor: pointer; white-space: nowrap; }
.qa:hover { border-color: var(--primary); color: var(--primary-dark); }
.qa.warn { color: var(--red); }
.qa[disabled] { opacity: .45; cursor: not-allowed; }
.qa[disabled]:hover { border-color: var(--border); color: var(--text); }
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

            <!-- Hero -->
            <section class="hero">
                <div class="hero-greeting">
                    <div class="eyebrow"><?= $greeting ?></div>
                    <h1><?= htmlspecialchars($user['name']) ?></h1>
                    <div class="date"><?= date('l') ?>, <?= date('d F Y') ?></div>
                </div>
            </section>

            <div class="view-tabs">
                <a class="view-tab active" href="receptionist.php">Today</a>
                <a class="view-tab" href="patients.php">Patients</a>
            </div>

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

            <!-- Today's work queue -->
            <div class="card">
                <div class="section-title">Today</div>
                <div class="section-sub"><?= count($todayRows) ?> registered &middot; <?= $countWaiting ?> waiting<?= $longestWaitMins > 0 ? ' (longest ' . $longestWaitMins . ' min)' : '' ?></div>

                <?php if (empty($todayRows)): ?>
                    <div class="empty-state">No patients registered today yet.</div>
                <?php else: ?>
                <div class="queue-scroll">
                <table class="queue-table">
                    <thead>
                        <tr><th>Token</th><th>Patient</th><th>Doctor / Type</th><th>Status</th><th>Paid</th><th class="ta-r">Actions</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($todayRows as $row): ?>
                        <?php
                            $isAdmitted = $row['disposition'] === 'SHORT_STAY';
                            $refunded = (float) $row['refunded'];
                            $paidAmount = (float) $row['paid_amount'];

                            if ($isAdmitted) {
                                $stripe = 'stay';
                            } elseif ($row['consult_status'] === 'IN_CONSULT') {
                                $stripe = 'active';
                            } elseif ($row['consult_status'] === 'WAITING') {
                                $stripe = 'wait';
                            } else {
                                $stripe = 'done';
                            }

                            if ($row['consult_status'] === 'WAITING') {
                                $waitedMins = (int) round((time() - strtotime($row['created_at'])) / 60);
                                $statusLabel = 'Waiting ' . $waitedMins . 'm';
                                $statusClass = 'wait';
                            } elseif ($row['consult_status'] === 'IN_CONSULT') {
                                $statusLabel = 'In consult';
                                $statusClass = 'active';
                            } else {
                                $statusLabel = $row['finished_at'] ? 'Done ' . date('H:i', strtotime($row['finished_at'])) : 'Done';
                                $statusClass = 'done';
                            }

                            $ageDisplay = $row['dob']
                                ? (new DateTime($row['dob']))->diff(new DateTime())->y . 'y'
                                : '—';
                        ?>
                        <tr class="qrow s-<?= $stripe ?><?= $refunded > 0 && $refunded >= $paidAmount ? ' voided' : '' ?>">
                            <td class="tok"><?= (int) $row['token_no'] ?><small><?= date('H:i', strtotime($row['created_at'])) ?></small></td>
                            <td>
                                <div class="q-name"><?= htmlspecialchars($row['patient_name']) ?></div>
                                <div class="q-meta"><span class="mono"><?= htmlspecialchars($row['mrn']) ?></span> &middot; <?= $ageDisplay ?> &middot; <?= htmlspecialchars($row['phone']) ?></div>
                            </td>
                            <td>
                                <div class="q-doc"><?= htmlspecialchars($row['doctor_name']) ?></div>
                                <div class="q-meta"><?= htmlspecialchars($row['consult_label']) ?></div>
                            </td>
                            <td>
                                <span class="status-pill <?= $statusClass ?>"><?= htmlspecialchars($statusLabel) ?></span>
                                <?php if ($isAdmitted): ?><span class="status-pill stay">Admitted</span><?php endif; ?>
                            </td>
                            <td class="mono">
                                <?php if ($refunded > 0): ?>
                                    <span class="struck">Rs <?= number_format($paidAmount, 0) ?></span>
                                    <div class="q-meta">refunded <?= number_format($refunded, 0) ?></div>
                                <?php else: ?>
                                    Rs <?= number_format($paidAmount, 0) ?>
                                <?php endif; ?>
                            </td>
                            <td class="ta-r">
                                <div class="q-acts">
                                    <!-- Admit/Discharge is deliberately inert until the short-stay model is
                                         specified (bed/room? own charge?). Shown disabled rather than hidden
                                         so the intended action is visible without implying it works. -->
                                    <button class="qa" disabled title="Short-stay admission isn't built yet"><?= $isAdmitted ? 'Discharge' : 'Admit' ?></button>
                                    <?php if ($row['bill_id']): ?>
                                        <a class="qa" href="checkout.php?print=1&amp;bill_id=<?= (int) $row['bill_id'] ?>" target="_blank" rel="noopener">Invoice</a>
                                        <?php if ($row['bill_status'] === 'paid' && $refunded < $paidAmount && has_permission('RECEPTION_ISSUE_REFUNDS')): ?>
                                            <a class="qa warn" href="refund.php?bill_id=<?= (int) $row['bill_id'] ?>">Refund</a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <a class="qa" href="patients.php?q=<?= urlencode($row['mrn']) ?>">Profile</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- Doctor Schedule -->
            <div class="card">
                <div class="section-title">Doctors today</div>
                <div class="section-sub">Who's in, and how many are still waiting to be seen</div>
                <?php if (empty($doctorSchedule)): ?>
                    <div class="empty-state">No visits booked today yet.</div>
                <?php else: ?>
                <div class="sched-list">
                    <?php foreach ($doctorSchedule as $d): ?>
                        <div class="sched-item">
                            <div class="doc-avatar"><?= strtoupper(substr($d['name'], 0, 1)) ?></div>
                            <div class="sched-text">
                                <div class="sched-name"><?= htmlspecialchars($d['name']) ?></div>
                                <div class="sched-time"><?= (int) $d['pending'] ?> of <?= (int) $d['total'] ?> still to see</div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>
<script src="assets/js/date-picker.js"></script>
</body>
</html>
