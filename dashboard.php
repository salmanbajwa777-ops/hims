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
        'clipboard' => '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M9 4H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-3"/>',
        'card' => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
        'wallet' => '<path d="M20 12V8a2 2 0 0 0-2-2H5a2 2 0 0 1 0-4h13a2 2 0 0 1 2 2v3"/><path d="M3 5v14a2 2 0 0 0 2 2h15a2 2 0 0 0 2-2v-4"/><circle cx="17" cy="14" r="1.5"/>',
        'bar-chart' => '<path d="M3 3v18h18"/><rect x="7" y="12" width="3" height="6"/><rect x="12" y="8" width="3" height="10"/><rect x="17" y="5" width="3" height="13"/>',
        'user-group' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'lock' => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'box' => '<path d="M21 8l-9-5-9 5v8l9 5 9-5V8z"/><path d="M3.3 7.6L12 12l8.7-4.4M12 12v9"/>',
        'trending-up' => '<path d="M23 6l-9.5 9.5-5-5L1 18"/><path d="M17 6h6v6"/>',
        'file-text' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
        'search' => '<circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>',
        'bell' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
        'mail' => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 7l10 6 10-6"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5M12 15V3"/>',
        'alert-triangle' => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4M12 17h.01"/>',
        'check-circle' => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="M22 4L12 14.01l-3-3"/>',
        'dollar-sign' => '<path d="M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
        'droplet' => '<path d="M12 2.69s5.66 5.86 5.66 10.31A5.66 5.66 0 1 1 6.34 13C6.34 8.55 12 2.69 12 2.69z"/>',
    ];
    $p = $paths[$name] ?? '';
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $p . '</svg>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>HIMS — Dashboard</title>
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

