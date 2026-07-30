<?php
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
require_once __DIR__ . '/config/notify.php';
require_once __DIR__ . '/config/admission_actions.php';
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
            audit_log($pdo, $auditAction, "Visit #$visitId ($auditAction)", $_SESSION['user_id']);
        }

        // Post/redirect/get so a refresh doesn't re-submit the state change.
        header('Location: doctor.php' . ($flash ? '?done=' . urlencode($flash) : ''));
        exit;
    }

    // ---------------- Admit a patient (doctor-initiated) ----------------
    // A doctor can admit straight from their console. The shared handler admits against
    // the passed visit (their queue row) and starts the stay clock.
    if ($action === 'admit_patient') {
        $result = handle_admit_patient($pdo);
        if ($result['ok']) {
            header('Location: admission.php?id=' . (int) $result['admission_id']);
            exit;
        }
        header('Location: doctor.php?admit_error=' . urlencode($result['error']));
        exit;
    }
}
$flash = trim($_GET['done'] ?? '');
$admitError = trim($_GET['admit_error'] ?? '');

$mustChangePassword = (bool) $user['must_change_password'];
$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good Morning' : ($hour < 17 ? 'Good Afternoon' : 'Good Evening');

// ---------------- Today's queue for this doctor ----------------
// One row per visit today, patient + consult type joined in. Ordered so the active
// consultation and those still waiting float to the top (by status), then newest
// registration first within each group (highest token = latest arrival on top).
$queueStmt = $pdo->prepare("
    SELECT v.id AS visit_id, v.token_no, v.token_session, v.consult_status, v.started_at, v.created_at,
           p.name AS patient_name, p.gender, p.dob, p.mrn,
           t.label AS type_label, v.consultation_fee_type,
           a.id AS admission_id, a.admission_type, a.status AS admission_status,
           b.id AS bill_id, b.status AS bill_status, b.paid_amount
    FROM visits v
    JOIN patients p ON p.id = v.patient_id
    LEFT JOIN doctor_consult_types t ON t.id = v.doctor_consult_type_id
    LEFT JOIN admissions a ON a.visit_id = v.id
    LEFT JOIN bills b ON b.visit_id = v.id
    WHERE v.doctor_id = ? AND v.visit_date = CURDATE()
    ORDER BY FIELD(v.consult_status, 'IN_CONSULT', 'WAITING', 'DONE'), v.token_session DESC, v.token_no DESC
");
$queueStmt->execute([$doctorId]);

// This doctor's token prefix, resolved once for every row in their queue.
$myPrefixStmt = $pdo->prepare('SELECT token_prefix FROM users WHERE id = ?');
$myPrefixStmt->execute([$doctorId]);
$myTokenPrefix = doctor_token_prefix($myPrefixStmt->fetchColumn() ?: null, $user['name'] ?? '');

// Admit-modal data for the doctor console (doctors can admit from their queue).
$canDoctorAdmit = has_permission('ADMISSION_ADMIT_PATIENT');
// Doctors hold RECEPTION_ISSUE_REFUNDS but had no entry point (the receptionist
// queue 403s them). Surface Invoice/Refund on their own DONE rows. refund.php still
// enforces that the approver is this visit's doctor, so scope is safe.
$canDoctorRefund = has_permission('RECEPTION_ISSUE_REFUNDS');
$admTypes = $admDoctors = $admNurses = [];
$admTypeLabels = ['ROUTINE' => 'Routine', 'PRIVATE' => 'Private Room', 'LONG_PRIVATE' => 'Long Private'];
if ($canDoctorAdmit) {
    $admTypes = $pdo->query('SELECT admission_type, rate_amount, rate_basis FROM admission_rates WHERE is_enabled = 1 ORDER BY FIELD(admission_type,"ROUTINE","PRIVATE","LONG_PRIVATE")')->fetchAll();
    $admDoctors = $pdo->query("SELECT id, name FROM users WHERE base_role = 'DOCTOR' ORDER BY name")->fetchAll();
    // Primary nurse is mandatory at admit — the doctor picks one here too.
    require_once __DIR__ . '/config/nurses.php';
    $admNurses = nurse_roster($pdo, 'NURSING_ATTEND_SHORT_STAY');
}
$visits = $queueStmt->fetchAll();

$waiting   = array_values(array_filter($visits, fn($v) => $v['consult_status'] === 'WAITING'));
$inConsult = array_values(array_filter($visits, fn($v) => $v['consult_status'] === 'IN_CONSULT'));
$doneCount = count(array_filter($visits, fn($v) => $v['consult_status'] === 'DONE'));

$current = $inConsult[0] ?? null;         // the one being seen right now (0 or 1)
$next    = $waiting[0] ?? null;           // next to call in

// ---------------- This-month analytics snapshot ----------------
// Small always-on summary under the queue; the full picture (charts, tables,
// history) lives in doctor_analytics.php. Figures are the DOCTOR'S EARNED share
// of paid consultations — (paid − tax) × share% — NOT the gross billed amount
// (the clinic keeps the rest). Same formula as dashboard.php's doctor share.
require_once __DIR__ . '/config/billing.php';
$moStart = date('Y-m-01');
$moEnd = date('Y-m-t');

// Per-doctor earned amount from one paid bill. Falls back to gross × 0 = 0 if
// the revenue-share columns aren't populated (share defaults to 0), which is the
// safe under-report — never shows more than the doctor is owed.
$earnedExpr = "(CASE WHEN dr.consult_has_tax = 1
                    THEN (b.paid_amount - b.paid_amount * dr.consult_tax_pct / 100) * dr.consult_share_pct / 100
                    ELSE b.paid_amount * dr.consult_share_pct / 100 END)";

