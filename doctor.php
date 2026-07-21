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

// This console is the doctor's own worklist. Gate to DOCTOR base_role (admins may
// open it too for support), matching how receptionist.php scopes itself.
$baseRole = $_SESSION['base_role'] ?? '';
if ($baseRole !== 'DOCTOR' && $baseRole !== 'ADMIN') {
    http_response_code(403);
    exit('Forbidden — doctor console only.');
}

// The queue belongs to a specific doctor. A real doctor sees their own; an admin
// opening the page falls back to viewing their own id (they normally have no visits,
// which is fine — the page just shows an empty queue).
$doctorId = (int) $user['id'];

// ---------------- Start / Finish a consultation ----------------
// Only WAITING -> IN_CONSULT and IN_CONSULT -> DONE are allowed, and only on visits
// that belong to this doctor today. The WHERE clause enforces both the ownership and
// the valid prior state, so a stale/replayed POST can't jump states or touch another
// doctor's visit.
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action'] ?? '';
    $visitId = (int) ($_POST['visit_id'] ?? 0);

    if ($visitId > 0 && in_array($action, ['start_consult', 'finish_consult'], true)) {
        if ($action === 'start_consult') {
            $upd = $pdo->prepare("
                UPDATE visits
                SET consult_status = 'IN_CONSULT', started_at = NOW()
                WHERE id = ? AND doctor_id = ? AND visit_date = CURDATE() AND consult_status = 'WAITING'
            ");
            $upd->execute([$visitId, $doctorId]);
            $auditAction = 'consult_started';
            $flash = $upd->rowCount() ? 'Consultation started.' : '';
        } else {
            $upd = $pdo->prepare("
                UPDATE visits
                SET consult_status = 'DONE', finished_at = NOW()
                WHERE id = ? AND doctor_id = ? AND visit_date = CURDATE() AND consult_status = 'IN_CONSULT'
            ");
            $upd->execute([$visitId, $doctorId]);
            $auditAction = 'consult_finished';
            $flash = $upd->rowCount() ? 'Consultation completed.' : '';
        }

        if ($upd->rowCount()) {
            $log = $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)');
            $log->execute([$_SESSION['user_id'], $auditAction, "Visit #$visitId ($auditAction)"]);
        }

        // Post/redirect/get so a refresh doesn't re-submit the state change.
        header('Location: doctor.php' . ($flash ? '?done=' . urlencode($flash) : ''));
        exit;
    }
}
$flash = trim($_GET['done'] ?? '');

$mustChangePassword = (bool) $user['must_change_password'];
$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good Morning' : ($hour < 17 ? 'Good Afternoon' : 'Good Evening');

