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
 * Consent wording is edited on procedure_consent_template.php, linked per row:
 * a paragraph does not fit in this table. A procedure with wording prints its
 * consent form with the receipt (procedure_bill.php); mandatory_consent alone
 * only gates billing.
 */
require_once __DIR__ . '/config/guard_admin.php';

$error = '';
$success = '';

// Has add_procedure_disposables.sql been run? The whole disposables UI is
// conditional on it, so an un-migrated database keeps working exactly as before
// instead of fataling on a missing column. Only the FLAG matters on this page —
// the admin sets which procedures have disposables; the cost itself is entered
// at billing time and lives on procedure_bill_items.
require_once __DIR__ . '/config/billing.php';
$procHasDisposables = procedure_disposables_flag($pdo);

// Has add_dental_procedure_fields.sql been run? Same degradation rule as
// disposables above: without the columns the page is exactly the pre-dental
// catalogue rather than a fatal. Checked on is_dental because that is the flag
// every dental picker filters on; the other three arrive in the same migration.
$procHasDental = column_exists($pdo, 'procedure_master', 'is_dental');

// DENTAL_CAT_LABELS (the category ENUM's display names) is shared from
// config/dental.php so this page, the treatment picker and the account picker
// cannot drift apart on wording.
require_once __DIR__ . '/config/dental.php';

// is_dental and category are redundant by design (see the migration header), so
// they are normalised together in ONE place rather than at each call site:
// unticking Dental clears the category and the lab fields; picking a category
// implies Dental. Returns [isDental, category, hasLab, defaultLabCharge].
function dental_fields_from_post($isDental, $category, $hasLab, $labCharge): array {
    $isDental = $isDental ? 1 : 0;
    $category = ($category !== '' && isset(DENTAL_CAT_LABELS[$category])) ? $category : null;
    if ($category !== null) {
        $isDental = 1;                 // a category implies dental
    }
    if (!$isDental) {
        // Not dental: nothing dental-shaped may survive on the row.
        return [0, null, 0, 0.0];
    }
    $hasLab = $hasLab ? 1 : 0;
    return [$isDental, $category, $hasLab, $hasLab ? max(0.0, (float) $labCharge) : 0.0];
}

// ---- Add a procedure to the master catalogue ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_procedure') {
    $name = trim($_POST['name'] ?? '');
    $fee = (float) ($_POST['fee'] ?? 0);
    $consent = isset($_POST['mandatory_consent']) ? 1 : 0;
    $hasDisp = isset($_POST['has_disposables']) ? 1 : 0;

    if ($name === '' || $fee < 0) {
        $error = 'A procedure needs a name and a non-negative rate.';
    } else {
        // Build the column list from what the database actually has. Two
        // optional migrations (disposables, dental) would otherwise mean four
        // hand-written INSERT variants; this stays one statement however many
        // land later.
        $cols = ['name', 'fee', 'mandatory_consent'];
        $vals = [$name, $fee, $consent];
        // Re-stated on the UPDATE arm so an existing row is brought in line
        // rather than keeping stale flags.
        $upd  = ['fee = VALUES(fee)', 'mandatory_consent = VALUES(mandatory_consent)', 'is_active = 1'];

        if ($procHasDisposables) {
            $cols[] = 'has_disposables'; $vals[] = $hasDisp;
            $upd[]  = 'has_disposables = VALUES(has_disposables)';
        }
        if ($procHasDental) {
            [$isDental, $category, $hasLab, $labCharge] = dental_fields_from_post(
                isset($_POST['is_dental']), $_POST['category'] ?? '',
                isset($_POST['has_lab_component']), $_POST['default_lab_charge'] ?? 0
            );
            $cols = array_merge($cols, ['is_dental', 'category', 'has_lab_component', 'default_lab_charge']);
            $vals = array_merge($vals, [$isDental, $category, $hasLab, $labCharge]);
            $upd  = array_merge($upd, ['is_dental = VALUES(is_dental)', 'category = VALUES(category)',
                                       'has_lab_component = VALUES(has_lab_component)',
                                       'default_lab_charge = VALUES(default_lab_charge)']);
        }
        $cols[] = 'created_by_id'; $vals[] = $_SESSION['user_id'];

        $pdo->prepare(
            'INSERT INTO procedure_master (' . implode(', ', $cols) . ') VALUES ('
            . implode(', ', array_fill(0, count($cols), '?')) . ') '
            . 'ON DUPLICATE KEY UPDATE ' . implode(', ', $upd)
        )->execute($vals);

        audit_log($pdo, 'procedure_added', "Added/updated procedure \"$name\" @ Rs $fee" . ($consent ? ' (consent required)' : '') . ($hasDisp ? ' (has disposables)' : '') . (!empty($isDental) ? ' (dental' . ($category ? ': ' . DENTAL_CAT_LABELS[$category] : '') . ')' : ''), $_SESSION['user_id']);
        $success = "Procedure \"$name\" saved.";
    }
}

