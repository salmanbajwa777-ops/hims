<?php
/**
 * IPD Digital Treatment Sheet — the main per-admission view.
 *
 * Replaces the paper medication chart. Three tables (Scheduled / PRN / STAT),
 * a non-dismissible allergy banner, today's administration slots as one-tap
 * targets, and the new-order form.
 *
 * The rule the whole screen is built around: a staff member may WRITE an order,
 * but only a doctor may APPROVE it, and nothing can be administered until they
 * have. Pending rows render greyed with no tick targets at all, so the gate is
 * visible and not merely enforced on POST.
 *
 * Scope: IPD admissions only (ipd_admissions.id).
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
require_once __DIR__ . '/config/ipd_treatment.php';
require_once __DIR__ . '/config/ipd_treatment_actions.php';
refresh_session_permissions($pdo);

$baseRole = $_SESSION['base_role'] ?? '';
$uid = (int) $_SESSION['user_id'];

require_permission('IPD_VIEW_TREATMENT_SHEET');

$canWrite       = has_permission('IPD_WRITE_MED_ORDER');
$canApprove     = has_permission('IPD_APPROVE_MED_ORDER');
$canAdminister  = has_permission('IPD_ADMINISTER_MED');
$canDiscontinue = has_permission('IPD_DISCONTINUE_MED');
$canAllergies   = has_permission('IPD_MANAGE_ALLERGIES');

$admissionId = (int) ($_GET['id'] ?? 0);

$st = $pdo->prepare("
    SELECT a.*, v.token_no, v.patient_id,
           p.mrn, p.name AS patient_name, p.dob, p.gender,
           COALESCE(du.name, a.admitting_consultant_manual) AS consultant_name
    FROM ipd_admissions a
    JOIN visits v ON v.id = a.visit_id
    JOIN patients p ON p.id = v.patient_id
    LEFT JOIN users du ON du.id = a.admitting_consultant_id
    WHERE a.id = ?
");
$st->execute([$admissionId]);
$adm = $st->fetch();
if (!$adm) { http_response_code(404); exit('IPD admission not found.'); }

$patientId = (int) $adm['patient_id'];
$isOpen = $adm['status'] !== 'DISCHARGED';

$flash = ''; $err = ''; $warnings = []; $allergyHits = [];
// Survives a blocked save so the form re-renders what was typed.
$form = [];

// ---------------- POST handlers ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'new_order' && $canWrite) {
        $res = ipd_create_med_order($pdo, $admissionId, $_POST);
        if ($res['ok']) {
            $flash = $res['pending']
                ? 'Order saved — waiting for a doctor to approve it before treatment can start.'
                : 'Order saved and approved. ' . (int) $res['slots'] . ' dose(s) scheduled.';
            if ($res['warnings']) { $warnings = $res['warnings']; }
        } else {
            $err = $res['error'];
            $allergyHits = $res['allergy_hits'] ?? [];
            $form = $_POST;   // re-render the typed values, including for the override step
        }
    } elseif ($action === 'approve_order' && $canApprove) {
        $res = ipd_approve_med_order($pdo, (int) ($_POST['order_id'] ?? 0));
        if ($res['ok']) { $flash = 'Order approved — ' . (int) $res['slots'] . ' dose(s) scheduled. Treatment can start.'; }
        else { $err = $res['error']; }
    } elseif ($action === 'reject_order' && $canApprove) {
        $res = ipd_reject_med_order($pdo, (int) ($_POST['order_id'] ?? 0), $_POST['reason'] ?? '');
        $res['ok'] ? $flash = 'Order rejected.' : $err = $res['error'];
    } elseif ($action === 'stop_order' && $canDiscontinue) {
        $res = ipd_discontinue_med_order($pdo, (int) ($_POST['order_id'] ?? 0), $_POST['reason'] ?? '');
        if ($res['ok']) { $flash = 'Drug stopped. ' . (int) $res['cancelled'] . ' future dose(s) cancelled; history kept.'; }
        else { $err = $res['error']; }
    } elseif ($action === 'mark_slot' && $canAdminister) {
        $res = ipd_mark_slot($pdo, (int) ($_POST['slot_id'] ?? 0), $_POST['slot_status'] ?? '', $_POST['note'] ?? '');
        $res['ok'] ? $flash = 'Dose recorded.' : $err = $res['error'];
    } elseif ($action === 'log_prn' && $canAdminister) {
        $res = ipd_log_prn_dose($pdo, (int) ($_POST['order_id'] ?? 0), $_POST['note'] ?? '');
        $res['ok'] ? $flash = 'PRN dose recorded.' : $err = $res['error'];
    } elseif ($action === 'add_allergy' && $canAllergies) {
        $res = ipd_add_allergy($pdo, $patientId, $_POST);
        $res['ok'] ? $flash = 'Allergy recorded.' : $err = $res['error'];
    } elseif ($action === 'retire_allergy' && $canAllergies) {
        $res = ipd_retire_allergy($pdo, (int) ($_POST['allergy_id'] ?? 0), $_POST['reason'] ?? '');
        $res['ok'] ? $flash = 'Allergy retired.' : $err = $res['error'];
    }
}

// ---------------- Reads ----------------
// Top up the rolling window for ongoing orders before reading the grid, or an
// open-ended drug quietly stops appearing once its horizon runs out.
ipd_extend_ongoing_slots($pdo, $admissionId);

$allergies  = ipd_patient_allergies($pdo, $patientId);
$orders     = ipd_load_orders($pdo, $admissionId);
$todaySlots = ipd_load_today_slots($pdo, $admissionId);
$prnLog     = ipd_load_prn_log($pdo, $admissionId);
$formulary  = ipd_formulary($pdo);
$freqMap    = ipd_frequency_map($pdo);
$sheetState = ipd_sheet_state($pdo, $admissionId);

// Split into the three tables the spec asks for. Discontinued rows stay in
// their own table so the live sheet reads clean while the history is one
// scroll away, never deleted.
$scheduled = $stat = $prn = $stopped = [];
foreach ($orders as $o) {
    if ($o['status'] === 'DISCONTINUED' || $o['approval_status'] === 'REJECTED') { $stopped[] = $o; }
    elseif ($o['order_type'] === 'PRN')  { $prn[] = $o; }
    elseif ($o['order_type'] === 'STAT') { $stat[] = $o; }
    else { $scheduled[] = $o; }
}

$age = '';
if (!empty($adm['dob'])) {
    try { $age = (new DateTime($adm['dob']))->diff(new DateTime())->y . 'y'; } catch (Throwable $e) {}
}
$hospitalDay = (int) ((new DateTime(date('Y-m-d')))
    ->diff(new DateTime(date('Y-m-d', strtotime($adm['admitted_at']))))->days) + 1;

$pageTitle = 'Treatment Sheet — ' . $adm['patient_name'];
$headExtra = <<<'CSS'
<style>
.ts-wrap { max-width: 1180px; }

/* ---- Allergy banner: red, always visible, never dismissible ---- */
.ts-allergy { background: var(--danger-bg); border: 2px solid var(--danger); border-radius: var(--radius-card); padding: 12px 16px; margin-bottom: 16px; }
.ts-allergy h3 { margin: 0 0 6px; font-size: var(--fs-card); color: var(--danger); letter-spacing: .02em; }
.ts-allergy ul { margin: 0; padding-left: 18px; }
.ts-allergy li { font-size: var(--fs-cell); color: var(--text); margin-bottom: 3px; }
.ts-allergy .sev { font-weight: 700; text-transform: uppercase; font-size: var(--fs-micro, 11px); }
.ts-none { background: var(--card-alt); border: 1px dashed var(--border-strong); border-radius: var(--radius-card); padding: 10px 14px; margin-bottom: 16px; font-size: var(--fs-cell); color: var(--text-secondary); }

