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
           p.name AS patient_name, p.gender, p.dob, p.mrn,
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

// ---------------- This-month analytics snapshot ----------------
// Small always-on summary under the queue; the full picture (charts, tables,
// history) lives in doctor_analytics.php. All figures are BILLED revenue from
// paid bills under this doctor — not earnings (commission engine is Phase 3A).
require_once __DIR__ . '/config/billing.php';
$moStart = date('Y-m-01');
$moEnd = date('Y-m-t');

// Paid consultations split into full vs revisit, plus the per-tier mix.
$moC = $pdo->prepare("
    SELECT SUM(CASE WHEN v.consultation_fee_type = 'FULL' THEN b.grand_total ELSE 0 END) AS full_amt,
           SUM(CASE WHEN v.consultation_fee_type <> 'FULL' THEN b.grand_total ELSE 0 END) AS revisit_amt,
           SUM(CASE WHEN v.consultation_fee_type = 'FULL' THEN 1 ELSE 0 END) AS full_n,
           SUM(CASE WHEN v.consultation_fee_type = 'FREE_FOLLOWUP' THEN 1 ELSE 0 END) AS free_n,
           SUM(CASE WHEN v.consultation_fee_type = 'HALF_FOLLOWUP' THEN 1 ELSE 0 END) AS half_n,
           SUM(CASE WHEN v.consultation_fee_type = 'THREE_QUARTER_FOLLOWUP' THEN 1 ELSE 0 END) AS tq_n,
           COUNT(*) AS n
    FROM visits v
    JOIN bills b ON b.visit_id = v.id AND b.status = 'paid'
    WHERE v.doctor_id = ? AND v.visit_date BETWEEN ? AND ?
");
$moC->execute([$doctorId, $moStart, $moEnd]);
$mo = $moC->fetch() ?: [];
$moConsultN = (int) ($mo['n'] ?? 0);
$moRevisitN = (int) (($mo['free_n'] ?? 0) + ($mo['half_n'] ?? 0) + ($mo['tq_n'] ?? 0));
$moRevisitRate = $moConsultN > 0 ? $moRevisitN / $moConsultN * 100 : 0.0;

// ER admission money is deliberately EXCLUDED from the doctor's revenue figures
// (2026-07-23): it's clinic revenue, not the doctor's. The active-admissions
// cards below stay — they're operational (who's in the ward), not revenue.
$moTotal = (float) ($mo['full_amt'] ?? 0) + (float) ($mo['revisit_amt'] ?? 0);

// Currently-active admissions under this doctor (cards, with running charge).
$actQ = $pdo->prepare("
    SELECT a.id, a.admission_type, a.admitted_at,
           p.name AS patient_name, p.mrn,
           nu.name AS nurse_name,
           ar.rate_amount, ar.rate_basis,
           (SELECT h.status_at_handover FROM admission_handovers h
            WHERE h.admission_id = a.id ORDER BY h.handover_time DESC, h.id DESC LIMIT 1) AS last_status,
           (SELECT COALESCE(SUM(s.calculated_charge), 0) FROM admission_services s
            WHERE s.admission_id = a.id AND s.is_billable = 1) AS services_total
    FROM admissions a
    JOIN visits v ON v.id = a.visit_id
    JOIN patients p ON p.id = v.patient_id
    LEFT JOIN users nu ON nu.id = a.assigned_nurse_id
    LEFT JOIN admission_rates ar ON ar.admission_type = a.admission_type
    WHERE COALESCE(a.admitting_doctor_id, v.doctor_id) = ?
      AND a.status IN ('PENDING_ASSIGNMENT','ACTIVE','DISCHARGE_IN_PROGRESS')
    ORDER BY a.admitted_at DESC
");
$actQ->execute([$doctorId]);
$activeAdms = $actQ->fetchAll();
foreach ($activeAdms as &$aa) {
    $mins = max(0, (int) floor((time() - strtotime($aa['admitted_at'])) / 60));
    $aa['elapsed_min'] = $mins;
    if (($aa['rate_basis'] ?? 'HOURLY') === 'DAILY') {
        $aa['stay_charge'] = (float) $aa['rate_amount'] * max(1, (int) ceil($mins / 1440));
    } else {
        $aa['stay_charge'] = (float) $aa['rate_amount'] * admission_billed_hours($mins);
    }
    $aa['running_total'] = $aa['stay_charge'] + (float) $aa['services_total'];
}
unset($aa);

function doc_age(array $v): ?int {
    if (!empty($v['dob'])) {
        return (new DateTime($v['dob']))->diff(new DateTime())->y;
    }
    return null;
}

// Minutes a still-waiting patient has been in the queue (since their visit row was created).
function wait_minutes(string $createdAt): int {
    return max(0, (int) floor((time() - strtotime($createdAt)) / 60));
}

function icon(string $name, int $size = 18): string {
    $paths = [
        'search'  => '<circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>',
        'check'   => '<path d="M20 6L9 17l-5-5"/>',
        'bell'    => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
        'play'    => '<polygon points="5 3 19 12 5 21 5 3"/>',
        'pen'     => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
    ];
    $p = $paths[$name] ?? '';
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $p . '</svg>';
}

$pageTitle = 'Doctor Console';
$headExtra = <<<CSS
<style>
/* Doctor Console keeps its OWN clinical sidebar (different nav taxonomy from the
   shared admin/reception sidebar), so it does NOT use partials/sidebar.php.
   The sidebar itself (markup + CSS + drawer JS) now lives in
   partials/doctor_sidebar.php, shared with doctor_analytics.php.
   app.css provides the design tokens, reset, body/a/button, .app/.main/.content,
   .card, .btn* aliases and the base .status-pill — those are dropped here.
   What remains below is doctor-specific: the top header bar and this page's
   bespoke components (queue-first layout: header greeting, KPI grid, queue w/
   inline "now serving" row, right-rail month summaries). */
.tnum { font-variant-numeric: tabular-nums; }

/* Header — carries the greeting now (Option B: no hero banner below) */
.header { height: 64px; position: sticky; top: 0; z-index: 20; display: flex; align-items: center; justify-content: space-between; padding: 0 32px; background: rgba(255,255,255,.82); backdrop-filter: blur(18px); border-bottom: 1px solid var(--border); }
.header-greet .greet-line { font-size: 12px; color: var(--text-muted); }
.header-greet .greet-name { font-size: 15.5px; font-weight: 700; line-height: 1.25; white-space: nowrap; }
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

/* Compact KPI grid — dense 2x2 block in the right rail (Option B: replaces the
   old full-width row of four tall kpi-cards). The 1px background trick draws the
   inner dividers from the wrapper's background color. */
.kpi-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1px; background: var(--border); border: 1px solid var(--border); border-radius: var(--radius-card); overflow: hidden; box-shadow: var(--shadow-sm); }
.kpi-cell { background: var(--card); padding: 13px 16px; display: block; color: inherit; }
a.kpi-cell:hover { background: var(--primary-light); }
.kpi-value { font-size: 21px; font-weight: 700; line-height: 1.15; }
.kpi-value small { font-size: 11px; font-weight: 600; color: var(--text-muted); }
.kpi-label { font-size: 11.5px; color: var(--text-secondary); margin-top: 1px; }
.kpi-sub { font-size: 10.5px; color: var(--text-muted); margin-top: 1px; }

/* Sections */
.section-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px; }
/* app.css .section-title is 16px; this console wants the larger 18px heading, and
   its .section-sub carries no bottom margin (spacing handled per-block here). */