// ---- Save all procedures in one submit (name / rate / consent / active) ----
// Whole catalogue posts as id-keyed arrays; every row re-saved. The consent and
// Active checkboxes are folded into the same save.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_procedures') {
    $names   = $_POST['name'] ?? [];
    $fees    = $_POST['fee'] ?? [];
    $consent = $_POST['mandatory_consent'] ?? [];
    $disp    = $_POST['has_disposables'] ?? [];
    $active  = $_POST['is_active'] ?? [];
    $dental   = $_POST['is_dental'] ?? [];
    $cats     = $_POST['category'] ?? [];
    $labFlags = $_POST['has_lab_component'] ?? [];
    $labFees  = $_POST['default_lab_charge'] ?? [];

    // Both disposables and dental arrived after this page did. Rather than one
    // prepared statement per combination of optional migrations, build the SET
    // clause from the columns that actually exist — an un-migrated database
    // simply saves fewer columns instead of fataling.
    $setCols = ['name = ?', 'fee = ?', 'mandatory_consent = ?'];
    if ($procHasDisposables) { $setCols[] = 'has_disposables = ?'; }
    if ($procHasDental) {
        $setCols[] = 'is_dental = ?';
        $setCols[] = 'category = ?';
        $setCols[] = 'has_lab_component = ?';
        $setCols[] = 'default_lab_charge = ?';
    }
    $setCols[] = 'is_active = ?';
    $upd = $pdo->prepare('UPDATE procedure_master SET ' . implode(', ', $setCols) . ' WHERE id = ?');

    $saved = 0; $bad = false; $dupe = false;
    foreach ($names as $id => $rawName) {
        $id = (int) $id;
        $name = trim($rawName);
        $fee = (float) ($fees[$id] ?? 0);
        if ($id <= 0) { continue; }
        if ($name === '' || $fee < 0) { $bad = true; continue; }
        try {
            // Params are appended in exactly the order $setCols was built.
            $params = [$name, $fee, isset($consent[$id]) ? 1 : 0];
            if ($procHasDisposables) { $params[] = isset($disp[$id]) ? 1 : 0; }
            if ($procHasDental) {
                [$isD, $cat, $hasLab, $labFee] = dental_fields_from_post(
                    isset($dental[$id]), $cats[$id] ?? '',
                    isset($labFlags[$id]), $labFees[$id] ?? 0
                );
                array_push($params, $isD, $cat, $hasLab, $labFee);
            }
            $params[] = isset($active[$id]) ? 1 : 0;
            $params[] = $id;
            $upd->execute($params);
            $saved++;
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') { $dupe = true; } else { throw $e; }
        }
    }
    if ($saved > 0) {
        audit_log($pdo, 'procedures_saved', "Bulk-saved $saved procedure(s)", $_SESSION['user_id']);
        $success = "Saved $saved procedure(s)." . ($bad ? ' (Rows missing a name/valid rate were skipped.)' : '') . ($dupe ? ' (Duplicate names were skipped.)' : '');
    } else {
        $error = $dupe ? 'A procedure with that name already exists.' : 'A procedure needs a name and a non-negative rate.';
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

            audit_log($pdo, 'doctor_procedures_updated', "Updated procedure assignments for doctor #$editId ({$doctor['name']}), " . count($keepIds) . ' assignment(s) on file', $_SESSION['user_id']);
            $success = "Procedure assignments updated for {$doctor['name']}.";
        }
    }
}