/* ---- "No approved treatment sheet" banner ---- */
.ts-nosheet { background: var(--warn-bg); border-left: 5px solid var(--warn); border-radius: var(--radius-card); padding: 14px 18px; margin-bottom: 16px; }
.ts-nosheet strong { color: var(--warn); display: block; font-size: var(--fs-card); margin-bottom: 4px; }
.ts-nosheet p { margin: 0; font-size: var(--fs-cell); color: var(--text-secondary); }

.ts-card { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius-card); padding: 16px 18px; margin-bottom: 18px; box-shadow: var(--shadow-sm); }
.ts-card > h2 { margin: 0 0 12px; font-size: var(--fs-card); display: flex; align-items: center; gap: 8px; }
.ts-count { background: var(--primary-light); color: var(--primary); border-radius: var(--radius-pill); padding: 1px 9px; font-size: 12px; font-weight: 600; }

/* The tables carry 6 columns of clinical data that cannot be dropped on a
   phone — a nurse needs drug, route, frequency AND today's slots together. So
   the TABLE scrolls inside its own container rather than the PAGE scrolling
   sideways. Measured at a real 390px viewport: without this the document
   scrollWidth hits ~500px and the whole layout drifts. */
.ts-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
table.ts { width: 100%; border-collapse: collapse; font-size: var(--fs-cell); min-width: 620px; }
@media (min-width: 780px) { table.ts { min-width: 0; } }
table.ts th { text-align: left; background: var(--card-alt); padding: 8px 10px; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: var(--text-secondary); border-bottom: 1px solid var(--border); }
table.ts td { padding: 9px 10px; border-bottom: 1px solid var(--row-line); vertical-align: top; }
table.ts tr:last-child td { border-bottom: none; }

.ts-generic { font-weight: 700; color: var(--text); }
.ts-brand { color: var(--text-secondary); font-weight: 500; }
.ts-dose { color: var(--text-secondary); font-size: 12.5px; }
.ts-instr { color: var(--text-muted); font-size: 12px; font-style: italic; margin-top: 2px; }

