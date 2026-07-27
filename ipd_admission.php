<?php
/**
 * In-Door (IPD) stay — the per-admission hub page.
 *
 * Phase 1 renders the auto-filled header only (patient, MR, admission no., age,
 * gender, ward, room, consultant, admitted, status). Later phases attach the
 * ward-round note (Phase 2), vitals/care/handover (Phase 3), and discharge +
 * billing (Phase 4) panels here. This is the IPD counterpart to admission.php.
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
require_once __DIR__ . '/config/tokens.php';
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
                    <div><div class="k">Ward</div><div class="v"><?= htmlspecialchars($adm['ward']) ?></div></div>
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
                </div>

                <!-- Right: ward round shortcut + handover -->
                <div>
                    <?php if (has_permission('IPD_VIEW_WARD_ROUNDS')): ?>
                    <div class="card">
                        <div class="section-title">Ward rounds</div>
                        <div class="section-sub">Consultant daily progress notes.</div>
                        <a class="btn" style="width:100%;text-align:center;margin-top:12px;" href="ipd_ward_round.php?id=<?= (int) $adm['id'] ?>">
                            <?= has_permission('IPD_WRITE_WARD_ROUND') && $isOpen ? 'Write ward round' : 'View ward-round notes' ?>
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
