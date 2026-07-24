<?php
/**
 * Procedures — admin catalogue + doctor assignments.
 *
 * Admin defines the clinic-wide procedure catalogue (name, rate, and whether
 * the procedure requires mandatory consent-form generation), then assigns
 * procedures to individual doctors. Each assignment carries:
 *   - an optional fee override (blank = charge the master's current rate),
 *   - the doctor/clinic share split (clinic = 100 - doctor %),
 *   - an optional tax %, withheld from the DOCTOR's share at commission time
 *     (patient invoices stay tax-free per clinic policy).
 * Billing procedures on visits, consent PDFs and commission payouts are later
 * phases — this page is the configuration source they will read.
 */
require_once __DIR__ . '/config/guard_admin.php';

$error = '';
$success = '';

// ---- Add a procedure to the master catalogue ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_procedure') {
    $name = trim($_POST['name'] ?? '');
    $fee = (float) ($_POST['fee'] ?? 0);
    $consent = isset($_POST['mandatory_consent']) ? 1 : 0;

    if ($name === '' || $fee < 0) {
        $error = 'A procedure needs a name and a non-negative rate.';
    } else {
        $stmt = $pdo->prepare('
            INSERT INTO procedure_master (name, fee, mandatory_consent, created_by_id)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE fee = VALUES(fee), mandatory_consent = VALUES(mandatory_consent), is_active = 1
        ');
        $stmt->execute([$name, $fee, $consent, $_SESSION['user_id']]);
        $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
            ->execute([$_SESSION['user_id'], 'procedure_added', "Added/updated procedure \"$name\" @ Rs $fee" . ($consent ? ' (consent required)' : '')]);
        $success = "Procedure \"$name\" saved.";
    }
}

// ---- Edit an existing procedure (name / rate / consent flag) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_procedure') {
    $id = (int) ($_POST['procedure_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $fee = (float) ($_POST['fee'] ?? 0);
    $consent = isset($_POST['mandatory_consent']) ? 1 : 0;

    if ($id > 0 && $name !== '' && $fee >= 0) {
        try {
            $pdo->prepare('UPDATE procedure_master SET name = ?, fee = ?, mandatory_consent = ? WHERE id = ?')
                ->execute([$name, $fee, $consent, $id]);
            $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
                ->execute([$_SESSION['user_id'], 'procedure_updated', "Updated procedure #$id (\"$name\", Rs $fee" . ($consent ? ', consent required' : '') . ')']);
            $success = 'Procedure updated.';
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $error = 'A procedure with that name already exists.';
            } else {
                throw $e;
            }
        }
    } else {
        $error = 'A procedure needs a name and a non-negative rate.';
    }
}

// ---- Toggle active/inactive ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_procedure') {
    $id = (int) ($_POST['procedure_id'] ?? 0);
    if ($id > 0) {
        $pdo->prepare('UPDATE procedure_master SET is_active = IF(is_active = 1, 0, 1) WHERE id = ?')
            ->execute([$id]);
        $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
            ->execute([$_SESSION['user_id'], 'procedure_toggled', "Toggled active state of procedure #$id"]);
        $success = 'Procedure status changed.';
    }
}