/* High-alert rows read differently at a glance, per spec 4.3. */
tr.ts-high td { background: #FDF4EC; }
.ts-hi-badge { display: inline-block; background: var(--danger); color: #fff; font-size: 10px; font-weight: 700; padding: 1px 5px; border-radius: 3px; letter-spacing: .05em; vertical-align: middle; margin-left: 5px; }

/* Pending rows are visibly not-yet-live. */
tr.ts-pending td { background: var(--warn-bg); opacity: .92; }
.ts-pend-badge { display: inline-block; background: var(--warn); color: #fff; font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 3px; letter-spacing: .04em; }
tr.ts-stopped td { opacity: .62; }
tr.ts-stopped .ts-generic, tr.ts-stopped .ts-brand { text-decoration: line-through; }
.ts-reason { color: var(--danger); font-size: 12px; margin-top: 3px; }

/* ---- Administration slots ---- */
.ts-slots { display: flex; flex-wrap: wrap; gap: 5px; }
.ts-slot { border: 1px solid var(--border-strong); border-radius: var(--radius-btn); padding: 4px 8px; font-size: 12px; font-weight: 600; background: var(--card); cursor: pointer; min-width: 54px; text-align: center; line-height: 1.3; }
.ts-slot:disabled { cursor: default; }
.ts-slot-pending { border-color: var(--border-strong); color: var(--text-secondary); }
.ts-slot-pending:hover { background: var(--primary-light); border-color: var(--primary-accent); color: var(--primary); }
.ts-slot-given { background: var(--ok-bg); border-color: var(--ok); color: var(--ok); }
.ts-slot-held { background: var(--warn-bg); border-color: var(--warn); color: var(--warn); }
.ts-slot-missed { background: var(--danger-bg); border-color: var(--danger); color: var(--danger); }
.ts-slot-cancelled { background: var(--card-alt); border-style: dashed; color: var(--text-muted); text-decoration: line-through; }
.ts-slot small { display: block; font-size: 9.5px; font-weight: 500; opacity: .85; }
.ts-locked { font-size: 12px; color: var(--warn); font-weight: 600; }

.ts-actions { display: flex; gap: 6px; flex-wrap: wrap; }
.ts-btn { border: 1px solid var(--border-strong); background: var(--card); border-radius: var(--radius-btn); padding: 4px 10px; font-size: var(--fs-btn-sm); font-weight: 600; cursor: pointer; color: var(--text-secondary); }
.ts-btn:hover { background: var(--card-alt); }
.ts-btn-ok { background: var(--ok); border-color: var(--ok); color: #fff; }
.ts-btn-ok:hover { filter: brightness(1.08); background: var(--ok); }
.ts-btn-danger { color: var(--danger); border-color: var(--danger); }
.ts-btn-danger:hover { background: var(--danger-bg); }

/* ---- New order form ---- */
.ts-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; }
.ts-field label { display: block; font-size: 12px; font-weight: 600; color: var(--text-secondary); margin-bottom: 4px; }
.ts-field input, .ts-field select, .ts-field textarea { width: 100%; padding: 8px 10px; border: 1px solid var(--border-strong); border-radius: var(--radius-input); font: inherit; font-size: var(--fs-cell); background: var(--card); color: var(--text); }
.ts-field input:focus, .ts-field select:focus, .ts-field textarea:focus { outline: 2px solid var(--primary-accent); outline-offset: -1px; }
.ts-full { grid-column: 1 / -1; }

.ts-flash { padding: 10px 14px; border-radius: var(--radius-input); margin-bottom: 14px; font-size: var(--fs-cell); font-weight: 500; }
.ts-flash-ok { background: var(--ok-bg); color: var(--ok); border: 1px solid var(--ok); }
.ts-flash-err { background: var(--danger-bg); color: var(--danger); border: 1px solid var(--danger); }
.ts-flash-warn { background: var(--warn-bg); color: var(--warn); border: 1px solid var(--warn); }

.ts-hdr { display: flex; flex-wrap: wrap; gap: 18px; background: var(--card); border: 1px solid var(--border); border-radius: var(--radius-card); padding: 14px 18px; margin-bottom: 16px; }
.ts-hdr div { font-size: var(--fs-cell); }
.ts-hdr span { display: block; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted); font-weight: 600; }
.ts-hdr b { font-weight: 600; color: var(--text); }

dialog.ts-modal { border: none; border-radius: var(--radius-card); padding: 0; max-width: 460px; width: 92vw; box-shadow: var(--shadow-lg); }
dialog.ts-modal::backdrop { background: rgba(22,33,28,.45); }
.ts-modal-in { padding: 20px 22px; }
.ts-modal-in h3 { margin: 0 0 10px; font-size: var(--fs-card); }
.ts-modal-in p { font-size: var(--fs-cell); color: var(--text-secondary); margin: 0 0 12px; }

/* ---- Print: the sheet must survive a network/power cut (spec 7) ---- */
@media print {
    @page { size: A4; margin: 12mm; }
    .sidebar, .app-bar, .no-print, .ts-btn, .ts-actions, form.ts-orderform,
    button, dialog, .view-toggle, .ts-flash { display: none !important; }
    body { background: #fff; }
    .ts-wrap { max-width: none; }
    .ts-card { border: 1px solid #000; box-shadow: none; page-break-inside: avoid; margin-bottom: 10px; }
    .ts-allergy { border: 2px solid #000; }
    .ts-slot { border: 1px solid #666; }
    table.ts th { background: #eee !important; }
    tr.ts-high td { background: #f2f2f2 !important; }
    .ts-print-sign { display: block !important; margin-top: 26px; }
}
.ts-print-sign { display: none; }
</style>
CSS;

include __DIR__ . '/partials/head.php';
include __DIR__ . '/partials/sidebar.php';

/** Render one order's today-slots as tap targets. */
function ts_render_slots(array $o, array $slots, bool $canAdminister): void {
    $orderId = (int) $o['id'];
    $mine = $slots[$orderId] ?? [];
    if (!$mine) {
        echo '<span class="ts-dose">&mdash;</span>';
        return;
    }
    echo '<div class="ts-slots">';
    foreach ($mine as $s) {
        [$label, $cls] = ipd_slot_badge($s['status']);
        $time = date('H:i', strtotime($s['scheduled_datetime']));
        $live = $s['status'] === 'PENDING' && $canAdminister && can_administer_order($o);
        $title = $s['status'] === 'GIVEN' && $s['given_by_name']
            ? 'Given by ' . $s['given_by_name'] . ' at ' . date('H:i', strtotime($s['given_at']))
            : $label;
        if ($live) {
            printf(
                '<button type="button" class="ts-slot %s" data-slot="%d" data-time="%s" data-drug="%s" title="Tap to record">%s<small>%s</small></button>',
                $cls, (int) $s['id'], htmlspecialchars($time),
                htmlspecialchars(ipd_order_line($o)), htmlspecialchars($time), htmlspecialchars($label)
            );
        } else {
            printf('<span class="ts-slot %s" title="%s">%s<small>%s</small></span>',
                $cls, htmlspecialchars($title), htmlspecialchars($time), htmlspecialchars($label));
        }
    }
    echo '</div>';
}

/** The drug-name cell: generic and brand rendered as the distinct things they are. */
function ts_drug_cell(array $o): void {
    $generic = $o['generic_name_snapshot'] ?: $o['drug_name_snapshot'];
    echo '<div class="ts-generic">' . htmlspecialchars($generic);
    if ((int) $o['is_high_alert']) { echo '<span class="ts-hi-badge">HIGH ALERT</span>'; }
    echo '</div>';
    if (!empty($o['brand_name_snapshot'])) {
        echo '<div class="ts-brand">' . htmlspecialchars($o['brand_name_snapshot']) . '</div>';
    }
    echo '<div class="ts-dose">' . htmlspecialchars(ipd_dose_line($o)) . '</div>';
    if (!empty($o['special_instructions'])) {
        echo '<div class="ts-instr">' . htmlspecialchars($o['special_instructions']) . '</div>';
    }
}
?>
<main class="page-wrap">
  <div class="ts-wrap">

    <div class="page-head no-print" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
      <h1 class="page-title">Treatment Sheet</h1>
      <div style="display:flex;gap:8px;">
        <a class="ts-btn" href="ipd_admission.php?id=<?= $admissionId ?>">&larr; Back to stay</a>
        <button type="button" class="ts-btn" onclick="window.print()">Print sheet</button>
      </div>
    </div>

    <?php if ($flash): ?><div class="ts-flash ts-flash-ok"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="ts-flash ts-flash-err"><?= htmlspecialchars($err) ?></div><?php endif; ?>
    <?php foreach ($warnings as $w): ?>
      <div class="ts-flash ts-flash-warn">Duplicate therapy check &mdash; <?= htmlspecialchars($w) ?></div>
    <?php endforeach; ?>

    <!-- Patient header -->
    <div class="ts-hdr">
      <div><span>Patient</span><b><?= htmlspecialchars($adm['patient_name']) ?></b></div>
      <div><span>MRN</span><b><?= htmlspecialchars($adm['mrn']) ?></b></div>
      <div><span>Age / Sex</span><b><?= htmlspecialchars(trim($age . ' ' . ($adm['gender'] ?? ''))) ?: '&mdash;' ?></b></div>
      <div><span>Room</span><b><?= htmlspecialchars($adm['room_category']) ?> &middot; <?= (int) $adm['room_no'] ?></b></div>
      <div><span>Consultant</span><b><?= htmlspecialchars($adm['consultant_name'] ?? '—') ?></b></div>
      <div><span>Admitted</span><b><?= date('d/m/Y', strtotime($adm['admitted_at'])) ?> (day <?= $hospitalDay ?>)</b></div>
      <div><span>Diagnosis</span><b><?= htmlspecialchars($adm['provisional_diagnosis'] ?? '—') ?></b></div>
    </div>

    <!-- Allergy banner: red, non-dismissible, live from patient_allergies -->
    <?php if ($allergies): ?>
      <div class="ts-allergy">
        <h3>&#9888; ALLERGIES</h3>
        <ul>
          <?php foreach ($allergies as $a): ?>
            <li>
              <b><?= htmlspecialchars($a['substance']) ?></b>
              <?php if ($a['reaction']): ?> &mdash; <?= htmlspecialchars($a['reaction']) ?><?php endif; ?>
              <span class="sev">[<?= htmlspecialchars($a['severity']) ?>]</span>
              <?php if ($canAllergies): ?>
                <button type="button" class="ts-btn no-print" style="padding:1px 6px;font-size:11px;"
                        onclick="tsRetire(<?= (int) $a['id'] ?>)">retire</button>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php else: ?>
      <div class="ts-none">No known drug allergies recorded for this patient.
        <?php if ($canAllergies): ?>
          <button type="button" class="ts-btn no-print" onclick="document.getElementById('tsAllergyForm').hidden=false">Record an allergy</button>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($canAllergies): ?>
      <form method="post" class="ts-card no-print" id="tsAllergyForm" <?= $allergies ? 'hidden' : 'hidden' ?>>
        <h2>Record an allergy</h2>
        <input type="hidden" name="action" value="add_allergy">
        <div class="ts-grid">
          <div class="ts-field"><label>Substance *</label><input name="substance" required placeholder="e.g. PENICILLIN" class="uc"></div>
          <div class="ts-field"><label>Reaction</label><input name="reaction" placeholder="e.g. Rash, anaphylaxis"></div>
          <div class="ts-field"><label>Severity</label>
            <select name="severity">
              <option value="UNKNOWN">Unknown</option><option value="MILD">Mild</option>
              <option value="MODERATE">Moderate</option><option value="SEVERE">Severe</option>
            </select>
          </div>
          <div class="ts-field" style="display:flex;align-items:flex-end;"><button class="ts-btn ts-btn-ok" style="width:100%;padding:8px;">Save allergy</button></div>
        </div>
      </form>
      <?php if ($allergies): ?>
        <p class="no-print" style="margin:-8px 0 16px;">
          <button type="button" class="ts-btn" onclick="document.getElementById('tsAllergyForm').hidden=false">+ Record another allergy</button>
        </p>
      <?php endif; ?>
    <?php endif; ?>

    <!-- The persistent "sheet not signed" banner -->
    <?php if (!($sheetState['available'] ?? true)): ?>
      <div class="ts-flash ts-flash-err">
        The treatment sheet tables are not set up yet &mdash; run
        <b>sql/ipd/add_ipd_treatment_sheet.sql</b>, then
        <b>sql/ipd/verify_ipd_treatment_sheet.sql</b>. Nothing on this page will save until then.
      </div>
    <?php elseif (!$sheetState['cleared'] && $isOpen): ?>
      <div class="ts-nosheet">
        <strong>&#9888; No approved treatment sheet &mdash; treatment cannot start</strong>
        <p>
          <?php if ($sheetState['pending']): ?>
            <?= (int) $sheetState['pending'] ?> order(s) are waiting for a doctor to approve them.
            Nothing on this sheet may be administered until a doctor signs.
          <?php else: ?>
            No medication has been ordered for this admitted patient yet.
          <?php endif; ?>
        </p>
      </div>
    <?php elseif ($sheetState['pending'] && $isOpen): ?>
      <div class="ts-nosheet">
        <strong>&#9888; <?= (int) $sheetState['pending'] ?> order(s) awaiting doctor approval</strong>
        <p>Approved drugs below are running normally. The highlighted rows cannot be given until a doctor signs them.</p>
      </div>
    <?php endif; ?>

    <!-- SCHEDULED -->
    <div class="ts-card">
      <h2>Scheduled medications <span class="ts-count"><?= count($scheduled) ?></span></h2>
      <?php if (!$scheduled): ?>
        <p class="ts-dose">No scheduled medications ordered.</p>
      <?php else: ?>
      <div class="ts-scroll">
      <table class="ts">
        <thead><tr>
          <th style="width:26%">Drug</th><th style="width:9%">Route</th><th style="width:9%">Freq</th>
          <th style="width:13%">Course</th><th style="width:23%">Today</th><th style="width:20%">Status</th>
        </tr></thead>
        <tbody>
        <?php foreach ($scheduled as $o): $pending = $o['approval_status'] === 'PENDING'; ?>
          <tr class="<?= (int) $o['is_high_alert'] ? 'ts-high' : '' ?> <?= $pending ? 'ts-pending' : '' ?>">
            <td><?php ts_drug_cell($o); ?></td>
            <td><?= htmlspecialchars($o['route']) ?></td>
            <td><?= htmlspecialchars($o['frequency']) ?></td>
            <td>
              <?= $o['duration_days'] === null ? 'Ongoing' : (int) $o['duration_days'] . ' days' ?>
              <div class="ts-dose">from <?= date('d/m H:i', strtotime($o['start_datetime'])) ?></div>
            </td>
            <td>
              <?php if ($pending): ?>
                <span class="ts-locked">Locked &mdash; needs approval</span>
              <?php else: ts_render_slots($o, $todaySlots, $canAdminister); endif; ?>
            </td>
            <td>
              <?php if ($pending): ?>
                <span class="ts-pend-badge">PENDING DOCTOR</span>
                <div class="ts-dose">by <?= htmlspecialchars($o['prescribed_by_name'] ?? '—') ?></div>
                <?php if ($canApprove): ?>
                  <div class="ts-actions no-print" style="margin-top:6px;">
                    <form method="post" style="display:inline;">
                      <input type="hidden" name="action" value="approve_order">
                      <input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>">
                      <button class="ts-btn ts-btn-ok">Approve</button>
                    </form>
                    <button type="button" class="ts-btn ts-btn-danger" onclick="tsReject(<?= (int) $o['id'] ?>)">Reject</button>
                  </div>
                <?php endif; ?>
              <?php else: ?>
                <div class="ts-dose">Approved by <?= htmlspecialchars($o['approved_by_name'] ?? '—') ?></div>
                <?php if ((int) $o['allergy_override']): ?>
                  <div class="ts-reason">Allergy override: <?= htmlspecialchars($o['allergy_override_reason']) ?></div>
                <?php endif; ?>
                <?php if ($canDiscontinue && $isOpen): ?>
                  <div class="ts-actions no-print" style="margin-top:6px;">
                    <button type="button" class="ts-btn ts-btn-danger" onclick="tsStop(<?= (int) $o['id'] ?>, '<?= htmlspecialchars(addslashes(ipd_order_line($o)), ENT_QUOTES) ?>')">Stop drug</button>
                  </div>
                <?php endif; ?>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php endif; ?>
    </div>

    <!-- PRN -->
    <div class="ts-card">
      <h2>PRN / as-needed medications <span class="ts-count"><?= count($prn) ?></span></h2>
      <?php if (!$prn): ?>
        <p class="ts-dose">No PRN medications ordered.</p>
      <?php else: ?>
      <div class="ts-scroll">
      <table class="ts">
        <thead><tr>
          <th style="width:26%">Drug</th><th style="width:9%">Route</th><th style="width:20%">Indication</th>
          <th style="width:12%">Max / 24h</th><th style="width:20%">Given today</th><th style="width:13%">Action</th>
        </tr></thead>
        <tbody>
        <?php foreach ($prn as $o):
            $pending = $o['approval_status'] === 'PENDING';
            $given24 = ipd_prn_given_24h($pdo, (int) $o['id']);
            $atCap = $o['prn_max_per_24h'] !== null && $given24 >= (int) $o['prn_max_per_24h'];
        ?>
          <tr class="<?= (int) $o['is_high_alert'] ? 'ts-high' : '' ?> <?= $pending ? 'ts-pending' : '' ?>">
            <td><?php ts_drug_cell($o); ?></td>
            <td><?= htmlspecialchars($o['route']) ?></td>
            <td><?= htmlspecialchars($o['prn_indication'] ?? '—') ?></td>
            <td>
              <b><?= $given24 ?> / <?= (int) $o['prn_max_per_24h'] ?></b>
              <?php if ($atCap): ?><div class="ts-reason">At the 24h limit</div><?php endif; ?>
            </td>
            <td>
              <?php foreach (($prnLog[(int) $o['id']] ?? []) as $d): ?>
                <div class="ts-dose"><?= date('H:i', strtotime($d['given_at'])) ?> &middot; <?= htmlspecialchars($d['given_by_name'] ?? '—') ?></div>
              <?php endforeach; ?>
              <?php if (empty($prnLog[(int) $o['id']])): ?><span class="ts-dose">&mdash;</span><?php endif; ?>
            </td>
            <td class="no-print">
              <?php if ($pending): ?>
                <span class="ts-pend-badge">PENDING DOCTOR</span>
                <?php if ($canApprove): ?>
                  <form method="post" style="display:inline;margin-top:6px;">
                    <input type="hidden" name="action" value="approve_order">
                    <input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>">
                    <button class="ts-btn ts-btn-ok">Approve</button>
                  </form>
                <?php endif; ?>
              <?php elseif ($canAdminister && !$atCap && $isOpen): ?>
                <button type="button" class="ts-btn ts-btn-ok" onclick="tsPrn(<?= (int) $o['id'] ?>, '<?= htmlspecialchars(addslashes(ipd_order_line($o)), ENT_QUOTES) ?>')">Give now</button>
              <?php endif; ?>
              <?php if (!$pending && $canDiscontinue && $isOpen): ?>
                <button type="button" class="ts-btn ts-btn-danger" style="margin-top:5px;" onclick="tsStop(<?= (int) $o['id'] ?>, '<?= htmlspecialchars(addslashes(ipd_order_line($o)), ENT_QUOTES) ?>')">Stop</button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php endif; ?>
    </div>

    <!-- STAT -->
    <div class="ts-card">
      <h2>STAT / one-time orders <span class="ts-count"><?= count($stat) ?></span></h2>
      <?php if (!$stat): ?>
        <p class="ts-dose">No STAT orders.</p>
      <?php else: ?>
      <div class="ts-scroll">
      <table class="ts">
        <thead><tr>
          <th style="width:32%">Drug</th><th style="width:10%">Route</th><th style="width:18%">Ordered</th>
          <th style="width:22%">Given</th><th style="width:18%">Status</th>
        </tr></thead>
        <tbody>
        <?php foreach ($stat as $o): $pending = $o['approval_status'] === 'PENDING'; ?>
          <tr class="<?= (int) $o['is_high_alert'] ? 'ts-high' : '' ?> <?= $pending ? 'ts-pending' : '' ?>">
            <td><?php ts_drug_cell($o); ?></td>
            <td><?= htmlspecialchars($o['route']) ?></td>
            <td><?= date('d/m H:i', strtotime($o['prescribed_at'])) ?><div class="ts-dose"><?= htmlspecialchars($o['prescribed_by_name'] ?? '—') ?></div></td>
            <td><?php if ($pending): ?><span class="ts-locked">Locked</span><?php else: ts_render_slots($o, $todaySlots, $canAdminister); endif; ?></td>
            <td>
              <?php if ($pending): ?>
                <span class="ts-pend-badge">PENDING DOCTOR</span>
                <?php if ($canApprove): ?>
                  <form method="post" style="display:inline;" class="no-print">
                    <input type="hidden" name="action" value="approve_order">
                    <input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>">
                    <button class="ts-btn ts-btn-ok" style="margin-top:5px;">Approve</button>
                  </form>
                <?php endif; ?>
              <?php else: ?>
                <div class="ts-dose">Approved by <?= htmlspecialchars($o['approved_by_name'] ?? '—') ?></div>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php endif; ?>
    </div>

    <!-- STOPPED / REJECTED — never deleted -->
    <?php if ($stopped): ?>
    <div class="ts-card">
      <h2>Stopped &amp; rejected orders <span class="ts-count"><?= count($stopped) ?></span></h2>
      <div class="ts-scroll">
      <table class="ts">
        <thead><tr><th style="width:32%">Drug</th><th style="width:12%">Route/Freq</th><th style="width:56%">Why it stopped</th></tr></thead>
        <tbody>
        <?php foreach ($stopped as $o): ?>
          <tr class="ts-stopped">
            <td><?php ts_drug_cell($o); ?></td>
            <td><?= htmlspecialchars($o['route'] . ' ' . $o['frequency']) ?></td>
            <td>
              <?php if ($o['approval_status'] === 'REJECTED'): ?>
                <b>Rejected</b> by <?= htmlspecialchars($o['approved_by_name'] ?? '—') ?>
                on <?= $o['approved_at'] ? date('d/m/Y H:i', strtotime($o['approved_at'])) : '—' ?>
                <div class="ts-reason"><?= htmlspecialchars($o['rejected_reason'] ?? '') ?></div>
              <?php else: ?>
                <b>Stopped</b> by <?= htmlspecialchars($o['discontinued_by_name'] ?? '—') ?>
                on <?= $o['discontinued_at'] ? date('d/m/Y H:i', strtotime($o['discontinued_at'])) : '—' ?>
                <div class="ts-reason"><?= htmlspecialchars($o['discontinued_reason'] ?? '') ?></div>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>
    <?php endif; ?>

    <!-- NEW ORDER -->
    <?php if ($canWrite && $isOpen): ?>
    <form method="post" class="ts-card ts-orderform no-print" id="tsNewOrder">
      <h2>Add a medication order</h2>
      <input type="hidden" name="action" value="new_order">

      <?php if ($allergyHits): ?>
        <div class="ts-flash ts-flash-err">
          <b>Allergy conflict</b>
          <ul style="margin:6px 0 0;padding-left:18px;">
            <?php foreach ($allergyHits as $h): ?>
              <li><?= htmlspecialchars($h['substance']) ?> (<?= htmlspecialchars($h['severity']) ?>)
                  &mdash; <?= htmlspecialchars($h['match_reason'] ?? '') ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php if ($canApprove): ?>
          <div class="ts-field ts-full" style="margin-bottom:12px;">
            <label>Override reason (required to proceed) *</label>
            <input name="allergy_override_reason" required
                   placeholder="Clinical justification — this is recorded against your name"
                   value="<?= htmlspecialchars($form['allergy_override_reason'] ?? '') ?>">
          </div>
        <?php else: ?>
          <p class="ts-flash ts-flash-warn">Only a doctor can override a documented allergy.</p>
        <?php endif; ?>
      <?php endif; ?>

      <div class="ts-grid">
        <div class="ts-field ts-full">
          <label>Drug (generic) *</label>
          <input list="tsDrugs" name="drug_search" id="tsDrugSearch" autocomplete="off"
                 placeholder="Type to search the formulary, or type any drug name"
                 value="<?= htmlspecialchars($form['drug_search'] ?? '') ?>">
          <datalist id="tsDrugs">
            <?php foreach ($formulary as $d): ?>
              <option value="<?= htmlspecialchars($d['generic_name']) ?>"
                      label="<?= htmlspecialchars(trim(($d['brand_name'] ? $d['brand_name'] . ' · ' : '') . ($d['drug_class'] ?? ''))) ?>"></option>
            <?php endforeach; ?>
          </datalist>
          <input type="hidden" name="drug_id" id="tsDrugId" value="<?= (int) ($form['drug_id'] ?? 0) ?>">
          <input type="hidden" name="drug_name_manual" id="tsDrugManual" value="<?= htmlspecialchars($form['drug_name_manual'] ?? '') ?>">
          <div class="ts-instr" id="tsDrugInfo"></div>
        </div>

        <div class="ts-field">
          <label>Brand (optional)</label>
          <input name="brand_name" id="tsBrand" placeholder="Trade name as dispensed"
                 value="<?= htmlspecialchars($form['brand_name'] ?? '') ?>">
        </div>
        <div class="ts-field">
          <label>Dose *</label>
          <input name="dose_value" type="number" step="0.001" min="0.001" required
                 value="<?= htmlspecialchars($form['dose_value'] ?? '') ?>">
        </div>
        <div class="ts-field">
          <label>Unit *</label>
          <select name="dose_unit" id="tsUnit" required>
            <?php foreach (IPD_DOSE_UNITS as $u): ?>
              <option value="<?= $u ?>" <?= ($form['dose_unit'] ?? 'mg') === $u ? 'selected' : '' ?>><?= $u ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="ts-field">
          <label>Route *</label>
          <select name="route" required>
            <?php foreach (IPD_ROUTES as $code => $lbl): ?>
              <option value="<?= $code ?>" <?= ($form['route'] ?? '') === $code ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="ts-field">
          <label>Frequency *</label>
          <select name="frequency" id="tsFreq" required>
            <?php foreach ($freqMap as $code => $f): ?>
              <option value="<?= $code ?>" <?= ($form['frequency'] ?? 'TDS') === $code ? 'selected' : '' ?>>
                <?= $code ?> &mdash; <?= htmlspecialchars($f['label']) ?><?= $f['times'] ? ' (' . implode(', ', $f['times']) . ')' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="ts-field">
          <label>Duration (days)</label>
          <input name="duration_days" type="number" min="1" max="365" placeholder="blank = ongoing"
                 value="<?= htmlspecialchars($form['duration_days'] ?? '') ?>">
        </div>
        <div class="ts-field">
          <label>Start</label>
          <input name="start_datetime" type="datetime-local"
                 value="<?= htmlspecialchars($form['start_datetime'] ?? date('Y-m-d\TH:i')) ?>">
        </div>

        <div class="ts-field" id="tsPrnInd" hidden>
          <label>PRN indication *</label>
          <input name="prn_indication" placeholder="What is it for?" value="<?= htmlspecialchars($form['prn_indication'] ?? '') ?>">
        </div>
        <div class="ts-field" id="tsPrnMax" hidden>
          <label>Max doses / 24h *</label>
          <input name="prn_max_per_24h" type="number" min="1" max="24" value="<?= htmlspecialchars($form['prn_max_per_24h'] ?? '') ?>">
        </div>

        <div class="ts-field ts-full">
          <label>Special instructions</label>
          <input name="special_instructions" placeholder="e.g. Take with food; infuse over 30 min"
                 value="<?= htmlspecialchars($form['special_instructions'] ?? '') ?>">
        </div>
        <div class="ts-field ts-full" style="display:flex;align-items:center;gap:8px;">
          <input type="checkbox" name="continue_at_discharge" id="tsCont" value="1" style="width:auto;"
                 <?= !empty($form['continue_at_discharge']) ? 'checked' : '' ?>>
          <label for="tsCont" style="margin:0;">Continue this medication at discharge</label>
        </div>
      </div>

      <div style="margin-top:14px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
        <button class="ts-btn ts-btn-ok" style="padding:9px 20px;">
          <?= $canApprove ? 'Save &amp; approve order' : 'Submit for doctor approval' ?>
        </button>
        <?php if (!$canApprove): ?>
          <span class="ts-dose">You can write this order; a doctor must approve it before it can be given.</span>
        <?php endif; ?>
      </div>
    </form>
    <?php endif; ?>

    <!-- Print-only signature block -->
    <div class="ts-print-sign">
      <table style="width:100%;font-size:12px;">
        <tr>
          <td style="padding-top:24px;">Consultant: ______________________</td>
          <td style="padding-top:24px;">Nurse: ______________________</td>
          <td style="padding-top:24px;">Printed: <?= date('d/m/Y H:i') ?></td>
        </tr>
      </table>
    </div>

  </div>
</main>

<!-- ---- Modals ---- -->
<dialog class="ts-modal" id="tsSlotModal">
  <form method="post" class="ts-modal-in">
    <h3>Record dose</h3>
    <p id="tsSlotDrug"></p>
    <input type="hidden" name="action" value="mark_slot">
    <input type="hidden" name="slot_id" id="tsSlotId">
    <input type="hidden" name="slot_status" id="tsSlotStatus" value="GIVEN">
    <div class="ts-field" style="margin-bottom:14px;">
      <label>Note (required if held or missed)</label>
      <input name="note" id="tsSlotNote" placeholder="e.g. patient refused, NPO for theatre">
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <button class="ts-btn ts-btn-ok" onclick="document.getElementById('tsSlotStatus').value='GIVEN'">Given</button>
      <button class="ts-btn" onclick="document.getElementById('tsSlotStatus').value='HELD'">Held</button>
      <button class="ts-btn ts-btn-danger" onclick="document.getElementById('tsSlotStatus').value='MISSED'">Missed</button>
      <button type="button" class="ts-btn" onclick="document.getElementById('tsSlotModal').close()">Cancel</button>
    </div>
  </form>
</dialog>

<?php /* Doctor-only dialogs. The server re-checks the permission on every POST
         (see ipd_treatment_actions.php), so this is presentation only — but
         emitting a Stop/Reject form to someone who can never use it invites
         confusion and shows up as a false positive in any markup audit. */ ?>
<?php if ($canDiscontinue): ?>
<dialog class="ts-modal" id="tsStopModal">
  <form method="post" class="ts-modal-in">
    <h3>Stop this drug</h3>
    <p id="tsStopDrug"></p>
    <p>Future doses are cancelled. Everything already given stays on the record.</p>
    <input type="hidden" name="action" value="stop_order">
    <input type="hidden" name="order_id" id="tsStopId">
    <div class="ts-field" style="margin-bottom:14px;">
      <label>Reason *</label>
      <input name="reason" required placeholder="e.g. Course completed early, rash developed">
    </div>
    <div style="display:flex;gap:8px;">
      <button class="ts-btn ts-btn-danger">Stop drug</button>
      <button type="button" class="ts-btn" onclick="document.getElementById('tsStopModal').close()">Cancel</button>
    </div>
  </form>
</dialog>
<?php endif; ?>

<?php if ($canApprove): ?>
<dialog class="ts-modal" id="tsRejectModal">
  <form method="post" class="ts-modal-in">
    <h3>Reject this order</h3>
    <input type="hidden" name="action" value="reject_order">
    <input type="hidden" name="order_id" id="tsRejectId">
    <div class="ts-field" style="margin-bottom:14px;">
      <label>Reason *</label>
      <input name="reason" required placeholder="Why should this not be given?">
    </div>
    <div style="display:flex;gap:8px;">
      <button class="ts-btn ts-btn-danger">Reject order</button>
      <button type="button" class="ts-btn" onclick="document.getElementById('tsRejectModal').close()">Cancel</button>
    </div>
  </form>
</dialog>
<?php endif; ?>

<dialog class="ts-modal" id="tsPrnModal">
  <form method="post" class="ts-modal-in">
    <h3>Give PRN dose</h3>
    <p id="tsPrnDrug"></p>
    <input type="hidden" name="action" value="log_prn">
    <input type="hidden" name="order_id" id="tsPrnId">
    <div class="ts-field" style="margin-bottom:14px;">
      <label>Note / indication observed</label>
      <input name="note" placeholder="e.g. pain score 8/10">
    </div>
    <div style="display:flex;gap:8px;">
      <button class="ts-btn ts-btn-ok">Record dose</button>
      <button type="button" class="ts-btn" onclick="document.getElementById('tsPrnModal').close()">Cancel</button>
    </div>
  </form>
</dialog>

<dialog class="ts-modal" id="tsRetireModal">
  <form method="post" class="ts-modal-in">
    <h3>Retire this allergy</h3>
    <p>The allergy stays on record as history — it just stops blocking new orders.</p>
    <input type="hidden" name="action" value="retire_allergy">
    <input type="hidden" name="allergy_id" id="tsRetireId">
    <div class="ts-field" style="margin-bottom:14px;">
      <label>Reason *</label>
      <input name="reason" required placeholder="e.g. Refuted on review — tolerated test dose">
    </div>
    <div style="display:flex;gap:8px;">
      <button class="ts-btn ts-btn-danger">Retire allergy</button>
      <button type="button" class="ts-btn" onclick="document.getElementById('tsRetireModal').close()">Cancel</button>
    </div>
  </form>
</dialog>

<script>
/* Formulary, for the typeahead's id resolution + default pre-fills. Generic and
   brand stay separate here exactly as they are in the database. */
const TS_DRUGS = <?= json_encode(array_map(fn($d) => [
    'id'    => (int) $d['id'],
    'gen'   => $d['generic_name'],
    'brand' => $d['brand_name'],
    'cls'   => $d['drug_class'],
    'hi'    => (int) $d['is_high_alert'],
    'unit'  => $d['default_dose_unit'],
    'freqs' => $d['default_frequencies'],
], $formulary), JSON_UNESCAPED_UNICODE) ?>;

const tsSearch = document.getElementById('tsDrugSearch');
if (tsSearch) {
    tsSearch.addEventListener('input', function () {
        const v = this.value.trim().toUpperCase();
        const hit = TS_DRUGS.find(d => d.gen.toUpperCase() === v);
        const info = document.getElementById('tsDrugInfo');
        if (hit) {
            /* A formulary pick: carry the id so the server uses catalogue data
               (class, high-alert, allergy group) rather than trusting the text. */
            document.getElementById('tsDrugId').value = hit.id;
            document.getElementById('tsDrugManual').value = '';
            if (hit.brand && !document.getElementById('tsBrand').value) {
                document.getElementById('tsBrand').value = hit.brand;
            }
            if (hit.unit) { document.getElementById('tsUnit').value = hit.unit; }
            if (hit.freqs) {
                const first = hit.freqs.split(',')[0].trim();
                if (first) { document.getElementById('tsFreq').value = first; }
            }
            info.textContent = (hit.cls || '') + (hit.hi ? '  ⚠ HIGH-ALERT DRUG' : '');
            info.style.color = hit.hi ? 'var(--danger)' : '';
        } else {
            /* Free-typed: no formulary row, so no class-based checks are possible. */
            document.getElementById('tsDrugId').value = 0;
            document.getElementById('tsDrugManual').value = this.value.trim();
            info.textContent = this.value.trim()
                ? 'Not in the formulary — allergy checking will match on name only.' : '';
            info.style.color = '';
        }
    });
    tsSearch.dispatchEvent(new Event('input'));
}

/* PRN reveals its two mandatory fields. */
const tsFreq = document.getElementById('tsFreq');
function tsSyncPrn() {
    const isPrn = tsFreq && tsFreq.value === 'PRN';
    ['tsPrnInd', 'tsPrnMax'].forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        el.hidden = !isPrn;
        el.querySelectorAll('input').forEach(i => i.required = isPrn);
    });
}
if (tsFreq) { tsFreq.addEventListener('change', tsSyncPrn); tsSyncPrn(); }

function tsSlot(id, time, drug) {
    document.getElementById('tsSlotId').value = id;
    document.getElementById('tsSlotDrug').textContent = drug + ' — due ' + time;
    document.getElementById('tsSlotNote').value = '';
    document.getElementById('tsSlotModal').showModal();
}
document.querySelectorAll('.ts-slot[data-slot]').forEach(b => {
    b.addEventListener('click', () => tsSlot(b.dataset.slot, b.dataset.time, b.dataset.drug));
});
/* The Stop/Reject dialogs are only emitted for a prescriber, so these guard on
   the dialog existing rather than assuming it does. */
function tsStop(id, drug) {
    const m = document.getElementById('tsStopModal');
    if (!m) { return; }
    document.getElementById('tsStopId').value = id;
    document.getElementById('tsStopDrug').textContent = drug;
    m.showModal();
}
function tsReject(id) {
    const m = document.getElementById('tsRejectModal');
    if (!m) { return; }
    document.getElementById('tsRejectId').value = id;
    m.showModal();
}
function tsPrn(id, drug) {
    document.getElementById('tsPrnId').value = id;
    document.getElementById('tsPrnDrug').textContent = drug;
    document.getElementById('tsPrnModal').showModal();
}
function tsRetire(id) {
    document.getElementById('tsRetireId').value = id;
    document.getElementById('tsRetireModal').showModal();
}
</script>
</body>
</html>
