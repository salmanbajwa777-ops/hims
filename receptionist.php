<?php
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
require_once __DIR__ . '/config/notify.php';
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

// ---------------- Admit a patient (start a short-stay admission) ----------------
// The doctor advises admission; reception starts it from the queue. Creates the
// admission record (clock starts now) and flags the visit SHORT_STAY. The
// admission bill is raised later, at discharge (separate document).
$admitError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'admit_patient') {
    if (!has_permission('RECEPTION_ADMIT_PATIENTS')) {
        http_response_code(403);
        exit('Forbidden — you cannot admit patients.');
    }
    $visitId = (int) ($_POST['visit_id'] ?? 0);
    $admType = $_POST['admission_type'] ?? '';
    $docId   = (int) ($_POST['admitting_doctor_id'] ?? 0) ?: null;
    $docManual = trim($_POST['admitting_doctor_manual'] ?? '') ?: null;

    // Validate the type is one that's currently enabled.
    $rateOk = $pdo->prepare('SELECT 1 FROM admission_rates WHERE admission_type = ? AND is_enabled = 1');
    $rateOk->execute([$admType]);

    if ($visitId <= 0 || !$rateOk->fetchColumn()) {
        $admitError = 'Pick a valid, enabled admission type.';
    } else {
        // Guard: one admission per visit (admissions.visit_id is UNIQUE).
        $exists = $pdo->prepare('SELECT 1 FROM admissions WHERE visit_id = ?');
        $exists->execute([$visitId]);
        if ($exists->fetchColumn()) {
            $admitError = 'This visit is already admitted.';
        } else {
            $pdo->beginTransaction();
            try {
                $ins = $pdo->prepare('
                    INSERT INTO admissions
                        (visit_id, admission_type, admitted_by_id, admitted_by_role, admitted_at,
                         admitting_doctor_id, admitting_doctor_manual, status)
                    VALUES (?, ?, ?, ?, NOW(), ?, ?, \'PENDING_ASSIGNMENT\')
                ');
                $ins->execute([
                    $visitId, $admType, $_SESSION['user_id'], $_SESSION['base_role'],
                    $docId, $docId ? null : $docManual,
                ]);
                $admissionId = (int) $pdo->lastInsertId();

                $pdo->prepare('UPDATE visits SET disposition = \'SHORT_STAY\', admission_type = ?, admitted_at = NOW() WHERE id = ?')
                    ->execute([$admType, $visitId]);

                $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
                    ->execute([$_SESSION['user_id'], 'patient_admitted', "Admitted visit #$visitId ($admType), admission #$admissionId"]);

                $pdo->commit();

                // Alert admin + admitting doctor (best-effort, after commit).
                notify_patient_admitted($pdo, $admissionId);

                header('Location: receptionist.php?admitted=1');
                exit;
            } catch (Throwable $e) {
                $pdo->rollBack();
                $admitError = 'Could not admit — please try again.';
            }
        }
    }
}

// Enabled admission types (for the dialog) + doctors (for the admitting-doctor picker).
$admTypes = $pdo->query('SELECT admission_type, rate_amount, rate_basis FROM admission_rates WHERE is_enabled = 1 ORDER BY FIELD(admission_type,"ROUTINE","PRIVATE","LONG_PRIVATE")')->fetchAll();
$admDoctors = $pdo->query("SELECT id, name FROM users WHERE base_role = 'DOCTOR' ORDER BY name")->fetchAll();
$admTypeLabels = ['ROUTINE' => 'Routine', 'PRIVATE' => 'Private Room', 'LONG_PRIVATE' => 'Long Private'];

$mustChangePassword = (bool) $user['must_change_password'];
$firstName = explode(' ', trim($user['name']))[0] ?? 'there';
$qhActive = 'today';
$qhBrand = false; // the sidebar already carries the HIMS mark
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
           v.started_at, v.finished_at, v.doctor_id,
           p.id AS patient_id, p.mrn, p.name AS patient_name, p.dob, p.phone,
           dr.name AS doctor_name,
           adm.id AS admission_id, adm.status AS admission_status,
           dct.label AS consult_label,
           b.id AS bill_id, b.grand_total, b.paid_amount, b.status AS bill_status,
           COALESCE(r.refunded, 0) AS refunded
    FROM visits v
    JOIN patients p ON p.id = v.patient_id
    JOIN users dr ON dr.id = v.doctor_id
    JOIN doctor_consult_types dct ON dct.id = v.doctor_consult_type_id
    LEFT JOIN bills b ON b.visit_id = v.id
    LEFT JOIN admissions adm ON adm.visit_id = v.id
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

$pageTitle = 'Reception Desk';
$headExtra = <<<CSS
<style>
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

/* ---------- Admit dialog ---------- */
.admit-overlay { display: none; position: fixed; inset: 0; background: rgba(15,23,42,.45); z-index: 60; align-items: center; justify-content: center; padding: 20px; }
.admit-overlay.open { display: flex; }
.admit-modal { background: var(--card); border-radius: var(--radius-card); width: 100%; max-width: 440px; box-shadow: var(--shadow-lg); overflow: hidden; }
.admit-head { display: flex; align-items: flex-start; justify-content: space-between; padding: 20px 22px 6px; }
.admit-eyebrow { font-size: 11px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: var(--text-muted); }
.admit-name { font-size: 18px; font-weight: 700; margin-top: 2px; }
.admit-x { background: none; border: none; font-size: 24px; line-height: 1; color: var(--text-muted); cursor: pointer; }
.admit-body { padding: 10px 22px 4px; display: flex; flex-direction: column; gap: 18px; }
.admit-field label { display: block; font-size: 12.5px; font-weight: 600; color: var(--text-secondary); margin-bottom: 8px; }
.type-opts { display: flex; flex-direction: column; gap: 8px; }
.type-opt { display: flex; align-items: center; gap: 10px; border: 1px solid var(--border); border-radius: 12px; padding: 10px 12px; cursor: pointer; }
.type-opt:has(input:checked) { border-color: var(--primary); background: var(--primary-light); }
.type-opt input { accent-color: var(--primary); }
.type-body { display: flex; justify-content: space-between; flex: 1; align-items: baseline; }
.type-name { font-weight: 600; font-size: 13.5px; }
.type-rate { font-size: 12.5px; color: var(--text-muted); font-variant-numeric: tabular-nums; }
.admit-field select, .admit-field input[type=text] { width: 100%; padding: 10px 12px; border: 1px solid var(--border); border-radius: var(--radius-input); font: inherit; font-size: 13.5px; background: var(--bg); color: var(--text); }
.admit-field select:focus, .admit-field input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,127,126,.15); background: #fff; }
.admit-foot { display: flex; justify-content: flex-end; gap: 10px; padding: 18px 22px 22px; }

/* ---------- Password nag ---------- */
.nag-banner {
    background: #FFFBEB; border: 1px solid #FDE68A; border-radius: 14px;
    padding: 14px 18px; display: flex; align-items: center; justify-content: space-between; gap: 12px;
    font-size: 13.5px; color: #92400E;
}
.nag-banner a { font-weight: 700; text-decoration: underline; }

/* ---------- Build notice ---------- */
@media (max-width: 1200px) {
    .grid-4 { grid-template-columns: repeat(2, 1fr); }
    .row-2 { grid-template-columns: 1fr; }
}
</style>
CSS;
require __DIR__ . '/partials/head.php';
$navActive = 'dashboard';
require __DIR__ . '/partials/sidebar.php';
?>
        <?php require __DIR__ . '/partials/quick_header.php'; ?>

        <div class="content">

            <?php if ($admitError): ?><div class="alert error"><?= htmlspecialchars($admitError) ?></div><?php endif; ?>
            <?php if (isset($_GET['admitted'])): ?><div class="alert success">Patient admitted — stay is now open.</div><?php endif; ?>

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
                                <?php if ($isAdmitted): ?>
                                    <?php if ($row['admission_status'] === 'DISCHARGE_IN_PROGRESS'): ?>
                                        <span class="status-pill wait">Awaiting billing</span>
                                    <?php elseif ($row['admission_status'] === 'DISCHARGED'): ?>
                                        <span class="status-pill done">Discharged</span>
                                    <?php else: ?>
                                        <span class="status-pill stay">Admitted</span>
                                    <?php endif; ?>
                                <?php endif; ?>
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
                                    <?php if ($isAdmitted && $row['admission_id'] && $row['admission_status'] === 'DISCHARGE_IN_PROGRESS'): ?>
                                        <a class="qa warn" href="admission_discharge.php?id=<?= (int) $row['admission_id'] ?>">Bill discharge</a>
                                    <?php elseif ($isAdmitted && $row['admission_id']): ?>
                                        <a class="qa" href="admission.php?id=<?= (int) $row['admission_id'] ?>">Manage stay</a>
                                    <?php elseif (has_permission('RECEPTION_ADMIT_PATIENTS')): ?>
                                        <button type="button" class="qa"
                                            onclick="openAdmit(<?= (int) $row['visit_id'] ?>, <?= htmlspecialchars(json_encode($row['patient_name']), ENT_QUOTES) ?>, <?= (int) $row['doctor_id'] ?>, <?= htmlspecialchars(json_encode($row['doctor_name']), ENT_QUOTES) ?>)">Admit</button>
                                    <?php endif; ?>
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

<!-- Admit dialog -->
<div class="admit-overlay" id="admitOverlay" onclick="if(event.target===this)closeAdmit()">
    <div class="admit-modal" role="dialog" aria-modal="true" aria-labelledby="admitTitle">
        <form method="POST" action="receptionist.php">
            <input type="hidden" name="action" value="admit_patient">
            <input type="hidden" name="visit_id" id="admitVisitId">
            <div class="admit-head">
                <div>
                    <div class="admit-eyebrow">Admit patient</div>
                    <div class="admit-name" id="admitTitle">—</div>
                </div>
                <button type="button" class="admit-x" onclick="closeAdmit()" aria-label="Close">&times;</button>
            </div>

            <div class="admit-body">
                <div class="admit-field">
                    <label>Admission type</label>
                    <div class="type-opts">
                        <?php foreach ($admTypes as $i => $t): ?>
                        <label class="type-opt">
                            <input type="radio" name="admission_type" value="<?= htmlspecialchars($t['admission_type']) ?>" <?= $i === 0 ? 'checked' : '' ?>>
                            <span class="type-body">
                                <span class="type-name"><?= htmlspecialchars($admTypeLabels[$t['admission_type']] ?? $t['admission_type']) ?></span>
                                <span class="type-rate">Rs <?= number_format((float) $t['rate_amount']) ?>/<?= $t['rate_basis'] === 'DAILY' ? 'day' : 'hr' ?></span>
                            </span>
                        </label>
                        <?php endforeach; ?>
                        <?php if (!$admTypes): ?>
                        <div class="muted">No admission types are enabled. Set them under ER Services &amp; Rates.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="admit-field">
                    <label>Admitting doctor</label>
                    <select name="admitting_doctor_id" id="admitDoctor">
                        <option value="">— manual entry below —</option>
                        <?php foreach ($admDoctors as $d): ?>
                        <option value="<?= (int) $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="admitting_doctor_manual" id="admitDoctorManual" placeholder="Or type the doctor's name" style="margin-top:8px;">
                </div>
            </div>

            <div class="admit-foot">
                <button type="button" class="btn secondary" onclick="closeAdmit()">Cancel</button>
                <button type="submit" class="btn" <?= $admTypes ? '' : 'disabled' ?>>Admit &amp; start stay</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAdmit(visitId, patientName, doctorId, doctorName) {
    document.getElementById('admitVisitId').value = visitId;
    document.getElementById('admitTitle').textContent = patientName || 'Patient';
    // Preselect the visit's doctor as the admitting doctor when it's a system user.
    var sel = document.getElementById('admitDoctor');
    if (doctorId && sel.querySelector('option[value="' + doctorId + '"]')) {
        sel.value = String(doctorId);
        document.getElementById('admitDoctorManual').value = '';
    }
    document.getElementById('admitOverlay').classList.add('open');
}
function closeAdmit() { document.getElementById('admitOverlay').classList.remove('open'); }
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeAdmit(); });
</script>
<script src="assets/js/date-picker.js"></script>
</body>
</html>
