<?php
/**
 * In-Door (IPD) stay — the per-admission hub page.
 *
 * Phase 1 renders the auto-filled header only (patient, MR, admission no., age,
 * gender, room category, room no, consultant, admitted, status). Later phases attach the
 * daily-round note (Phase 2), vitals/care/handover (Phase 3), and discharge +
 * billing (Phase 4) panels here. This is the IPD counterpart to admission.php.
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
require_once __DIR__ . '/config/tokens.php';
require_once __DIR__ . '/config/billing.php';        // require_day_open()
require_once __DIR__ . '/config/ipd_billing.php';    // service charge + bill append
require_once __DIR__ . '/config/ipd_advances.php';
require_once __DIR__ . '/config/payment_methods.php';
require_once __DIR__ . '/config/ipd_treatment.php';   // ipd_sheet_state() for the sheet card + banner
refresh_session_permissions($pdo);

$baseRole = $_SESSION['base_role'] ?? '';
$uid = (int) $_SESSION['user_id'];

require_permission('IPD_VIEW_WARD');

// Doctors see clinical/operational data, never charges (Phase 4 uses this).
$hideMoney = ($baseRole === 'DOCTOR');

$admissionId = (int) ($_GET['id'] ?? 0);

function load_ipd_admission(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("
        SELECT a.*, v.token_no, v.visit_date,
               p.id AS patient_id, p.mrn, p.name AS patient_name, p.phone, p.dob, p.gender,
               COALESCE(du.name, a.admitting_consultant_manual) AS consultant_name,
               -- Prefix from the VISIT's doctor, not the consultant — see admissions.php.
               vd.name AS token_doctor_name, vd.token_prefix
        FROM ipd_admissions a
        JOIN visits v ON v.id = a.visit_id
        JOIN patients p ON p.id = v.patient_id
        LEFT JOIN users vd ON vd.id = v.doctor_id
        LEFT JOIN users du ON du.id = a.admitting_consultant_id
        WHERE a.id = ?
    ");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

$adm = load_ipd_admission($pdo, $admissionId);
if (!$adm) { http_response_code(404); exit('IPD admission not found.'); }

$flash = '';
$err = '';
$isOpen = $adm['status'] === 'ACTIVE';
$loggedRole = in_array($baseRole, ['ADMIN','DOCTOR','STAFF'], true) ? $baseRole : 'STAFF';

// ---------------- Record vitals ----------------
$canRecordVitals = $isOpen && has_permission('IPD_RECORD_VITALS');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_vitals' && $canRecordVitals) {
    $num = function (string $k, string $type = 'int') {
        $v = trim($_POST[$k] ?? '');
        if ($v === '') { return null; }
        return $type === 'float' ? (float) $v : (int) $v;
    };
    $vitalNotes = trim($_POST['vital_notes'] ?? '') ?: null;
    try {
        $pdo->prepare('
            INSERT INTO ipd_vitals
                (admission_id, recorded_at, temp_f, pulse_bpm, resp_rate, systolic_bp, diastolic_bp,
                 spo2_pct, blood_glucose, weight_kg, height_cm, ofc_cm, pain_score, notes, recorded_by_id, recorded_by_role)
            VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ')->execute([
            $admissionId,
            $num('temp_f', 'float'), $num('pulse_bpm'), $num('resp_rate'),
            $num('systolic_bp'), $num('diastolic_bp'), $num('spo2_pct'),
            $num('blood_glucose', 'float'), $num('weight_kg', 'float'),
            $num('height_cm', 'float'), $num('ofc_cm', 'float'), $num('pain_score'),
            $vitalNotes, $uid, $loggedRole,
        ]);
        $flash = 'Vitals recorded.';
    } catch (Throwable $e) {
        $err = 'Could not record vitals — the vitals table may not be set up yet.';
    }
}

// ---------------- Log a care event ----------------
$canLogCare = $isOpen && has_permission('IPD_LOG_CARE');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_care' && $canLogCare) {
    $type = in_array($_POST['event_type'] ?? '', ['NURSING_CARE','MEDICATION','OBSERVATION','OTHER'], true) ? $_POST['event_type'] : 'NURSING_CARE';
    $note = trim($_POST['care_note'] ?? '');
    if ($note !== '') {
        try {
            $pdo->prepare('
                INSERT INTO ipd_care_events (admission_id, event_type, note, logged_by_id, logged_by_role, event_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ')->execute([$admissionId, $type, mb_substr($note, 0, 1000), $uid, $loggedRole]);
            $flash = 'Care event logged.';
        } catch (Throwable $e) {
            $err = 'Could not log the care event.';
        }
    }
}

// ---------------- Log a chargeable service ----------------
// The nurse who GIVES the service is the one who records it, at the bedside,
// as it happens — rather than reception reconstructing the list from memory on
// the discharge screen. Deliberately NOT fenced on $isOpen: care continues right
// up to the moment the patient walks out, and append_ipd_service_to_bill() puts
// a late entry onto the draft bill instead of silently dropping it.
$canLogServices = has_permission('IPD_LOG_SERVICES');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_service' && $canLogServices) {
    $erId = (int) ($_POST['er_service_id'] ?? 0);
    $qty = max(1, (int) ($_POST['quantity'] ?? 1));
    $dur = ($_POST['duration_minutes'] ?? '') !== '' ? (int) $_POST['duration_minutes'] : null;
    $note = trim($_POST['clinical_note'] ?? '');

    $svc = null;
    if ($erId > 0) {
        // Price comes from the catalogue, never from the POST.
        $s = $pdo->prepare("SELECT * FROM er_services_master WHERE id = ? AND status = 'ACTIVE'");
        $s->execute([$erId]);
        $svc = $s->fetch() ?: null;
    }

    if (!$svc) {
        $err = 'Pick a service.';
    } elseif ($svc['charge_type'] === 'HOURLY' && (!$dur || $dur < 1)) {
        // Without this an hourly service silently bills Rs 0 (rate x 0/60).
        $err = 'Enter how many minutes for an hourly service.';
    } else {
        try {
            $charge = ipd_service_charge($svc['charge_type'], (float) $svc['base_charge'], $qty, $dur);
            $pdo->beginTransaction();
            $pdo->prepare('
                INSERT INTO ipd_services (admission_id, er_service_id, service_type, service_name, charge_type, quantity, duration_minutes, unit_charge, calculated_charge, clinical_note, logged_by_id, logged_by_role)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ')->execute([
                $admissionId, $svc['id'], $svc['service_type'], $svc['service_name'], $svc['charge_type'],
                $qty, $dur, $svc['base_charge'], $charge,
                $note !== '' ? mb_substr($note, 0, 200) : null, $uid, $loggedRole,
            ]);
            $serviceId = (int) $pdo->lastInsertId();
            // A given service is also a care action — mirror it into the
            // flow-sheet so the clinical record is complete without prices.
            log_ipd_service_care_event($pdo, $admissionId, [
                'service_name' => $svc['service_name'], 'charge_type' => $svc['charge_type'],
                'quantity' => $qty, 'duration_minutes' => $dur, 'clinical_note' => $note,
            ], $serviceId, $uid, $loggedRole);
            $billState = append_ipd_service_to_bill($pdo, $admissionId, $serviceId);
            audit_log($pdo, 'ipd_service_logged', $svc['service_name'] . " logged for IPD admission #$admissionId (bill: $billState)", $uid);
            $pdo->commit();

            // PRG: without the redirect an F5 re-posts and double-logs.
            $msg = $billState === 'settled'
                ? 'Service recorded — but the bill is already settled, so tell reception to add the charge.'
                : 'Service logged.';
            header('Location: ipd_admission.php?id=' . $admissionId . '&svc=' . rawurlencode($msg) . '#services');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $err = 'Could not log the service.';
        }
    }
}
if (isset($_GET['svc']) && $_GET['svc'] !== '') { $flash = (string) $_GET['svc']; }

// ---------------- Record a handover ----------------
$canHandover = $isOpen && has_permission('IPD_RECORD_HANDOVER');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_handover' && $canHandover) {
    $toNurse = (int) ($_POST['to_nurse_id'] ?? 0);
    $notes = trim($_POST['handover_notes'] ?? '');
    $statusAt = in_array($_POST['status_at'] ?? '', ['ACTIVE','STABLE','CRITICAL'], true) ? $_POST['status_at'] : 'ACTIVE';
    if ($toNurse > 0 && $toNurse !== $uid) {
        try {
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO ipd_handovers (admission_id, from_nurse_id, to_nurse_id, handover_time, notes, status_at_handover) VALUES (?, ?, ?, NOW(), ?, ?)')
                ->execute([$admissionId, $uid, $toNurse, $notes ?: null, $statusAt]);
            $pdo->prepare('INSERT INTO ipd_care_events (admission_id, event_type, note, logged_by_id, logged_by_role, event_at) VALUES (?, \'HANDOVER\', ?, ?, ?, NOW())')
                ->execute([$admissionId, 'Handover · status ' . $statusAt . ($notes ? ' · ' . mb_substr($notes, 0, 200) : ''), $uid, $loggedRole]);
            $pdo->commit();
            $flash = 'Handover recorded.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $err = 'Could not record the handover.';
        }
    }
}

// ---- Advance payments (money taken before the stay is billed) ----
// Reception-only: doctors never handle cash, so the whole panel is gated on the
// permission AND on $hideMoney below.
$canTakeAdvance = !$hideMoney && has_permission('RECEPTION_TAKE_IPD_ADVANCE');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'take_advance' && $canTakeAdvance) {
    // A closed day accepts no more cash — the tally for it has been signed.
    $dayLock = require_day_open($pdo);
    if ($dayLock) {
        $err = $dayLock;
    } else {
        [$ok, $res] = ipd_take_advance(
            $pdo, $admissionId,
            (float) ($_POST['amount'] ?? 0),
            pay_method_in($_POST['payment_method'] ?? null),
            trim((string) ($_POST['note'] ?? '')) ?: null,
            $uid
        );
        if ($ok) {
            // PRG so a refresh cannot take the same advance twice.
            header('Location: ipd_admission.php?id=' . $admissionId . '&adv=' . urlencode($res));
            exit;
        }
        $err = $res;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'void_advance' && $baseRole === 'ADMIN') {
    [$ok, $res] = ipd_void_advance($pdo, (int) ($_POST['advance_id'] ?? 0), (string) ($_POST['reason'] ?? ''), $uid);
    if ($ok) {
        header('Location: ipd_admission.php?id=' . $admissionId . '&voided=' . urlencode($res));
        exit;
    }
    $err = $res;
}

if (($newAdv = trim($_GET['adv'] ?? '')) !== '') {
    $flash = 'Advance received — receipt ' . $newAdv . '.';
}
if (($vAdv = trim($_GET['voided'] ?? '')) !== '') {
    $flash = 'Receipt ' . $vAdv . ' voided.';
}

// Reload after any mutation so panels reflect the latest state.
$adm = load_ipd_admission($pdo, $admissionId);

// Age from dob: "Ny" for >=2 years, "Ny Mm" for infants (pediatric-friendly,
// matching the practice's weight/OFC vitals fields).
function ipd_age_label(?string $dob): string {
    if (!$dob) { return '—'; }
    $d = strtotime($dob);
    if ($d === false) { return '—'; }
    $now = time();
    if ($d > $now) { return '—'; }
    $years = (int) floor(($now - $d) / (365.25 * 86400));
    if ($years >= 2) { return $years . 'y'; }
    // Under 2: months (and remaining weeks not shown — months is enough).
    $months = (int) floor(($now - $d) / (30.44 * 86400));
    return $months . 'm';
}

// Hospital day = calendar days since admission, admit day = day 1.
$hospitalDay = (int) ((new DateTime(date('Y-m-d')))->diff(new DateTime(date('Y-m-d', strtotime($adm['admitted_at']))))->days) + 1;

$statusLabels = [
    'ACTIVE' => 'Active',
    'DISCHARGE_IN_PROGRESS' => 'Discharge in progress',
    'DISCHARGED' => 'Discharged',
];
$genderLabels = ['MALE' => 'Male', 'FEMALE' => 'Female', 'OTHER' => 'Other'];

// ---- Data for the vitals / care / handover panels (Phase 3) ----
$canViewVitals = has_permission('IPD_VIEW_VITALS_HISTORY') || has_permission('IPD_RECORD_VITALS');
$vitals = [];
if ($canViewVitals) {
    try {
        $vs = $pdo->prepare('SELECT v.*, u.name AS by_name FROM ipd_vitals v JOIN users u ON u.id = v.recorded_by_id WHERE v.admission_id = ? ORDER BY v.recorded_at DESC, v.id DESC');
        $vs->execute([$admissionId]);
        $vitals = $vs->fetchAll();
    } catch (Throwable $e) { $vitals = []; }
}

// Merged care-event stream (doctor visits + nursing + handovers), newest first.
$careEvents = [];
try {
    $ce = $pdo->prepare('SELECT c.*, u.name AS by_name FROM ipd_care_events c JOIN users u ON u.id = c.logged_by_id WHERE c.admission_id = ? ORDER BY c.event_at DESC, c.id DESC LIMIT 100');
    $ce->execute([$admissionId]);
    $careEvents = $ce->fetchAll();
} catch (Throwable $e) { $careEvents = []; }

$handovers = [];
try {
    $ho = $pdo->prepare('SELECT h.*, f.name AS from_name, t.name AS to_name FROM ipd_handovers h JOIN users f ON f.id = h.from_nurse_id JOIN users t ON t.id = h.to_nurse_id WHERE h.admission_id = ? ORDER BY h.handover_time DESC');
    $ho->execute([$admissionId]);
    $handovers = $ho->fetchAll();
} catch (Throwable $e) { $handovers = []; }

// Services logged this stay. Joined to users so the log shows WHO gave it —
// the whole point of moving logging to the bedside. Removed items (is_billable
// = 0) are still listed: the clinical record of what was done survives an admin
// taking the charge off.
$loggedServices = [];
try {
    $ls = $pdo->prepare('
        SELECT s.*, u.name AS by_name
        FROM ipd_services s
        LEFT JOIN users u ON u.id = s.logged_by_id
        WHERE s.admission_id = ?
        ORDER BY s.logged_at DESC, s.id DESC
    ');
    $ls->execute([$admissionId]);
    $loggedServices = $ls->fetchAll();
} catch (Throwable $e) { $loggedServices = []; }

// Catalogue for the picker. Shared with ER — see er_services_master.
$serviceCatalogue = [];
if ($canLogServices) {
    try {
        $serviceCatalogue = $pdo->query("SELECT id, service_type, service_name, charge_type, base_charge FROM er_services_master WHERE status = 'ACTIVE' ORDER BY service_type, service_name")->fetchAll();
    } catch (Throwable $e) { $serviceCatalogue = []; }
}

// Nurses available for handover = active users holding IPD_RECORD_HANDOVER.
$nurses = [];
if ($canHandover) {
    $nurses = $pdo->query("
        SELECT DISTINCT u.id, u.name
        FROM users u
        WHERE u.is_active = 1 AND (
              EXISTS (SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
                      WHERE rp.base_role = u.base_role AND p.`key` = 'IPD_RECORD_HANDOVER'
                        AND NOT EXISTS (SELECT 1 FROM user_permission_overrides o JOIN permissions p2 ON p2.id = o.permission_id
                                        WHERE o.user_id = u.id AND p2.`key` = 'IPD_RECORD_HANDOVER' AND o.granted = 0))
           OR EXISTS (SELECT 1 FROM user_permission_overrides o JOIN permissions p ON p.id = o.permission_id
                      WHERE o.user_id = u.id AND p.`key` = 'IPD_RECORD_HANDOVER' AND o.granted = 1)
        )
        ORDER BY u.name
    ")->fetchAll();
}

$careTypeLabels = [
    'DOCTOR_VISIT' => 'Doctor visit', 'NURSING_CARE' => 'Nursing care', 'MEDICATION' => 'Medication',
    'OBSERVATION' => 'Observation', 'HANDOVER' => 'Handover', 'OTHER' => 'Other',
];

$pageTitle = 'In-Door — ' . $adm['patient_name'];
$headExtra = <<<CSS
<style>
/* ---- Advance payments panel ---- */
.adv-held { display: flex; justify-content: space-between; align-items: baseline; background: var(--primary-dark); color: #fff; border-radius: var(--radius-input); padding: 12px 15px; margin-bottom: 14px; }
.adv-held .k { font-size: 11px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: #9BD4CF; }
.adv-held .v { font-size: 23px; font-weight: 700; font-variant-numeric: tabular-nums; letter-spacing: -.02em; }
.adv-form label { display: block; font-size: 12px; font-weight: 600; color: var(--text-secondary); margin-bottom: 5px; }
.adv-form input, .adv-form select { width: 100%; padding: 9px 11px; border: 1px solid var(--border); border-radius: var(--radius-input); font: inherit; font-size: 13.5px; background: var(--bg); box-sizing: border-box; }
.adv-form input:focus, .adv-form select:focus { outline: none; border-color: var(--primary); background: #fff; }
.adv-grid { display: grid; grid-template-columns: 1.2fr 1fr auto; gap: 12px; align-items: end; }
@media (max-width: 620px) { .adv-grid { grid-template-columns: 1fr; } }
.adv-hint { font-size: 11.5px; color: var(--text-muted); margin-top: 8px; }
.adv-row { display: flex; align-items: center; gap: 10px; padding: 9px 0; border-bottom: 1px dotted var(--border); font-size: 13px; }
.adv-row:last-child { border-bottom: none; }
.adv-main { flex: 1; min-width: 0; }
.adv-no { font-weight: 600; font-variant-numeric: tabular-nums; }
.adv-meta { font-size: 11px; color: var(--text-muted); margin-top: 2px; overflow-wrap: anywhere; }
.adv-amt { font-weight: 700; font-variant-numeric: tabular-nums; white-space: nowrap; }
.adv-link { font-size: 12px; color: var(--primary-dark); white-space: nowrap; }
.adv-row.void { opacity: .75; }
.adv-row.void .adv-no, .adv-row.void .adv-amt { text-decoration: line-through; color: var(--text-muted); }
.adv-tag { display: inline-block; font-size: 10px; font-weight: 700; padding: 1px 6px; border-radius: 20px; background: var(--primary-light); color: var(--primary-dark); margin-left: 5px; }
.adv-tag.void, .adv-tag.ret { background: #fdeaea; color: var(--red-text); }

.a-head { display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 16px; }
.a-status { font-size: 12px; font-weight: 700; padding: 4px 12px; border-radius: 20px; }
.a-status.ACTIVE { background: var(--green-bg); color: var(--green-text); }
.a-status.DISCHARGE_IN_PROGRESS { background: #EDE7FB; color: #6D28D9; }
.a-status.DISCHARGED { background: #F1F5F9; color: var(--text-secondary); }
.kv { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px,1fr)); gap: 2px; border: 1px solid var(--border); border-radius: 12px; overflow: hidden; margin-top: 14px; }
.kv > div { padding: 10px 14px; border-right: 1px solid var(--border); }
.kv .k { font-size: 10.5px; text-transform: uppercase; letter-spacing: .06em; color: var(--text-muted); font-weight: 700; }
.kv .v { font-size: 14px; font-weight: 650; margin-top: 2px; }
.phase-note { margin-top: 20px; padding: 16px 18px; border: 1px dashed var(--border); border-radius: 12px; color: var(--text-muted); font-size: 13px; }
.two-col { display: grid; grid-template-columns: 1.5fr 1fr; gap: 20px; align-items: start; margin-top: 20px; }
@media (max-width: 960px){ .two-col { grid-template-columns: 1fr; } }
.vit-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(90px, 1fr)); gap: 10px; }
.vit-grid label { display: block; font-size: 11px; font-weight: 600; color: var(--text-secondary); margin-bottom: 4px; }
.vit-grid input { width: 100%; padding: 8px 9px; border: 1px solid var(--border); border-radius: 9px; font: inherit; font-size: 13px; background: var(--bg); }
.vit-grid input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,127,126,.15); background: #fff; }
.vitals-log, .care-log { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.vitals-log th, .vitals-log td, .care-log th, .care-log td { text-align: left; padding: 7px 9px; border-bottom: 1px solid var(--border); white-space: nowrap; }
.vitals-log th, .care-log th { font-size: 10.5px; text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted); font-weight: 700; }
.care-log td { white-space: normal; }
.ev-tag { font-size: 10.5px; font-weight: 700; padding: 2px 8px; border-radius: 20px; background: var(--primary-light); color: var(--primary-dark); }
.ev-tag.HANDOVER { background: #EDE7FB; color: #6D28D9; }
.ev-tag.DOCTOR_VISIT { background: var(--green-bg); color: var(--green-text); }
.ho-item { border-top: 1px solid var(--border); padding: 10px 0; font-size: 12.5px; }
.ho-item:first-child { border-top: none; }
.mini-form input, .mini-form select, .mini-form textarea { width: 100%; padding: 9px 11px; border: 1px solid var(--border); border-radius: 10px; font: inherit; font-size: 13px; background: var(--bg); }
.mini-form input:focus, .mini-form select:focus, .mini-form textarea:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,127,126,.15); background: #fff; }
.mini-form label { font-size: 11.5px; font-weight: 600; color: var(--text-secondary); display: block; margin-bottom: 5px; }
.svc-form { display: grid; grid-template-columns: 2fr .6fr .7fr; gap: 10px; align-items: end; margin: 14px 0; }
.svc-form .svc-note { grid-column: 1 / 3; }
.svc-form button { grid-column: 3; }
@media (max-width: 620px){
    .svc-form { grid-template-columns: 1fr 1fr; }
    .svc-form > div:first-child, .svc-form .svc-note, .svc-form button { grid-column: 1 / -1; }
}
.ev-tag.PROCEDURE { background: var(--amber-bg); color: var(--amber-text); }
.ev-tag.svc-rm { background: var(--red-bg); color: var(--red-text); margin-left: 4px; }
.svc-off td { opacity: .6; }
.svc-off td:nth-child(2) { text-decoration: line-through; }
.svc-off td:nth-child(2) .ev-tag { text-decoration: none; display: inline-block; }
CSS;
$headExtra .= "\n</style>";
require __DIR__ . '/partials/head.php';
$navActive = 'ipd';
require __DIR__ . '/partials/sidebar.php';
?>
        <div class="content">
            <div class="page-head">
                <div>
                    <div class="page-title">In-Door stay</div>
                    <div class="page-sub"><a href="ipd_admissions.php" style="color:var(--primary);font-weight:600;">&larr; All in-patients</a></div>
                </div>
            </div>

            <?php if (isset($_GET['admitted'])): ?><div class="alert success">Patient admitted to In-Door.</div><?php endif; ?>
            <?php if ($flash): ?><div class="alert success"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
            <?php if ($err): ?><div class="alert error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

            <?php
            /* Treatment-sheet prompt + unmissable "not signed yet" banner.
               Renders nothing once a doctor has approved at least one order.
               The partial reads $admissionId / $isOpen from this scope. */
            include __DIR__ . '/partials/treatment_sheet_prompt.php';
            ?>

            <div class="card">
                <div class="a-head">
                    <div>
                        <div class="section-title" style="font-size:18px;"><?= htmlspecialchars($adm['patient_name']) ?></div>
                        <div class="section-sub">
                            <span class="mono"><?= htmlspecialchars($adm['mrn']) ?></span> &middot;
                            <?= htmlspecialchars($adm['phone'] ?: '—') ?> &middot; Token <?= htmlspecialchars(token_code($adm['token_prefix'] ?? null, $adm['token_doctor_name'] ?? '', $adm['token_no'])) ?>
                        </div>
                    </div>
                    <span class="a-status <?= $adm['status'] ?>"><?= $statusLabels[$adm['status']] ?? $adm['status'] ?></span>
                </div>
                <div class="kv">
                    <div><div class="k">Admission No.</div><div class="v">#<?= (int) $adm['id'] ?></div></div>
                    <div><div class="k">Age</div><div class="v"><?= htmlspecialchars(ipd_age_label($adm['dob'])) ?></div></div>
                    <div><div class="k">Gender</div><div class="v"><?= htmlspecialchars($genderLabels[$adm['gender']] ?? $adm['gender']) ?></div></div>
                    <div><div class="k">Room</div><div class="v"><?= htmlspecialchars($adm['room_category']) ?></div></div>
                    <div><div class="k">Room</div><div class="v"><?= (int) $adm['room_no'] ?></div></div>
                    <div><div class="k">Consultant</div><div class="v"><?= htmlspecialchars($adm['consultant_name'] ?: '—') ?></div></div>
                    <div><div class="k">Admitted</div><div class="v"><?= date('d/m, H:i', strtotime($adm['admitted_at'])) ?></div></div>
                    <div><div class="k">Hospital Day</div><div class="v"><?= $hospitalDay ?></div></div>
                </div>
                <?php if (!empty($adm['provisional_diagnosis'])): ?>
                <div style="margin-top:14px;">
                    <div class="k" style="font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);font-weight:700;">Provisional diagnosis</div>
                    <div style="font-size:14px;font-weight:600;margin-top:3px;"><?= htmlspecialchars($adm['provisional_diagnosis']) ?></div>
                </div>
                <?php endif; ?>
            </div>

            <?php
            // ---- Advance payments ----
            // Hidden entirely from doctors ($hideMoney) and from anyone without
            // the permission. Also hidden once discharged: the stay has settled
            // against this ledger, so taking more would have nothing to apply to.
            $advReady = ipd_advances_ready($pdo);
            $showAdvances = !$hideMoney && $advReady
                            && ($canTakeAdvance || ipd_advance_balance($pdo, $admissionId) > 0);
            if ($showAdvances):
                $advHeld = ipd_advance_balance($pdo, $admissionId);
                $advRows = ipd_advance_rows($pdo, $admissionId);
                $advOpen = $adm['status'] !== 'DISCHARGED';
            ?>
            <div class="card">
                <div class="section-title">Advance payments</div>
                <div class="section-sub">Money taken before the stay is billed. Deducted from the final bill at discharge.</div>

                <div class="adv-held">
                    <span class="k">Advance held</span>
                    <span class="v">Rs <?= number_format($advHeld, 0) ?></span>
                </div>

                <?php if ($canTakeAdvance && $advOpen): ?>
                <form method="POST" action="ipd_admission.php?id=<?= $admissionId ?>" class="adv-form" data-lock-submit>
                    <input type="hidden" name="action" value="take_advance">
                    <div class="adv-grid">
                        <div>
                            <label for="adv_amt">Amount (Rs)</label>
                            <input id="adv_amt" name="amount" type="number" min="1" step="1" required
                                   placeholder="0" inputmode="numeric"
                                   style="font-size:16px;font-weight:700;font-variant-numeric:tabular-nums;">
                        </div>
                        <div>
                            <label>Received as</label>
                            <?= pay_method_toggle('payment_method', 'cash', 'adv') ?>
                        </div>
                        <div><button type="submit" class="btn">Take advance</button></div>
                    </div>
                    <div style="margin-top:10px;">
                        <label for="adv_note">Note (optional)</label>
                        <input id="adv_note" name="note" type="text" maxlength="255" placeholder="e.g. second instalment">
                    </div>
                    <div class="adv-hint">Lands on your shift's cash tally immediately and prints an ADV receipt.</div>
                </form>
                <?php elseif (!$advOpen): ?>
                <div class="adv-hint" style="margin-top:0;">This stay is discharged — the advance above was settled against the final bill.</div>
                <?php endif; ?>

                <?php if ($advRows): ?>
                <div style="margin-top:18px;">
                    <div class="section-title" style="font-size:13px;">Receipts</div>
                    <div style="margin-top:6px;">
                        <?php foreach ($advRows as $a):
                            $isVoid = !empty($a['voided_at']);
                            $isRet  = $a['direction'] === 'REFUND';
                        ?>
                        <div class="adv-row<?= $isVoid ? ' void' : '' ?>">
                            <div class="adv-main">
                                <span class="adv-no"><?= htmlspecialchars($a['receipt_number']) ?></span>
                                <?php if ($isVoid): ?><span class="adv-tag void">VOID</span><?php endif; ?>
                                <?php if ($isRet): ?><span class="adv-tag ret">RETURNED</span><?php endif; ?>
                                <div class="adv-meta">
                                    <?= htmlspecialchars(pay_method_label($a['payment_method'])) ?>
                                    &middot; <?= date('d/m/Y, h:i A', strtotime($a['created_at'])) ?>
                                    &middot; <?= htmlspecialchars($a['received_by_name'] ?: '—') ?>
                                    <?= $a['note'] ? ' &middot; ' . htmlspecialchars($a['note']) : '' ?>
                                </div>
                            </div>
                            <span class="adv-amt"><?= $isRet ? '&minus; ' : '' ?>Rs <?= number_format((float) $a['amount'], 2) ?></span>
                            <a class="adv-link" href="ipd_advance_receipt.php?id=<?= (int) $a['id'] ?>" target="_blank">Receipt</a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="two-col">
                <!-- Left: vitals + care flow-sheet -->
                <div>
                    <?php if ($canViewVitals): ?>
                    <div class="card">
                        <div class="section-title">Vitals</div>
                        <div class="section-sub">Clinical observations during the stay — not billed.</div>
                        <?php if ($canRecordVitals): ?>
                        <form method="POST" action="ipd_admission.php?id=<?= $admissionId ?>" style="margin:14px 0 4px;">
                            <input type="hidden" name="action" value="add_vitals">
                            <div class="vit-grid">
                                <div><label>Temp (&deg;F)</label><input type="number" step="0.1" name="temp_f" placeholder="98.6"></div>
                                <div><label>Pulse</label><input type="number" name="pulse_bpm" placeholder="—"></div>
                                <div><label>Resp</label><input type="number" name="resp_rate" placeholder="—"></div>
                                <div><label>SpO&#8322; (%)</label><input type="number" name="spo2_pct" placeholder="—"></div>
                                <div><label>BP Sys</label><input type="number" name="systolic_bp" placeholder="—"></div>
                                <div><label>BP Dia</label><input type="number" name="diastolic_bp" placeholder="—"></div>
                                <div><label>Glucose</label><input type="number" step="0.1" name="blood_glucose" placeholder="mg/dL"></div>
                                <div><label>Pain (0&ndash;10)</label><input type="number" min="0" max="10" name="pain_score" placeholder="—"></div>
                                <div><label>Weight (kg)</label><input type="number" step="0.1" name="weight_kg" placeholder="—"></div>
                                <div><label>Height (cm)</label><input type="number" step="0.1" name="height_cm" placeholder="—"></div>
                                <div><label>OFC (cm)</label><input type="number" step="0.1" name="ofc_cm" placeholder="—"></div>
                                <div style="display:flex;align-items:flex-end;"><button type="submit" class="btn secondary" style="width:100%;">Record</button></div>
                            </div>
                            <div style="margin-top:10px;"><input type="text" name="vital_notes" placeholder="Notes (optional)" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;font:inherit;font-size:13.5px;background:var(--bg);"></div>
                        </form>
                        <?php endif; ?>
                        <div style="overflow-x:auto;margin-top:12px;">
                        <table class="vitals-log">
                            <thead><tr><th>Time</th><th>Temp</th><th>Pulse</th><th>Resp</th><th>SpO&#8322;</th><th>BP</th><th>Glu</th><th>Pain</th><th>By</th></tr></thead>
                            <tbody>
                                <?php if (!$vitals): ?><tr><td colspan="9" class="muted" style="padding:16px 10px;">No vitals recorded yet.</td></tr><?php endif; ?>
                                <?php foreach ($vitals as $vt): ?>
                                <tr>
                                    <td class="mono"><?= date('d/m H:i', strtotime($vt['recorded_at'])) ?></td>
                                    <td><?= $vt['temp_f'] !== null ? htmlspecialchars($vt['temp_f']) : '—' ?></td>
                                    <td><?= $vt['pulse_bpm'] !== null ? (int) $vt['pulse_bpm'] : '—' ?></td>
                                    <td><?= $vt['resp_rate'] !== null ? (int) $vt['resp_rate'] : '—' ?></td>
                                    <td><?= $vt['spo2_pct'] !== null ? (int) $vt['spo2_pct'] . '%' : '—' ?></td>
                                    <td><?= ($vt['systolic_bp'] !== null || $vt['diastolic_bp'] !== null) ? ((int) $vt['systolic_bp'] . '/' . (int) $vt['diastolic_bp']) : '—' ?></td>
                                    <td><?= $vt['blood_glucose'] !== null ? htmlspecialchars($vt['blood_glucose']) : '—' ?></td>
                                    <td><?= $vt['pain_score'] !== null ? (int) $vt['pain_score'] : '—' ?></td>
                                    <td class="muted"><?= htmlspecialchars($vt['by_name']) ?></td>
                                </tr>
                                <?php if (!empty($vt['notes'])): ?><tr><td></td><td colspan="8" class="muted" style="font-size:12px;padding-top:0;">&#8627; <?= htmlspecialchars($vt['notes']) ?></td></tr><?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Care flow-sheet (merged event stream) -->
                    <div class="card" style="margin-top:20px;">
                        <div class="section-title">Care flow-sheet</div>
                        <div class="section-sub">Doctor visits, nursing care and handovers, newest first.</div>
                        <?php if ($canLogCare): ?>
                        <form method="POST" action="ipd_admission.php?id=<?= $admissionId ?>" class="mini-form" style="display:grid;grid-template-columns:150px 1fr auto;gap:10px;align-items:end;margin:14px 0;">
                            <input type="hidden" name="action" value="add_care">
                            <div>
                                <label>Type</label>
                                <select name="event_type">
                                    <option value="NURSING_CARE">Nursing care</option>
                                    <option value="MEDICATION">Medication</option>
                                    <option value="OBSERVATION">Observation</option>
                                    <option value="OTHER">Other</option>
                                </select>
                            </div>
                            <div><label>Note</label><input type="text" name="care_note" maxlength="1000" placeholder="e.g. IV line flushed; patient tolerating oral feeds" required></div>
                            <button type="submit" class="btn secondary">Log</button>
                        </form>
                        <?php endif; ?>
                        <div style="overflow-x:auto;margin-top:6px;">
                        <table class="care-log">
                            <thead><tr><th>Time</th><th>Type</th><th>Detail</th><th>By</th></tr></thead>
                            <tbody>
                                <?php if (!$careEvents): ?><tr><td colspan="4" class="muted" style="padding:16px 10px;">No care events yet.</td></tr><?php endif; ?>
                                <?php foreach ($careEvents as $ev): ?>
                                <tr>
                                    <td class="mono"><?= date('d/m H:i', strtotime($ev['event_at'])) ?></td>
                                    <td><span class="ev-tag <?= $ev['event_type'] ?>"><?= htmlspecialchars($careTypeLabels[$ev['event_type']] ?? $ev['event_type']) ?></span></td>
                                    <td><?= htmlspecialchars($ev['note'] ?: '—') ?></td>
                                    <td class="muted"><?= htmlspecialchars($ev['by_name']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    </div>

                    <?php if ($canLogServices || $loggedServices): ?>
                    <div class="card" style="margin-top:20px;" id="services">
                        <div class="section-title">Services</div>
                        <div class="section-sub">Log each chargeable service as you give it &mdash; it goes on the patient's bill and stay report automatically.</div>
                        <?php if ($canLogServices): ?>
                        <form method="POST" action="ipd_admission.php?id=<?= $admissionId ?>" class="mini-form svc-form">
                            <input type="hidden" name="action" value="add_service">
                            <div>
                                <label>Service</label>
                                <select name="er_service_id" required>
                                    <option value="">Select&hellip;</option>
                                    <?php foreach ($serviceCatalogue as $cs): ?>
                                    <option value="<?= (int) $cs['id'] ?>" data-hourly="<?= $cs['charge_type'] === 'HOURLY' ? 1 : 0 ?>">
                                        <?= htmlspecialchars($cs['service_name']) ?><?= $cs['charge_type'] === 'HOURLY' ? ' (hourly)' : '' ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div><label>Qty</label><input type="number" name="quantity" value="1" min="1" step="1"></div>
                            <div><label>Minutes</label><input type="number" name="duration_minutes" min="1" step="1" placeholder="&mdash;"></div>
                            <div class="svc-note"><label>Note (optional)</label><input type="text" name="clinical_note" maxlength="200" placeholder="e.g. 4 L/min via mask"></div>
                            <button type="submit" class="btn secondary">Log</button>
                        </form>
                        <?php endif; ?>
                        <div style="overflow-x:auto;margin-top:6px;">
                        <table class="care-log">
                            <thead><tr><th>Time</th><th>Service</th><th>Qty</th><th>By</th></tr></thead>
                            <tbody>
                                <?php if (!$loggedServices): ?><tr><td colspan="4" class="muted" style="padding:16px 10px;">No services logged yet.</td></tr><?php endif; ?>
                                <?php foreach ($loggedServices as $ls): $off = (int) $ls['is_billable'] === 0; ?>
                                <tr<?= $off ? ' class="svc-off"' : '' ?>>
                                    <td class="mono"><?= date('d/m H:i', strtotime($ls['logged_at'])) ?></td>
                                    <td>
                                        <span class="ev-tag <?= $ls['service_type'] ?>"><?= $ls['service_type'] === 'PROCEDURE' ? 'Procedure' : 'Service' ?></span>
                                        <?= htmlspecialchars($ls['service_name']) ?>
                                        <?php if ($off): ?><span class="ev-tag svc-rm">Not charged</span><?php endif; ?>
                                    </td>
                                    <td class="mono"><?= $ls['charge_type'] === 'HOURLY' ? ((int) $ls['duration_minutes']) . ' min' : (int) $ls['quantity'] ?></td>
                                    <td class="muted"><?= htmlspecialchars($ls['by_name'] ?: '—') ?></td>
                                </tr>
                                <?php if (!empty($ls['clinical_note'])): ?>
                                <tr><td></td><td colspan="3" class="muted" style="font-size:12px;padding-top:0;">&#8627; <?= htmlspecialchars($ls['clinical_note']) ?></td></tr>
                                <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Right: daily round shortcut + handover -->
                <div>
                    <?php if (has_permission('IPD_VIEW_TREATMENT_SHEET')):
                        /* $sheetState is computed by the prompt partial above; recompute
                           defensively in case this block is ever moved before it. */
                        $tsCard = $sheetState ?? ipd_sheet_state($pdo, (int) $adm['id']);
                    ?>
                    <div class="card">
                        <div class="section-title">Treatment sheet</div>
                        <div class="section-sub">
                            <?php if (!$tsCard['cleared']): ?>
                                <span style="color:var(--warn);font-weight:600;">Not approved &mdash; treatment cannot start</span>
                            <?php elseif ($tsCard['pending']): ?>
                                <span style="color:var(--warn);font-weight:600;"><?= (int) $tsCard['pending'] ?> order(s) awaiting approval</span>
                            <?php else: ?>
                                <?= (int) $tsCard['approved'] ?> approved medication order(s).
                            <?php endif; ?>
                        </div>
                        <a class="btn" style="width:100%;text-align:center;margin-top:12px;" href="ipd_treatment_sheet.php?id=<?= (int) $adm['id'] ?>">
                            <?= has_permission('IPD_WRITE_MED_ORDER') && $isOpen ? 'Open treatment sheet' : 'View treatment sheet' ?>
                        </a>
                    </div>
                    <?php endif; ?>

                    <?php if (has_permission('IPD_VIEW_WARD_ROUNDS')): ?>
                    <div class="card" style="margin-top:20px;">
                        <div class="section-title">Daily rounds</div>
                        <div class="section-sub">Consultant daily progress notes.</div>
                        <a class="btn" style="width:100%;text-align:center;margin-top:12px;" href="ipd_daily_round.php?id=<?= (int) $adm['id'] ?>">
                            <?= has_permission('IPD_WRITE_WARD_ROUND') && $isOpen ? 'Write daily round' : 'View daily-round notes' ?>
                        </a>
                    </div>
                    <?php endif; ?>

                    <?php
                    $canSummary = has_permission('IPD_WRITE_SUMMARY');
                    $canDischargeFlow = has_permission('IPD_DISCHARGE_PATIENT') || has_permission('IPD_FINALIZE_BILL') || has_permission('IPD_LOG_SERVICES');
                    if ($canSummary || $canDischargeFlow): ?>
                    <div class="card" style="margin-top:20px;">
                        <div class="section-title">Discharge</div>
                        <div class="section-sub">Summary, services &amp; final bill.</div>
                        <div style="display:flex;flex-direction:column;gap:10px;margin-top:12px;">
                            <?php if ($canSummary): ?>
                            <a class="btn secondary" style="text-align:center;" href="ipd_discharge_summary.php?id=<?= (int) $adm['id'] ?>">Discharge summary</a>
                            <?php endif; ?>
                            <a class="btn secondary" style="text-align:center;" href="ipd_stay_report.php?id=<?= (int) $adm['id'] ?>" target="_blank">Stay report</a>
                            <a class="btn secondary" style="text-align:center;" href="ipd_file.php?id=<?= (int) $adm['id'] ?>" target="_blank">Patient file</a>
                            <?php if ($canDischargeFlow): ?>
                            <a class="btn" style="text-align:center;" href="ipd_discharge.php?id=<?= (int) $adm['id'] ?>">
                                <?= $adm['status'] === 'DISCHARGED' ? 'View bill' : ($adm['status'] === 'ACTIVE' ? 'Discharge & bill' : 'Continue billing') ?>
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="card" style="margin-top:20px;">
                        <div class="section-title">Handover</div>
                        <div class="section-sub">Nurse-to-nurse accountability.</div>
                        <?php if ($canHandover): ?>
                        <form method="POST" action="ipd_admission.php?id=<?= $admissionId ?>" class="mini-form" style="margin-top:12px;display:flex;flex-direction:column;gap:10px;">
                            <input type="hidden" name="action" value="add_handover">
                            <div>
                                <label>Hand over to</label>
                                <select name="to_nurse_id" required>
                                    <option value="">Select nurse…</option>
                                    <?php foreach ($nurses as $n): if ((int) $n['id'] === $uid) continue; ?>
                                    <option value="<?= (int) $n['id'] ?>"><?= htmlspecialchars($n['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label>Status at handover</label>
                                <select name="status_at"><option value="ACTIVE">Active</option><option value="STABLE">Stable</option><option value="CRITICAL">Critical</option></select>
                            </div>
                            <div><label>Notes</label><textarea name="handover_notes" rows="2" placeholder="Handover notes"></textarea></div>
                            <button type="submit" class="btn secondary">Record handover</button>
                        </form>
                        <?php endif; ?>
                        <?php if ($handovers): ?>
                        <div style="margin-top:14px;">
                            <?php foreach ($handovers as $h): ?>
                            <div class="ho-item">
                                <b><?= htmlspecialchars($h['from_name']) ?></b> &rarr; <b><?= htmlspecialchars($h['to_name']) ?></b>
                                &middot; <?= date('d/m H:i', strtotime($h['handover_time'])) ?> &middot; <?= htmlspecialchars($h['status_at_handover']) ?>
                                <?php if ($h['notes']): ?><div class="muted" style="margin-top:2px;"><?= htmlspecialchars($h['notes']) ?></div><?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php elseif (!$canHandover): ?>
                        <div class="muted" style="margin-top:10px;font-size:12.5px;">No handovers recorded.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