// Paid consultations split into full vs revisit (earned share), plus per-tier mix.
// Wrapped so a pre-migration DB (no consult_share columns) degrades to zeros
// rather than fataling the whole console.
try {
    $moC = $pdo->prepare("
        SELECT SUM(CASE WHEN v.consultation_fee_type = 'FULL' THEN $earnedExpr ELSE 0 END) AS full_amt,
               SUM(CASE WHEN v.consultation_fee_type <> 'FULL' THEN $earnedExpr ELSE 0 END) AS revisit_amt,
               SUM(CASE WHEN v.consultation_fee_type = 'FULL' THEN 1 ELSE 0 END) AS full_n,
               SUM(CASE WHEN v.consultation_fee_type = 'FREE_FOLLOWUP' THEN 1 ELSE 0 END) AS free_n,
               SUM(CASE WHEN v.consultation_fee_type = 'HALF_FOLLOWUP' THEN 1 ELSE 0 END) AS half_n,
               SUM(CASE WHEN v.consultation_fee_type = 'THREE_QUARTER_FOLLOWUP' THEN 1 ELSE 0 END) AS tq_n,
               COUNT(*) AS n
        FROM visits v
        JOIN bills b ON b.visit_id = v.id AND b.status = 'paid' AND b.voided_at IS NULL
        JOIN users dr ON dr.id = v.doctor_id
        WHERE v.doctor_id = ? AND v.visit_date BETWEEN ? AND ?
    ");
    $moC->execute([$doctorId, $moStart, $moEnd]);
} catch (PDOException $e) {
    // consult_share columns missing — earnings read zero, counts still work.
    $moC = $pdo->prepare("
        SELECT 0 AS full_amt, 0 AS revisit_amt,
               SUM(CASE WHEN v.consultation_fee_type = 'FULL' THEN 1 ELSE 0 END) AS full_n,
               SUM(CASE WHEN v.consultation_fee_type = 'FREE_FOLLOWUP' THEN 1 ELSE 0 END) AS free_n,
               SUM(CASE WHEN v.consultation_fee_type = 'HALF_FOLLOWUP' THEN 1 ELSE 0 END) AS half_n,
               SUM(CASE WHEN v.consultation_fee_type = 'THREE_QUARTER_FOLLOWUP' THEN 1 ELSE 0 END) AS tq_n,
               COUNT(*) AS n
        FROM visits v
        JOIN bills b ON b.visit_id = v.id AND b.status = 'paid' AND b.voided_at IS NULL
        WHERE v.doctor_id = ? AND v.visit_date BETWEEN ? AND ?
    ");
    $moC->execute([$doctorId, $moStart, $moEnd]);
}
$mo = $moC->fetch() ?: [];
$moConsultN = (int) ($mo['n'] ?? 0);
$moRevisitN = (int) (($mo['free_n'] ?? 0) + ($mo['half_n'] ?? 0) + ($mo['tq_n'] ?? 0));
$moRevisitRate = $moConsultN > 0 ? $moRevisitN / $moConsultN * 100 : 0.0;

// ER admission money is deliberately EXCLUDED from the doctor's earnings figures
// (2026-07-23): it's clinic revenue, not the doctor's. The active-admissions
// cards below stay — they're operational (who's admitted), not money.
// $moTotal is now the doctor's EARNED share this month, not gross billed.
$moTotal = (float) ($mo['full_amt'] ?? 0) + (float) ($mo['revisit_amt'] ?? 0);

// Currently-active admissions under this doctor (operational cards only — the
// doctor sees who's admitted, NOT the billing. Charges/running totals are
// deliberately not fetched here; billing belongs to reception.)
$actQ = $pdo->prepare("
    SELECT a.id, a.admission_type, a.admitted_at,
           p.name AS patient_name, p.mrn,
           nu.name AS nurse_name,
           (SELECT h.status_at_handover FROM admission_handovers h
            WHERE h.admission_id = a.id ORDER BY h.handover_time DESC, h.id DESC LIMIT 1) AS last_status
    FROM admissions a
    JOIN visits v ON v.id = a.visit_id
    JOIN patients p ON p.id = v.patient_id
    LEFT JOIN users nu ON nu.id = a.assigned_nurse_id
    WHERE COALESCE(a.admitting_doctor_id, v.doctor_id) = ?
      AND a.status IN ('PENDING_ASSIGNMENT','ACTIVE','DISCHARGE_IN_PROGRESS')
    ORDER BY a.admitted_at DESC
");
$actQ->execute([$doctorId]);
$activeAdms = $actQ->fetchAll();
foreach ($activeAdms as &$aa) {
    $aa['elapsed_min'] = max(0, (int) floor((time() - strtotime($aa['admitted_at'])) / 60));
}
unset($aa);

// Open dental package accounts owned by this dentist, with what is still owed.
// Try-wrapped and empty for everyone else: a paediatrician has no accounts, and
// a database without the dental migration must not fatal the whole console.
// The balance is derived here exactly as dental_account_totals() defines it —
// one grouped query rather than a call per row.
$dentalAccounts = [];
try {
    $dq = $pdo->prepare("
        SELECT a.id, a.account_number, a.title, p.name AS patient_name, p.mrn,
               (COALESCE(i.charged, 0) + COALESCE(i.lab, 0) - COALESCE(pm.paid, 0)) AS balance
          FROM dental_procedure_accounts a
          JOIN patients p ON p.id = a.patient_id
          LEFT JOIN (SELECT account_id, SUM(amount) AS charged, SUM(lab_charge) AS lab
                       FROM dental_procedure_account_items WHERE voided_at IS NULL
                      GROUP BY account_id) i ON i.account_id = a.id
          LEFT JOIN (SELECT account_id, SUM(amount) AS paid
                       FROM dental_procedure_payments WHERE status = 'paid' AND voided_at IS NULL
                      GROUP BY account_id) pm ON pm.account_id = a.id
         WHERE a.doctor_id = ? AND a.status = 'OPEN'
         ORDER BY a.opened_at DESC LIMIT 10
    ");
    $dq->execute([$doctorId]);
    $dentalAccounts = $dq->fetchAll();
} catch (PDOException $e) {
    // Dental module not migrated on this database.
}

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
/* The bespoke page header is gone — partials/quick_header.php is the app bar
   now, and its styles ship in assets/app.css. */

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

/* Destination cards — the console's launchpad, mirroring the sidebar groups.
   Two per row on desktop, one per row once the rail stacks underneath. */
.nav-card-group { margin-bottom: 18px; }
.nav-card-group:last-child { margin-bottom: 0; }
.nav-card-group-label { font-size: 11px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--text-muted); margin-bottom: 8px; }
.nav-card-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.nav-card { display: flex; align-items: flex-start; gap: 12px; padding: 14px 16px; background: var(--card); border: 1px solid var(--border); border-radius: var(--radius-card); box-shadow: var(--shadow-sm); color: inherit; text-decoration: none; transition: border-color .15s ease, box-shadow .15s ease, transform .15s ease; }
a.nav-card:hover { border-color: var(--primary); box-shadow: var(--shadow-md, var(--shadow-sm)); transform: translateY(-1px); }
.nav-card.disabled { opacity: .55; cursor: default; box-shadow: none; }
.nav-card-icon { flex: 0 0 auto; width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; background: var(--primary-light); color: var(--primary-dark); }
.nav-card-icon svg { width: 19px; height: 19px; }
.nav-card-body { min-width: 0; display: block; }
.nav-card-label { font-size: 14px; font-weight: 600; display: flex; align-items: center; gap: 7px; }
.nav-card-badge { background: var(--primary); color: #fff; font-size: 11px; font-weight: 700; line-height: 1; padding: 3px 7px; border-radius: 20px; }
.nav-card-desc { display: block; font-size: 12px; color: var(--text-muted); margin-top: 2px; }
@media (max-width: 560px) { .nav-card-grid { grid-template-columns: 1fr; } }
html[data-view="mobile"] .nav-card-grid { grid-template-columns: 1fr; }

.status-pill { font-size: 11.5px; font-weight: 600; padding: 4px 10px; border-radius: 20px; white-space: nowrap; }
.status-pill.waiting { background: #FFFBEB; color: #92400E; }
.status-pill.in-consult { background: #ECFDF5; color: #047857; }
.status-pill.done { background: #F1F5F9; color: var(--text-secondary); }
.btn-primary { background: var(--primary); color: #fff; border: none; font-weight: 600; font-size: 12.5px; padding: 8px 14px; border-radius: 10px; display: inline-flex; align-items: center; gap: 6px; transition: background .15s ease; }
.btn-primary:hover { background: var(--primary-dark); }
.btn-primary svg { width: 12px; height: 12px; }
.btn-ghost { background: transparent; border: 1px solid var(--border); color: var(--text-secondary); font-weight: 600; font-size: 12.5px; padding: 7px 13px; border-radius: 10px; }

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
html[data-view="mobile"] .row-2 { grid-template-columns: 1fr; }
/* Forced DESKTOP on a phone: keep the two-column console (don't collapse). */
html[data-view="desktop"] .row-2 { grid-template-columns: 1.6fr 1fr; }
</style>
CSS;
require __DIR__ . '/partials/head.php';
?>
<div class="app">

    <?php
    $dsActive = 'console';
    $dsUserName = $user['name'];
    $dsWaitingCount = count($waiting);
    // The sidebar's Dental group is specialty-gated; without this a dentist
    // opening the console lost the group they see everywhere else.
    $dsSpecialty = $user['specialty'] ?? '';
    require __DIR__ . '/partials/doctor_sidebar.php';
    ?>

    <div class="main">
        <?php
        /* The shared application bar — identical to the one every other role
           sees. This page used to hand-roll its own <header class="header">
           with a greeting block; three doctor pages each had their own copy and
           the front desk had a fourth, so the top of the screen changed shape
           as you moved between them. The greeting has not been lost: the
           welcome line in .content below still carries it, which is where a
           greeting belongs anyway. */
        require __DIR__ . '/partials/quick_header.php';
        ?>

        <div class="content">

            <div class="welcome-line">
                <h1><?= $greeting ?>, <?= htmlspecialchars($user['name']) ?></h1>
                <span class="welcome-date"><?= date('l, d/m/Y') ?></span>
            </div>


            <?php if ($mustChangePassword): ?>
            <div class="nag-banner">
                <span>You're signed in with a temporary password. Please set a new one to secure your account.</span>
                <a href="change-password.php">Change password now &rarr;</a>
            </div>
            <?php endif; ?>

            <?php if ($flash !== ''): ?>
            <div class="flash"><?= htmlspecialchars($flash) ?></div>
            <?php endif; ?>

            <!-- Main row: the console is a launchpad. The live queue lives on My Queue
                 (its own page, which owns the note editor and history drawer); here the
                 left column mirrors the sidebar as destination cards, stats in the rail. -->
            <div class="row-2">

                <!-- Destination cards — one per sidebar nav item, same gating as
                     partials/doctor_sidebar.php so the two never disagree. -->
                <div>
                    <div class="section-head" style="margin-bottom:12px">
                        <div>
                            <div class="section-title">Where to next</div>
                            <div class="section-sub">Everything in your sidebar, one tap away</div>
                        </div>
                    </div>

                    <?php
                    // Built as data so the gating reads in one place. 'badge' shows a
                    // live count; 'disabled' matches the not-yet-built sidebar entries.
                    $navCards = [
                        ['group' => 'Clinical', 'items' => array_values(array_filter([
                            ['href' => 'my_queue.php', 'icon' => 'users', 'label' => 'My Queue',
                             'desc' => "Today's patients — start, note, finish",
                             'badge' => count($waiting) ?: null],
                            has_permission('IPD_VIEW_WARD')
                                ? ['href' => 'ipd_admissions.php', 'icon' => 'bed', 'label' => 'In-Door (IPD)',
                                   'desc' => 'Admitted patients and daily rounds'] : null,
                            ['href' => '#', 'icon' => 'stetho', 'label' => 'Consultations',
                             'desc' => 'Coming in a later phase', 'disabled' => true],
                            ['href' => '#', 'icon' => 'file', 'label' => 'Prescriptions',
                             'desc' => 'Coming in a later phase', 'disabled' => true],
                        ]))],
                    ];

                    // Dental group — same two conditions the sidebar uses: the
                    // permission AND specialty DENTAL, so a paediatrician never
                    // sees a tooth chart.
                    $isDentist = ($user['specialty'] ?? '') === 'DENTAL';
                    if ($isDentist) {
                        $dentalItems = array_values(array_filter([
                            has_permission('DENTAL_RECORD_TREATMENT')
                                ? ['href' => 'dental_treatment.php', 'icon' => 'tooth', 'label' => 'Treatment Records',
                                   'desc' => 'Tooth chart and treatment history'] : null,
                            has_permission('DENTAL_VIEW_ACCOUNTS')
                                ? ['href' => 'dental_accounts.php', 'icon' => 'receipt', 'label' => 'Dental Accounts',
                                   'desc' => 'Multi-visit package balances',
                                   'badge' => count($dentalAccounts) ?: null] : null,
                            has_permission('DENTAL_MANAGE_LAB_WORK')
                                ? ['href' => 'dental_lab.php', 'icon' => 'clock', 'label' => 'Lab Work',
                                   'desc' => 'Work sent out and due back'] : null,
                        ]));
                        if ($dentalItems) {
                            $navCards[] = ['group' => 'Dental', 'items' => $dentalItems];
                        }
                    }

                    $navCards[] = ['group' => 'Records', 'items' => [
                        ['href' => 'patients.php', 'icon' => 'search', 'label' => 'Find Patient',
                         'desc' => 'Search records by name or MRN'],
                        ['href' => 'my_schedule.php', 'icon' => 'calendar', 'label' => 'My Schedule',
                         'desc' => 'Your weekly clinic timings'],
                    ]];
                    $navCards[] = ['group' => 'Analytics', 'items' => [
                        ['href' => 'doctor_analytics.php', 'icon' => 'chart', 'label' => 'My Reports',
                         'desc' => 'Earnings, revisits and admissions'],
                    ]];
                    $navCards[] = ['group' => 'Account', 'items' => [
                        ['href' => 'profile.php', 'icon' => 'user', 'label' => 'My Profile',
                         'desc' => 'Your details and password'],
                    ]];
                    ?>

                    <?php foreach ($navCards as $grp): ?>
                    <div class="nav-card-group">
                        <div class="nav-card-group-label"><?= htmlspecialchars($grp['group']) ?></div>
                        <div class="nav-card-grid">
                            <?php foreach ($grp['items'] as $it):
                                $isDisabled = !empty($it['disabled']);
                                $tag = $isDisabled ? 'div' : 'a';
                            ?>
                            <<?= $tag ?> class="nav-card<?= $isDisabled ? ' disabled' : '' ?>"<?= $isDisabled ? '' : ' href="' . htmlspecialchars($it['href']) . '"' ?>>
                                <span class="nav-card-icon"><?= ds_icon($it['icon']) ?></span>
                                <span class="nav-card-body">
                                    <span class="nav-card-label"><?= htmlspecialchars($it['label']) ?>
                                        <?php if (!empty($it['badge'])): ?><span class="nav-card-badge tnum"><?= (int) $it['badge'] ?></span><?php endif; ?>
                                    </span>
                                    <span class="nav-card-desc"><?= htmlspecialchars($it['desc']) ?></span>
                                </span>
                            </<?= $tag ?>>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
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
                            <div class="kpi-label">Earned — <?= date('M') ?></div>
                            <div class="kpi-sub">Your share of paid consults</div>
                        </a>
                    </div>

                    <div class="card">
                        <div class="rail-title"><span>Earnings — <?= date('F') ?></span><a class="card-link" href="doctor_analytics.php?view=revenue">Full chart &rarr;</a></div>
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
                        <div class="rev-total"><span>Your earnings</span><span class="tnum"><?= number_format($moTotal) ?> PKR</span></div>
                        <div class="rv-note" style="margin-top:8px;font-size:11px;color:var(--text-muted)">Your share of paid consultation fees — the clinic keeps the rest.</div>
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
                            Consultation notes and patient history live on <a href="my_queue.php">My Queue</a>. Prescriptions land in a later phase.
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($dentalAccounts): ?>
            <div class="card">
                <div class="section-head">
                    <div>
                        <div class="section-title">Open dental packages (<?= count($dentalAccounts) ?>)</div>
                        <div class="section-sub">Multi-visit treatment you are still working through</div>
                    </div>
                    <a class="card-link" href="dental_accounts.php">All accounts &rarr;</a>
                </div>
                <div style="display:flex;flex-direction:column;gap:10px;margin-top:12px">
                    <?php foreach ($dentalAccounts as $da): $bal = (float) $da['balance']; ?>
                    <a href="dental_account.php?id=<?= (int) $da['id'] ?>"
                       style="display:flex;align-items:center;justify-content:space-between;gap:12px;
                              padding:10px 12px;border:1px solid var(--border);border-radius:var(--radius-btn);
                              text-decoration:none;color:inherit;">
                        <div>
                            <div style="font-weight:600;"><?= htmlspecialchars($da['patient_name']) ?></div>
                            <div style="font-size:var(--fs-meta);color:var(--text-muted);">
                                <?= htmlspecialchars($da['account_number']) ?> · <?= htmlspecialchars($da['title']) ?>
                            </div>
                        </div>
                        <div style="text-align:right;font-weight:700;font-variant-numeric:tabular-nums;
                                    color:<?= $bal > 0.004 ? 'var(--warn)' : ($bal < -0.004 ? 'var(--danger)' : 'var(--ok)') ?>;">
                            <?php if ($bal > 0.004): ?>Rs <?= number_format($bal, 0) ?>
                            <?php elseif ($bal < -0.004): ?>Rs <?= number_format(-$bal, 0) ?> refund
                            <?php else: ?>Clear<?php endif; ?>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($activeAdms): ?>
            <div class="card">
                <div class="section-head">
                    <div>
                        <div class="section-title">Active ER admissions (<?= count($activeAdms) ?>)</div>
                        <div class="section-sub">Patients you admitted who are still admitted</div>
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
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>
<?php if ($canDoctorAdmit) { require __DIR__ . '/partials/admit_modal.php'; } ?>
<?php if ($admitError): ?>
<script>window.addEventListener('load', function () { alert(<?= json_encode($admitError) ?>); });</script>
<?php endif; ?>
<script src="assets/js/date-picker.js?v=<?= @filemtime(__DIR__ . "/assets/js/date-picker.js") ?: 1 ?>"></script>
</body>
</html>