.section-title { font-size: 18px; font-weight: 600; }
.section-sub { font-size: 12.5px; color: var(--text-muted); }
.row-2 { display: grid; grid-template-columns: 1.6fr 1fr; gap: 18px; align-items: start; }
.stack { display: flex; flex-direction: column; gap: 18px; }

/* Queue — flush edge-to-edge rows; the card that hosts it drops its padding */
.q-card { padding: 0; overflow: hidden; }
.q-card .section-head { padding: 14px 20px 12px; border-bottom: 1px solid var(--border); margin-bottom: 0; }
.q-count-pills { display: flex; gap: 6px; }
/* section-head carries the divider below it, so the first row draws no border-top
   (.section-head + .q-item, not :first-of-type — the head is also a div sibling) */
.q-item { display: grid; grid-template-columns: 40px 1fr auto; gap: 13px; align-items: center; padding: 11px 20px; border-top: 1px solid var(--border); }
.section-head + .q-item { border-top: none; }
/* "Now serving" as a highlighted first row instead of a separate side panel */
.q-item.serving { background: linear-gradient(90deg, var(--primary-light), transparent); border-left: 3px solid var(--primary); padding-left: 17px; }
.q-item.serving .q-token { background: var(--primary); color: #fff; }
.q-token { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 14px; background: var(--primary-light); color: var(--primary-dark); }
.q-name { font-size: 14px; font-weight: 600; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
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

/* Live pulse dot next to the "now serving" row's pill */
.pulse { width: 9px; height: 9px; border-radius: 50%; background: var(--green); position: relative; flex: 0 0 auto; }
.pulse::after { content: ""; position: absolute; inset: -4px; border-radius: 50%; border: 2px solid var(--green); animation: ring 1.8s ease-out infinite; }
@keyframes ring { 0% { transform: scale(.6); opacity: .8; } 100% { transform: scale(1.6); opacity: 0; } }
@media (prefers-reduced-motion: reduce) { .pulse::after { animation: none; } }

.mrn { font-family: 'Courier New', monospace; font-size: 12px; color: var(--text-secondary); }
.nag-banner { background: #FFFBEB; border: 1px solid #FDE68A; border-radius: 14px; padding: 14px 18px; display: flex; align-items: center; justify-content: space-between; gap: 12px; font-size: 13.5px; color: #92400E; }
.nag-banner a { font-weight: 700; text-decoration: underline; }
.flash { background: #ECFDF5; border: 1px solid #A7F3D0; border-radius: 14px; padding: 12px 18px; font-size: 13.5px; color: #047857; }
.build-notice { border-top: 1px dashed var(--border); padding-top: 10px; font-size: 11.5px; color: var(--text-muted); }

/* This-month analytics — one-line rail rows (compact versions of doctor_analytics.php) */
.rail-title { font-size: 13.5px; font-weight: 700; display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 4px; }
.rev-row { display: grid; grid-template-columns: 108px 1fr 74px; gap: 10px; align-items: center; font-size: 12.5px; padding: 5px 0; }
.rev-row .amt { text-align: right; font-weight: 600; }
.bar-track { height: 7px; border-radius: 5px; background: var(--bg); overflow: hidden; }
.bar-fill { height: 100%; border-radius: 5px; }
.rev-total { display: flex; justify-content: space-between; border-top: 1px solid var(--border); margin-top: 8px; padding-top: 9px; font-weight: 700; font-size: 13px; }
.rv-row { display: grid; grid-template-columns: 1fr auto; gap: 12px; align-items: center; padding: 6px 0; border-top: 1px solid var(--border); font-size: 12.5px; }
.rv-row:first-of-type { border-top: none; }
.rv-note { font-size: 11px; color: var(--text-muted); }
.badge-count { background: var(--bg); border: 1px solid var(--border); border-radius: 9px; padding: 1px 10px; font-weight: 700; font-size: 12.5px; }
.adm { border-left: 3px solid var(--primary); border-radius: 14px; background: var(--card); border-top: 1px solid var(--border); border-right: 1px solid var(--border); border-bottom: 1px solid var(--border); padding: 14px 16px; }
.adm.stable { border-left-color: var(--green); }
.adm.critical { border-left-color: var(--red); }
.adm-name { font-size: 13.5px; font-weight: 700; }
.adm-meta { font-size: 12px; color: var(--text-muted); margin-top: 2px; }
.adm-foot { display: flex; justify-content: space-between; gap: 10px; flex-wrap: wrap; border-top: 1px solid var(--border); margin-top: 10px; padding-top: 8px; font-size: 12px; color: var(--text-muted); }
.adm-foot b { color: var(--text-secondary); font-weight: 600; }
.status-pill.green { background: #ECFDF5; color: #047857; }
.status-pill.amber { background: #FFFBEB; color: #92400E; }
.status-pill.red   { background: #FEE2E2; color: #B91C1C; }
.status-pill.teal  { background: var(--primary-light); color: var(--primary-dark); }
.card-link { font-size: 12.5px; font-weight: 600; color: var(--primary); }

@media (max-width: 1200px) { .row-2 { grid-template-columns: 1fr; } }
@media (max-width: 720px) {
    .header { height: auto; padding: 10px 16px; flex-wrap: wrap; gap: 8px; }
    .search-box { order: 3; margin: 0; max-width: none; flex-basis: 100%; }
    .header-date { display: none; }
    .q-item { grid-template-columns: 34px 1fr; }
    .q-item .q-right { grid-column: 2; justify-content: flex-start; margin-top: 4px; }
    .q-item .wait-time { min-width: 0; text-align: left; order: 3; margin-left: auto; }
}
</style>
CSS;
require __DIR__ . '/partials/head.php';
?>
<div class="app">

    <?php
    $dsActive = 'console';
    $dsUserName = $user['name'];
    $dsWaitingCount = count($waiting);
    require __DIR__ . '/partials/doctor_sidebar.php';
    ?>

    <div class="main">
        <header class="header">
            <div class="header-greet">
                <div class="greet-line"><?= $greeting ?>, <?= date('l, d/m') ?></div>
                <div class="greet-name"><?= htmlspecialchars($user['name']) ?></div>
            </div>
            <form class="search-box" method="GET" action="patients.php">
                <span class="icon"><?= icon('search') ?></span>
                <input type="text" name="q" placeholder="Search a patient by name, phone or MRN…">
            </form>
            <div class="header-right">
                <button class="icon-btn" type="button"><?= icon('bell', 17) ?></button>
                <span class="header-date tnum"><?= date('D, d/m/Y') ?></span>
                <a class="avatar" href="profile.php" title="My Profile" style="text-decoration:none;"><?= htmlspecialchars(strtoupper(substr($user['name'], 0, 1))) ?></a>
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

            <!-- Main row: queue-first (Option B). The queue owns the left column with the
                 active consultation as a highlighted first row; stats live in the right rail. -->
            <div class="row-2">

                <!-- My Queue -->
                <div class="card q-card">
                    <div class="section-head">
                        <div>
                            <div class="section-title">My Queue</div>
                            <div class="section-sub">Patients registered for you today, in token order</div>
                        </div>
                        <div class="q-count-pills">
                            <?php if ($current): ?><span class="status-pill in-consult">1 in consult</span><?php endif; ?>
                            <span class="status-pill waiting"><?= count($waiting) ?> waiting</span>
                        </div>
                    </div>

                    <?php if (empty($visits)): ?>
                        <div class="empty-state">No patients registered for you today yet.<br>New registrations for you will appear here.</div>
                    <?php else: foreach ($visits as $v): $age = doc_age($v); $st = $v['consult_status']; ?>
                    <div class="q-item<?= $st === 'IN_CONSULT' ? ' serving' : '' ?>">
                        <div class="q-token tnum"><?= (int) $v['token_no'] ?></div>
                        <div>
                            <div class="q-name"><?= htmlspecialchars($v['patient_name']) ?>
                                <?php if ($st === 'IN_CONSULT'): ?><span class="pulse"></span><span class="status-pill in-consult">Now serving</span><?php endif; ?>
                                <?php if (!empty($v['type_label'])): ?><span class="q-tag"><?= htmlspecialchars($v['type_label']) ?></span><?php endif; ?>
                            </div>
                            <div class="q-meta">
                                <?= $age !== null ? 'Age ' . $age . ' · ' : '' ?>
                                <?= htmlspecialchars(ucfirst(strtolower($v['gender']))) ?> ·
                                <span class="mrn"><?= htmlspecialchars($v['mrn']) ?></span>
                                <?php if ($st === 'IN_CONSULT' && $v['started_at']): ?> · started <?= date('g:i A', strtotime($v['started_at'])) ?><?php endif; ?>
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
                                <button class="btn-ghost" type="button" disabled title="Note-writing lands in the next phase"><?= icon('pen', 12) ?> Note</button>
                                <form class="inline-form" method="POST" action="doctor.php">
                                    <input type="hidden" name="action" value="finish_consult">
                                    <input type="hidden" name="visit_id" value="<?= (int) $v['visit_id'] ?>">
                                    <button class="btn-primary" type="submit"><?= icon('check', 12) ?> Finish</button>
                                </form>
                            <?php else: ?>
                                <span class="status-pill done">Done</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>
                </div>

                <!-- Right rail: dense stats + month summaries -->
                <?php
                $moFull = (float) ($mo['full_amt'] ?? 0);
                $moRev = (float) ($mo['revisit_amt'] ?? 0);
                $moMax = max(1.0, $moFull, $moRev);
                ?>
                <div class="stack">
                    <div class="kpi-grid">
                        <div class="kpi-cell">
                            <div class="kpi-value tnum"><?= count($waiting) ?></div>
                            <div class="kpi-label">Waiting</div>
                        </div>
                        <div class="kpi-cell">
                            <div class="kpi-value tnum"><?= $doneCount ?></div>
                            <div class="kpi-label">Seen today</div>
                        </div>
                        <a class="kpi-cell" href="doctor_analytics.php?view=revisits">
                            <div class="kpi-value tnum"><?= number_format($moRevisitRate, 1) ?>%</div>
                            <div class="kpi-label">Revisit rate — <?= date('M') ?></div>
                            <div class="kpi-sub tnum"><?= $moRevisitN ?> of <?= $moConsultN ?> paid consults</div>
                        </a>
                        <a class="kpi-cell" href="doctor_analytics.php?view=revenue">
                            <div class="kpi-value tnum"><?= number_format($moTotal) ?> <small>PKR</small></div>
                            <div class="kpi-label">Billed — <?= date('M') ?></div>
                            <div class="kpi-sub">Paid consultations</div>
                        </a>
                    </div>

                    <div class="card">
                        <div class="rail-title"><span>Revenue — <?= date('F') ?></span><a class="card-link" href="doctor_analytics.php?view=revenue">Full chart &rarr;</a></div>
                        <div class="rev-row">
                            <span>Full consults</span>
                            <div class="bar-track"><div class="bar-fill" style="width:<?= round($moFull / $moMax * 100) ?>%;background:var(--primary)"></div></div>
                            <span class="amt tnum"><?= number_format($moFull) ?></span>
                        </div>
                        <div class="rev-row">
                            <span>Revisits</span>
                            <div class="bar-track"><div class="bar-fill" style="width:<?= round($moRev / $moMax * 100) ?>%;background:#0891B2"></div></div>
                            <span class="amt tnum"><?= number_format($moRev) ?></span>
                        </div>
                        <div class="rev-total"><span>Total billed</span><span class="tnum"><?= number_format($moTotal) ?> PKR</span></div>
                    </div>

                    <div class="card">
                        <div class="rail-title"><span>Revisit mix — <?= date('F') ?></span><a class="card-link" href="doctor_analytics.php?view=revisits">Breakdown &rarr;</a></div>
                        <div class="rv-row">
                            <div>Free follow-ups <div class="rv-note">1st within 5 days</div></div>
                            <span class="badge-count tnum"><?= (int) ($mo['free_n'] ?? 0) ?></span>
                        </div>
                        <div class="rv-row">
                            <div>50% follow-ups <div class="rv-note">2nd+ within 5 days</div></div>
                            <span class="badge-count tnum"><?= (int) ($mo['half_n'] ?? 0) ?></span>
                        </div>
                        <div class="rv-row">
                            <div>75% follow-ups <div class="rv-note">Day 6–15</div></div>
                            <span class="badge-count tnum"><?= (int) ($mo['tq_n'] ?? 0) ?></span>
                        </div>
                        <div class="rev-total" style="font-size:12.5px"><span>Revisit rate</span><span class="tnum"><?= number_format($moRevisitRate, 1) ?>% (<?= $moRevisitN ?> / <?= $moConsultN ?>)</span></div>
                        <div class="build-notice" style="margin-top:10px">
                            Consultation notes &amp; prescriptions land in the next phase — this console runs the live Start &rarr; Finish queue.
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($activeAdms): ?>
            <div class="card">
                <div class="section-head">
                    <div>
                        <div class="section-title">Active ER admissions (<?= count($activeAdms) ?>)</div>
                        <div class="section-sub">Patients you admitted who are still in the ward</div>
                    </div>
                    <a class="card-link" href="doctor_analytics.php?view=admissions">History &rarr;</a>
                </div>
                <div style="display:flex;flex-direction:column;gap:12px;margin-top:12px">
                    <?php
                    $admTypeLabel = ['ROUTINE' => 'ER Routine', 'PRIVATE' => 'ER Private', 'LONG_PRIVATE' => 'Long Private'];
                    foreach ($activeAdms as $aa):
                        $st = $aa['last_status'] ?? 'ACTIVE';
                        $cls = $st === 'STABLE' ? 'stable' : ($st === 'CRITICAL' ? 'critical' : '');
                        $pill = $st === 'STABLE' ? 'green' : ($st === 'CRITICAL' ? 'red' : 'teal');
                        $eh = intdiv($aa['elapsed_min'], 60); $em = $aa['elapsed_min'] % 60;
                    ?>
                    <div class="adm <?= $cls ?>">
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
                            <div>
                                <div class="adm-name"><?= htmlspecialchars($aa['patient_name']) ?> <span class="mrn">MRN <?= htmlspecialchars($aa['mrn']) ?></span></div>
                                <div class="adm-meta">
                                    <?= $admTypeLabel[$aa['admission_type']] ?? $aa['admission_type'] ?>
                                    · admitted <?= date('g:i A', strtotime($aa['admitted_at'])) ?>
                                    · <b class="tnum"><?= $eh > 0 ? "{$eh}h {$em}m" : "{$em}m" ?></b> elapsed
                                </div>
                            </div>
                            <span class="status-pill <?= $pill ?>"><?= ucfirst(strtolower($st)) ?></span>
                        </div>
                        <div class="adm-foot">
                            <span>Nurse: <b><?= htmlspecialchars($aa['nurse_name'] ?? 'Unassigned') ?></b></span>
                            <span>Running total: <b class="tnum"><?= number_format($aa['running_total']) ?> PKR</b></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>
<script src="assets/js/date-picker.js"></script>
</body>
</html>