// ---- Save a doctor's procedure assignments (bulk upsert, staff.php pattern) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_doctor_procedures') {
    $editId = (int) ($_POST['doctor_id'] ?? 0);
    $rowIds = $_POST['dp_id'] ?? [];
    $procIds = $_POST['dp_procedure_id'] ?? [];
    $fees = $_POST['dp_fee'] ?? [];
    $shares = $_POST['dp_share'] ?? [];
    $taxPcts = $_POST['dp_tax_pct'] ?? [];
    $hasTaxes = $_POST['dp_has_tax'] ?? [];  // ['1' => '1', ...] keyed by row index (stamped by JS on submit)

    $doctorStmt = $pdo->prepare('SELECT id, name FROM users WHERE id = ? AND base_role = "DOCTOR"');
    $doctorStmt->execute([$editId]);
    $doctor = $doctorStmt->fetch();

    if (!$doctor) {
        $error = 'Doctor not found.';
    } else {
        // Money splits are involved — validate everything first, save nothing on error.
        $validProcIds = array_map('intval', array_column(
            $pdo->query('SELECT id FROM procedure_master')->fetchAll(), 'id'
        ));
        $rows = [];
        $seenProcs = [];
        foreach ($procIds as $i => $procId) {
            $procId = (int) $procId;
            if ($procId <= 0) {
                continue; // blank/unpicked row — ignore it
            }
            if (!in_array($procId, $validProcIds, true)) {
                $error = 'One of the selected procedures no longer exists — reload the page.';
                break;
            }
            if (isset($seenProcs[$procId])) {
                $error = 'Each procedure can only be assigned once per doctor.';
                break;
            }
            $seenProcs[$procId] = true;

            $fee = trim($fees[$i] ?? '');
            if ($fee === '') {
                $fee = null; // inherit the master's current rate
            } elseif (!is_numeric($fee) || (float) $fee < 0) {
                $error = 'Fee overrides must be blank or a non-negative amount.';
                break;
            } else {
                $fee = (float) $fee;
            }

            $share = trim($shares[$i] ?? '');
            if ($share === '' || !is_numeric($share) || (float) $share < 0 || (float) $share > 100) {
                $error = 'Doctor share must be between 0 and 100 for every row.';
                break;
            }
            $share = (float) $share;

            $hasTax = !empty($hasTaxes[$i]) ? 1 : 0;
            $taxPct = 0.0;
            if ($hasTax) {
                $taxPct = trim($taxPcts[$i] ?? '');
                if ($taxPct === '' || !is_numeric($taxPct) || (float) $taxPct <= 0 || (float) $taxPct > 100) {
                    $error = 'Enter a tax % (above 0, up to 100) for rows with tax deduction enabled.';
                    break;
                }
                $taxPct = (float) $taxPct;
            }

            $rows[] = [
                'id' => (int) ($rowIds[$i] ?? 0),
                'procedure_id' => $procId,
                'fee' => $fee,
                'share' => $share,
                'has_tax' => $hasTax,
                'tax_pct' => $taxPct,
            ];
        }

        if ($error === '') {
            $insert = $pdo->prepare('INSERT INTO doctor_procedures (doctor_id, procedure_master_id, fee, doctor_share_pct, has_tax, tax_percent) VALUES (?, ?, ?, ?, ?, ?)');
            $update = $pdo->prepare('UPDATE doctor_procedures SET procedure_master_id = ?, fee = ?, doctor_share_pct = ?, has_tax = ?, tax_percent = ? WHERE id = ? AND doctor_id = ?');
            $keepIds = [];
            foreach ($rows as $r) {
                if ($r['id'] > 0) {
                    $update->execute([$r['procedure_id'], $r['fee'], $r['share'], $r['has_tax'], $r['tax_pct'], $r['id'], $editId]);
                    $keepIds[] = $r['id'];
                } else {
                    $insert->execute([$editId, $r['procedure_id'], $r['fee'], $r['share'], $r['has_tax'], $r['tax_pct']]);
                    $keepIds[] = (int) $pdo->lastInsertId();
                }
            }

            if (!empty($keepIds)) {
                $placeholders = implode(',', array_fill(0, count($keepIds), '?'));
                $pdo->prepare("DELETE FROM doctor_procedures WHERE doctor_id = ? AND id NOT IN ($placeholders)")
                    ->execute(array_merge([$editId], $keepIds));
            } else {
                $pdo->prepare('DELETE FROM doctor_procedures WHERE doctor_id = ?')->execute([$editId]);
            }

            $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
                ->execute([
                    $_SESSION['user_id'],
                    'doctor_procedures_updated',
                    "Updated procedure assignments for doctor #$editId ({$doctor['name']}), " . count($keepIds) . ' assignment(s) on file',
                ]);
            $success = "Procedure assignments updated for {$doctor['name']}.";
        }
    }
}