/* ---------- Layout ---------- */
.app {
    display: grid;
    grid-template-columns: 280px 1fr;
    min-height: 100vh;
}
.main {
    display: flex;
    flex-direction: column;
    min-width: 0;
}
.content {
    padding: 28px 32px 60px;
    display: flex;
    flex-direction: column;
    gap: 24px;
}

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
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 0 8px 24px;
    font-weight: 700;
    font-size: 18px;
}
.sidebar-brand .logo-mark {
    width: 34px;
    height: 34px;
    border-radius: 10px;
    background: linear-gradient(135deg, var(--primary-dark), var(--primary));
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-weight: 700;
    font-size: 14px;
}
.nav-group { margin-bottom: 18px; }
.nav-group-label {
    font-size: 11px;
    font-weight: 600;
    letter-spacing: .06em;
    color: var(--text-muted);
    padding: 0 12px 8px;
    text-transform: uppercase;
}
.nav-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 12px;
    border-radius: 12px;
    color: var(--text-secondary);
    font-weight: 500;
    font-size: 13.5px;
    transition: background .15s ease;
}
.nav-item:hover { background: #F8FAFC; }
.nav-item.active {
    background: var(--primary-light);
    color: var(--primary-dark);
    font-weight: 600;
    position: relative;
}
.nav-item.active::before {
    content: "";
    position: absolute;
    left: -16px;
    top: 8px;
    bottom: 8px;
    width: 3px;
    background: var(--primary);
    border-radius: 0 3px 3px 0;
}
.nav-icon {
    width: 28px;
    height: 28px;
    border-radius: 8px;
    background: #F1F5F9;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    color: var(--text-secondary);
}
.nav-icon svg { width: 15px; height: 15px; }
.nav-item.active .nav-icon { background: #fff; color: var(--primary-dark); }

/* ---------- Header ---------- */
.header {
    height: 72px;
    position: sticky;
    top: 0;
    z-index: 20;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 32px;
    background: rgba(255,255,255,.80);
    backdrop-filter: blur(18px);
    border-bottom: 1px solid var(--border);
}
.search-box {
    flex: 1;
    max-width: 420px;
    margin: 0 32px;
    position: relative;
}
.search-box input {
    width: 100%;
    padding: 9px 14px 9px 38px;
    border-radius: var(--radius-input);
    border: 1px solid var(--border);
    background: #F8FAFC;
    font-size: 13.5px;
    color: var(--text);
}
.search-box input:focus { outline: none; border-color: var(--primary); background: #fff; }
.search-box .icon { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: var(--text-muted); display: flex; }
.search-box .icon svg { width: 15px; height: 15px; }
.search-box .kbd {
    position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
    font-size: 11px; color: var(--text-muted); background: #fff; border: 1px solid var(--border);
    border-radius: 6px; padding: 2px 6px;
}
.header-right { display: flex; align-items: center; gap: 18px; }
.icon-btn {
    width: 38px; height: 38px; border-radius: 12px; border: 1px solid var(--border);
    background: #fff; display: flex; align-items: center; justify-content: center;
    color: var(--text-secondary); position: relative; cursor: pointer;
}
.icon-btn svg { width: 17px; height: 17px; }
.icon-btn .dot {
    position: absolute; top: 6px; right: 6px; width: 7px; height: 7px; border-radius: 50%; background: var(--red);
    border: 1.5px solid #fff;
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
    border-radius: var(--radius-card);
    padding: 32px 36px;
    min-height: 180px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 24px;
    color: #fff;
    position: relative;
    overflow: hidden;
}
.hero::before, .hero::after {
    content: "";
    position: absolute;
    border-radius: 50%;
    background: rgba(255,255,255,.08);
}
.hero::before { width: 220px; height: 220px; top: -80px; right: 120px; }
.hero::after { width: 140px; height: 140px; bottom: -60px; right: -20px; }
.hero-greeting .eyebrow { font-size: 14px; opacity: .85; font-weight: 500; }
.hero-greeting h1 { font-size: 32px; font-weight: 700; margin: 4px 0 8px; }
.hero-greeting .date { font-size: 13.5px; opacity: .85; }
.hero-kpis { display: flex; gap: 36px; flex-wrap: wrap; position: relative; z-index: 1; }
.hero-kpi .label { font-size: 12.5px; opacity: .8; margin-bottom: 4px; }
.hero-kpi .value { font-size: 28px; font-weight: 700; }

/* ---------- KPI Cards ---------- */
.grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 18px; }
.kpi-card {
    background: var(--card);
    border-radius: var(--radius-card);
    padding: 20px 22px;
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--border);
    transition: transform .25s ease, box-shadow .25s ease;
}
.kpi-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-md); }
.kpi-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
.kpi-icon {
    width: 42px; height: 42px; border-radius: 12px; display: flex; align-items: center; justify-content: center;
    background: var(--primary-light); color: var(--primary-dark);
}
.kpi-icon svg { width: 20px; height: 20px; }
.kpi-trend { font-size: 12px; font-weight: 600; padding: 3px 8px; border-radius: 8px; }
.kpi-trend.up { color: #047857; background: #ECFDF5; }
.kpi-trend.down { color: #B91C1C; background: #FEF2F2; }
.kpi-value { font-size: 30px; font-weight: 700; margin-bottom: 2px; }
.kpi-label { font-size: 13px; color: var(--text-secondary); margin-bottom: 10px; }
.kpi-sparkline { height: 28px; }
.kpi-sub { font-size: 12px; color: var(--text-muted); margin-top: 8px; display: flex; gap: 12px; }

/* ---------- Quick Actions ---------- */
.quick-actions { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; }
.action-tile {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 16px;
    min-height: 100px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8px;
    cursor: pointer;
    transition: transform .2s ease, box-shadow .2s ease, background .2s ease;
    text-align: center;
    padding: 10px;
}
.action-tile:hover {
    transform: scale(1.03);
    box-shadow: var(--shadow-md);
    background: linear-gradient(160deg, #fff, var(--primary-light));
}
.action-tile .icon { color: var(--primary-dark); }
.action-tile .icon svg { width: 24px; height: 24px; }
.action-tile .label { font-size: 12.5px; font-weight: 600; color: var(--text); }

/* ---------- Section shell ---------- */
.section-title { font-size: 18px; font-weight: 600; margin-bottom: 2px; }
.section-sub { font-size: 12.5px; color: var(--text-muted); margin-bottom: 16px; }
.card {
    background: var(--card);
    border-radius: var(--radius-card);
    border: 1px solid var(--border);
    box-shadow: var(--shadow-sm);
    padding: 22px 24px;
}
.row-2 { display: grid; grid-template-columns: 1.4fr 1fr; gap: 20px; align-items: start; }

/* ---------- Revenue chart ---------- */
.chart-tabs { display: flex; gap: 6px; background: #F1F5F9; padding: 4px; border-radius: 10px; width: fit-content; }
.chart-tab { padding: 6px 14px; border-radius: 8px; font-size: 12.5px; font-weight: 600; color: var(--text-secondary); cursor: pointer; }
.chart-tab.active { background: #fff; color: var(--primary-dark); box-shadow: var(--shadow-sm); }
.chart-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; }
.bars { display: flex; align-items: flex-end; gap: 14px; height: 160px; margin-top: 8px; }
.bar-col { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 8px; height: 100%; justify-content: flex-end; }
.bar-fill {
    width: 100%; max-width: 24px; border-radius: 4px 4px 0 0;
    background: linear-gradient(180deg, var(--primary), var(--primary-dark));
}
.bar-day { font-size: 11px; color: var(--text-muted); }
.revenue-summary { display: flex; flex-direction: column; gap: 16px; margin-top: 20px; padding-top: 18px; border-top: 1px solid var(--border); }
.summary-row { display: flex; justify-content: space-between; align-items: baseline; }
.summary-row .label { font-size: 12.5px; color: var(--text-secondary); }
.summary-row .value { font-size: 15px; font-weight: 700; }
.summary-row .value.good { color: #047857; }

/* ---------- Snapshot ---------- */
.snapshot-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.snapshot-item { display: flex; align-items: center; gap: 10px; }
.snapshot-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.snapshot-item .value { font-size: 20px; font-weight: 700; display: block; }
.snapshot-item .label { font-size: 12px; color: var(--text-muted); }

/* ---------- Patient flow ---------- */
.flow { display: flex; gap: 16px; }
.flow-stage { flex: 1; text-align: center; }
.flow-stage .num { font-size: 24px; font-weight: 700; }
.flow-stage .name { font-size: 12px; color: var(--text-secondary); margin: 4px 0 8px; }
.flow-bar { height: 6px; border-radius: 4px; background: #F1F5F9; overflow: hidden; }
.flow-bar-fill { height: 100%; background: var(--primary); border-radius: 4px; }
.flow-pct { font-size: 11px; color: var(--text-muted); margin-top: 6px; }
.flow-arrow { display: flex; align-items: center; color: var(--text-muted); font-size: 14px; padding-top: 30px; }

/* ---------- Bed occupancy ---------- */
.rings { display: flex; justify-content: space-around; gap: 12px; flex-wrap: wrap; }
.ring-item { text-align: center; }
.ring {
    width: 96px; height: 96px; border-radius: 50%; display: flex; align-items: center; justify-content: center;
    position: relative; margin: 0 auto 10px;
}
.ring-value { font-size: 18px; font-weight: 700; }
.ring-label { font-size: 12px; color: var(--text-secondary); margin-top: 2px; }
.ring-sub { font-size: 11px; color: var(--text-muted); }

/* ---------- Doctor table ---------- */
table { width: 100%; border-collapse: collapse; }
th { text-align: left; font-size: 11.5px; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted); padding: 0 10px 10px; font-weight: 600; }
td { padding: 12px 10px; border-top: 1px solid var(--border); font-size: 13.5px; }
tr.top-performer td:first-child { position: relative; }
tr.top-performer td:first-child::before { content: "★"; color: var(--amber); margin-right: 6px; }
.doc-name { display: flex; align-items: center; gap: 10px; font-weight: 600; }
.doc-avatar { width: 30px; height: 30px; border-radius: 50%; background: var(--primary-light); color: var(--primary-dark); display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; }
.status-pill { font-size: 11.5px; font-weight: 600; padding: 3px 9px; border-radius: 20px; }
.status-pill.in-clinic { background: #ECFDF5; color: #047857; }
.status-pill.on-leave { background: #FEF2F2; color: #B91C1C; }
tabular { font-variant-numeric: tabular-nums; }

/* ---------- Financial summary ---------- */
.fin-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }
.fin-card { padding: 4px 2px; }
.fin-card .label { font-size: 12.5px; color: var(--text-secondary); margin-bottom: 6px; }
.fin-card .value { font-size: 24px; font-weight: 700; margin-bottom: 4px; }
.fin-card .trend { font-size: 12px; font-weight: 600; }
.fin-card .trend.up { color: #047857; }
.fin-card .trend.down { color: #B91C1C; }

/* ---------- Timeline ---------- */
.timeline { display: flex; flex-direction: column; max-height: 320px; overflow-y: auto; }
.tl-item { display: flex; gap: 14px; padding-bottom: 20px; position: relative; }
.tl-item:not(:last-child)::before {
    content: ""; position: absolute; left: 5px; top: 16px; bottom: 0; width: 1px; background: var(--border);
}
.tl-dot { width: 11px; height: 11px; border-radius: 50%; margin-top: 3px; flex-shrink: 0; z-index: 1; }
.tl-time { font-size: 11.5px; color: var(--text-muted); margin-bottom: 2px; }
.tl-text { font-size: 13.5px; font-weight: 500; }

/* ---------- Notifications ---------- */
.notif-list { display: flex; flex-direction: column; gap: 4px; }
.notif-item { display: flex; align-items: center; gap: 12px; padding: 12px 4px; border-bottom: 1px solid var(--border); }
.notif-item:last-child { border-bottom: none; }
.notif-icon {
    width: 34px; height: 34px; border-radius: 10px; display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.notif-icon svg { width: 16px; height: 16px; }
.notif-text { flex: 1; font-size: 13px; font-weight: 500; }
.notif-action { font-size: 12px; font-weight: 600; color: var(--primary); }

/* ---------- Inventory ---------- */
.inv-list { display: flex; flex-direction: column; gap: 10px; }
.inv-item {
    display: flex; justify-content: space-between; align-items: center;
    padding: 10px 14px; border-radius: 10px; background: #FEF2F2; border-left: 3px solid var(--red);
}
.inv-name { font-size: 13px; font-weight: 600; }
.inv-qty { font-size: 12px; color: var(--text-secondary); }
.inv-order { font-size: 12px; font-weight: 600; color: var(--primary); }

/* ---------- Calendar ---------- */
.cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 6px; text-align: center; }
.cal-dow { font-size: 11px; color: var(--text-muted); font-weight: 600; padding-bottom: 6px; }
.cal-day { font-size: 13px; padding: 8px 0; border-radius: 8px; position: relative; color: var(--text-secondary); }
.cal-day.today { background: var(--primary); color: #fff; font-weight: 700; }
.cal-day.has-event::after { content: ""; position: absolute; bottom: 3px; left: 50%; transform: translateX(-50%); width: 4px; height: 4px; border-radius: 50%; background: var(--amber); }
.cal-day.muted { color: var(--text-muted); opacity: .4; }

/* ---------- Password nag ---------- */
.nag-banner {
    background: #FFFBEB; border: 1px solid #FDE68A; border-radius: 14px;
    padding: 14px 18px; display: flex; align-items: center; justify-content: space-between; gap: 12px;
    font-size: 13.5px; color: #92400E;
}
.nag-banner a { font-weight: 700; text-decoration: underline; }

@media (max-width: 1200px) {
    .grid-4, .quick-actions, .fin-grid { grid-template-columns: repeat(2, 1fr); }
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
            <a class="nav-item active" href="dashboard.php"><span class="nav-icon"><?= icon('grid') ?></span> Dashboard</a>
        </div>

        <div class="nav-group">
            <div class="nav-group-label">Operations</div>
            <a class="nav-item" href="patients.php"><span class="nav-icon"><?= icon('users') ?></span> Patients</a>
            <a class="nav-item" href="staff.php"><span class="nav-icon"><?= icon('stethoscope') ?></span> Doctors</a>
            <a class="nav-item" href="#"><span class="nav-icon"><?= icon('calendar') ?></span> Appointments</a>
            <a class="nav-item" href="#"><span class="nav-icon"><?= icon('bed') ?></span> Admissions</a>
            <a class="nav-item" href="#"><span class="nav-icon"><?= icon('clipboard') ?></span> Procedures</a>
        </div>

        <div class="nav-group">
            <div class="nav-group-label">Finance</div>
            <a class="nav-item" href="#"><span class="nav-icon"><?= icon('card') ?></span> Billing</a>
            <a class="nav-item" href="#"><span class="nav-icon"><?= icon('wallet') ?></span> Payments</a>
            <a class="nav-item" href="#"><span class="nav-icon"><?= icon('bar-chart') ?></span> Settlements</a>
        </div>

        <div class="nav-group">
            <div class="nav-group-label">Management</div>
            <a class="nav-item" href="staff.php"><span class="nav-icon"><?= icon('user-group') ?></span> Staff</a>
            <a class="nav-item" href="locations.php"><span class="nav-icon"><?= icon('bed') ?></span> Cities &amp; Areas</a>
            <a class="nav-item" href="permissions.php"><span class="nav-icon"><?= icon('lock') ?></span> Permissions</a>
            <a class="nav-item" href="#"><span class="nav-icon"><?= icon('box') ?></span> Inventory</a>
        </div>

        <div class="nav-group">
            <div class="nav-group-label">Analytics</div>
            <a class="nav-item" href="#"><span class="nav-icon"><?= icon('trending-up') ?></span> Reports</a>
            <a class="nav-item" href="#"><span class="nav-icon"><?= icon('file-text') ?></span> Audit Logs</a>
        </div>

        <div class="nav-group">
            <div class="nav-group-label">System</div>
            <a class="nav-item" href="#"><span class="nav-icon"><?= icon('settings') ?></span> Settings</a>
        </div>
    </aside>

    <div class="main">
        <header class="header">
            <div class="sidebar-brand" style="padding:0;">
                <div class="logo-mark" style="width:30px;height:30px;font-size:12px;">H</div>
                <span style="font-size:15px;">HIMS</span>
            </div>

            <div class="search-box">
                <span class="icon"><?= icon('search') ?></span>
                <input type="text" placeholder="Search patients, invoices, staff...">
                <span class="kbd">Ctrl K</span>
            </div>

            <div class="header-right">
                <button class="icon-btn"><?= icon('bell', 17) ?><span class="dot"></span></button>
                <button class="icon-btn"><?= icon('mail', 17) ?></button>
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
                    <div class="date"><?= date('l') ?><br><?= date('d F Y') ?></div>
                </div>
                <div class="hero-kpis">
                    <div class="hero-kpi"><div class="label">Today's Revenue</div><div class="value">82,500</div></div>
                    <div class="hero-kpi"><div class="label">Patients</div><div class="value">145</div></div>
                    <div class="hero-kpi"><div class="label">Admissions</div><div class="value">18</div></div>
                    <div class="hero-kpi"><div class="label">Discharges</div><div class="value">14</div></div>
                </div>
            </section>

            <!-- KPI Cards -->
            <div class="grid-4">
                <div class="kpi-card">
                    <div class="kpi-top">
                        <div class="kpi-icon"><?= icon('stethoscope', 20) ?></div>
                        <div class="kpi-trend up">&#9650; +2%</div>
                    </div>
                    <div class="kpi-value">5</div>
                    <div class="kpi-label">Doctors</div>
                    <div class="kpi-sub"><span>4 In Clinic</span><span>1 On Leave</span></div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-top">
                        <div class="kpi-icon"><?= icon('users', 20) ?></div>
                        <div class="kpi-trend up">&#9650; +8%</div>
                    </div>
                    <div class="kpi-value">145</div>
                    <div class="kpi-label">Patients Today</div>
                    <div class="kpi-sub"><span>126 Returning</span><span>19 New</span></div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-top">
                        <div class="kpi-icon" style="background:#FFFBEB;color:#92400E;"><?= icon('lock', 20) ?></div>
                        <div class="kpi-trend down">&#9660; -1</div>
                    </div>
                    <div class="kpi-value">2</div>
                    <div class="kpi-label">Pending Permissions</div>
                    <div class="kpi-sub"><span>Awaiting admin review</span></div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-top">
                        <div class="kpi-icon" style="background:#ECFDF5;color:#047857;"><?= icon('check-circle', 20) ?></div>
                        <div class="kpi-trend up">Stable</div>
                    </div>
                    <div class="kpi-value">99.9%</div>
                    <div class="kpi-label">System Health</div>
                    <div class="kpi-sub"><span>All services operational</span></div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div>
                <div class="section-title">Quick Actions</div>
                <div class="section-sub">Jump straight into the most common tasks</div>
                <div class="quick-actions">
                    <a class="action-tile" href="patients.php"><span class="icon"><?= icon('users', 24) ?></span><span class="label">Patients</span></a>
                    <a class="action-tile" href="staff.php"><span class="icon"><?= icon('plus', 24) ?></span><span class="label">Add Staff</span></a>
                    <a class="action-tile" href="staff.php"><span class="icon"><?= icon('stethoscope', 24) ?></span><span class="label">Add Doctor</span></a>
                    <a class="action-tile" href="permissions.php"><span class="icon"><?= icon('lock', 24) ?></span><span class="label">Permissions</span></a>
                    <div class="action-tile"><span class="icon"><?= icon('bar-chart', 24) ?></span><span class="label">Settlement</span></div>
                    <div class="action-tile"><span class="icon"><?= icon('box', 24) ?></span><span class="label">Inventory</span></div>
                    <div class="action-tile"><span class="icon"><?= icon('file-text', 24) ?></span><span class="label">Reports</span></div>
                    <div class="action-tile"><span class="icon"><?= icon('download', 24) ?></span><span class="label">Export</span></div>
                    <div class="action-tile"><span class="icon"><?= icon('settings', 24) ?></span><span class="label">Settings</span></div>
                </div>
            </div>

            <!-- Revenue + Snapshot -->
            <div class="row-2">
                <div class="card">
                    <div class="chart-head">
                        <div>
                            <div class="section-title" style="margin-bottom:0;">Revenue Analytics</div>
                            <div class="section-sub" style="margin-bottom:0;">Weekly performance overview</div>
                        </div>
                        <div class="chart-tabs">
                            <div class="chart-tab active">Week</div>
                            <div class="chart-tab">Month</div>
                            <div class="chart-tab">Year</div>
                        </div>
                    </div>
                    <div class="bars">
                        <?php
                        $days = ['Mon'=>62,'Tue'=>74,'Wed'=>58,'Thu'=>81,'Fri'=>69,'Sat'=>92,'Sun'=>47];
                        $max = max($days);
                        foreach ($days as $day => $val):
                            $h = round(($val / $max) * 100);
                        ?>
                        <div class="bar-col">
                            <div class="bar-fill" style="height: <?= $h ?>%;" title="<?= $day ?>: <?= $val ?>k"></div>
                            <div class="bar-day"><?= $day ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="revenue-summary">
                        <div class="summary-row"><span class="label">Revenue</span><span class="value">347,000</span></div>
                        <div class="summary-row"><span class="label">Average</span><span class="value">49,570</span></div>
                        <div class="summary-row"><span class="label">Peak Day</span><span class="value">Saturday</span></div>
                        <div class="summary-row"><span class="label">Growth</span><span class="value good">&#9650; +12%</span></div>
                    </div>
                </div>

                <div class="card">
                    <div class="section-title">Today's Snapshot</div>
                    <div class="section-sub">Executive summary</div>
                    <div class="snapshot-grid">
                        <div class="snapshot-item"><span class="snapshot-dot" style="background:var(--primary);"></span><div><span class="value">85</span><span class="label">Appointments</span></div></div>
                        <div class="snapshot-item"><span class="snapshot-dot" style="background:var(--green);"></span><div><span class="value">54</span><span class="label">Completed</span></div></div>
                        <div class="snapshot-item"><span class="snapshot-dot" style="background:var(--amber);"></span><div><span class="value">19</span><span class="label">Waiting</span></div></div>
                        <div class="snapshot-item"><span class="snapshot-dot" style="background:var(--red);"></span><div><span class="value">4</span><span class="label">Cancelled</span></div></div>
                        <div class="snapshot-item"><span class="snapshot-dot" style="background:var(--green);"></span><div><span class="value">82,500</span><span class="label">Collections</span></div></div>
                        <div class="snapshot-item"><span class="snapshot-dot" style="background:var(--amber);"></span><div><span class="value">8,500</span><span class="label">Outstanding</span></div></div>
                    </div>
                </div>
            </div>

            <!-- Patient Flow + Bed Occupancy -->
            <div class="row-2">
                <div class="card">
                    <div class="section-title">Patient Flow</div>
                    <div class="section-sub">Today's journey across stages</div>
                    <div class="flow">
                        <?php
                        $stages = [
                            'Registered' => ['num'=>145,'pct'=>100],
                            'Waiting' => ['num'=>34,'pct'=>72],
                            'Consultation' => ['num'=>58,'pct'=>60],
                            'Pharmacy' => ['num'=>39,'pct'=>45],
                            'Completed' => ['num'=>102,'pct'=>70],
                        ];
                        $i = 0;
                        foreach ($stages as $name => $s):
                            if ($i > 0): ?><div class="flow-arrow">&rarr;</div><?php endif;
                        ?>
                        <div class="flow-stage">
                            <div class="num"><?= $s['num'] ?></div>
                            <div class="name"><?= $name ?></div>
                            <div class="flow-bar"><div class="flow-bar-fill" style="width:<?= $s['pct'] ?>%;"></div></div>
                            <div class="flow-pct"><?= $s['pct'] ?>%</div>
                        </div>
                        <?php $i++; endforeach; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="section-title">Bed Occupancy</div>
                    <div class="section-sub">Live ward status</div>
                    <div class="rings">
                        <?php
                        $wards = [
                            ['name'=>'Emergency','pct'=>83,'sub'=>'5/6 Beds','color'=>'#DC2626'],
                            ['name'=>'ICU','pct'=>72,'sub'=>'8/11 Beds','color'=>'#F59E0B'],
                            ['name'=>'General','pct'=>58,'sub'=>'26/45 Beds','color'=>'#10B981'],
                        ];
                        foreach ($wards as $w):
                            $deg = round($w['pct'] * 3.6);
                        ?>
                        <div class="ring-item">
                            <div class="ring" style="background: conic-gradient(<?= $w['color'] ?> <?= $deg ?>deg, #F1F5F9 0deg);">
                                <div style="width:74px;height:74px;border-radius:50%;background:#fff;display:flex;align-items:center;justify-content:center;">
                                    <span class="ring-value"><?= $w['pct'] ?>%</span>
                                </div>
                            </div>
                            <div class="ring-label"><?= $w['name'] ?></div>
                            <div class="ring-sub"><?= $w['sub'] ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Doctor Performance + Financial Summary -->
            <div class="row-2">
                <div class="card">
                    <div class="section-title">Doctor Performance</div>
                    <div class="section-sub">Today's activity by doctor</div>
                    <table>
                        <thead>
                            <tr><th>Doctor</th><th>Patients</th><th>Revenue</th><th>Avg. Time</th><th>Rating</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            <?php
                            $doctors = [
                                ['name'=>'Dr Fatima Ahmed','patients'=>18,'revenue'=>'42k','time'=>'12m','rating'=>'4.9','status'=>'in-clinic','top'=>true],
                                ['name'=>'Dr Ahmed Raza','patients'=>15,'revenue'=>'37k','time'=>'14m','rating'=>'4.7','status'=>'in-clinic','top'=>false],
                                ['name'=>'Dr Ali Hassan','patients'=>11,'revenue'=>'28k','time'=>'16m','rating'=>'4.6','status'=>'on-leave','top'=>false],
                            ];
                            foreach ($doctors as $d):
                                $initials = strtoupper(substr($d['name'], 4, 1));
                            ?>
                            <tr class="<?= $d['top'] ? 'top-performer' : '' ?>">
                                <td><div class="doc-name"><span class="doc-avatar"><?= $initials ?></span><?= $d['name'] ?></div></td>
                                <td class="tabular"><?= $d['patients'] ?></td>
                                <td class="tabular"><?= $d['revenue'] ?></td>
                                <td class="tabular"><?= $d['time'] ?></td>
                                <td class="tabular"><?= $d['rating'] ?> &#9733;</td>
                                <td><span class="status-pill <?= $d['status'] ?>"><?= $d['status'] === 'in-clinic' ? 'In Clinic' : 'On Leave' ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="card">
                    <div class="section-title">Financial Summary</div>
                    <div class="section-sub">Today at a glance</div>
                    <div class="fin-grid">
                        <div class="fin-card"><div class="label">Collections</div><div class="value">82.5k</div><div class="trend up">&#9650; 6%</div></div>
                        <div class="fin-card"><div class="label">Expenses</div><div class="value">21.3k</div><div class="trend down">&#9660; 2%</div></div>
                        <div class="fin-card"><div class="label">Doctor Share</div><div class="value">34.8k</div><div class="trend up">&#9650; 4%</div></div>
                        <div class="fin-card"><div class="label">Net Profit</div><div class="value">26.4k</div><div class="trend up">&#9650; 9%</div></div>
                    </div>
                </div>
            </div>

            <!-- Timeline + Notifications -->
            <div class="row-2">
                <div class="card">
                    <div class="section-title">Timeline</div>
                    <div class="section-sub">Recent activity</div>
                    <div class="timeline">
                        <?php
                        $events = [
                            ['time'=>'10:40','text'=>'Consultation Completed — Dr Fatima Ahmed','color'=>'var(--green)'],
                            ['time'=>'10:15','text'=>'Patient Admitted — Emergency Ward','color'=>'var(--red)'],
                            ['time'=>'09:52','text'=>'Payment Received — PKR 12,000','color'=>'var(--primary)'],
                            ['time'=>'08:30','text'=>'Monthly Settlement Generated','color'=>'var(--amber)'],
                        ];
                        foreach ($events as $e):
                        ?>
                        <div class="tl-item">
                            <div class="tl-dot" style="background:<?= $e['color'] ?>;"></div>
                            <div>
                                <div class="tl-time"><?= $e['time'] ?></div>
                                <div class="tl-text"><?= $e['text'] ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="section-title">Notifications</div>
                    <div class="section-sub">Needs your attention</div>
                    <div class="notif-list">
                        <div class="notif-item"><span class="notif-icon" style="background:#FFFBEB;color:#92400E;"><?= icon('alert-triangle') ?></span><span class="notif-text">2 Permission Requests</span><span class="notif-action">Approve</span></div>
                        <div class="notif-item"><span class="notif-icon" style="background:#ECFDF5;color:#047857;"><?= icon('wallet') ?></span><span class="notif-text">Settlement Ready</span><span class="notif-action">Open</span></div>
                        <div class="notif-item"><span class="notif-icon" style="background:#FEF2F2;color:#B91C1C;"><?= icon('bed') ?></span><span class="notif-text">Emergency Beds Almost Full</span><span class="notif-action">View</span></div>
                        <div class="notif-item"><span class="notif-icon" style="background:#ECFDF5;color:#047857;"><?= icon('check-circle') ?></span><span class="notif-text">Backup Completed</span><span class="notif-action">Dismiss</span></div>
                    </div>
                </div>
            </div>

            <!-- Inventory + Calendar -->
            <div class="row-2">
                <div class="card">
                    <div class="section-title">Inventory Alerts</div>
                    <div class="section-sub">Low stock needs reordering</div>
                    <div class="inv-list">
                        <div class="inv-item"><div><div class="inv-name">BCG Vaccine</div><div class="inv-qty">2 vials remaining</div></div><span class="inv-order">Order</span></div>
                        <div class="inv-item"><div><div class="inv-name">Insulin</div><div class="inv-qty">5 pens remaining</div></div><span class="inv-order">Order</span></div>
                        <div class="inv-item"><div><div class="inv-name">Examination Gloves</div><div class="inv-qty">1 box remaining</div></div><span class="inv-order">Order</span></div>
                    </div>
                </div>

                <div class="card">
                    <div class="section-title"><?= date('F Y') ?></div>
                    <div class="section-sub">Monthly overview</div>
                    <div class="cal-grid">
                        <?php
                        foreach (['S','M','T','W','T','F','S'] as $d) echo "<div class='cal-dow'>$d</div>";
                        $firstDay = (int) date('N', strtotime(date('Y-m-01'))) % 7;
                        $daysInMonth = (int) date('t');
                        $today = (int) date('j');
                        $eventDays = [3, 9, 14, 22, 27];
                        for ($i = 0; $i < $firstDay; $i++) echo "<div class='cal-day muted'></div>";
                        for ($d = 1; $d <= $daysInMonth; $d++) {
                            $classes = 'cal-day';
                            if ($d === $today) $classes .= ' today';
                            if (in_array($d, $eventDays, true)) $classes .= ' has-event';
                            echo "<div class='$classes'>$d</div>";
                        }
                        ?>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>
<script src="assets/js/date-picker.js"></script>
</body>
</html>
