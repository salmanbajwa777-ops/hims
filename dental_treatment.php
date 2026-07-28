<?php
/**
 * Dental treatment record — what happened at the chair, per visit, per tooth.
 *
 * This is the CLINICAL record. It is deliberately NOT the money: charges are
 * recorded here for reference, but nothing on this page bills anything. After
 * saving, the dentist chooses one of two money paths:
 *
 *   "Bill this now"          -> procedure_bill.php, the existing P-series
 *                               prepaid bill. For a filling or an extraction:
 *                               done today, paid today, no account.
 *   "Add to a package"       -> dental_account.php, an itemised multi-visit
 *                               account. For a crown, an ortho case, a full
 *                               arch: quoted once, paid down over months.
 *
 * That choice is the whole design of the dental module — see the header of
 * sql/add_dental_module.sql.
 *
 * PRIOR VISITS ARE READ-ONLY. A treatment record is a clinical history; letting
 * someone edit last month's entry would destroy the continuity it exists to
 * provide. Only an admin can VOID a row (with a reason), and the void stays
 * visible.
 *
 * Requires sql/add_dental_procedure_fields.sql + sql/add_dental_module.sql.
 */

require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
require_once __DIR__ . '/config/billing.php';
require_once __DIR__ . '/config/dental.php';
refresh_session_permissions($pdo);
require_permission('DENTAL_RECORD_TREATMENT');

$error = '';
$success = '';
$userId = (int) $_SESSION['user_id'];
$isAdmin = ($_SESSION['base_role'] ?? '') === 'ADMIN';

// ---- Record a treatment ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_treatment') {
    $patientId = (int) ($_POST['patient_id'] ?? 0);
    $doctorId  = (int) ($_POST['doctor_id'] ?? 0);
    $procId    = (int) ($_POST['procedure_master_id'] ?? 0);
    $tooth     = trim($_POST['tooth_fdi'] ?? '');
    $visitDate = trim($_POST['visit_date'] ?? '') ?: date('Y-m-d');
    $findings  = trim($_POST['findings'] ?? '');
    $notes     = trim($_POST['treatment_notes'] ?? '');
    $nextPlan  = trim($_POST['next_visit_plan'] ?? '');

    // A tooth is optional (scaling, a denture and a full-mouth exam are not
    // tooth-specific), but if one IS given it must be a real FDI code. The
    // picker makes an invalid choice unreachable; this catches a crafted POST.
    if ($tooth !== '' && !is_valid_fdi($tooth)) {
        $error = 'That is not a valid FDI tooth number.';
    } elseif ($patientId <= 0 || $doctorId <= 0 || $procId <= 0) {
        $error = 'Pick a patient, a dentist and a procedure.';
    } elseif ($visitDate > date('Y-m-d')) {
        $error = 'A treatment cannot be recorded for a future date.';
    } else {
        // Name and charge are re-read from the catalogue, never taken from the
        // POST, and snapshotted onto the row so retiring the catalogue entry
        // later cannot make this record unreadable.
        $pm = $pdo->prepare('SELECT id, name, fee FROM procedure_master WHERE id = ? AND is_active = 1');
        $pm->execute([$procId]);
        $proc = $pm->fetch();

        if (!$proc) {
            $error = 'That procedure is no longer available.';
        } else {
            try {
                $pdo->prepare('
                    INSERT INTO dental_treatment_records
                        (patient_id, doctor_id, visit_date, procedure_master_id, procedure_name,
                         tooth_fdi, charge, findings, treatment_notes, next_visit_plan,
                         performed_by_id, entered_by_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ')->execute([
                    $patientId, $doctorId, $visitDate, $procId, $proc['name'],
                    $tooth !== '' ? $tooth : null, (float) $proc['fee'],
                    $findings !== '' ? $findings : null,
                    $notes !== '' ? $notes : null,
                    $nextPlan !== '' ? $nextPlan : null,
                    $doctorId, $userId,
                ]);
                audit_log($pdo, 'dental_treatment_recorded', "Recorded \"{$proc['name']}\"" . ($tooth !== '' ? " on tooth $tooth" : '') . " for patient #$patientId", $userId);
                $success = 'Treatment recorded.' . ($tooth !== '' ? ' Tooth ' . htmlspecialchars($tooth) . '.' : '');
            } catch (PDOException $e) {
                error_log('[dental_treatment] ' . $e->getMessage());
                $error = 'Could not save the treatment. Please try again.';
            }
        }
    }
}

