<?php
/**
 * Admission detail — the working page for one short stay.
 *
 * Shows status + timeline, the chargeable-services log (add / edit / remove),
 * nurse handover, and the discharge trigger. Reachable from the reception queue
 * ("Manage stay") and, later, the nurse dashboard. The discharge -> billing
 * screen is checkout-side; this page hands off to it.
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
require_once __DIR__ . '/config/billing.php';
refresh_session_permissions($pdo);

$baseRole = $_SESSION['base_role'] ?? '';
$uid = (int) $_SESSION['user_id'];

// Access: reception, nursing, admin, manager. (Doctors don't manage the ward here.)
$canView = has_permission('RECEPTION_REGISTER_PATIENTS')
        || has_permission('NURSING_RECORD_ADMISSIONS')
        || in_array($baseRole, ['ADMIN', 'MANAGER', 'NURSE'], true);
if (!$canView) {
    http_response_code(403);
    exit('Forbidden.');
}

$admissionId = (int) ($_GET['id'] ?? 0);

function load_admission(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("
        SELECT a.*, v.token_no, v.visit_date,
               p.id AS patient_id, p.mrn, p.name AS patient_name, p.phone, p.dob,
               nu.name AS nurse_name,
               COALESCE(du.name, a.admitting_doctor_manual) AS doctor_name
        FROM admissions a
        JOIN visits v ON v.id = a.visit_id
        JOIN patients p ON p.id = v.patient_id
        LEFT JOIN users nu ON nu.id = a.assigned_nurse_id
        LEFT JOIN users du ON du.id = a.admitting_doctor_id
        WHERE a.id = ?
    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

$adm = load_admission($pdo, $admissionId);
if (!$adm) { http_response_code(404); exit('Admission not found.'); }

$flash = '';
$err = '';
$isOpen = $adm['status'] !== 'DISCHARGED';
$canLog = $isOpen && (has_permission('NURSING_LOG_CHARGEABLE_EVENTS') || in_array($baseRole, ['ADMIN','MANAGER','RECEPTIONIST','NURSE'], true));

// ---------------- Add a service ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_service' && $canLog) {
    $erId = (int) ($_POST['er_service_id'] ?? 0) ?: null;
    $qty = max(1, (int) ($_POST['quantity'] ?? 1));
    $dur = ($_POST['duration_minutes'] ?? '') !== '' ? (int) $_POST['duration_minutes'] : null;

    if (!$erId) {
        $err = 'Pick a service.';
    } else {
        $svc = $pdo->prepare('SELECT * FROM er_services_master WHERE id = ? AND status = \'ACTIVE\'');
        $svc->execute([$erId]);
        $s = $svc->fetch();
        if (!$s) {
            $err = 'That service is not available.';
        } else {
            $charge = admission_service_charge($s['charge_type'], (float) $s['base_charge'], $qty, $dur);
            $pdo->prepare('
                INSERT INTO admission_services
                    (admission_id, er_service_id, service_type, service_name, charge_type,
                     quantity, duration_minutes, unit_charge, calculated_charge, logged_by_id, logged_by_role)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ')->execute([
                $admissionId, $s['id'], $s['service_type'], $s['service_name'], $s['charge_type'],
                $qty, $dur, $s['base_charge'], $charge, $uid,
                $baseRole === 'NURSE' ? 'NURSE' : ($baseRole === 'ADMIN' ? 'ADMIN' : ($baseRole === 'MANAGER' ? 'MANAGER' : 'RECEPTIONIST')),
            ]);
            $flash = 'Service logged.';
        }
    }
    $adm = load_admission($pdo, $admissionId);
}

// ---------------- Remove a service ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_service' && $canLog) {
    $sid = (int) ($_POST['service_id'] ?? 0);
    $pdo->prepare('DELETE FROM admission_services WHERE id = ? AND admission_id = ?')->execute([$sid, $admissionId]);
    // Removing a chargeable line is a money-affecting edit — audit it (matches the
    // audit discipline on every other bill mutation).
    $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
        ->execute([$uid, 'admission_service_removed', "Removed service #$sid from admission #$admissionId"]);
    $flash = 'Service removed.';
}

// ---------------- Record vitals ----------------
// Clinical, non-chargeable. Gated on NURSING_RECORD_VITALS (nurse + doctor). Every
// field is optional — the nurse saves whatever she measured. Wrapped in try/catch so
// the page still works if add_admission_vitals.sql hasn't been applied yet.
$canRecordVitals = $isOpen && (has_permission('NURSING_RECORD_VITALS') || in_array($baseRole, ['ADMIN','MANAGER'], true));
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_vitals' && $canRecordVitals) {
    // Nullable numeric helper: '' -> null, else typed value.
    $num = function (string $k, string $type = 'int') {
        $v = trim($_POST[$k] ?? '');
        if ($v === '') { return null; }
        return $type === 'float' ? (float) $v : (int) $v;
    };
    $vitalNotes = trim($_POST['vital_notes'] ?? '') ?: null;
    $role = in_array($baseRole, ['NURSE','DOCTOR','ADMIN','MANAGER','RECEPTIONIST'], true) ? $baseRole : 'NURSE';
    try {
        $pdo->prepare('
            INSERT INTO admission_vitals
                (admission_id, recorded_at, temp_c, pulse_bpm, resp_rate, systolic_bp, diastolic_bp,
                 spo2_pct, blood_glucose, weight_kg, height_cm, ofc_cm, pain_score, notes, recorded_by_id, recorded_by_role)
            VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ')->execute([
            $admissionId,
            $num('temp_c', 'float'), $num('pulse_bpm'), $num('resp_rate'),
            $num('systolic_bp'), $num('diastolic_bp'), $num('spo2_pct'),
            $num('blood_glucose', 'float'), $num('weight_kg', 'float'),
            $num('height_cm', 'float'), $num('ofc_cm', 'float'), $num('pain_score'),
            $vitalNotes, $uid, $role,
        ]);
        $flash = 'Vitals recorded.';
    } catch (PDOException $e) {
        $err = 'Could not record vitals — the vitals table may not be set up yet.';
    }
    $adm = load_admission($pdo, $admissionId);
}

// ---------------- Assign / pick up nurse ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign_nurse' && $isOpen) {
    $nurseId = (int) ($_POST['nurse_id'] ?? 0);
    if ($nurseId > 0) {
        $pdo->prepare('UPDATE admissions SET assigned_nurse_id = ?, assigned_at = NOW(), status = IF(status = \'PENDING_ASSIGNMENT\', \'ACTIVE\', status) WHERE id = ?')
            ->execute([$nurseId, $admissionId]);
        $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
            ->execute([$uid, 'admission_nurse_assigned', "Assigned nurse #$nurseId to admission #$admissionId"]);
        $flash = 'Nurse assigned.';
        $adm = load_admission($pdo, $admissionId);
    }
}

// ---------------- Handover ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'handover' && $isOpen && $baseRole === 'NURSE') {
    $toNurse = (int) ($_POST['to_nurse_id'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    $statusAt = in_array($_POST['status_at'] ?? '', ['ACTIVE','STABLE','CRITICAL'], true) ? $_POST['status_at'] : 'ACTIVE';
    if ($toNurse > 0 && $toNurse !== (int) $adm['assigned_nurse_id']) {
        $pdo->prepare('INSERT INTO admission_handovers (admission_id, from_nurse_id, to_nurse_id, notes, status_at_handover) VALUES (?, ?, ?, ?, ?)')
            ->execute([$admissionId, $uid, $toNurse, $notes, $statusAt]);
        $pdo->prepare('UPDATE admissions SET assigned_nurse_id = ? WHERE id = ?')->execute([$toNurse, $admissionId]);
        $flash = 'Handover recorded.';
        $adm = load_admission($pdo, $admissionId);
    }
}

// ---------------- Submit discharge ----------------
// Marks the stay as discharge-in-progress. What happens NEXT depends on who
// submitted: a nurse's job ends here — the stay appears on reception's queue
// as "awaiting billing" and she stays on this page. Anyone who can also bill
// (reception/admin/manager) is taken straight to the billing screen, which
// covers the one-person-covering-both-desks case without any UI switching.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_discharge' && $isOpen) {
    $canDischarge = has_permission('NURSING_DISCHARGE_PATIENT') || in_array($baseRole, ['ADMIN','MANAGER','RECEPTIONIST'], true);
    if ($canDischarge) {
        $pdo->prepare('UPDATE admissions SET status = \'DISCHARGE_IN_PROGRESS\', discharged_at = COALESCE(discharged_at, NOW()) WHERE id = ?')
            ->execute([$admissionId]);
        $pdo->prepare('UPDATE visits SET discharged_at = NOW() WHERE id = ?')->execute([(int) $adm['visit_id']]);
        $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
            ->execute([$uid, 'admission_discharge_submitted', "Discharge submitted for admission #$admissionId"]);

        $canBill = has_permission('RECEPTION_PROCESS_PAYMENTS') || in_array($baseRole, ['ADMIN','MANAGER'], true);
        if ($canBill) {
            header('Location: admission_discharge.php?id=' . $admissionId);
        } else {
            header('Location: admission.php?id=' . $admissionId . '&discharge_sent=1');
        }
        exit;
    }
}

// ---------------- Data for the view ----------------
$services = $pdo->prepare('SELECT * FROM admission_services WHERE admission_id = ? ORDER BY logged_at');
$services->execute([$admissionId]);
$services = $services->fetchAll();

// Vitals timeline (newest first). Tolerate the table not existing yet so a mid-deploy
// gap degrades to "no vitals" rather than fataling the whole stay page.
$vitals = [];
$canViewVitals = has_permission('CLINICAL_VIEW_VITALS_HISTORY') || has_permission('NURSING_RECORD_VITALS') || in_array($baseRole, ['ADMIN','MANAGER'], true);
if ($canViewVitals) {
    try {
        $vStmt = $pdo->prepare('
            SELECT v.*, u.name AS recorded_by_name
            FROM admission_vitals v JOIN users u ON u.id = v.recorded_by_id
            WHERE v.admission_id = ? ORDER BY v.recorded_at DESC, v.id DESC
        ');
        $vStmt->execute([$admissionId]);
        $vitals = $vStmt->fetchAll();
    } catch (PDOException $e) {
        $vitals = [];   // table not migrated yet
    }
}
$servicesTotal = 0.0;
foreach ($services as $s) { if ($s['is_billable']) { $servicesTotal += (float) $s['calculated_charge']; } }

$erServices = $pdo->query("SELECT id, service_type, service_name, charge_type, base_charge FROM er_services_master WHERE status = 'ACTIVE' ORDER BY service_type, service_name")->fetchAll();
$nurses = $pdo->query("SELECT id, name FROM users WHERE base_role = 'NURSE' ORDER BY name")->fetchAll();
$handovers = $pdo->prepare('SELECT h.*, f.name AS from_name, t.name AS to_name FROM admission_handovers h JOIN users f ON f.id = h.from_nurse_id JOIN users t ON t.id = h.to_nurse_id WHERE h.admission_id = ? ORDER BY h.handover_time DESC');
$handovers->execute([$admissionId]);
$handovers = $handovers->fetchAll();

// Live stay duration + projected stay charge (for the running estimate).
$rate = $pdo->prepare('SELECT rate_amount, rate_basis FROM admission_rates WHERE admission_type = ?');
$rate->execute([$adm['admission_type']]);
$rate = $rate->fetch() ?: ['rate_amount' => 0, 'rate_basis' => 'HOURLY'];
$endTs = $adm['discharged_at'] ? strtotime($adm['discharged_at']) : time();
$stayMins = max(0, (int) round(($endTs - strtotime($adm['admitted_at'])) / 60));
$billedHours = admission_billed_hours($stayMins);
$stayCharge = $rate['rate_basis'] === 'DAILY'
    ? (float) $rate['rate_amount'] * max(1, (int) ceil($stayMins / 1440))
    : (float) $rate['rate_amount'] * $billedHours;
$runningTotal = $stayCharge + $servicesTotal;

$statusLabels = [
    'PENDING_ASSIGNMENT' => 'Awaiting nurse',
    'ACTIVE' => 'Active',
    'DISCHARGE_IN_PROGRESS' => 'Discharge in progress',
    'DISCHARGED' => 'Discharged',
];
$typeLabels = ['ROUTINE' => 'Routine', 'PRIVATE' => 'Private Room', 'LONG_PRIVATE' => 'Long Private'];

function fmt_dur(int $mins): string {
    $h = intdiv($mins, 60); $m = $mins % 60;
    return ($h ? $h . 'h ' : '') . $m . 'm';
}

$pageTitle = 'Admission — ' . $adm['patient_name'];
$headExtra = <<<CSS
<style>
.a-head { display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 16px; }
.a-status { font-size: 12px; font-weight: 700; padding: 4px 12px; border-radius: 20px; }
.a-status.PENDING_ASSIGNMENT { background: var(--amber-bg); color: var(--amber-text); }
.a-status.ACTIVE { background: var(--green-bg); color: var(--green-text); }
.a-status.DISCHARGE_IN_PROGRESS { background: #EDE7FB; color: #6D28D9; }
.a-status.DISCHARGED { background: #F1F5F9; color: var(--text-secondary); }
.a-grid { display: grid; grid-template-columns: 1.6fr 1fr; gap: 20px; align-items: start; }
.kv { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px,1fr)); gap: 2px; border: 1px solid var(--border); border-radius: 12px; overflow: hidden; }
.kv > div { padding: 10px 14px; border-right: 1px solid var(--border); }
.kv .k { font-size: 10.5px; text-transform: uppercase; letter-spacing: .06em; color: var(--text-muted); font-weight: 700; }
.kv .v { font-size: 14px; font-weight: 650; margin-top: 2px; }
.svc-add { display: grid; grid-template-columns: 2fr .8fr .9fr auto; gap: 10px; align-items: end; }
.svc-add label { font-size: 11.5px; font-weight: 600; color: var(--text-secondary); display: block; margin-bottom: 5px; }
.svc-add select, .svc-add input { width: 100%; padding: 9px 11px; border: 1px solid var(--border); border-radius: 10px; font: inherit; font-size: 13.5px; background: var(--bg); }
.svc-add select:focus, .svc-add input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,127,126,.15); background: #fff; }
.est { display: flex; flex-direction: column; gap: 10px; }
.est-row { display: flex; justify-content: space-between; font-size: 13.5px; }
.est-row.total { border-top: 1px solid var(--border); padding-top: 10px; font-weight: 700; font-size: 15px; }
.est-note { font-size: 11.5px; color: var(--text-muted); }
.side-actions { display: flex; flex-direction: column; gap: 10px; }
.link-btn { background: none; border: none; color: var(--red-text); font: inherit; font-size: 12px; font-weight: 600; cursor: pointer; padding: 0; }
.ho-item { border-top: 1px solid var(--border); padding: 10px 0; font-size: 12.5px; }
.ho-item:first-child { border-top: none; }
.vit-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(96px, 1fr)); gap: 10px; }
.vit-grid label { display: block; font-size: 11px; font-weight: 600; color: var(--text-secondary); margin-bottom: 4px; }
.vit-grid input { width: 100%; padding: 8px 9px; border: 1px solid var(--border); border-radius: 9px; font: inherit; font-size: 13px; background: var(--bg); }
.vit-grid input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,127,126,.15); background: #fff; }
.vitals-log { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.vitals-log th, .vitals-log td { text-align: left; padding: 7px 9px; border-bottom: 1px solid var(--border); white-space: nowrap; }
.vitals-log th { font-size: 10.5px; text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted); font-weight: 700; }
@media (max-width: 960px) { .a-grid { grid-template-columns: 1fr; } }
</style>
CSS;
require __DIR__ . '/partials/head.php';
$navActive = 'admissions';
require __DIR__ . '/partials/sidebar.php';
?>
        <div class="content">
            <div class="page-head">
                <div>
                    <div class="page-title">Admission</div>
                    <div class="page-sub"><a href="admissions.php" style="color:var(--primary);font-weight:600;">&larr; All admissions</a></div>
                </div>
            </div>

            <?php if ($flash): ?><div class="alert success"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
            <?php if (isset($_GET['discharge_sent'])): ?><div class="alert success">Discharge submitted — the stay is now with reception for billing.</div><?php endif; ?>
            <?php if ($err): ?><div class="alert error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

            <div class="card">
                <div class="a-head">
                    <div>
                        <div class="section-title" style="font-size:18px;"><?= htmlspecialchars($adm['patient_name']) ?></div>
                        <div class="section-sub"><span class="mono"><?= htmlspecialchars($adm['mrn']) ?></span> &middot; <?= htmlspecialchars($adm['phone'] ?: '—') ?> &middot; Token #<?= (int) $adm['token_no'] ?></div>
                    </div>
                    <span class="a-status <?= $adm['status'] ?>"><?= $statusLabels[$adm['status']] ?? $adm['status'] ?></span>
                </div>
                <div class="kv" style="margin-top:14px;">
                    <div><div class="k">Type</div><div class="v"><?= $typeLabels[$adm['admission_type']] ?? $adm['admission_type'] ?></div></div>
                    <div><div class="k">Admitted</div><div class="v"><?= date('d/m, H:i', strtotime($adm['admitted_at'])) ?></div></div>
                    <div><div class="k">Discharged</div><div class="v"><?= $adm['discharged_at'] ? date('d/m, H:i', strtotime($adm['discharged_at'])) : '—' ?></div></div>
                    <div><div class="k"><?= $isOpen && !$adm['discharged_at'] ? 'Stay so far' : 'Total stay' ?></div><div class="v"><?= fmt_dur($stayMins) ?></div></div>
                    <div><div class="k">Doctor</div><div class="v"><?= htmlspecialchars($adm['doctor_name'] ?: '—') ?></div></div>
                    <div><div class="k">Nurse</div><div class="v"><?= htmlspecialchars($adm['nurse_name'] ?: 'Unassigned') ?></div></div>
                </div>
            </div>

            <div class="a-grid">
                <!-- Services -->
                <div class="card">
                    <div class="section-title">Services logged</div>
                    <div class="section-sub">Chargeable events during this stay.</div>

                    <?php if ($canLog): ?>
                    <form method="POST" action="admission.php?id=<?= $admissionId ?>" style="margin-bottom:16px;">
                        <input type="hidden" name="action" value="add_service">
                        <div class="svc-add">
                            <div>
                                <label>Service</label>
                                <select name="er_service_id" required>
                                    <option value="">Select…</option>
                                    <?php foreach ($erServices as $e): ?>
                                    <option value="<?= (int) $e['id'] ?>"><?= htmlspecialchars($e['service_name']) ?> (Rs <?= number_format((float) $e['base_charge']) ?><?= $e['charge_type'] === 'HOURLY' ? '/hr' : '' ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div><label>Qty</label><input type="number" name="quantity" min="1" value="1"></div>
                            <div><label>Duration (min)</label><input type="number" name="duration_minutes" min="0" placeholder="hourly only"></div>
                            <button type="submit" class="btn">Add</button>
                        </div>
                    </form>
                    <?php endif; ?>

                    <div style="overflow-x:auto;">
                    <table>
                        <thead><tr><th>Time</th><th>Service</th><th>Qty/Dur</th><th>Charge</th><th>By</th><?php if ($canLog): ?><th></th><?php endif; ?></tr></thead>
                        <tbody>
                            <?php if (!$services): ?>
                            <tr><td colspan="6" class="muted" style="padding:18px 10px;">No services logged yet.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($services as $s): ?>
                            <tr>
                                <td class="mono"><?= date('H:i', strtotime($s['logged_at'])) ?></td>
                                <td style="font-weight:600;"><?= htmlspecialchars($s['service_name']) ?></td>
                                <td><?= $s['charge_type'] === 'HOURLY' ? ((int) $s['duration_minutes']) . ' min' : ('×' . (int) $s['quantity']) ?></td>
                                <td class="mono">Rs <?= number_format((float) $s['calculated_charge']) ?></td>
                                <td class="muted"><?= htmlspecialchars($s['logged_by_role']) ?></td>
                                <?php if ($canLog): ?>
                                <td>
                                    <form method="POST" action="admission.php?id=<?= $admissionId ?>" style="display:inline;" onsubmit="return confirm('Remove this service?');">
                                        <input type="hidden" name="action" value="remove_service">
                                        <input type="hidden" name="service_id" value="<?= (int) $s['id'] ?>">
                                        <button type="submit" class="link-btn">Remove</button>
                                    </form>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>

                    <?php if ($handovers): ?>
                    <div class="section-title" style="margin-top:20px;">Handover log</div>
                    <?php foreach ($handovers as $h): ?>
                    <div class="ho-item">
                        <b><?= htmlspecialchars($h['from_name']) ?></b> &rarr; <b><?= htmlspecialchars($h['to_name']) ?></b>
                        &middot; <?= date('d/m H:i', strtotime($h['handover_time'])) ?> &middot; <?= htmlspecialchars($h['status_at_handover']) ?>
                        <?php if ($h['notes']): ?><div class="muted" style="margin-top:2px;"><?= htmlspecialchars($h['notes']) ?></div><?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Estimate + actions -->
                <div class="card">
                    <div class="section-title">Running estimate</div>
                    <div class="section-sub">Final bill is raised at discharge.</div>
                    <div class="est">
                        <div class="est-row"><span>Stay (<?= $billedHours ?> hr <?= $typeLabels[$adm['admission_type']] ?? '' ?>)</span><span class="mono">Rs <?= number_format($stayCharge) ?></span></div>
                        <div class="est-row"><span>Services (<?= count($services) ?>)</span><span class="mono">Rs <?= number_format($servicesTotal) ?></span></div>
                        <div class="est-row total"><span>Estimated total</span><span class="mono">Rs <?= number_format($runningTotal) ?></span></div>
                        <div class="est-note">Under 45 min bills a flat half-hour; beyond that, rounded down to the previous quarter-hour.</div>
                    </div>

                    <div class="side-actions" style="margin-top:18px;">
                        <?php if ($isOpen && !$adm['assigned_nurse_id']): ?>
                        <form method="POST" action="admission.php?id=<?= $admissionId ?>">
                            <input type="hidden" name="action" value="assign_nurse">
                            <label style="font-size:12.5px;font-weight:600;color:var(--text-secondary);">Assign nurse</label>
                            <div style="display:flex;gap:8px;margin-top:6px;">
                                <select name="nurse_id" required style="flex:1;padding:9px 11px;border:1px solid var(--border);border-radius:10px;font:inherit;">
                                    <option value="">Select nurse…</option>
                                    <?php foreach ($nurses as $n): ?><option value="<?= (int) $n['id'] ?>"><?= htmlspecialchars($n['name']) ?></option><?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn small">Assign</button>
                            </div>
                        </form>
                        <?php endif; ?>

                        <?php if ($isOpen && $baseRole === 'NURSE' && (int) $adm['assigned_nurse_id'] === $uid): ?>
                        <details>
                            <summary style="cursor:pointer;font-size:13px;font-weight:600;color:var(--primary);">Hand over to another nurse</summary>
                            <form method="POST" action="admission.php?id=<?= $admissionId ?>" style="margin-top:10px;display:flex;flex-direction:column;gap:8px;">
                                <input type="hidden" name="action" value="handover">
                                <select name="to_nurse_id" required style="padding:9px 11px;border:1px solid var(--border);border-radius:10px;font:inherit;">
                                    <option value="">To nurse…</option>
                                    <?php foreach ($nurses as $n): if ((int) $n['id'] === $uid) continue; ?><option value="<?= (int) $n['id'] ?>"><?= htmlspecialchars($n['name']) ?></option><?php endforeach; ?>
                                </select>
                                <select name="status_at" style="padding:9px 11px;border:1px solid var(--border);border-radius:10px;font:inherit;">
                                    <option value="ACTIVE">Active</option><option value="STABLE">Stable</option><option value="CRITICAL">Critical</option>
                                </select>
                                <textarea name="notes" rows="2" placeholder="Handover notes" style="padding:9px 11px;border:1px solid var(--border);border-radius:10px;font:inherit;"></textarea>
                                <button type="submit" class="btn secondary small">Record handover</button>
                            </form>
                        </details>
                        <?php endif; ?>

                        <?php
                        // Who can do what from here: nurses SUBMIT the discharge
                        // (their part ends), billing-capable users go straight
                        // through to the bill.
                        $canSubmitDischarge = has_permission('NURSING_DISCHARGE_PATIENT') || in_array($baseRole, ['ADMIN','MANAGER','RECEPTIONIST'], true);
                        $canBillHere = has_permission('RECEPTION_PROCESS_PAYMENTS') || in_array($baseRole, ['ADMIN','MANAGER'], true);
                        ?>
                        <?php if ($isOpen && $canSubmitDischarge): ?>
                        <?php if ($canBillHere): ?>
                        <form method="POST" action="admission.php?id=<?= $admissionId ?>" onsubmit="return confirm('Submit discharge? This starts the final bill.');">
                            <input type="hidden" name="action" value="submit_discharge">
                            <button type="submit" class="btn" style="width:100%;">Discharge &amp; bill</button>
                        </form>
                        <?php else: ?>
                        <form method="POST" action="admission.php?id=<?= $admissionId ?>" onsubmit="return confirm('Submit discharge? Make sure every service provided has been logged — reception will bill from this list.');">
                            <input type="hidden" name="action" value="submit_discharge">
                            <button type="submit" class="btn" style="width:100%;">Submit discharge</button>
                        </form>
                        <div class="est-note" style="font-size:11.5px;color:var(--text-muted);">Sends the stay to reception, who reviews the charges and generates the invoice.</div>
                        <?php endif; ?>
                        <?php elseif ($adm['status'] === 'DISCHARGE_IN_PROGRESS'): ?>
                        <?php if ($canBillHere): ?>
                        <a class="btn" style="width:100%;text-align:center;" href="admission_discharge.php?id=<?= $admissionId ?>">Go to billing</a>
                        <?php else: ?>
                        <div class="muted">Discharge submitted <?= $adm['discharged_at'] ? date('d/m, H:i', strtotime($adm['discharged_at'])) : '' ?> — with reception for billing.</div>
                        <?php endif; ?>
                        <?php elseif ($adm['status'] === 'DISCHARGED'): ?>
                        <div class="muted">Discharged <?= $adm['discharged_at'] ? date('d/m, H:i', strtotime($adm['discharged_at'])) : '' ?>.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Vitals (clinical, non-chargeable) — full width below the two columns. -->
            <?php if ($canRecordVitals || $canViewVitals): ?>
            <div class="card" style="margin-top:20px;">
                <div class="section-title">Vitals</div>
                <div class="section-sub">Clinical observations during the stay — not billed.</div>

                <?php if ($canRecordVitals): ?>
                <form method="POST" action="admission.php?id=<?= $admissionId ?>" style="margin:14px 0 4px;">
                    <input type="hidden" name="action" value="add_vitals">
                    <div class="vit-grid">
                        <div><label>Temp (&deg;C)</label><input type="number" step="0.1" name="temp_c" placeholder="37.0"></div>
                        <div><label>Pulse (bpm)</label><input type="number" name="pulse_bpm" placeholder="—"></div>
                        <div><label>Resp (/min)</label><input type="number" name="resp_rate" placeholder="—"></div>
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
                    <div style="margin-top:10px;">
                        <input type="text" name="vital_notes" placeholder="Notes (optional)" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;font:inherit;font-size:13.5px;background:var(--bg);">
                    </div>
                </form>
                <?php endif; ?>

                <div style="overflow-x:auto;margin-top:12px;">
                <table class="vitals-log">
                    <thead><tr><th>Time</th><th>Temp</th><th>Pulse</th><th>Resp</th><th>SpO&#8322;</th><th>BP</th><th>Glu</th><th>Pain</th><th>By</th></tr></thead>
                    <tbody>
                        <?php if (!$vitals): ?>
                        <tr><td colspan="9" class="muted" style="padding:18px 10px;">No vitals recorded yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($vitals as $vt): ?>
                        <tr>
                            <td class="mono"><?= date('d/m H:i', strtotime($vt['recorded_at'])) ?></td>
                            <td><?= $vt['temp_c'] !== null ? htmlspecialchars($vt['temp_c']) : '—' ?></td>
                            <td><?= $vt['pulse_bpm'] !== null ? (int) $vt['pulse_bpm'] : '—' ?></td>
                            <td><?= $vt['resp_rate'] !== null ? (int) $vt['resp_rate'] : '—' ?></td>
                            <td><?= $vt['spo2_pct'] !== null ? (int) $vt['spo2_pct'] . '%' : '—' ?></td>
                            <td><?= ($vt['systolic_bp'] !== null || $vt['diastolic_bp'] !== null) ? ((int) $vt['systolic_bp'] . '/' . (int) $vt['diastolic_bp']) : '—' ?></td>
                            <td><?= $vt['blood_glucose'] !== null ? htmlspecialchars($vt['blood_glucose']) : '—' ?></td>
                            <td><?= $vt['pain_score'] !== null ? (int) $vt['pain_score'] : '—' ?></td>
                            <td class="muted"><?= htmlspecialchars($vt['recorded_by_name']) ?></td>
                        </tr>
                        <?php if (!empty($vt['notes'])): ?>
                        <tr><td></td><td colspan="8" class="muted" style="font-size:12px;padding-top:0;">&#8627; <?= htmlspecialchars($vt['notes']) ?></td></tr>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<script src="assets/js/date-picker.js"></script>
</body>
</html>