// ---- Page data ----
$procedures = $pdo->query('SELECT * FROM procedure_master ORDER BY name')->fetchAll();

$doctors = $pdo->query('SELECT id, name, specialty FROM users WHERE base_role = "DOCTOR" ORDER BY name')->fetchAll();

$assignRows = $pdo->query('
    SELECT dp.*, pm.name AS procedure_name, pm.fee AS master_fee, pm.is_active AS master_active
      FROM doctor_procedures dp
      JOIN procedure_master pm ON pm.id = dp.procedure_master_id
     ORDER BY pm.name
')->fetchAll();
$assignmentsByDoctor = [];
foreach ($assignRows as $r) {
    $assignmentsByDoctor[(int) $r['doctor_id']][] = [
        'id' => (int) $r['id'],
        'procedure_id' => (int) $r['procedure_master_id'],
        'fee' => $r['fee'],  // null = inherit master
        'share' => $r['doctor_share_pct'],
        'has_tax' => (int) $r['has_tax'],
        'tax_pct' => $r['tax_percent'],
    ];
}

// Active procedures for the assignment dropdowns; inactive ones referenced by an
// existing assignment are still rendered (flagged) so old rows stay editable.
$procsJson = [];
foreach ($procedures as $p) {
    $procsJson[] = [
        'id' => (int) $p['id'],
        'name' => $p['name'],
        'fee' => (float) $p['fee'],
        'active' => (int) $p['is_active'],
    ];
}

$postedDoctorId = ($_POST['action'] ?? '') === 'save_doctor_procedures' ? (int) ($_POST['doctor_id'] ?? 0) : 0;

$pageTitle = 'Procedures';
$headExtra = <<<CSS
<style>
.header { height: 72px; position: sticky; top: 0; z-index: 20; display: flex; align-items: center; justify-content: space-between; padding: 0 32px; background: rgba(255,255,255,.80); backdrop-filter: blur(18px); border-bottom: 1px solid var(--border); }
.header-right { display: flex; align-items: center; gap: 18px; margin-left: auto; }
.header-date { font-size: 13px; color: var(--text-secondary); white-space: nowrap; }
.logout-link { font-size: 13px; color: var(--text-secondary); font-weight: 500; }

.add-row { display: grid; grid-template-columns: 1.6fr 1fr auto auto; gap: 10px; align-items: end; }
.add-row label { font-size: 11.5px; font-weight: 600; color: var(--text-secondary); display: block; margin-bottom: 5px; }
.add-row input[type=text], .add-row input[type=number] { width: 100%; padding: 9px 11px; border: 1px solid var(--border); border-radius: 10px; font: inherit; font-size: 13.5px; background: var(--bg); }
.add-row input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,127,126,.15); background: #fff; }
.consent-check { display: flex; align-items: center; gap: 7px; font-size: 12.5px; color: var(--text-secondary); white-space: nowrap; padding: 10px 0; }
.consent-check input { width: 15px; height: 15px; accent-color: var(--primary); }

.row-inp { padding: 7px 9px; border: 1px solid var(--border); border-radius: 8px; font: inherit; font-size: 12.5px; background: #fff; max-width: 100%; }
.row-inp:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,127,126,.15); }
.btn.small { padding: 7px 14px; font-size: 12.5px; }
.row-inactive td { opacity: .5; }
.link-btn { background: none; border: none; color: var(--primary); font: inherit; font-size: 12.5px; font-weight: 600; cursor: pointer; padding: 0; }
.link-btn.warn { color: var(--red-text); }

