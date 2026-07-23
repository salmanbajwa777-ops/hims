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
/* ---------- Hero ---------- */
/* Compact greeting strip — name + date on one line, no oversized band. */
.hero {
    background: linear-gradient(135deg, var(--primary-dark), var(--primary));
    border-radius: var(--radius-card); padding: 16px 22px;
    display: flex; align-items: baseline; justify-content: space-between;
    flex-wrap: wrap; gap: 6px 16px; color: #fff;
}
.hero-greeting { display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap; }
.hero-greeting .eyebrow { font-size: 13px; opacity: .8; font-weight: 500; }
.hero-greeting h1 { font-size: 20px; font-weight: 700; margin: 0; }
.hero-greeting .date { font-size: 13px; opacity: .82; }

/* ---------- Stat cards ---------- */
.grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; }
.kpi-card {
    background: var(--card); border-radius: var(--radius-card); padding: 14px 16px;
    box-shadow: var(--shadow-sm); border: 1px solid var(--border);
    display: flex; align-items: center; gap: 13px; text-decoration: none; color: inherit;
    transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
}
.kpi-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
a.kpi-card:hover { border-color: var(--primary); }
.kpi-icon {
    width: 40px; height: 40px; border-radius: 11px; flex: none;
    display: flex; align-items: center; justify-content: center;
    background: var(--primary-light); color: var(--primary-dark);
}
.kpi-icon svg { width: 19px; height: 19px; }
.kpi-body { min-width: 0; }
.kpi-value { font-size: 24px; font-weight: 700; line-height: 1.1; }
.kpi-label { font-size: 12.5px; color: var(--text-secondary); margin-top: 2px; }

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
.wa-link { display: inline-flex; align-items: center; gap: 4px; color: inherit; text-decoration: none; vertical-align: middle; }
.wa-link svg { width: 13px; height: 13px; color: #25D366; flex-shrink: 0; }
.wa-link:hover { color: #128C7E; text-decoration: underline; }
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

/* ---------- Doctor-timings shift popup ---------- */
.tim-overlay { display: none; position: fixed; inset: 0; background: rgba(15,23,42,.45); z-index: 70; align-items: center; justify-content: center; padding: 20px; }
.tim-overlay.open { display: flex; }
.tim-modal { background: var(--card); border-radius: var(--radius-card); width: 100%; max-width: 560px; box-shadow: var(--shadow-lg); overflow: hidden; display: flex; flex-direction: column; max-height: min(84vh, 640px); }
.tim-head { display: flex; align-items: flex-start; justify-content: space-between; padding: 20px 22px 8px; }
.tim-eyebrow { font-size: 11px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: var(--text-muted); }
.tim-title { font-size: 18px; font-weight: 700; margin-top: 2px; }
.tim-sub { font-size: 12.5px; color: var(--text-muted); margin-top: 3px; }
.tim-x { background: none; border: none; font-size: 24px; line-height: 1; color: var(--text-muted); cursor: pointer; }
.tim-body { padding: 8px 22px 4px; overflow-y: auto; }
.tim-row { display: flex; align-items: center; gap: 12px; padding: 11px 0; border-bottom: 1px solid var(--border); }
.tim-row:last-child { border-bottom: none; }
.tim-row .doc-avatar { width: 32px; height: 32px; font-size: 11.5px; }
.tim-row.off { opacity: .55; }
.tim-info { flex: 1; min-width: 0; }
.tim-doc { font-size: 13.5px; font-weight: 600; }
.tim-note { font-size: 11.5px; color: var(--text-muted); margin-top: 1px; }
.tim-when { font-size: 13px; font-weight: 600; font-variant-numeric: tabular-nums; white-space: nowrap; text-align: right; line-height: 1.5; }
.tim-pill { font-size: 11px; font-weight: 700; padding: 3px 9px; border-radius: 20px; white-space: nowrap; }
.tim-pill.avail { background: #ECFDF5; color: #047857; }
.tim-pill.delay { background: #FFFBEB; color: #92400E; }
.tim-pill.off { background: #FEF2F2; color: #B91C1C; }
.tim-pill.unset { background: #F1F5F9; color: var(--text-secondary); }
.tim-foot { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 22px 20px; border-top: 1px solid var(--border); flex-wrap: wrap; }
.tim-touch { font-size: 12px; color: var(--text-muted); }

/* ---------- Today's-bookings shift popup + panel ---------- */
/* Same shell as the timings popup; own overlay id so the two can be sequenced. */
.bkp-overlay { display: none; position: fixed; inset: 0; background: rgba(15,23,42,.45); z-index: 70; align-items: center; justify-content: center; padding: 20px; }
.bkp-overlay.open { display: flex; }
.bk-row { display: flex; align-items: center; gap: 12px; padding: 11px 0; border-bottom: 1px solid var(--border); }
.bk-row:last-child { border-bottom: none; }
.bk-row.arrived { opacity: .55; }
.bk-info { flex: 1; min-width: 0; }
.bk-who { font-size: 13.5px; font-weight: 600; }
.bk-what { font-size: 11.5px; color: var(--text-muted); margin-top: 1px; }
.bk-pill { font-size: 11px; font-weight: 700; padding: 3px 9px; border-radius: 20px; white-space: nowrap; }
.bk-pill.booked { background: var(--primary-light); color: var(--primary-dark); }
.bk-pill.arrived { background: #EDE7FB; color: #6D28D9; }

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
                    <div class="date"><?= date('l') ?>, <?= date('d/m/Y') ?></div>
                </div>
            </section>

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
                                <div class="q-meta"><span class="mono"><?= htmlspecialchars($row['mrn']) ?></span> &middot; <?= $ageDisplay ?> &middot;
                                    <!-- Today's patient → WhatsApp chat pre-filled with the thank-you message (E.164 stripped to digits for wa.me) -->
                                    <a class="wa-link" href="https://wa.me/<?= preg_replace('/\D/', '', $row['phone']) ?>?text=<?= rawurlencode('Thank You for Visiting BabyMedics!') ?>" target="_blank" rel="noopener" title="Send thank-you on WhatsApp">
                                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M17.47 14.38c-.3-.15-1.76-.87-2.03-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.17-.17.2-.35.22-.64.07-.3-.15-1.26-.46-2.4-1.47-.88-.79-1.48-1.76-1.65-2.06-.17-.3-.02-.46.13-.61.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.03-.52-.07-.15-.67-1.6-.91-2.2-.24-.58-.49-.5-.67-.5h-.57c-.2 0-.52.07-.79.37-.27.3-1.04 1.02-1.04 2.48 0 1.46 1.06 2.87 1.21 3.07.15.2 2.1 3.2 5.08 4.49.71.3 1.26.49 1.69.62.71.23 1.36.2 1.87.12.57-.08 1.76-.72 2-1.41.25-.7.25-1.29.18-1.42-.08-.12-.28-.2-.57-.34zM12.04 21.5h-.01a9.4 9.4 0 0 1-4.79-1.31l-.34-.2-3.56.93.95-3.47-.22-.36a9.4 9.4 0 0 1-1.44-5.02c0-5.2 4.24-9.43 9.45-9.43a9.4 9.4 0 0 1 6.68 2.77 9.37 9.37 0 0 1 2.76 6.67c0 5.2-4.24 9.43-9.44 9.43zm8.03-17.46A11.3 11.3 0 0 0 12.04.66C5.8.66.72 5.73.72 11.97c0 1.99.52 3.94 1.51 5.66L.63 23.5l6-1.57a11.34 11.34 0 0 0 5.4 1.37h.01c6.24 0 11.32-5.07 11.32-11.31 0-3.02-1.18-5.87-3.29-8.01z"/></svg><?= htmlspecialchars($row['phone']) ?></a>
                                </div>
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
                <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:12px;">
                    <div>
                        <div class="section-title">Doctors today</div>
                        <div class="section-sub">Who's in, and how many are still waiting to be seen</div>
                    </div>
                    <?php if (!empty($docTimings)): ?>
                    <div style="display:flex; gap:8px; flex-shrink:0;">
                        <button type="button" class="qa" onclick="openTimings()">View timings</button>
                        <a class="qa" href="doctor_timings.php">Edit timings</a>
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
                        <button type="button" class="qa" onclick="openBookingsPopup()">View expected</button>
                        <?php endif; ?>
                        <a class="qa" href="bookings.php">Manage bookings</a>
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
                                    <a class="qa" href="patients.php?q=<?= urlencode($b['mrn']) ?>">Arrived</a>
                                <?php else: ?>
                                    <a class="qa" href="patients.php?register=1&amp;booking=<?= (int) $b['id'] ?>">Arrived</a>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="bk-pill arrived">Arrived</span>
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
                <span class="bk-pill booked">Expected</span>
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
</script>
<script src="assets/js/date-picker.js"></script>
</body>
</html>
