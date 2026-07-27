<?php
// TEMPORARY DIAGNOSTIC — remove once the 500 on this page is identified.
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
require_once __DIR__ . '/config/notify.php';
require_once __DIR__ . '/config/billing.php';
require_once __DIR__ . '/config/tokens.php';
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
// admission record (clock starts now) and flags the visit SHORT_STAY. The admission
// bill is raised later, at discharge (separate document). The actual logic lives in
// the shared handler so reception, doctor console, and the all-patients list all admit
// through one code path (config/admission_actions.php).
require_once __DIR__ . '/config/admission_actions.php';
$admitError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'admit_patient') {
    $result = handle_admit_patient($pdo);
    if ($result['ok']) {
        header('Location: receptionist.php?admitted=1');
        exit;
    }
    $admitError = $result['error'];
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

// Straggler reminder: this receptionist's unclosed business days (incl. today) in
// the last 5 that had money movement. Only for drawer holders; tolerant of any DB
// hiccup so the landing page never breaks over a reminder.
$myUnclosedDays = [];
try {
    if (has_permission('RECEPTION_CLOSE_DAY') && user_holds_drawer($pdo, (int) $_SESSION['user_id'])) {
        $myUnclosedDays = unclosed_business_days($pdo, (int) $_SESSION['user_id'], 5, true);
    }
} catch (Throwable $e) {
    $myUnclosedDays = [];
}

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
    SELECT v.id AS visit_id, v.token_no, v.token_session, v.consult_status, v.disposition, v.created_at,
           v.started_at, v.finished_at, v.doctor_id,
           p.id AS patient_id, p.mrn, p.name AS patient_name, p.dob, p.phone,
           dr.name AS doctor_name, dr.token_prefix AS doctor_token_prefix,
           adm.id AS admission_id, adm.status AS admission_status,
           dct.label AS consult_label,
           b.id AS bill_id, b.grand_total, b.paid_amount, b.status AS bill_status,
           COALESCE(r.refunded, 0) AS refunded,
           -- The stay is billed SEPARATELY (the \"A\" series, admission_bills) and the
           -- consultation bill is often Rs 0 for a straight-to-admission patient,
           -- so the row must carry both or the money column reads a false zero.
           ab.id AS adm_bill_id, ab.status AS adm_bill_status,
           COALESCE(ab.paid_amount, 0) AS adm_paid_amount
    FROM visits v
    JOIN patients p ON p.id = v.patient_id
    JOIN users dr ON dr.id = v.doctor_id
    JOIN doctor_consult_types dct ON dct.id = v.doctor_consult_type_id
    LEFT JOIN bills b ON b.visit_id = v.id AND b.voided_at IS NULL
    LEFT JOIN admissions adm ON adm.visit_id = v.id
    LEFT JOIN admission_bills ab ON ab.admission_id = adm.id AND ab.voided_at IS NULL
    LEFT JOIN (
        SELECT bill_id, SUM(amount) AS refunded FROM refunds WHERE voided_at IS NULL GROUP BY bill_id
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
    // Consultation + stay. Admission revenue was missing entirely, so the
    // day's collected-cash card under-reported by every discharge bill.
    $grossCollected += (float) $row['paid_amount'] + (float) $row['adm_paid_amount'];
    $totalRefunded += (float) $row['refunded'];
}
$netCollected = $grossCollected - $totalRefunded;

// Bookings still to walk in today: phone appointments taken for CURDATE() that
// haven't been consumed (arrived), cancelled or swept as no-show. This is the
// number reception actually chases through the day. try/catch so an un-migrated
// bookings table degrades to zero rather than 500ing the whole console.
$pendingBookings = 0;
try {
    $pendingBookings = (int) $pdo->query("
        SELECT COUNT(*) FROM bookings
        WHERE booking_date = CURDATE() AND status = 'BOOKED'
    ")->fetchColumn();
} catch (Throwable $e) {
    // bookings not set up yet — leave at zero.
}

$stats = [
    ['label' => 'Pending Bookings', 'value' => (string) $pendingBookings, 'icon' => 'calendar', 'href' => 'bookings.php'],
    ['label' => 'Waiting', 'value' => (string) $countWaiting, 'icon' => 'users'],
    ['label' => 'In Consult', 'value' => (string) $countInConsult, 'icon' => 'stethoscope'],
    ['label' => 'Collected (net)', 'value' => 'Rs ' . number_format($netCollected), 'icon' => 'dollar-sign'],
];

// ---------------- Today's doctor timings (shift-start popup) ----------------
// First thing a receptionist must do on shift is confirm the doctors' timings
// for the day. This popup shows the current confirmed sheet automatically ONCE
// per login session (flag below); edits live on doctor_timings.php, and the
// next shift sees whatever was last saved there.
// Wrapped in try/catch so the console still loads if the migration
// (sql/add_doctor_day_timings.sql) hasn't been run yet.
$docTimings = [];
$timingsLastTouch = null;
try {
    $tStmt = $pdo->prepare("
        SELECT u.name, t.start_time, t.end_time, t.start_time_2, t.end_time_2,
               t.status, t.note, t.updated_at,
               ub.name AS updated_by_name
        FROM users u
        LEFT JOIN doctor_day_timings t ON t.doctor_id = u.id AND t.timing_date = CURDATE()
        LEFT JOIN users ub ON ub.id = t.updated_by
        WHERE u.base_role = 'DOCTOR'
        ORDER BY (t.status <=> 'OFF'), u.name
    ");
    $tStmt->execute();
    $docTimings = $tStmt->fetchAll();
    foreach ($docTimings as $t) {
        if ($t['updated_at'] && (!$timingsLastTouch || $t['updated_at'] > $timingsLastTouch['at'])) {
            $timingsLastTouch = ['at' => $t['updated_at'], 'by' => $t['updated_by_name']];
        }
    }
} catch (Throwable $e) {
    // Table missing — feature silently dormant until the migration runs.
}

// Auto-open once per login session, not on every visit to this page.
$showTimingsPopup = !empty($docTimings) && empty($_SESSION['timings_popup_shown']);
if ($showTimingsPopup) {
    $_SESSION['timings_popup_shown'] = 1;
}

// ---------------- Today's bookings (shift-start popup B-side + panel) ----------------
// Phone appointments still expected today. Shown once per session as a popup
// (sequenced AFTER the timings popup — never stacked) and always as a panel.
// try/catch so the console loads if sql/add_bookings.sql hasn't been run yet.
$todayBookings = [];
try {
    $todayBookings = $pdo->query("
        SELECT bk.id, bk.person_name, bk.phone, bk.preferred_time, bk.note, bk.status,
               bk.patient_id, p.name AS patient_name, p.mrn,
               du.name AS doctor_name, dct.label AS purpose
        FROM bookings bk
        JOIN users du ON du.id = bk.doctor_id
        JOIN doctor_consult_types dct ON dct.id = bk.doctor_consult_type_id
        LEFT JOIN patients p ON p.id = bk.patient_id
        WHERE bk.booking_date = CURDATE() AND bk.status IN ('BOOKED', 'ARRIVED')
        ORDER BY (bk.status = 'ARRIVED'), du.name, bk.created_at
    ")->fetchAll();
} catch (Throwable $e) {
    // Table missing — feature silently dormant until the migration runs.
}
$openBookings = array_values(array_filter($todayBookings, fn ($b) => $b['status'] === 'BOOKED'));

// Auto-open once per session, only when something is still expected. When the
// timings popup also fires this session, this one queues behind it (JS below).
$showBookingsPopup = !empty($openBookings) && empty($_SESSION['bookings_popup_shown']);
if ($showBookingsPopup) {
    $_SESSION['bookings_popup_shown'] = 1;
}

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
/* ============================================================================
   receptionist.php — page-specific styles ONLY.
   ----------------------------------------------------------------------------
   Sage & Clay cleanup (2026-07-26). Removed from this block:
     - the gradient .hero (the name is already in the app-bar avatar, the date
       is already in the app bar)
     - duplicate .card / .section-title / table / th / td declarations that
       assets/app.css already owns, with slightly different values
     - this page's PRIVATE .status-pill dialect (#FFFBEB/#ECFDF5/#EDE7FB —
       different hexes for the same states as app.css). Everything now uses
       the single .pill primitive.
     - the whole .queue-table / .qa / left-severity-stripe system, replaced by
       the .queue / .qrow / .qbtn grid in app.css.
   ============================================================================ */

/* ---------- Stat cards ---------- */
.grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; }
.kpi-card {
    background: var(--card); border-radius: var(--radius-card); padding: 14px 16px;
    box-shadow: var(--shadow-sm); border: 1px solid var(--border);
    display: flex; align-items: center; gap: 13px; text-decoration: none; color: inherit;
    transition: border-color .15s ease;
}
a.kpi-card:hover { border-color: var(--primary-accent); }
.kpi-icon {
    width: 38px; height: 38px; border-radius: var(--radius-btn); flex: none;
    display: flex; align-items: center; justify-content: center;
    background: var(--primary-light); color: var(--primary);
}
.kpi-icon svg { width: 18px; height: 18px; }
.kpi-body { min-width: 0; }
.kpi-value { font-size: var(--fs-kpi); font-weight: 700; line-height: 1.1; color: var(--text); }
.kpi-label { font-size: var(--fs-micro); color: var(--text-secondary); margin-top: 2px; }

/* ---------- Queue row extras (base grid lives in app.css) ---------- */
.wa-link { display: inline-flex; align-items: center; gap: 4px; color: inherit; text-decoration: none; vertical-align: middle; }
.wa-link svg { width: 13px; height: 13px; color: #25D366; flex-shrink: 0; }
.wa-link:hover { color: #128C7E; text-decoration: underline; }
.mono { font-variant-numeric: tabular-nums; }

/* ---------- Doctor schedule ---------- */
.sched-list { display: flex; flex-direction: column; gap: 4px; }
.sched-item { display: flex; align-items: center; gap: 12px; padding: 12px 4px; border-bottom: 1px solid var(--row-line); }
.sched-item:last-child { border-bottom: none; }
.doc-avatar {
    width: 34px; height: 34px; border-radius: 50%; background: var(--primary-accent);
    color: var(--on-primary); display: flex; align-items: center; justify-content: center;
    font-size: var(--fs-micro); font-weight: 700; flex-shrink: 0;
}
.sched-text { flex: 1; min-width: 0; }
.sched-name { font-size: var(--fs-cell); font-weight: 600; }
.sched-time { font-size: var(--fs-micro); color: var(--text-muted); }

/* ---------- Admit dialog ---------- */
.admit-overlay { display: none; position: fixed; inset: 0; background: rgba(22,33,28,.48); z-index: 60; align-items: center; justify-content: center; padding: 20px; }
.admit-overlay.open { display: flex; }
.admit-modal { background: var(--card); border-radius: var(--radius-card); width: 100%; max-width: 440px; box-shadow: var(--shadow-lg); overflow: hidden; }
.admit-head { display: flex; align-items: flex-start; justify-content: space-between; padding: 20px 22px 6px; }
.admit-eyebrow { font-size: var(--fs-eyebrow); font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: var(--text-muted); }
.admit-name { font-size: var(--fs-page); font-weight: 700; margin-top: 2px; }
.admit-x { background: none; border: none; font-size: 24px; line-height: 1; color: var(--text-muted); cursor: pointer; }
.admit-body { padding: 10px 22px 4px; display: flex; flex-direction: column; gap: 18px; }
.admit-field label { display: block; font-size: var(--fs-micro); font-weight: 600; color: var(--text-secondary); margin-bottom: 8px; }
.type-opts { display: flex; flex-direction: column; gap: 8px; }
.type-opt { display: flex; align-items: center; gap: 10px; border: 1px solid var(--border-strong); border-radius: var(--radius-input); padding: 10px 12px; cursor: pointer; }
.type-opt:has(input:checked) { border-color: var(--primary-accent); background: var(--primary-light); }
.type-opt input { accent-color: var(--primary-accent); }
.type-body { display: flex; justify-content: space-between; flex: 1; align-items: baseline; }
.type-name { font-weight: 600; font-size: var(--fs-cell); }
.type-rate { font-size: var(--fs-meta); color: var(--text-muted); font-variant-numeric: tabular-nums; }
.admit-field select, .admit-field input[type=text] { width: 100%; height: 40px; padding: 0 12px; border: 1px solid var(--border-strong); border-radius: var(--radius-input); font: inherit; font-size: var(--fs-cell); background: var(--card); color: var(--text); }
.admit-field select:focus, .admit-field input:focus { outline: none; border-color: var(--primary-accent); box-shadow: 0 0 0 3px rgba(63,122,99,.18); }
.admit-foot { display: flex; justify-content: flex-end; gap: 10px; padding: 18px 22px 22px; }

/* ---------- Doctor-timings shift popup ---------- */
.tim-overlay { display: none; position: fixed; inset: 0; background: rgba(22,33,28,.48); z-index: 70; align-items: center; justify-content: center; padding: 20px; }
.tim-overlay.open { display: flex; }
.tim-modal { background: var(--card); border-radius: var(--radius-card); width: 100%; max-width: 560px; box-shadow: var(--shadow-lg); overflow: hidden; display: flex; flex-direction: column; max-height: min(84vh, 640px); }
.tim-head { display: flex; align-items: flex-start; justify-content: space-between; padding: 20px 22px 8px; }
.tim-eyebrow { font-size: var(--fs-eyebrow); font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: var(--text-muted); }
.tim-title { font-size: var(--fs-page); font-weight: 700; margin-top: 2px; }
.tim-sub { font-size: var(--fs-meta); color: var(--text-secondary); margin-top: 3px; }
.tim-x { background: none; border: none; font-size: 24px; line-height: 1; color: var(--text-muted); cursor: pointer; }
.tim-body { padding: 8px 22px 4px; overflow-y: auto; }
.tim-row { display: flex; align-items: center; gap: 12px; padding: 11px 0; border-bottom: 1px solid var(--row-line); }
.tim-row:last-child { border-bottom: none; }
.tim-row .doc-avatar { width: 32px; height: 32px; font-size: var(--fs-pill); }
.tim-info { flex: 1; min-width: 0; }
.tim-doc { font-size: var(--fs-cell); font-weight: 600; }
.tim-note { font-size: var(--fs-micro); color: var(--text-muted); margin-top: 1px; }
.tim-when { font-size: var(--fs-btn); font-weight: 600; font-variant-numeric: tabular-nums; white-space: nowrap; text-align: right; line-height: 1.5; }
.tim-foot { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 22px 20px; border-top: 1px solid var(--border); flex-wrap: wrap; }
.tim-touch { font-size: var(--fs-micro); color: var(--text-muted); }

/* ---------- Today's-bookings shift popup + panel ---------- */
/* Same shell as the timings popup; own overlay id so the two can be sequenced. */
.bkp-overlay { display: none; position: fixed; inset: 0; background: rgba(22,33,28,.48); z-index: 70; align-items: center; justify-content: center; padding: 20px; }
.bkp-overlay.open { display: flex; }
.bk-row { display: flex; align-items: center; gap: 12px; padding: 11px 0; border-bottom: 1px solid var(--row-line); }
.bk-row:last-child { border-bottom: none; }
.bk-info { flex: 1; min-width: 0; }
.bk-who { font-size: var(--fs-cell); font-weight: 600; }
.bk-what { font-size: var(--fs-micro); color: var(--text-muted); margin-top: 1px; }

/* ---------- Password nag ---------- */
.nag-banner {
    background: var(--warn-bg); border: 1px solid #E8D9B4; border-radius: var(--radius-card);
    padding: 14px 18px; display: flex; align-items: center; justify-content: space-between; gap: 12px;
    font-size: var(--fs-cell); color: var(--warn);
}
.nag-banner a { font-weight: 700; text-decoration: underline; }

@media (max-width: 1200px) {
    .grid-4 { grid-template-columns: repeat(2, 1fr); }
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

            <?php if ($myUnclosedDays): ?>
            <div class="nag-banner" style="flex-wrap:wrap;">
                <span><b>You have <?= count($myUnclosedDays) ?> shift<?= count($myUnclosedDays) === 1 ? '' : 's' ?> still to close.</b>
                    Close them before the day ages out — after 5 days an admin has to do it for you.</span>
                <span style="display:inline-flex;gap:10px;flex-wrap:wrap;">
                <?php foreach ($myUnclosedDays as $u): ?>
                    <a href="shift_closing.php?date=<?= htmlspecialchars($u['date']) ?>">
                        <?= date('D d/m', strtotime($u['date'])) ?> (Rs <?= number_format($u['expected_cash'], 0) ?>) &rarr;
                    </a>
                <?php endforeach; ?>
                </span>
            </div>
            <?php endif; ?>

            <!-- Page head. Replaces the gradient hero: the name is already in
                 the app-bar avatar and the date is already in the app bar, so
                 the band was repeating what the chrome had just said. -->
            <div class="page-head">
                <div>
                    <h1 class="page-title">Today</h1>
                    <div class="page-meta" aria-live="polite">
                        <?= count($todayRows) ?> registered &middot; <?= $countWaiting ?> waiting<?= $longestWaitMins > 0 ? ' &middot; longest ' . $longestWaitMins . ' min' : '' ?>
                    </div>
                </div>
                <?php if (has_permission('RECEPTION_REGISTER_PATIENTS')): ?>
                <a class="btn" href="patients.php?register=1">Register patient</a>
                <?php endif; ?>
            </div>

            <!-- Stat cards -->
            <div class="grid-4">
                <?php foreach ($stats as $s): ?>
                <?php $tag = !empty($s['href']) ? 'a' : 'div'; $attr = !empty($s['href']) ? ' href="' . htmlspecialchars($s['href']) . '"' : ''; ?>
                <<?= $tag ?> class="kpi-card"<?= $attr ?>>
                    <div class="kpi-icon"><?= icon($s['icon'], 19) ?></div>
                    <div class="kpi-body">
                        <div class="kpi-value"><?= htmlspecialchars($s['value']) ?></div>
                        <div class="kpi-label"><?= htmlspecialchars($s['label']) ?></div>
                    </div>
                </<?= $tag ?>>
                <?php endforeach; ?>
            </div>

            <!-- Today's work queue -->
            <div class="card">
                <?php if (empty($todayRows)): ?>
                    <div class="empty-state">No patients registered today yet.</div>
                <?php else: ?>
                <div class="queue">
                    <span class="sr-only" id="queueCaption">Patients registered today, newest first.</span>
                    <div class="qthead" aria-hidden="true">
                        <div>Token</div><div>Patient</div><div>Doctor / Type</div>
                        <div>Status</div><div>Paid</div><div style="text-align:right;">Actions</div>
                    </div>
                    <?php foreach ($todayRows as $row): ?>
                        <?php
                            $isAdmitted = $row['disposition'] === 'SHORT_STAY';
                            $refunded   = (float) $row['refunded'];
                            $paidAmount = (float) $row['paid_amount'];
                            // Stay money lives in admission_bills, not bills.
                            $admPaid      = (float) $row['adm_paid_amount'];
                            $rowPaidTotal = $paidAmount + $admPaid;

                            // ---- Status: ONE pill, one tone. -------------------
                            // The old row carried up to four simultaneous signals
                            // (left stripe + status pill + a SECOND admission pill
                            // + an opacity drop when refunded). Admission state now
                            // folds into the same pill, and the struck amount plus
                            // the clay "refunded" line carries the refund.
                            if ($row['consult_status'] === 'WAITING') {
                                $waitedMins  = (int) round((time() - strtotime($row['created_at'])) / 60);
                                $statusLabel = 'Waiting ' . $waitedMins . 'm';
                                $statusTone  = 'pill--warn';
                            } elseif ($row['consult_status'] === 'IN_CONSULT') {
                                $statusLabel = 'In consult';
                                $statusTone  = 'pill--brand';
                            } else {
                                $statusLabel = $row['finished_at'] ? 'Done ' . date('H:i', strtotime($row['finished_at'])) : 'Done';
                                $statusTone  = '';
                            }
                            if ($isAdmitted) {
                                if ($row['admission_status'] === 'DISCHARGE_IN_PROGRESS') {
                                    $statusLabel = 'Awaiting billing';
                                    $statusTone  = 'pill--warn';
                                } elseif ($row['admission_status'] === 'DISCHARGED') {
                                    $statusLabel = 'Discharged';
                                    $statusTone  = '';
                                } else {
                                    $statusLabel = 'Admitted · short stay';
                                    $statusTone  = 'pill--ok';
                                }
                            }

                            $ageDisplay = $row['dob']
                                ? (new DateTime($row['dob']))->diff(new DateTime())->y . 'y'
                                : '—';

                            // ---- Action hierarchy: ONE primary per row state ----
                            // Everything else demotes to the outline button or the
                            // "···" menu. Refund NEVER sits in the row; it lives in
                            // the menu, coloured clay, and keeps its confirm step.
                            $canER     = has_permission('RECEPTION_RAISE_ER_BILL');
                            $canProc   = has_permission('RECEPTION_RAISE_PROCEDURE_BILL');
                            $canAdmit  = has_permission('ADMISSION_ADMIT_PATIENT');
                            $canRefund = $row['bill_id'] && $row['bill_status'] === 'paid'
                                         && $refunded < $paidAmount
                                         && has_permission('RECEPTION_ISSUE_REFUNDS');

                            $admitAttrs = 'onclick="openAdmit(' . (int) $row['visit_id'] . ', '
                                . htmlspecialchars(json_encode($row['patient_name']), ENT_QUOTES) . ', '
                                . (int) $row['doctor_id'] . ', '
                                . htmlspecialchars(json_encode($row['doctor_name']), ENT_QUOTES) . ')"';
                            $invoiceHref = $row['bill_id']
                                ? 'checkout.php?print=1&amp;bill_id=' . (int) $row['bill_id'] : '';
                            // admission_invoice.php is keyed by ADMISSION id, not bill id.
                            $admInvoiceHref = $row['adm_bill_id']
                                ? 'admission_invoice.php?id=' . (int) $row['admission_id'] : '';
                            $profileHref = 'patients.php?q=' . urlencode($row['mrn']);

                            // [label, html-attrs, is-link] for primary + secondary.
                            $primary = null; $secondary = null; $overflow = [];

                            if ($isAdmitted && $row['admission_id'] && $row['admission_status'] === 'DISCHARGE_IN_PROGRESS') {
                                $primary = ['Bill discharge', 'href="admission_discharge.php?id=' . (int) $row['admission_id'] . '"', true];
                                if ($invoiceHref) { $secondary = ['Invoice', 'href="' . $invoiceHref . '" target="_blank" rel="noopener"', true]; }
                            } elseif ($isAdmitted && $row['admission_id'] && $row['admission_status'] === 'DISCHARGED') {
                                // The stay is over — nothing is being "managed". Reprinting the
                                // A-series discharge invoice is what reception actually wants
                                // here, so that is the solid button and the (read-only) stay
                                // record demotes to the outline.
                                if ($admInvoiceHref) {
                                    $primary   = ['Invoice', 'href="' . $admInvoiceHref . '" target="_blank" rel="noopener"', true];
                                    $secondary = ['View stay', 'href="admission.php?id=' . (int) $row['admission_id'] . '"', true];
                                } else {
                                    $primary = ['View stay', 'href="admission.php?id=' . (int) $row['admission_id'] . '"', true, true];
                                }
                            } elseif ($isAdmitted && $row['admission_id']) {
                                $primary = ['Manage stay', 'href="admission.php?id=' . (int) $row['admission_id'] . '"', true];
                                if ($invoiceHref) { $secondary = ['Invoice', 'href="' . $invoiceHref . '" target="_blank" rel="noopener"', true]; }
                            } elseif ($row['consult_status'] === 'WAITING' && $canAdmit) {
                                $primary = ['Admit', $admitAttrs, false];
                                if ($invoiceHref) { $secondary = ['Invoice', 'href="' . $invoiceHref . '" target="_blank" rel="noopener"', true]; }
                            } elseif ($invoiceHref) {
                                // In consult -> Invoice is the live action, so it is
                                // solid. Done/refunded -> nothing is urgent, so the
                                // same button drops to an outline.
                                $isLive  = $row['consult_status'] === 'IN_CONSULT';
                                $primary = ['Invoice', 'href="' . $invoiceHref . '" target="_blank" rel="noopener"', true, !$isLive];
                                $secondary = ['Profile', 'href="' . $profileHref . '"', true];
                            } else {
                                $primary = ['Profile', 'href="' . $profileHref . '"', true, true];
                            }

                            // Whatever did not become primary/secondary goes in "···".
                            $used = array_filter([$primary[0] ?? null, $secondary[0] ?? null]);
                            if ($canER) {
                                $overflow[] = ['ER service', 'href="er_bill.php?patient_id=' . (int) $row['patient_id'] . '"', true, false];
                            }
                            if ($canProc) {
                                // Carry the queue row's doctor across as the performing-doctor
                                // hint; procedure_bill.php drops it if they have none assigned.
                                $overflow[] = ['Procedure', 'href="procedure_bill.php?patient_id=' . (int) $row['patient_id']
                                    . ((int) ($row['doctor_id'] ?? 0) ? '&doctor_id=' . (int) $row['doctor_id'] : '') . '"', true, false];
                            }
                            if (!in_array('Admit', $used, true) && !$isAdmitted && $canAdmit) {
                                $overflow[] = ['Admit', $admitAttrs, false, false];
                            }
                            if (!in_array('Profile', $used, true)) {
                                $overflow[] = ['Profile', 'href="' . $profileHref . '"', true, false];
                            }
                            // A discharged row promotes the ADMISSION invoice, so the
                            // consultation slip would otherwise become unreachable.
                            if ($invoiceHref && !in_array('Invoice', $used, true)) {
                                $overflow[] = ['Consult invoice', 'href="' . $invoiceHref . '" target="_blank" rel="noopener"', true, false];
                            }
                            if ($canRefund) {
                                $overflow[] = ['Refund', 'href="refund.php?bill_id=' . (int) $row['bill_id'] . '"', true, true];
                            }
                        ?>
                        <div class="qrow">
                            <div class="c-token">
                                <?php
                                // Reception sees every doctor's queue at once, so each row carries
                                // its own prefix. Session 2 is captioned — numbers restart at 1.
                                $rowSession = (int) ($row['token_session'] ?? 1);
                                ?>
                                <div class="qtoken"><?= htmlspecialchars(token_code($row['doctor_token_prefix'] ?? null, $row['doctor_name'] ?? '', $row['token_no'])) ?></div>
                                <div class="qtime"><?= date('H:i', strtotime($row['created_at'])) ?><?= $rowSession >= 2 ? ' · ' . htmlspecialchars(token_session_label($rowSession)) : '' ?></div>
                            </div>
                            <div class="c-patient">
                                <div class="qname"><?= htmlspecialchars($row['patient_name']) ?></div>
                                <div class="qmeta"><span class="mono"><?= htmlspecialchars($row['mrn']) ?></span> &middot; <?= $ageDisplay ?> &middot;
                                    <!-- Today's patient → WhatsApp chat pre-filled with the thank-you message (E.164 stripped to digits for wa.me) -->
                                    <a class="wa-link" href="https://wa.me/<?= preg_replace('/\D/', '', $row['phone']) ?>?text=<?= rawurlencode('Thank You for Visiting BabyMedics!') ?>" target="_blank" rel="noopener" title="Send thank-you on WhatsApp">
                                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M17.47 14.38c-.3-.15-1.76-.87-2.03-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.17-.17.2-.35.22-.64.07-.3-.15-1.26-.46-2.4-1.47-.88-.79-1.48-1.76-1.65-2.06-.17-.3-.02-.46.13-.61.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.03-.52-.07-.15-.67-1.6-.91-2.2-.24-.58-.49-.5-.67-.5h-.57c-.2 0-.52.07-.79.37-.27.3-1.04 1.02-1.04 2.48 0 1.46 1.06 2.87 1.21 3.07.15.2 2.1 3.2 5.08 4.49.71.3 1.26.49 1.69.62.71.23 1.36.2 1.87.12.57-.08 1.76-.72 2-1.41.25-.7.25-1.29.18-1.42-.08-.12-.28-.2-.57-.34zM12.04 21.5h-.01a9.4 9.4 0 0 1-4.79-1.31l-.34-.2-3.56.93.95-3.47-.22-.36a9.4 9.4 0 0 1-1.44-5.02c0-5.2 4.24-9.43 9.45-9.43a9.4 9.4 0 0 1 6.68 2.77 9.37 9.37 0 0 1 2.76 6.67c0 5.2-4.24 9.43-9.44 9.43zm8.03-17.46A11.3 11.3 0 0 0 12.04.66C5.8.66.72 5.73.72 11.97c0 1.99.52 3.94 1.51 5.66L.63 23.5l6-1.57a11.34 11.34 0 0 0 5.4 1.37h.01c6.24 0 11.32-5.07 11.32-11.31 0-3.02-1.18-5.87-3.29-8.01z"/></svg><?= htmlspecialchars($row['phone']) ?></a>
                                </div>
                            </div>
                            <div class="c-doctor">
                                <div class="qdoc"><?= htmlspecialchars($row['doctor_name']) ?></div>
                                <div class="qmeta"><?= htmlspecialchars($row['consult_label']) ?></div>
                            </div>
                            <div class="c-status">
                                <span class="pill <?= $statusTone ?>"><?= htmlspecialchars($statusLabel) ?></span>
                            </div>
                            <div class="c-paid qpaid">
                                <?php if ($refunded > 0): ?>
                                    <span class="struck">Rs <?= number_format($rowPaidTotal, 0) ?></span>
                                    <div class="refunded">refunded <?= number_format($refunded, 0) ?></div>
                                <?php else: ?>
                                    Rs <?= number_format($rowPaidTotal, 0) ?>
                                    <?php if ($admPaid > 0 && $paidAmount > 0): ?>
                                        <div class="qsub">consult <?= number_format($paidAmount, 0) ?> &middot; stay <?= number_format($admPaid, 0) ?></div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <div class="qactions">
                                <?php if ($primary): ?>
                                    <?php
                                    // 4th element = "render as outline" — used when the
                                    // action exists but nothing about it is urgent.
                                    $pCls = 'qbtn' . (empty($primary[3]) ? ' qbtn--primary' : '');
                                    $pTag = $primary[2] ? 'a' : 'button';
                                    ?>
                                    <<?= $pTag ?> class="<?= $pCls ?>" <?= $primary[2] ? '' : 'type="button"' ?> <?= $primary[1] ?>><?= htmlspecialchars($primary[0]) ?></<?= $pTag ?>>
                                <?php endif; ?>
                                <?php if ($secondary): ?>
                                    <?php $sTag = $secondary[2] ? 'a' : 'button'; ?>
                                    <<?= $sTag ?> class="qbtn" <?= $secondary[2] ? '' : 'type="button"' ?> <?= $secondary[1] ?>><?= htmlspecialchars($secondary[0]) ?></<?= $sTag ?>>
                                <?php endif; ?>
                                <?php if ($overflow): ?>
                                <span class="qmenu-wrap">
                                    <button type="button" class="qbtn qbtn--more" aria-haspopup="menu" aria-expanded="false"
                                            aria-label="More actions for <?= htmlspecialchars($row['patient_name']) ?>"
                                            onclick="qToggleMenu(this)">&hellip;</button>
                                    <span class="qmenu" role="menu">
                                        <?php foreach ($overflow as $i => $o): ?>
                                            <?php if (!empty($o[3]) && $i > 0): ?><hr><?php endif; ?>
                                            <?php $oTag = $o[2] ? 'a' : 'button'; $oCls = !empty($o[3]) ? 'qmenu--danger' : ''; ?>
                                            <<?= $oTag ?> role="menuitem" class="<?= $oCls ?>" <?= $o[2] ? '' : 'type="button"' ?> <?= $o[1] ?>><?= htmlspecialchars($o[0]) ?></<?= $oTag ?>>
                                        <?php endforeach; ?>
                                    </span>
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Doctor Schedule -->
            <div class="card">
                <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:12px;">
                    <div>
                        <div class="section-title">Doctors today</div>
                        <div class="section-sub">Who's in, and how many are still waiting to be seen</div>
                    </div>
                    <?php if (!empty($docTimings)): ?>
                    <div style="display:flex; gap:8px; flex-shrink:0;">
                        <button type="button" class="qbtn" onclick="openTimings()">View timings</button>
                        <a class="qbtn" href="doctor_timings.php">Edit timings</a>
                    </div>
                    <?php endif; ?>
                </div>
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

            <!-- Today's bookings (phone appointments) -->
            <div class="card">
                <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:12px;">
                    <div>
                        <div class="section-title">Bookings today</div>
                        <div class="section-sub"><?= count($openBookings) ?> still expected &middot; arriving patients are matched automatically at registration</div>
                    </div>
                    <div style="display:flex; gap:8px; flex-shrink:0;">
                        <?php if (!empty($openBookings)): ?>
                        <button type="button" class="qbtn" onclick="openBookingsPopup()">View expected</button>
                        <?php endif; ?>
                        <a class="qbtn" href="bookings.php">Manage bookings</a>
                    </div>
                </div>
                <?php if (empty($todayBookings)): ?>
                    <div class="empty-state">No phone bookings for today.</div>
                <?php else: ?>
                <div class="sched-list">
                    <?php foreach ($todayBookings as $b): ?>
                        <div class="bk-row<?= $b['status'] === 'ARRIVED' ? ' arrived' : '' ?>">
                            <div class="doc-avatar"><?= strtoupper(mb_substr($b['patient_name'] ?: $b['person_name'], 0, 1)) ?></div>
                            <div class="bk-info">
                                <div class="bk-who"><?= htmlspecialchars($b['patient_name'] ?: $b['person_name']) ?></div>
                                <div class="bk-what">
                                    <?= htmlspecialchars($b['doctor_name']) ?> &middot; <?= htmlspecialchars($b['purpose']) ?>
                                    <?= $b['preferred_time'] ? ' · ' . htmlspecialchars($b['preferred_time']) : '' ?>
                                </div>
                            </div>
                            <?php if ($b['status'] === 'BOOKED'): ?>
                                <?php // Arrive → register jumps into the pre-filled flow; the SAVE there consumes the booking. ?>
                                <?php if ($b['patient_id']): ?>
                                    <a class="qbtn" href="patients.php?q=<?= urlencode($b['mrn']) ?>">Arrived</a>
                                <?php else: ?>
                                    <a class="qbtn" href="patients.php?register=1&amp;booking=<?= (int) $b['id'] ?>">Arrived</a>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="pill pill--ok">Arrived</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>

<!-- Doctor-timings shift popup -->
<?php if (!empty($docTimings)): ?>
<div class="tim-overlay<?= $showTimingsPopup ? ' open' : '' ?>" id="timOverlay" onclick="if(event.target===this)closeTimings()">
    <div class="tim-modal" role="dialog" aria-modal="true" aria-labelledby="timTitle">
        <div class="tim-head">
            <div>
                <div class="tim-eyebrow">Shift start</div>
                <div class="tim-title" id="timTitle">Doctor timings today</div>
                <div class="tim-sub"><?= date('l, d/m/Y') ?> &middot; confirm these are correct before you start the queue.</div>
            </div>
            <button type="button" class="tim-x" onclick="closeTimings()" aria-label="Close">&times;</button>
        </div>
        <div class="tim-body">
            <?php foreach ($docTimings as $t): ?>
                <?php
                    $tst = $t['status']; // NULL means not confirmed yet today
                    // A doctor may sit in one or two sessions: show only the
                    // windows that actually have values.
                    $fmtWin = static function ($s, $e) {
                        if (!$s && !$e) { return null; }
                        return ($s ? date('g:i A', strtotime($s)) : '?')
                             . ' – ' . ($e ? date('g:i A', strtotime($e)) : '?');
                    };
                    if ($tst === 'OFF') {
                        $pill = ['off', 'Off today'];
                        $when = '—';
                    } elseif ($tst === 'DELAYED' || $tst === 'AVAILABLE') {
                        $pill = $tst === 'DELAYED' ? ['delay', 'Delayed'] : ['avail', 'Available'];
                        $wins = array_filter([
                            $fmtWin($t['start_time'], $t['end_time']),
                            $fmtWin($t['start_time_2'] ?? null, $t['end_time_2'] ?? null),
                        ]);
                        $when = $wins ? implode('<br>', array_map('htmlspecialchars', $wins)) : '?';
                    } else {
                        $pill = ['unset', 'Not confirmed'];
                        $when = '—';
                    }
                ?>
                <div class="tim-row<?= $tst === 'OFF' ? ' off' : '' ?>">
                    <div class="doc-avatar"><?= strtoupper(mb_substr($t['name'], 0, 1)) ?></div>
                    <div class="tim-info">
                        <div class="tim-doc"><?= htmlspecialchars($t['name']) ?></div>
                        <?php if (!empty($t['note'])): ?><div class="tim-note"><?= htmlspecialchars($t['note']) ?></div><?php endif; ?>
                    </div>
                    <?php /* $when is built above from formatted times only and
                             is already escaped where needed (may contain <br>
                             between two sessions) */ ?>
                    <div class="tim-when"><?= $when ?></div>
                    <span class="tim-pill <?= $pill[0] ?>"><?= $pill[1] ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="tim-foot">
            <div class="tim-touch">
                <?php if ($timingsLastTouch): ?>
                    Last updated by <strong><?= htmlspecialchars($timingsLastTouch['by'] ?? 'unknown') ?></strong> at <?= date('H:i', strtotime($timingsLastTouch['at'])) ?>
                <?php else: ?>
                    Not confirmed for today yet — please set the timings.
                <?php endif; ?>
            </div>
            <div style="display:flex; gap:10px;">
                <button type="button" class="btn secondary" onclick="closeTimings()">Got it</button>
                <a class="btn" href="doctor_timings.php">Edit timings</a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Today's-bookings shift popup (sequenced AFTER the timings popup) -->
<?php if (!empty($openBookings)): ?>
<div class="bkp-overlay" id="bkpOverlay" onclick="if(event.target===this)closeBookingsPopup()">
    <div class="tim-modal" role="dialog" aria-modal="true" aria-labelledby="bkpTitle">
        <div class="tim-head">
            <div>
                <div class="tim-eyebrow">Shift start</div>
                <div class="tim-title" id="bkpTitle">Expected today — phone bookings</div>
                <div class="tim-sub"><?= count($openBookings) ?> appointment<?= count($openBookings) === 1 ? '' : 's' ?> not yet arrived. Registration will match them by phone number automatically.</div>
            </div>
            <button type="button" class="tim-x" onclick="closeBookingsPopup()" aria-label="Close">&times;</button>
        </div>
        <div class="tim-body">
            <?php foreach ($openBookings as $b): ?>
            <div class="bk-row">
                <div class="doc-avatar"><?= strtoupper(mb_substr($b['patient_name'] ?: $b['person_name'], 0, 1)) ?></div>
                <div class="bk-info">
                    <div class="bk-who"><?= htmlspecialchars($b['patient_name'] ?: $b['person_name']) ?><?= $b['mrn'] ? ' <span style="font-weight:400;color:var(--text-muted);font-size:11.5px;">' . htmlspecialchars($b['mrn']) . '</span>' : '' ?></div>
                    <div class="bk-what">
                        <?= htmlspecialchars($b['doctor_name']) ?> &middot; <?= htmlspecialchars($b['purpose']) ?>
                        <?= $b['preferred_time'] ? ' · ' . htmlspecialchars($b['preferred_time']) : '' ?>
                        &middot; <?= htmlspecialchars($b['phone']) ?>
                    </div>
                    <?php if ($b['note']): ?><div class="bk-what"><?= htmlspecialchars($b['note']) ?></div><?php endif; ?>
                </div>
                <span class="pill pill--brand">Expected</span>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="tim-foot">
            <div class="tim-touch">When they arrive, register or invoice as usual — the booking is matched and consumed on save.</div>
            <div style="display:flex; gap:10px;">
                <button type="button" class="btn secondary" onclick="closeBookingsPopup()">Got it</button>
                <a class="btn" href="bookings.php">Manage bookings</a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

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
                    <input type="text" name="admitting_doctor_manual" id="admitDoctorManual" class="uc" placeholder="Or type the doctor's name" style="margin-top:8px;">
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
function openTimings() { var o = document.getElementById('timOverlay'); if (o) o.classList.add('open'); }
function openBookingsPopup() { var o = document.getElementById('bkpOverlay'); if (o) o.classList.add('open'); }
function closeBookingsPopup() { var o = document.getElementById('bkpOverlay'); if (o) o.classList.remove('open'); }

// Shift-start sequencing: timings first, then today's bookings — never stacked.
// The server marks which should auto-open this session; closing the timings
// popup releases the queued bookings popup.
var bookingsPopupQueued = <?= $showBookingsPopup ? 'true' : 'false' ?>;
function closeTimings() {
    var o = document.getElementById('timOverlay');
    var wasOpen = o && o.classList.contains('open');
    if (o) o.classList.remove('open');
    // Release the queued bookings popup only on a real close — an Escape press
    // with the timings popup already shut must not surprise-open it.
    if (wasOpen && bookingsPopupQueued) { bookingsPopupQueued = false; openBookingsPopup(); }
}
<?php if ($showBookingsPopup && !$showTimingsPopup): ?>
// No timings popup this session — the bookings popup opens directly.
bookingsPopupQueued = false;
openBookingsPopup();
<?php endif; ?>
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeAdmit(); closeTimings(); closeBookingsPopup(); } });

/* ---------------------------------------------------------------------
   Queue row overflow menu ("···").
   One menu open at a time; click-away and Escape close it, and Escape
   returns focus to the trigger that opened it.
   ------------------------------------------------------------------- */
function qCloseMenus(except) {
    document.querySelectorAll('.qmenu-wrap.open').forEach(function (w) {
        if (w === except) { return; }
        w.classList.remove('open');
        var t = w.querySelector('[aria-haspopup="menu"]');
        if (t) { t.setAttribute('aria-expanded', 'false'); }
    });
}
function qToggleMenu(btn) {
    var wrap = btn.closest('.qmenu-wrap');
    if (!wrap) { return; }
    var willOpen = !wrap.classList.contains('open');
    qCloseMenus(wrap);
    wrap.classList.toggle('open', willOpen);
    btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
}
document.addEventListener('click', function (e) {
    if (!e.target.closest('.qmenu-wrap')) { qCloseMenus(null); }
});
document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') { return; }
    var open = document.querySelector('.qmenu-wrap.open');
    if (!open) { return; }
    var trigger = open.querySelector('[aria-haspopup="menu"]');
    qCloseMenus(null);
    if (trigger) { trigger.focus(); }
});
</script>
<script src="assets/js/date-picker.js?v=<?= @filemtime(__DIR__ . "/assets/js/date-picker.js") ?: 1 ?>"></script>
</body>
</html>