// ---------------- Today's queue for this doctor ----------------
// One row per visit today, patient + consult type joined in. Ordered so the active
// consultation and those still waiting float to the top (by status), then by token.
$queueStmt = $pdo->prepare("
    SELECT v.id AS visit_id, v.token_no, v.consult_status, v.started_at, v.created_at,
           p.name AS patient_name, p.gender, p.dob, p.approx_age, p.mrn,
           t.label AS type_label
    FROM visits v
    JOIN patients p ON p.id = v.patient_id
    LEFT JOIN doctor_consult_types t ON t.id = v.doctor_consult_type_id
    WHERE v.doctor_id = ? AND v.visit_date = CURDATE()
    ORDER BY FIELD(v.consult_status, 'IN_CONSULT', 'WAITING', 'DONE'), v.token_no
");
$queueStmt->execute([$doctorId]);
$visits = $queueStmt->fetchAll();

$waiting   = array_values(array_filter($visits, fn($v) => $v['consult_status'] === 'WAITING'));
$inConsult = array_values(array_filter($visits, fn($v) => $v['consult_status'] === 'IN_CONSULT'));
$doneCount = count(array_filter($visits, fn($v) => $v['consult_status'] === 'DONE'));

$current = $inConsult[0] ?? null;         // the one being seen right now (0 or 1)
$next    = $waiting[0] ?? null;           // next to call in

function doc_age(array $v): ?int {
    if (!empty($v['dob'])) {
        return (new DateTime($v['dob']))->diff(new DateTime())->y;
    }
    return $v['approx_age'] !== null ? (int) $v['approx_age'] : null;
}

// Minutes a still-waiting patient has been in the queue (since their visit row was created).
function wait_minutes(string $createdAt): int {
    return max(0, (int) floor((time() - strtotime($createdAt)) / 60));
}

function icon(string $name, int $size = 18): string {
    $paths = [
        'grid'    => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'users'   => '<path d="M17 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'stetho'  => '<path d="M4.8 2.3A.3.3 0 1 0 5 2H4a2 2 0 0 0-2 2v5a6 6 0 0 0 6 6a6 6 0 0 0 6-6V4a2 2 0 0 0-2-2h-1a.2.2 0 1 0 .3.3"/><path d="M8 15v1a6 6 0 0 0 6 6a6 6 0 0 0 6-6v-4"/><circle cx="20" cy="10" r="2"/>',
        'file'    => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/>',
        'search'  => '<circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>',
        'calendar'=> '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'chart'   => '<path d="M3 3v18h18"/><path d="M18 9l-5 5-3-3-4 4"/>',
        'check'   => '<path d="M20 6L9 17l-5-5"/>',
        'bell'    => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
        'play'    => '<polygon points="5 3 19 12 5 21 5 3"/>',
        'pen'     => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
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
<title>HIMS — Doctor Console</title>
<style>
:root {
    --primary-dark: #0E5456;
    --primary: #1A7F7E;
    --primary-light: #E0F2F1;
    --green: #10B981;
    --amber: #F59E0B;
    --red: #DC2626;
    --bg: #F5F8F8;
    --card: #FFFFFF;
    --text: #0F172A;
    --text-secondary: #334155;
    --text-muted: #64748B;
    --border: #E2E8F0;
    --shadow-sm: 0 2px 8px rgba(15,23,42,.05);
    --shadow-md: 0 10px 25px rgba(15,23,42,.08);
    --radius-card: 20px;
    --radius-input: 12px;
    --radius-btn: 14px;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Inter', system-ui, -apple-system, "Segoe UI", sans-serif; background: var(--bg); color: var(--text); font-size: 14px; line-height: 1.5; -webkit-font-smoothing: antialiased; }
a { text-decoration: none; color: inherit; }
button { font-family: inherit; cursor: pointer; }
.tnum { font-variant-numeric: tabular-nums; }

.app { display: grid; grid-template-columns: 280px 1fr; min-height: 100vh; }
.main { display: flex; flex-direction: column; min-width: 0; }
.content { padding: 28px 32px 60px; display: flex; flex-direction: column; gap: 24px; }

/* Sidebar */
.sidebar { background: var(--card); border-right: 1px solid var(--border); padding: 24px 16px; position: sticky; top: 0; height: 100vh; overflow-y: auto; }
.sidebar-brand { display: flex; align-items: center; gap: 10px; padding: 0 8px 24px; font-weight: 700; font-size: 18px; }
.sidebar-brand .logo-mark { width: 34px; height: 34px; border-radius: 10px; background: linear-gradient(135deg, var(--primary-dark), var(--primary)); display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 700; font-size: 14px; }
.nav-group { margin-bottom: 18px; }
.nav-group-label { font-size: 11px; font-weight: 600; letter-spacing: .06em; color: var(--text-muted); padding: 0 12px 8px; text-transform: uppercase; }
.nav-item { display: flex; align-items: center; gap: 10px; padding: 9px 12px; border-radius: 12px; color: var(--text-secondary); font-weight: 500; font-size: 13.5px; transition: background .15s ease; }
.nav-item:hover { background: #EEF4F4; }
.nav-item.active { background: var(--primary-light); color: var(--primary-dark); font-weight: 600; position: relative; }
.nav-item.active::before { content: ""; position: absolute; left: -16px; top: 8px; bottom: 8px; width: 3px; background: var(--primary); border-radius: 0 3px 3px 0; }
.nav-item.disabled { opacity: .45; cursor: not-allowed; }
.nav-item .count { margin-left: auto; font-size: 11.5px; font-weight: 700; background: var(--primary); color: #fff; border-radius: 20px; padding: 1px 8px; }
.nav-icon { width: 28px; height: 28px; border-radius: 8px; background: #F1F5F9; display: flex; align-items: center; justify-content: center; flex-shrink: 0; color: var(--text-secondary); }
.nav-icon svg { width: 15px; height: 15px; }
.nav-item.active .nav-icon { background: #fff; color: var(--primary-dark); }
.sidebar-foot { margin-top: 8px; padding: 12px; border-radius: 14px; background: var(--primary-light); font-size: 12px; color: var(--text-secondary); }
.sidebar-foot b { color: var(--text); }

/* Header */
.header { height: 72px; position: sticky; top: 0; z-index: 20; display: flex; align-items: center; justify-content: space-between; padding: 0 32px; background: rgba(255,255,255,.82); backdrop-filter: blur(18px); border-bottom: 1px solid var(--border); }
.header-title { font-size: 16px; font-weight: 700; }
.search-box { flex: 1; max-width: 420px; margin: 0 32px; position: relative; }
.search-box input { width: 100%; padding: 9px 14px 9px 38px; border-radius: var(--radius-input); border: 1px solid var(--border); background: var(--bg); font-size: 13.5px; color: var(--text); }
.search-box input:focus { outline: none; border-color: var(--primary); background: #fff; }
.search-box .icon { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: var(--text-muted); display: flex; }
.search-box .icon svg { width: 15px; height: 15px; }
.header-right { display: flex; align-items: center; gap: 16px; }
.icon-btn { width: 38px; height: 38px; border-radius: 12px; border: 1px solid var(--border); background: #fff; display: flex; align-items: center; justify-content: center; color: var(--text-secondary); position: relative; }
.icon-btn svg { width: 17px; height: 17px; }
.header-date { font-size: 13px; color: var(--text-secondary); white-space: nowrap; }
.avatar { width: 38px; height: 38px; border-radius: 50%; background: linear-gradient(135deg, var(--primary-dark), var(--primary)); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 13px; }
.logout-link { font-size: 13px; color: var(--text-secondary); font-weight: 500; }

/* Hero */
.hero { background: linear-gradient(135deg, var(--primary-dark), var(--primary)); border-radius: var(--radius-card); padding: 30px 36px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 24px; color: #fff; position: relative; overflow: hidden; }
.hero::before, .hero::after { content: ""; position: absolute; border-radius: 50%; background: rgba(255,255,255,.08); }
.hero::before { width: 220px; height: 220px; top: -80px; right: 160px; }
.hero::after { width: 150px; height: 150px; bottom: -60px; right: -20px; }
.hero-greeting .eyebrow { font-size: 14px; opacity: .85; font-weight: 500; }
.hero-greeting h1 { font-size: 28px; font-weight: 700; margin: 4px 0 6px; }
.hero-greeting .date { font-size: 13.5px; opacity: .85; }
.hero-next { position: relative; z-index: 1; background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.20); border-radius: 16px; padding: 16px 20px; min-width: 240px; }
.hero-next .lab { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; opacity: .8; }
.hero-next .who { font-size: 18px; font-weight: 700; margin-top: 4px; }
.hero-next .meta { font-size: 12.5px; opacity: .85; margin-top: 2px; }
.hero-next .btn { margin-top: 12px; display: inline-flex; align-items: center; gap: 6px; background: #fff; color: var(--primary-dark); font-weight: 600; font-size: 13px; padding: 8px 14px; border-radius: 10px; border: none; }
.hero-next .btn svg { width: 13px; height: 13px; }
.hero-next.empty .who { font-size: 15px; font-weight: 600; opacity: .92; }

/* KPIs */
.grid-2 { display: grid; grid-template-columns: repeat(2, 1fr); gap: 18px; }
.kpi-card { background: var(--card); border-radius: var(--radius-card); padding: 20px 22px; box-shadow: var(--shadow-sm); border: 1px solid var(--border); transition: transform .25s ease, box-shadow .25s ease; }
.kpi-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-md); }
.kpi-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
.kpi-icon { width: 42px; height: 42px; border-radius: 12px; display: flex; align-items: center; justify-content: center; background: var(--primary-light); color: var(--primary); }
.kpi-icon svg { width: 20px; height: 20px; }
.kpi-value { font-size: 28px; font-weight: 700; margin-bottom: 2px; }
.kpi-label { font-size: 13px; color: var(--text-secondary); }

/* Sections */
.section-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px; }
.section-title { font-size: 18px; font-weight: 600; }
.section-sub { font-size: 12.5px; color: var(--text-muted); }
.card { background: var(--card); border-radius: var(--radius-card); border: 1px solid var(--border); box-shadow: var(--shadow-sm); padding: 22px 24px; }
.row-2 { display: grid; grid-template-columns: 1.45fr 1fr; gap: 20px; align-items: start; }
.stack { display: flex; flex-direction: column; gap: 20px; }

/* Queue */
.q-item { display: grid; grid-template-columns: 46px 1fr auto; gap: 14px; align-items: center; padding: 14px 6px; border-top: 1px solid var(--border); }
.q-item:first-of-type { border-top: none; }
.q-token { width: 46px; height: 46px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 15px; background: var(--primary-light); color: var(--primary-dark); }
.q-name { font-size: 14.5px; font-weight: 600; display: flex; align-items: center; gap: 8px; }
.q-tag { font-size: 10.5px; font-weight: 700; letter-spacing: .03em; padding: 1px 7px; border-radius: 6px; background: rgba(26,127,126,.12); color: var(--primary); }
.q-meta { font-size: 12.5px; color: var(--text-muted); margin-top: 2px; }
.q-right { display: flex; align-items: center; gap: 12px; }
.status-pill { font-size: 11.5px; font-weight: 600; padding: 4px 10px; border-radius: 20px; white-space: nowrap; }
.status-pill.waiting { background: #FFFBEB; color: #92400E; }
.status-pill.in-consult { background: #ECFDF5; color: #047857; }
.status-pill.done { background: #F1F5F9; color: var(--text-secondary); }
.wait-time { font-size: 12px; color: var(--text-muted); min-width: 54px; text-align: right; }
.btn-primary { background: var(--primary); color: #fff; border: none; font-weight: 600; font-size: 12.5px; padding: 8px 14px; border-radius: 10px; display: inline-flex; align-items: center; gap: 6px; transition: background .15s ease; }
.btn-primary:hover { background: var(--primary-dark); }
.btn-primary svg { width: 12px; height: 12px; }
.btn-ghost { background: transparent; border: 1px solid var(--border); color: var(--text-secondary); font-weight: 600; font-size: 12.5px; padding: 7px 13px; border-radius: 10px; }
.inline-form { display: inline; }
.empty-state { padding: 32px 10px; text-align: center; color: var(--text-muted); font-size: 13px; }

/* Now serving */
.serving { background: linear-gradient(160deg, #fff, #EAF5F4); border: 1px solid var(--border); }
.serving-head { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
.pulse { width: 10px; height: 10px; border-radius: 50%; background: var(--green); position: relative; }
.pulse::after { content: ""; position: absolute; inset: -4px; border-radius: 50%; border: 2px solid var(--green); animation: ring 1.8s ease-out infinite; }
@keyframes ring { 0% { transform: scale(.6); opacity: .8; } 100% { transform: scale(1.6); opacity: 0; } }
@media (prefers-reduced-motion: reduce) { .pulse::after { animation: none; } }
.serving-patient { display: flex; align-items: center; gap: 14px; }
.serving-avatar { width: 52px; height: 52px; border-radius: 14px; background: linear-gradient(135deg, var(--primary-dark), var(--primary)); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 18px; }
.serving-name { font-size: 17px; font-weight: 700; }
.serving-sub { font-size: 12.5px; color: var(--text-secondary); }
.serving-actions { display: flex; gap: 10px; margin-top: 18px; }
.serving-actions .btn-primary { flex: 1; justify-content: center; padding: 11px; font-size: 13px; }
.serving-actions .btn-ghost { flex: 1; text-align: center; }
.serving-empty { text-align: center; padding: 24px 10px; color: var(--text-muted); font-size: 13px; }

.mrn { font-family: 'Courier New', monospace; font-size: 12px; color: var(--text-secondary); }
.nag-banner { background: #FFFBEB; border: 1px solid #FDE68A; border-radius: 14px; padding: 14px 18px; display: flex; align-items: center; justify-content: space-between; gap: 12px; font-size: 13.5px; color: #92400E; }
.nag-banner a { font-weight: 700; text-decoration: underline; }
.flash { background: #ECFDF5; border: 1px solid #A7F3D0; border-radius: 14px; padding: 12px 18px; font-size: 13.5px; color: #047857; }
.build-notice { background: #F1F5F9; border: 1px dashed var(--border); border-radius: 14px; padding: 12px 18px; font-size: 12.5px; color: var(--text-secondary); }

@media (max-width: 1200px) { .grid-2 { grid-template-columns: 1fr; } .row-2 { grid-template-columns: 1fr; } }
@media (max-width: 900px) { .app { grid-template-columns: 1fr; } .sidebar { display: none; } .content { padding: 20px 18px 48px; } }
</style>
</head>
<body>
<div class="app">

    <aside class="sidebar">
        <div class="sidebar-brand"><div class="logo-mark">H</div> HIMS</div>

        <div class="nav-group">
            <div class="nav-group-label">Clinical</div>
            <a class="nav-item active" href="doctor.php"><span class="nav-icon"><?= icon('grid') ?></span> My Console</a>
            <a class="nav-item" href="doctor.php"><span class="nav-icon"><?= icon('users') ?></span> My Queue <?php if (count($waiting)): ?><span class="count"><?= count($waiting) ?></span><?php endif; ?></a>
            <a class="nav-item disabled" href="#"><span class="nav-icon"><?= icon('stetho') ?></span> Consultations</a>
            <a class="nav-item disabled" href="#"><span class="nav-icon"><?= icon('file') ?></span> Prescriptions</a>
        </div>

        <div class="nav-group">
            <div class="nav-group-label">Records</div>
            <a class="nav-item" href="patients.php"><span class="nav-icon"><?= icon('search') ?></span> Find Patient</a>
            <a class="nav-item disabled" href="#"><span class="nav-icon"><?= icon('calendar') ?></span> My Schedule</a>
        </div>

        <div class="nav-group">
            <div class="nav-group-label">Analytics</div>
            <a class="nav-item disabled" href="#"><span class="nav-icon"><?= icon('chart') ?></span> My Reports</a>
        </div>

        <div class="sidebar-foot">
            Signed in as <b><?= htmlspecialchars($user['name']) ?></b><br>Doctor
        </div>
    </aside>

    <div class="main">
        <header class="header">
            <div class="header-title">Doctor Console</div>
            <form class="search-box" method="GET" action="patients.php">
                <span class="icon"><?= icon('search') ?></span>
                <input type="text" name="q" placeholder="Search a patient by name, phone or MRN…">
            </form>
            <div class="header-right">
                <button class="icon-btn" type="button"><?= icon('bell', 17) ?></button>
                <span class="header-date tnum"><?= date('D, d M Y') ?></span>
                <div class="avatar"><?= htmlspecialchars(strtoupper(substr($user['name'], 0, 1))) ?></div>
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

            <?php if ($flash !== ''): ?>
            <div class="flash"><?= htmlspecialchars($flash) ?></div>
            <?php endif; ?>

            <div class="build-notice">
                Consultation notes and prescriptions aren't built yet — this console handles the live
                queue (Start &rarr; Finish) from today's registered visits. Note-writing lands in the next phase.
            </div>

            <!-- Hero + next patient -->
            <section class="hero">
                <div class="hero-greeting">
                    <div class="eyebrow"><?= $greeting ?></div>
                    <h1><?= htmlspecialchars($user['name']) ?></h1>
                    <div class="date"><?= date('l') ?>, <?= date('d F Y') ?></div>
                </div>
                <?php if ($next): $nAge = doc_age($next); ?>
                <div class="hero-next">
                    <div class="lab">Next in queue</div>
                    <div class="who"><?= htmlspecialchars($next['patient_name']) ?> · Token <?= (int) $next['token_no'] ?></div>
                    <div class="meta"><?= $nAge !== null ? 'Age ' . $nAge . ' · ' : '' ?><?= htmlspecialchars($next['type_label'] ?? 'Consultation') ?> · waiting <?= wait_minutes($next['created_at']) ?> min</div>
                    <?php if (!$current): ?>
                    <form method="POST" action="doctor.php">
                        <input type="hidden" name="action" value="start_consult">
                        <input type="hidden" name="visit_id" value="<?= (int) $next['visit_id'] ?>">
                        <button class="btn" type="submit"><?= icon('play', 13) ?> Call in next</button>
                    </form>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="hero-next empty">
                    <div class="lab">Queue</div>
                    <div class="who">No one waiting right now</div>
                    <div class="meta">New registrations for you will appear here.</div>
                </div>
                <?php endif; ?>
            </section>

            <!-- KPIs -->
            <div class="grid-2">
                <div class="kpi-card">
                    <div class="kpi-top"><div class="kpi-icon"><?= icon('users', 20) ?></div></div>
                    <div class="kpi-value tnum"><?= count($waiting) ?></div>
                    <div class="kpi-label">Waiting in my queue</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-top"><div class="kpi-icon"><?= icon('check', 20) ?></div></div>
                    <div class="kpi-value tnum"><?= $doneCount ?></div>
                    <div class="kpi-label">Seen today</div>
                </div>
            </div>

            <!-- Main row -->
            <div class="row-2">

                <!-- My Queue -->
                <div class="card">
                    <div class="section-head">
                        <div>
                            <div class="section-title">My Queue</div>
                            <div class="section-sub">Patients registered for you today, in token order</div>
                        </div>
                    </div>

                    <div style="margin-top:8px">
                        <?php if (empty($visits)): ?>
                            <div class="empty-state">No patients registered for you today yet.</div>
                        <?php else: foreach ($visits as $v): $age = doc_age($v); $st = $v['consult_status']; ?>
                        <div class="q-item">
                            <div class="q-token tnum"><?= (int) $v['token_no'] ?></div>
                            <div>
                                <div class="q-name"><?= htmlspecialchars($v['patient_name']) ?>
                                    <?php if (!empty($v['type_label'])): ?><span class="q-tag"><?= htmlspecialchars($v['type_label']) ?></span><?php endif; ?>
                                </div>
                                <div class="q-meta">
                                    <?= $age !== null ? 'Age ' . $age . ' · ' : '' ?>
                                    <?= htmlspecialchars(ucfirst(strtolower($v['gender']))) ?> ·
                                    <span class="mrn"><?= htmlspecialchars($v['mrn']) ?></span>
                                </div>
                            </div>
                            <div class="q-right">
                                <?php if ($st === 'WAITING'): ?>
                                    <span class="wait-time tnum"><?= wait_minutes($v['created_at']) ?> min</span>
                                    <span class="status-pill waiting">Waiting</span>
                                    <?php if (!$current): ?>
                                    <form class="inline-form" method="POST" action="doctor.php">
                                        <input type="hidden" name="action" value="start_consult">
                                        <input type="hidden" name="visit_id" value="<?= (int) $v['visit_id'] ?>">
                                        <button class="btn-primary" type="submit"><?= icon('play', 12) ?> Start</button>
                                    </form>
                                    <?php else: ?>
                                        <button class="btn-ghost" type="button" disabled title="Finish the current consultation first">Start</button>
                                    <?php endif; ?>
                                <?php elseif ($st === 'IN_CONSULT'): ?>
                                    <span class="status-pill in-consult">In consultation</span>
                                    <form class="inline-form" method="POST" action="doctor.php">
                                        <input type="hidden" name="action" value="finish_consult">
                                        <input type="hidden" name="visit_id" value="<?= (int) $v['visit_id'] ?>">
                                        <button class="btn-primary" type="submit"><?= icon('check', 12) ?> Finish</button>
                                    </form>
                                <?php else: ?>
                                    <span class="wait-time"></span>
                                    <span class="status-pill done">Done</span>
                                    <button class="btn-ghost" type="button" disabled>Notes</button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>

                <!-- Right column -->
                <div class="stack">
                    <div class="card serving">
                        <div class="serving-head">
                            <?php if ($current): ?><span class="pulse"></span><?php endif; ?>
                            <div class="section-title" style="font-size:15px">In consultation</div>
                        </div>
                        <?php if ($current): $cAge = doc_age($current); ?>
                        <div class="serving-patient">
                            <div class="serving-avatar"><?= htmlspecialchars(strtoupper(substr($current['patient_name'], 0, 1))) ?></div>
                            <div>
                                <div class="serving-name"><?= htmlspecialchars($current['patient_name']) ?> · Token <?= (int) $current['token_no'] ?></div>
                                <div class="serving-sub">
                                    <?= htmlspecialchars(ucfirst(strtolower($current['gender']))) ?>
                                    <?= $cAge !== null ? ' · Age ' . $cAge : '' ?>
                                    · <span class="mrn"><?= htmlspecialchars($current['mrn']) ?></span>
                                    <?= $current['started_at'] ? ' · started ' . date('g:i A', strtotime($current['started_at'])) : '' ?>
                                </div>
                            </div>
                        </div>
                        <div class="serving-actions">
                            <button class="btn-primary" type="button" disabled title="Note-writing lands in the next phase"><?= icon('pen', 12) ?> Write note</button>
                            <form class="inline-form" method="POST" action="doctor.php" style="flex:1;">
                                <input type="hidden" name="action" value="finish_consult">
                                <input type="hidden" name="visit_id" value="<?= (int) $current['visit_id'] ?>">
                                <button class="btn-ghost" type="submit" style="width:100%;">Finish consultation</button>
                            </form>
                        </div>
                        <?php else: ?>
                        <div class="serving-empty">No active consultation. Press <b>Start</b> on a waiting patient to begin.</div>
                        <?php endif; ?>
                    </div>

                    <div class="card">
                        <div class="section-title" style="font-size:16px">Today at a glance</div>
                        <div class="section-sub" style="margin-bottom:12px">Your visits for <?= date('d M') ?></div>
                        <div style="display:flex; flex-direction:column; gap:10px;">
                            <div style="display:flex; justify-content:space-between; font-size:13.5px;"><span style="color:var(--text-secondary)">Total registered</span><b class="tnum"><?= count($visits) ?></b></div>
                            <div style="display:flex; justify-content:space-between; font-size:13.5px;"><span style="color:var(--text-secondary)">Waiting</span><b class="tnum"><?= count($waiting) ?></b></div>
                            <div style="display:flex; justify-content:space-between; font-size:13.5px;"><span style="color:var(--text-secondary)">In consultation</span><b class="tnum"><?= count($inConsult) ?></b></div>
                            <div style="display:flex; justify-content:space-between; font-size:13.5px;"><span style="color:var(--text-secondary)">Completed</span><b class="tnum"><?= $doneCount ?></b></div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>
<script src="assets/js/date-picker.js"></script>
</body>
</html>