/* Doctor assignments editor */
.doc-pick { display: grid; grid-template-columns: 1fr auto; gap: 10px; align-items: end; max-width: 520px; }
.doc-pick label { font-size: 11.5px; font-weight: 600; color: var(--text-secondary); display: block; margin-bottom: 5px; }
.doc-pick select { width: 100%; padding: 9px 11px; border: 1px solid var(--border); border-radius: 10px; font: inherit; font-size: 13.5px; background: var(--bg); }
.doc-pick select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,127,126,.15); background: #fff; }

.assign-head, .assign-row { display: grid; grid-template-columns: 1.5fr 130px 130px 150px 90px 32px; gap: 10px; align-items: center; }
.assign-head { margin-top: 18px; padding: 0 2px 7px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted); border-bottom: 1px solid var(--border); }
.assign-row { padding: 9px 2px; border-bottom: 1px solid var(--border); }
.assign-row select, .assign-row input[type=number] { width: 100%; padding: 8px 10px; border: 1px solid var(--border); border-radius: 9px; font: inherit; font-size: 13px; background: #fff; }
.assign-row select:focus, .assign-row input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,127,126,.15); }
.assign-row .sub { font-size: 10.5px; color: var(--text-muted); margin-top: 3px; }
.assign-row .tax-cell { display: flex; align-items: center; gap: 7px; }
.assign-row .tax-cell input[type=checkbox] { width: 15px; height: 15px; accent-color: var(--primary); flex-shrink: 0; }
.assign-row .tax-cell input[type=number] { width: 70px; }
.remove-row { width: 28px; height: 28px; border: none; border-radius: 8px; background: #FEE2E2; color: #B91C1C; cursor: pointer; display: flex; align-items: center; justify-content: center; }
.remove-row svg { width: 13px; height: 13px; }
.assign-actions { display: flex; justify-content: space-between; align-items: center; margin-top: 14px; }
.add-assign-btn { background: none; border: 1px dashed var(--border); border-radius: 10px; color: var(--primary); font: inherit; font-size: 12.5px; font-weight: 600; cursor: pointer; padding: 9px 16px; }
.add-assign-btn:hover { border-color: var(--primary); background: var(--primary-light); }
.assign-empty { padding: 18px 2px; font-size: 13px; color: var(--text-muted); }
</style>
CSS;
require __DIR__ . '/partials/head.php';
$navActive = 'procedure_master';
require __DIR__ . '/partials/sidebar.php';
?>
        <header class="header">
            <div class="page-title" style="font-size:16px;">Procedures</div>
            <div class="header-right">
                <span class="header-date"><?= date('D, d/m/Y') ?></span>
                <a class="logout-link" href="logout.php">Logout</a>
            </div>
        </header>

        <div class="content">
            <div class="page-head">
                <div>
                    <div class="page-title">Procedures</div>
                    <div class="page-sub">The clinic-wide procedure catalogue, and which procedures each doctor performs</div>
                </div>
            </div>

            <?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($success): ?><div class="alert success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

            <!-- Add a procedure -->
            <div class="card">
                <div class="section-title">Add a Procedure</div>
                <div class="section-sub">Add to the procedure catalogue. Re-adding an existing name updates its rate.</div>
                <form method="POST" action="procedure_master.php">
                    <input type="hidden" name="action" value="add_procedure">
                    <div class="add-row">
                        <div>
                            <label>Procedure name</label>
                            <input type="text" name="name" placeholder="e.g. Nebulization" required>
                        </div>
                        <div>
                            <label>Rate (Rs)</label>
                            <input type="number" step="0.01" min="0" name="fee" value="0">
                        </div>
                        <label class="consent-check" title="If ticked, adding this procedure to a visit will require generating a consent form (built in a later phase — the flag is captured now).">
                            <input type="checkbox" name="mandatory_consent" value="1">
                            Requires consent form
                        </label>
                        <button type="submit" class="btn">Add</button>
                    </div>
                </form>
            </div>

            <!-- Procedure catalogue -->
            <div class="card">
                <div class="section-title">Procedure Catalogue</div>
                <div class="section-sub">Set the rate for each procedure. Toggle off anything not offered.</div>
                <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr><th>Procedure</th><th style="width:130px;">Rate (Rs)</th><th style="width:150px;">Consent form</th><th style="width:150px;">Update</th><th style="width:90px;">Status</th><th style="width:110px;"></th></tr>
                    </thead>
                    <tbody>
                        <?php if (!$procedures): ?>
                        <tr><td colspan="6" class="muted" style="padding:20px 10px;">No procedures yet — add one above.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($procedures as $p): ?>
                        <tr class="<?= (int) $p['is_active'] === 1 ? '' : 'row-inactive' ?>">
                            <td>
                                <input type="text" name="name" form="edit-<?= (int) $p['id'] ?>" class="row-inp" style="font-weight:600;width:100%;" value="<?= htmlspecialchars($p['name']) ?>">
                            </td>
                            <td>
                                <input type="number" step="0.01" min="0" name="fee" form="edit-<?= (int) $p['id'] ?>" class="row-inp" style="width:100px;" value="<?= htmlspecialchars((string) $p['fee']) ?>">
                            </td>
                            <td>
                                <label class="consent-check" style="padding:0;">
                                    <input type="checkbox" name="mandatory_consent" value="1" form="edit-<?= (int) $p['id'] ?>" <?= (int) $p['mandatory_consent'] === 1 ? 'checked' : '' ?>>
                                    Mandatory
                                </label>
                            </td>
                            <td>
                                <form method="POST" action="procedure_master.php" id="edit-<?= (int) $p['id'] ?>" style="margin:0;">
                                    <input type="hidden" name="action" value="edit_procedure">
                                    <input type="hidden" name="procedure_id" value="<?= (int) $p['id'] ?>">
                                    <button type="submit" class="btn small">Save changes</button>
                                </form>
                            </td>
                            <td><?= (int) $p['is_active'] === 1 ? '<span class="status-pill active">Active</span>' : '<span class="status-pill on-leave">Inactive</span>' ?></td>
                            <td>
                                <form method="POST" action="procedure_master.php" style="display:inline;margin:0;">
                                    <input type="hidden" name="action" value="toggle_procedure">
                                    <input type="hidden" name="procedure_id" value="<?= (int) $p['id'] ?>">
                                    <button type="submit" class="link-btn <?= (int) $p['is_active'] === 1 ? 'warn' : '' ?>"><?= (int) $p['is_active'] === 1 ? 'Deactivate' : 'Activate' ?></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>

            <!-- Doctor assignments -->
            <div class="card">
                <div class="section-title">Doctor Assignments</div>
                <div class="section-sub">Which procedures each doctor performs, their fee, the doctor/clinic split, and any tax withheld from the doctor's share.</div>

                <div class="doc-pick">
                    <div>
                        <label>Doctor</label>
                        <select id="assignDoctor">
                            <option value="">— Select a doctor —</option>
                            <?php foreach ($doctors as $d): ?>
                            <option value="<?= (int) $d['id'] ?>">
                                <?php
                                    $specLabels = ['PEDIATRICIAN' => 'Pediatrician', 'ENT' => 'ENT Consultant', 'DENTAL' => 'Dental Surgeon', 'PEDIATRIC_SURGEON' => 'Pediatric Surgeon'];
                                ?>
                                <?= htmlspecialchars($d['name']) ?><?= isset($specLabels[$d['specialty']]) ? ' — ' . $specLabels[$d['specialty']] : '' ?>
                                (<?= count($assignmentsByDoctor[(int) $d['id']] ?? []) ?> assigned)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <form method="POST" action="procedure_master.php" id="assignForm" style="display:none;">
                    <input type="hidden" name="action" value="save_doctor_procedures">
                    <input type="hidden" name="doctor_id" id="assignDoctorId" value="">

                    <div class="assign-head">
                        <div>Procedure</div>
                        <div>Fee (Rs)</div>
                        <div>Doctor share %</div>
                        <div>Tax deduction</div>
                        <div>Clinic %</div>
                        <div></div>
                    </div>
                    <div id="assignRowList"></div>

                    <div class="assign-actions">
                        <button type="button" class="add-assign-btn" id="addAssignRowBtn">+ Add procedure</button>
                        <button type="submit" class="btn">Save Assignments</button>
                    </div>
                </form>
                <div class="assign-empty" id="assignEmptyHint">Select a doctor above to manage their procedures.</div>
            </div>
        </div>
    </div>
</div>
<script>
const PROCEDURES = <?= json_encode($procsJson, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const ASSIGNMENTS = <?= json_encode($assignmentsByDoctor ?: new stdClass(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const PROC_BY_ID = Object.fromEntries(PROCEDURES.map(p => [p.id, p]));

const assignDoctor = document.getElementById('assignDoctor');
const assignForm = document.getElementById('assignForm');
const assignDoctorId = document.getElementById('assignDoctorId');
const assignRowList = document.getElementById('assignRowList');
const assignEmptyHint = document.getElementById('assignEmptyHint');

function fmtRs(n) {
    return Number(n).toLocaleString('en-PK', { maximumFractionDigits: 2 });
}

// One assignment row. row = {id, procedure_id, fee (null = inherit), share, has_tax, tax_pct}
function assignmentRow(row) {
    row = row || { id: '', procedure_id: 0, fee: null, share: '', has_tax: 0, tax_pct: '' };
    const wrap = document.createElement('div');
    wrap.className = 'assign-row';

    const idInput = document.createElement('input');
    idInput.type = 'hidden';
    idInput.name = 'dp_id[]';
    idInput.value = row.id;

    // Procedure select: active procedures, plus this row's procedure even if
    // it has since been deactivated (flagged) so the row stays editable.
    const procCell = document.createElement('div');
    const procSel = document.createElement('select');
    procSel.name = 'dp_procedure_id[]';
    const blankOpt = document.createElement('option');
    blankOpt.value = '';
    blankOpt.textContent = '— Pick a procedure —';
    procSel.appendChild(blankOpt);
    PROCEDURES.forEach(p => {
        if (!p.active && p.id !== Number(row.procedure_id)) return;
        const opt = document.createElement('option');
        opt.value = p.id;
        opt.textContent = p.name + (p.active ? '' : ' (inactive)');
        if (p.id === Number(row.procedure_id)) opt.selected = true;
        procSel.appendChild(opt);
    });
    procCell.appendChild(procSel);

    // Fee override: blank = inherit the master's current rate.
    const feeCell = document.createElement('div');
    const feeInput = document.createElement('input');
    feeInput.type = 'number';
    feeInput.name = 'dp_fee[]';
    feeInput.min = '0';
    feeInput.step = '0.01';
    feeInput.value = row.fee === null || row.fee === undefined || row.fee === '' ? '' : row.fee;
    const feeSub = document.createElement('div');
    feeSub.className = 'sub';
    feeCell.appendChild(feeInput);
    feeCell.appendChild(feeSub);

    function refreshFeeHint() {
        const p = PROC_BY_ID[Number(procSel.value)];
        if (p) {
            feeInput.placeholder = p.fee;
            feeSub.textContent = 'blank = master rate (Rs ' + fmtRs(p.fee) + ')';
        } else {
            feeInput.placeholder = '';
            feeSub.textContent = '';
        }
    }
    procSel.addEventListener('change', refreshFeeHint);

    // Doctor share % with live clinic % readout.
    const shareCell = document.createElement('div');
    const shareInput = document.createElement('input');
    shareInput.type = 'number';
    shareInput.name = 'dp_share[]';
    shareInput.min = '0';
    shareInput.max = '100';
    shareInput.step = '0.01';
    shareInput.placeholder = '0–100';
    shareInput.value = row.share === '' || row.share === null || row.share === undefined ? '' : Number(row.share);
    shareCell.appendChild(shareInput);

    const clinicCell = document.createElement('div');
    clinicCell.style.fontSize = '13px';
    clinicCell.style.fontWeight = '600';
    clinicCell.style.color = 'var(--text-secondary)';
    function refreshClinic() {
        const v = parseFloat(shareInput.value);
        clinicCell.textContent = (!isNaN(v) && v >= 0 && v <= 100) ? (Math.round((100 - v) * 100) / 100) + '%' : '—';
    }
    shareInput.addEventListener('input', refreshClinic);

    // Tax: checkbox toggles the % input; % withheld from the doctor's share.
    const taxCell = document.createElement('div');
    taxCell.className = 'tax-cell';
    taxCell.title = 'Tax withheld from the doctor’s share at commission time';
    const taxCb = document.createElement('input');
    taxCb.type = 'checkbox';
    taxCb.checked = Number(row.has_tax) === 1;
    const taxPct = document.createElement('input');
    taxPct.type = 'number';
    taxPct.name = 'dp_tax_pct[]';
    taxPct.min = '0';
    taxPct.max = '100';
    taxPct.step = '0.01';
    taxPct.placeholder = '%';
    taxPct.value = Number(row.has_tax) === 1 && row.tax_pct !== '' && row.tax_pct !== null ? Number(row.tax_pct) : '';
    function refreshTax() {
        taxPct.style.display = taxCb.checked ? '' : 'none';
        if (!taxCb.checked) taxPct.value = '';
    }
    taxCb.addEventListener('change', refreshTax);
    taxCell.appendChild(taxCb);
    taxCell.appendChild(taxPct);

    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'remove-row';
    removeBtn.setAttribute('aria-label', 'Remove procedure assignment');
    removeBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg>';
    removeBtn.addEventListener('click', () => wrap.remove());

    wrap.appendChild(procCell);
    wrap.appendChild(feeCell);
    wrap.appendChild(shareCell);
    wrap.appendChild(taxCell);
    wrap.appendChild(clinicCell);
    wrap.appendChild(removeBtn);
    wrap.appendChild(idInput);
    wrap._taxCb = taxCb;

    refreshFeeHint();
    refreshClinic();
    refreshTax();
    return wrap;
}

function openDoctorAssignments(doctorId) {
    if (!doctorId) {
        assignForm.style.display = 'none';
        assignEmptyHint.style.display = '';
        return;
    }
    assignDoctorId.value = doctorId;
    assignRowList.innerHTML = '';
    const rows = ASSIGNMENTS[doctorId] || [];
    if (rows.length === 0) {
        assignRowList.appendChild(assignmentRow());
    } else {
        rows.forEach(r => assignRowList.appendChild(assignmentRow(r)));
    }
    assignForm.style.display = '';
    assignEmptyHint.style.display = 'none';
}

assignDoctor.addEventListener('change', () => openDoctorAssignments(assignDoctor.value));

document.getElementById('addAssignRowBtn').addEventListener('click', () => {
    assignRowList.appendChild(assignmentRow());
});

// Checkboxes only submit when checked, which would break alignment with the
// parallel dp_procedure_id[]/dp_fee[] arrays. So on submit, stamp each row's
// tax checkbox with its DOM-order index name (dp_has_tax[IDX]); PHP reads that map.
assignForm.addEventListener('submit', () => {
    Array.from(assignRowList.children).forEach((rowEl, idx) => {
        if (rowEl._taxCb) { rowEl._taxCb.name = 'dp_has_tax[' + idx + ']'; }
    });
});

<?php if ($postedDoctorId > 0): ?>
// Re-open the doctor whose assignments were just submitted.
assignDoctor.value = '<?= $postedDoctorId ?>';
openDoctorAssignments('<?= $postedDoctorId ?>');
<?php endif; ?>
</script>
<script src="assets/js/date-picker.js"></script>
</body>
</html>
