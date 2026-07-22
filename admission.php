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
    $flash = 'Service removed.';
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
                    <div><div class="k">Admitted</div><div class="v"><?= date('d M, H:i', strtotime($adm['admitted_at'])) ?></div></div>
                    <div><div class="k">Discharged</div><div class="v"><?= $adm['discharged_at'] ? date('d M, H:i', strtotime($adm['discharged_at'])) : '—' ?></div></div>
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
                        &middot; <?= date('d M H:i', strtotime($h['handover_time'])) ?> &middot; <?= htmlspecialchars($h['status_at_handover']) ?>
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
                        <div class="muted">Discharge submitted <?= $adm['discharged_at'] ? date('d M, H:i', strtotime($adm['discharged_at'])) : '' ?> — with reception for billing.</div>
                        <?php endif; ?>
                        <?php elseif ($adm['status'] === 'DISCHARGED'): ?>
                        <div class="muted">Discharged <?= $adm['discharged_at'] ? date('d M, H:i', strtotime($adm['discharged_at'])) : '' ?>.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="assets/js/date-picker.js"></script>
</body>
</html>