// ---- Page data ----
$procedures = $pdo->query('SELECT * FROM procedure_master ORDER BY name')->fetchAll();

// Has add_procedure_consent.sql been run? Same degradation rule as the two
// migrations above: without the column the catalogue is exactly the pre-consent
// page, and the link to the wording editor is simply absent.
require_once __DIR__ . '/config/consent.php';
$procHasConsentTpl = consent_column_live($pdo);

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
.tpl-link { display: inline-block; margin-top: 6px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; padding: 2px 8px; border-radius: 999px; text-decoration: none; background: var(--surface-2, #eee); color: var(--text-muted); white-space: nowrap; }
.tpl-link.has { background: var(--green-bg); color: var(--green-text); }

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
/* Active toggle — checkbox styled as a small pill switch */
.active-toggle { display: inline-flex; align-items: center; cursor: pointer; }
.active-toggle input { position: absolute; opacity: 0; width: 0; height: 0; }
.active-toggle span { width: 40px; height: 22px; border-radius: 20px; background: var(--border); position: relative; transition: background .15s; display: inline-block; }
.active-toggle span::after { content: ''; position: absolute; top: 2px; left: 2px; width: 18px; height: 18px; border-radius: 50%; background: #fff; transition: transform .15s; box-shadow: 0 1px 2px rgba(0,0,0,.2); }
.active-toggle input:checked + span { background: var(--primary); }
.active-toggle input:checked + span::after { transform: translateX(18px); }
.active-toggle input:focus-visible + span { box-shadow: 0 0 0 3px rgba(26,127,126,.25); }
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
                        <label class="consent-check" title="If ticked, this procedure cannot be billed without a consent. Give it wording on the consent-template page and the form prints with the receipt; leave it without wording and the consent must be captured on the dental consent page instead.">
                            <input type="checkbox" name="mandatory_consent" value="1">
                            Requires consent form
                        </label>
                        <?php if ($procHasDisposables): ?>
                        <label class="consent-check" title="If ticked, reception is asked for the supplies cost when billing this procedure. The cost is deducted before tax and before the doctor/clinic split.">
                            <input type="checkbox" name="has_disposables" value="1">
                            Has disposables
                        </label>
                        <?php endif; ?>
                        <?php if ($procHasDental): ?>
                        <label class="consent-check" title="Dental procedures appear in the dental treatment record and package accounts. The ordinary procedure biller still sees every procedure.">
                            <input type="checkbox" name="is_dental" value="1" id="addIsDental">
                            Dental
                        </label>
                        <?php endif; ?>
                        <button type="submit" class="btn">Add</button>
                    </div>
                    <?php if ($procHasDental): ?>
                    <!-- Dental-only fields. Hidden until Dental is ticked: on a
                         mixed catalogue most procedures are not dental, and three
                         permanently-greyed inputs on every add is noise. -->
                    <div class="add-row" id="addDentalRow" style="display:none; margin-top:10px;">
                        <div>
                            <label>Category</label>
                            <select name="category">
                                <option value="">— none —</option>
                                <?php foreach (DENTAL_CAT_LABELS as $cv => $cl): ?>
                                <option value="<?= $cv ?>"><?= htmlspecialchars($cl) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <label class="consent-check" title="Crowns, dentures and bridges go out to an outside lab. Ticking this offers the lab tracker (vendor, sent/received/fitted) for this procedure.">
                            <input type="checkbox" name="has_lab_component" value="1">
                            Sends work to a lab
                        </label>
                        <div>
                            <label>Usual lab charge (Rs)</label>
                            <input type="number" step="0.01" min="0" name="default_lab_charge" value="0">
                            <div class="hint">A prefill only — the actual charge is entered per case.</div>
                        </div>
                    </div>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Procedure catalogue — one form, one Save all changes button. -->
            <div class="card">
                <div class="section-title">Procedure Catalogue</div>
                <div class="section-sub">Set the rate for each procedure, then <b>Save all changes</b> once. Uncheck Active for anything not offered.</div>
                <form method="POST" action="procedure_master.php" id="saveAll">
                <input type="hidden" name="action" value="save_procedures">
                <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr><th>Procedure</th><th style="width:130px;">Rate (Rs)</th><th style="width:150px;">Consent form</th><?php if ($procHasDisposables): ?><th style="width:150px;">Disposables</th><?php endif; ?><?php if ($procHasDental): ?><th style="width:230px;">Dental</th><th style="width:170px;">Lab</th><?php endif; ?><th style="width:90px;">Active</th></tr>
                    </thead>
                    <tbody>
                        <?php if (!$procedures): ?>
                        <tr><td colspan="<?= 4 + ($procHasDisposables ? 1 : 0) + ($procHasDental ? 2 : 0) ?>" class="muted" style="padding:20px 10px;">No procedures yet — add one above.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($procedures as $p): $pid = (int) $p['id']; ?>
                        <tr class="<?= (int) $p['is_active'] === 1 ? '' : 'row-inactive' ?>">
                            <td>
                                <input type="text" name="name[<?= $pid ?>]" class="row-inp" style="font-weight:600;width:100%;" value="<?= htmlspecialchars($p['name']) ?>">
                            </td>
                            <td>
                                <input type="number" step="0.01" min="0" name="fee[<?= $pid ?>]" class="row-inp" style="width:100px;" value="<?= htmlspecialchars((string) $p['fee']) ?>">
                            </td>
                            <td>
                                <label class="consent-check" style="padding:0;">
                                    <input type="checkbox" name="mandatory_consent[<?= $pid ?>]" value="1" <?= (int) $p['mandatory_consent'] === 1 ? 'checked' : '' ?>>
                                    Mandatory
                                </label>
                                <?php if ($procHasConsentTpl): ?>
                                <?php $hasTpl = trim((string) ($p['consent_template'] ?? '')) !== ''; ?>
                                <!-- The wording lives on its own page: a paragraph does not fit in a
                                     table row. "Form" means this procedure prints a consent with its
                                     receipt; "Mandatory" alone only gates billing. -->
                                <a href="procedure_consent_template.php?id=<?= $pid ?>"
                                   class="tpl-link <?= $hasTpl ? 'has' : '' ?>"
                                   title="<?= $hasTpl ? 'Edit the consent wording printed for this procedure' : 'Write the consent wording so this procedure prints a consent form' ?>">
                                    <?= $hasTpl ? 'Form &check;' : 'Add form' ?>
                                </a>
                                <?php endif; ?>
                            </td>
                            <?php if ($procHasDisposables): ?>
                            <td>
                                <!-- Flag only, no default amount: supply use varies case to case, so
                                     reception types the actual cost when billing a flagged procedure.
                                     Unflagged procedures show no cost box at all. -->
                                <label class="consent-check" style="padding:0;" title="Reception will be asked for the supplies cost when billing this procedure">
                                    <input type="checkbox" name="has_disposables[<?= $pid ?>]" value="1" <?= (int) ($p['has_disposables'] ?? 0) === 1 ? 'checked' : '' ?>>
                                    Has disposables
                                </label>
                            </td>
                            <?php endif; ?>
                            <?php if ($procHasDental): ?>
                            <td>
                                <!-- The category select IS the dental flag in the UI: picking one
                                     ticks Dental server-side (dental_fields_from_post), and
                                     unticking Dental clears the category and both lab fields. That
                                     normalisation lives in one function so the two save handlers
                                     cannot drift apart. -->
                                <label class="consent-check" style="padding:0;">
                                    <input type="checkbox" name="is_dental[<?= $pid ?>]" value="1" class="dental-flag" data-pid="<?= $pid ?>" <?= (int) ($p['is_dental'] ?? 0) === 1 ? 'checked' : '' ?>>
                                    Dental
                                </label>
                                <select name="category[<?= $pid ?>]" class="row-inp dental-cat" data-pid="<?= $pid ?>" style="width:100%;margin-top:6px;" <?= (int) ($p['is_dental'] ?? 0) === 1 ? '' : 'disabled' ?>>
                                    <option value="">— category —</option>
                                    <?php foreach (DENTAL_CAT_LABELS as $cv => $cl): ?>
                                    <option value="<?= $cv ?>" <?= ($p['category'] ?? '') === $cv ? 'selected' : '' ?>><?= htmlspecialchars($cl) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <label class="consent-check" style="padding:0;" title="Crowns, dentures and bridges go out to an outside lab. Ticking this offers the lab tracker for this procedure.">
                                    <input type="checkbox" name="has_lab_component[<?= $pid ?>]" value="1" class="dental-lab" data-pid="<?= $pid ?>" <?= (int) ($p['has_lab_component'] ?? 0) === 1 ? 'checked' : '' ?> <?= (int) ($p['is_dental'] ?? 0) === 1 ? '' : 'disabled' ?>>
                                    Lab work
                                </label>
                                <input type="number" step="0.01" min="0" name="default_lab_charge[<?= $pid ?>]" class="row-inp dental-labfee" data-pid="<?= $pid ?>" style="width:100px;margin-top:6px;" value="<?= htmlspecialchars((string) ($p['default_lab_charge'] ?? 0)) ?>" <?= (int) ($p['has_lab_component'] ?? 0) === 1 ? '' : 'disabled' ?> title="Usual vendor charge — a prefill, not a fixed price">
                            </td>
                            <?php endif; ?>
                            <td><label class="active-toggle"><input type="checkbox" name="is_active[<?= $pid ?>]" value="1" <?= (int) $p['is_active'] === 1 ? 'checked' : '' ?>><span></span></label></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php if ($procedures): ?>
                <div style="display:flex;justify-content:flex-end;margin-top:16px;">
                    <button type="submit" class="btn">Save all changes</button>
                </div>
                <?php endif; ?>
                </form>
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

<?php if ($procHasDental): ?>
// Dental dependent fields. A disabled input does not submit, which is exactly
// what we want: an un-ticked row posts no category and no lab charge, and
// dental_fields_from_post() then normalises the row to a clean non-dental
// state. The server repeats this rule — the JS is convenience, not the guard.
document.querySelectorAll('.dental-flag').forEach(cb => {
    cb.addEventListener('change', () => {
        const pid = cb.dataset.pid;
        const cat = document.querySelector('.dental-cat[data-pid="' + pid + '"]');
        const lab = document.querySelector('.dental-lab[data-pid="' + pid + '"]');
        const fee = document.querySelector('.dental-labfee[data-pid="' + pid + '"]');
        if (cat) { cat.disabled = !cb.checked; if (!cb.checked) { cat.value = ''; } }
        if (lab) { lab.disabled = !cb.checked; if (!cb.checked) { lab.checked = false; } }
        if (fee) { fee.disabled = !cb.checked || !(lab && lab.checked); }
    });
});
// Picking a category implies dental — mirror the server rule so the row's own
// controls don't contradict what is about to be saved.
document.querySelectorAll('.dental-cat').forEach(sel => {
    sel.addEventListener('change', () => {
        if (!sel.value) { return; }
        const cb = document.querySelector('.dental-flag[data-pid="' + sel.dataset.pid + '"]');
        if (cb && !cb.checked) { cb.checked = true; cb.dispatchEvent(new Event('change')); }
    });
});
document.querySelectorAll('.dental-lab').forEach(cb => {
    cb.addEventListener('change', () => {
        const fee = document.querySelector('.dental-labfee[data-pid="' + cb.dataset.pid + '"]');
        if (fee) { fee.disabled = !cb.checked; }
    });
});
// The add-form's dental fields, same rule, one row.
const addIsDental = document.getElementById('addIsDental');
const addDentalRow = document.getElementById('addDentalRow');
if (addIsDental && addDentalRow) {
    addIsDental.addEventListener('change', () => {
        addDentalRow.style.display = addIsDental.checked ? '' : 'none';
    });
}
<?php endif; ?>

<?php if ($postedDoctorId > 0): ?>
// Re-open the doctor whose assignments were just submitted.
assignDoctor.value = '<?= $postedDoctorId ?>';
openDoctorAssignments('<?= $postedDoctorId ?>');
<?php endif; ?>
</script>
<script src="assets/js/date-picker.js?v=<?= @filemtime(__DIR__ . "/assets/js/date-picker.js") ?: 1 ?>"></script>
</body>
</html>