// ---- Void a treatment record (admin only) ----
// Prior visits are otherwise immutable; a void keeps the row and its reason
// visible rather than deleting clinical history.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'void_treatment') {
    $recId  = (int) ($_POST['record_id'] ?? 0);
    $reason = trim($_POST['void_reason'] ?? '');
    if (!$isAdmin) {
        $error = 'Only an admin can void a treatment record.';
    } elseif ($reason === '') {
        $error = 'A void needs a reason.';
    } else {
        $upd = $pdo->prepare('UPDATE dental_treatment_records
                                 SET voided_at = NOW(), voided_by_id = ?, void_reason = ?
                               WHERE id = ? AND voided_at IS NULL');
        $upd->execute([$userId, $reason, $recId]);
        if ($upd->rowCount() === 1) {
            audit_log($pdo, 'dental_treatment_voided', "Voided treatment record #$recId — $reason", $userId);
            $success = 'Treatment record voided.';
        } else {
            $error = 'Could not void that record (already voided?).';
        }
    }
}

// ---- Patient search (same LIKE pattern as reception/procedure_bill) ----
$q = trim($_GET['q'] ?? '');
$patientResults = [];
if ($q !== '') {
    $like = '%' . $q . '%';
    $s = $pdo->prepare('
        SELECT id, mrn, name, father_name, phone, dob
        FROM patients
        WHERE name LIKE ? OR phone LIKE ? OR father_name LIKE ? OR mrn LIKE ?
        ORDER BY name LIMIT 25
    ');
    $s->execute([$like, $like, $like, $like]);
    $patientResults = $s->fetchAll();
}

$selectedId = (int) ($_GET['patient_id'] ?? $_POST['patient_id'] ?? 0);
$selectedPatient = null;
if ($selectedId > 0) {
    $s = $pdo->prepare('SELECT id, mrn, name, father_name, phone, dob FROM patients WHERE id = ?');
    $s->execute([$selectedId]);
    $selectedPatient = $s->fetch() ?: null;
}

// Dentists: doctors whose specialty is DENTAL. A general physician does not
// appear here — the tooth chart is not theirs to fill in.
$dentists = $pdo->query("
    SELECT id, name FROM users
    WHERE base_role = 'DOCTOR' AND is_active = 1 AND specialty = 'DENTAL'
    ORDER BY name
")->fetchAll();

// The dental catalogue, grouped by category for the picker.
$dentalProcs = $pdo->query("
    SELECT id, name, fee, category, mandatory_consent, has_lab_component
    FROM procedure_master
    WHERE is_dental = 1 AND is_active = 1
    ORDER BY category IS NULL, category, name
")->fetchAll();

// This patient's history, newest first. Live and voided rows both shown.
$history = [];
$openAccounts = [];
if ($selectedId > 0) {
    $s = $pdo->prepare('
        SELECT r.*, d.name AS doctor_name, e.name AS entered_by_name, v.name AS voided_by_name,
               a.account_number
          FROM dental_treatment_records r
          LEFT JOIN users d ON d.id = r.doctor_id
          LEFT JOIN users e ON e.id = r.entered_by_id
          LEFT JOIN users v ON v.id = r.voided_by_id
          LEFT JOIN dental_procedure_accounts a ON a.id = r.account_id
         WHERE r.patient_id = ?
         ORDER BY r.visit_date DESC, r.id DESC
    ');
    $s->execute([$selectedId]);
    $history = $s->fetchAll();

    // Offered as a destination for "add to a package".
    $s = $pdo->prepare("SELECT id, account_number, title FROM dental_procedure_accounts
                         WHERE patient_id = ? AND status = 'OPEN' ORDER BY id DESC");
    $s->execute([$selectedId]);
    $openAccounts = $s->fetchAll();
}

$pageTitle = 'Dental Treatment';
$headExtra = <<<CSS
<style>
/* Page-specific only — .card/.btn/.field/.alert/.status-pill come from app.css. */
.tx-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.tx-grid .full { grid-column: 1 / -1; }
.tooth-pill { display:inline-block; min-width:34px; text-align:center; padding:2px 7px;
  border-radius:var(--radius-pill); background:var(--primary-light); color:var(--primary-dark);
  font-weight:700; font-size:var(--fs-pill); letter-spacing:.02em; }
.tooth-pill.none { background:var(--card-alt); color:var(--text-muted); font-weight:600; }
.hist-row.voided { opacity:.55; }
.hist-row.voided .hist-proc { text-decoration: line-through; }
.hist-note { font-size:var(--fs-meta); color:var(--text-secondary); margin-top:3px; }
.void-tag { color:var(--danger); font-size:var(--fs-micro); font-weight:700; }
.pt-card { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; }
.res-row { display:flex; align-items:center; justify-content:space-between; gap:10px;
  padding:9px 0; border-bottom:1px solid var(--row-line); }
.res-row:last-child { border-bottom:0; }
.after-save { display:flex; gap:10px; flex-wrap:wrap; margin-top:10px; }
@media (max-width: 900px) { .tx-grid { grid-template-columns: 1fr; } }
</style>
CSS;
require __DIR__ . '/partials/head.php';
$navActive = 'dental_treatment';
require __DIR__ . '/partials/sidebar.php';
?>
        <?php require __DIR__ . '/partials/quick_header.php'; ?>

        <div class="content">
            <div class="page-head">
                <div>
                    <div class="page-title">Dental Treatment</div>
                    <div class="page-sub">Record what was done at the chair, tooth by tooth. Prior visits are read-only.</div>
                </div>
            </div>

            <?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($success): ?><div class="alert success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

            <?php if (!$selectedPatient): ?>
            <!-- ---- Find the patient ---- -->
            <div class="card">
                <div class="section-title">Find a patient</div>
                <div class="section-sub">Search by name, phone, guardian or MR number.</div>
                <form method="GET" action="dental_treatment.php" style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;">
                    <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Name, phone, MR #…" style="flex:1;min-width:220px;" autofocus>
                    <button type="submit" class="btn">Search</button>
                </form>

                <?php if ($q !== ''): ?>
                    <?php if (!$patientResults): ?>
                    <div class="empty" style="margin-top:14px;">No patient matches “<?= htmlspecialchars($q) ?>”.</div>
                    <?php else: ?>
                    <div style="margin-top:14px;">
                        <?php foreach ($patientResults as $p): ?>
                        <div class="res-row">
                            <div>
                                <div style="font-weight:600;"><?= htmlspecialchars($p['name']) ?></div>
                                <div class="hist-note"><?= htmlspecialchars($p['mrn']) ?><?= $p['father_name'] ? ' · ' . htmlspecialchars($p['father_name']) : '' ?><?= $p['phone'] ? ' · ' . htmlspecialchars($p['phone']) : '' ?></div>
                            </div>
                            <a class="btn small" href="dental_treatment.php?patient_id=<?= (int) $p['id'] ?>">Open</a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <?php else: ?>
            <!-- ---- The selected patient ---- -->
            <div class="card">
                <div class="pt-card">
                    <div>
                        <div style="font-weight:700;font-size:var(--fs-card);"><?= htmlspecialchars($selectedPatient['name']) ?></div>
                        <div class="hist-note">
                            <?= htmlspecialchars($selectedPatient['mrn']) ?>
                            <?= $selectedPatient['father_name'] ? ' · ' . htmlspecialchars($selectedPatient['father_name']) : '' ?>
                            <?= $selectedPatient['phone'] ? ' · ' . htmlspecialchars($selectedPatient['phone']) : '' ?>
                            <?= $selectedPatient['dob'] ? ' · DOB ' . date('d/m/Y', strtotime($selectedPatient['dob'])) : '' ?>
                        </div>
                    </div>
                    <a class="btn secondary small" href="dental_treatment.php">Change patient</a>
                </div>
            </div>

            <?php if (!$dentists): ?>
            <div class="alert error">No dentist is set up yet. An admin must set a doctor's specialty to <b>Dental</b> on the Staff page before treatment can be recorded.</div>
            <?php elseif (!$dentalProcs): ?>
            <div class="alert error">No dental procedures in the catalogue yet. An admin adds them on <a href="procedure_master.php">Procedures</a> by ticking <b>Dental</b>.</div>
            <?php else: ?>

            <!-- ---- Record today's work ---- -->
            <div class="card">
                <div class="section-title">Record treatment</div>
                <div class="section-sub">One entry per procedure per tooth — the same procedure on three teeth is three entries.</div>

                <form method="POST" action="dental_treatment.php">
                    <input type="hidden" name="action" value="record_treatment">
                    <input type="hidden" name="patient_id" value="<?= (int) $selectedPatient['id'] ?>">

                    <div class="tx-grid" style="margin-top:12px;">
                        <div class="field">
                            <label>Dentist</label>
                            <select name="doctor_id" required>
                                <option value="">— select —</option>
                                <?php foreach ($dentists as $d): ?>
                                <option value="<?= (int) $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>Visit date</label>
                            <input type="date" name="visit_date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="field">
                            <label>Procedure</label>
                            <select name="procedure_master_id" id="procSel" required>
                                <option value="">— select —</option>
                                <?php
                                $lastCat = '__none__';
                                foreach ($dentalProcs as $p):
                                    $cat = $p['category'] ?: 'Uncategorised';
                                    if ($cat !== $lastCat) {
                                        if ($lastCat !== '__none__') { echo '</optgroup>'; }
                                        echo '<optgroup label="' . htmlspecialchars(DENTAL_CAT_LABELS[$p['category']] ?? 'Uncategorised') . '">';
                                        $lastCat = $cat;
                                    }
                                ?>
                                <option value="<?= (int) $p['id'] ?>"
                                        data-consent="<?= (int) $p['mandatory_consent'] ?>"
                                        data-lab="<?= (int) $p['has_lab_component'] ?>">
                                    <?= htmlspecialchars($p['name']) ?> — Rs <?= number_format((float) $p['fee'], 2) ?>
                                </option>
                                <?php endforeach; if ($lastCat !== '__none__') { echo '</optgroup>'; } ?>
                            </select>
                            <div class="hint" id="procHint"></div>
                        </div>
                        <div class="field">
                            <!-- Labelled "Tooth (FDI)" everywhere on purpose: FDI, Universal and
                                 Palmer numbering collide, so an unlabelled "36" is ambiguous. -->
                            <label>Tooth (FDI)</label>
                            <?= fdi_select_html('tooth_fdi') ?>
                            <div class="hint">Leave blank for whole-mouth work (scaling, dentures).</div>
                        </div>
                        <div class="field full">
                            <label>Findings</label>
                            <textarea name="findings" rows="2" placeholder="What was seen — caries, mobility, swelling…"></textarea>
                        </div>
                        <div class="field full">
                            <label>Treatment notes</label>
                            <textarea name="treatment_notes" rows="2" placeholder="What was done, materials used, anaesthetic…"></textarea>
                        </div>
                        <div class="field full">
                            <label>Plan for next visit</label>
                            <input type="text" name="next_visit_plan" placeholder="e.g. Crown cementation in 2 weeks">
                        </div>
                    </div>

                    <div style="display:flex;justify-content:flex-end;margin-top:14px;">
                        <button type="submit" class="btn">Record treatment</button>
                    </div>
                </form>

                <!-- The two money paths. Recording is clinical; neither of these
                     is automatic, because only the dentist knows whether this is
                     a same-visit charge or part of a package. -->
                <div style="border-top:1px solid var(--row-line);margin-top:16px;padding-top:14px;">
                    <div class="section-sub" style="margin:0 0 6px;"><b>Then charge for it</b> — recording treatment does not bill anything.</div>
                    <div class="after-save">
                        <a class="btn secondary small" href="procedure_bill.php?patient_id=<?= (int) $selectedPatient['id'] ?>">Bill this now (single visit)</a>
                        <?php if ($openAccounts): ?>
                            <?php foreach ($openAccounts as $acc): ?>
                            <a class="btn secondary small" href="dental_account.php?id=<?= (int) $acc['id'] ?>">Add to <?= htmlspecialchars($acc['account_number']) ?><?= $acc['title'] ? ' · ' . htmlspecialchars($acc['title']) : '' ?></a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <?php if (has_permission('DENTAL_OPEN_ACCOUNT')): ?>
                        <a class="btn secondary small" href="dental_accounts.php?new_for=<?= (int) $selectedPatient['id'] ?>">Open a package account</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- ---- History: read-only ---- -->
            <div class="card">
                <div class="section-title">Treatment history</div>
                <div class="section-sub">Previous visits cannot be edited — that continuity is the point of the record.</div>

                <?php if (!$history): ?>
                <div class="empty" style="margin-top:14px;">Nothing recorded for this patient yet.</div>
                <?php else: ?>
                <div style="overflow-x:auto;margin-top:12px;">
                <table>
                    <thead>
                        <tr>
                            <th style="width:110px;">Date</th>
                            <th style="width:70px;">Tooth</th>
                            <th>Procedure</th>
                            <th style="width:150px;">Dentist</th>
                            <th style="width:110px;" class="text-right">Charge</th>
                            <?php if ($isAdmin): ?><th style="width:70px;"></th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $h): $isVoid = $h['voided_at'] !== null; ?>
                        <tr class="hist-row <?= $isVoid ? 'voided' : '' ?>">
                            <td><?= date('d/m/Y', strtotime($h['visit_date'])) ?></td>
                            <td>
                                <?php if ($h['tooth_fdi']): ?>
                                <span class="tooth-pill" title="<?= htmlspecialchars(fdi_label($h['tooth_fdi'])) ?>"><?= htmlspecialchars($h['tooth_fdi']) ?></span>
                                <?php else: ?>
                                <span class="tooth-pill none">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="hist-proc" style="font-weight:600;"><?= htmlspecialchars($h['procedure_name']) ?></span>
                                <?php if ($h['account_number']): ?>
                                <span class="status-pill" style="margin-left:6px;"><?= htmlspecialchars($h['account_number']) ?></span>
                                <?php endif; ?>
                                <?php if ($h['findings']): ?><div class="hist-note"><b>Findings:</b> <?= htmlspecialchars($h['findings']) ?></div><?php endif; ?>
                                <?php if ($h['treatment_notes']): ?><div class="hist-note"><?= htmlspecialchars($h['treatment_notes']) ?></div><?php endif; ?>
                                <?php if ($h['next_visit_plan']): ?><div class="hist-note"><b>Next:</b> <?= htmlspecialchars($h['next_visit_plan']) ?></div><?php endif; ?>
                                <?php if ($isVoid): ?><div class="void-tag">VOIDED<?= $h['void_reason'] ? ' — ' . htmlspecialchars($h['void_reason']) : '' ?><?= $h['voided_by_name'] ? ' (' . htmlspecialchars($h['voided_by_name']) . ')' : '' ?></div><?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($h['doctor_name'] ?? '—') ?></td>
                            <td class="text-right">Rs <?= number_format((float) $h['charge'], 2) ?></td>
                            <?php if ($isAdmin): ?>
                            <td>
                                <?php if (!$isVoid): ?>
                                <!-- data-no-once: the global double-submit guard would disable the
                                     button before the prompt resolves, so a cancelled prompt would
                                     leave a dead control. -->
                                <form method="POST" action="dental_treatment.php" data-no-once
                                      onsubmit="var r=prompt('Reason for voiding this record?'); if(!r){return false;} this.void_reason.value=r;">
                                    <input type="hidden" name="action" value="void_treatment">
                                    <input type="hidden" name="patient_id" value="<?= (int) $selectedPatient['id'] ?>">
                                    <input type="hidden" name="record_id" value="<?= (int) $h['id'] ?>">
                                    <input type="hidden" name="void_reason" value="">
                                    <button type="submit" class="btn small danger">Void</button>
                                </form>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<script>
// Surface the two flags that change what happens after this is recorded, so the
// dentist knows a consent is owed BEFORE the patient leaves the chair.
(function () {
    var sel = document.getElementById('procSel');
    var hint = document.getElementById('procHint');
    if (!sel || !hint) { return; }
    sel.addEventListener('change', function () {
        var o = sel.options[sel.selectedIndex], parts = [];
        if (o && o.dataset.consent === '1') { parts.push('Consent form required before this can be billed.'); }
        if (o && o.dataset.lab === '1') { parts.push('Sends work to a lab — log it on the Lab Work page.'); }
        hint.textContent = parts.join(' ');
    });
})();
</script>
<script src="assets/js/date-picker.js?v=<?= @filemtime(__DIR__ . "/assets/js/date-picker.js") ?: 1 ?>"></script>
</body>
</html>
