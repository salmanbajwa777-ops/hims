<?php
/**
 * Dental lab work — where every crown, denture and bridge currently is.
 *
 * WHY THIS EXISTS AT ALL. A crown disappearing into a drawer for three weeks
 * with nobody accountable is the one operational failure dental has that the
 * rest of the system cannot see: the money side looks fine (the item is
 * quoted, maybe even paid), the clinical record looks fine (the tooth was
 * prepped), and yet nothing is happening. Three status columns and a vendor
 * name make it visible, and the Overdue tab makes it unmissable.
 *
 * SENT -> RECEIVED -> FITTED, one direction only. Each step stamps its own date
 * so "how long is this vendor actually taking" is answerable later.
 *
 * TWO DIFFERENT lab_charge COLUMNS, deliberately:
 *   dental_lab_work.lab_charge                  what the CLINIC PAYS THE VENDOR
 *   dental_procedure_account_items.lab_charge   what the PATIENT PAYS
 * Usually equal, but not the same fact — a clinic that marks lab work up needs
 * both, and only the second one is ever billed.
 *
 * Requires sql/add_dental_module.sql.
 */

require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
require_once __DIR__ . '/config/billing.php';
require_once __DIR__ . '/config/dental.php';
refresh_session_permissions($pdo);
require_permission('DENTAL_MANAGE_LAB_WORK');

$userId = (int) $_SESSION['user_id'];
$error = '';
$success = '';

// ---- Log new lab work ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'log_lab') {
    $patientId = (int) ($_POST['patient_id'] ?? 0);
    $accountId = (int) ($_POST['account_id'] ?? 0) ?: null;
    $doctorId  = (int) ($_POST['doctor_id'] ?? 0) ?: null;
    $vendor    = trim($_POST['vendor_name'] ?? '');
    $desc      = trim($_POST['work_description'] ?? '');
    $tooth     = trim($_POST['tooth_fdi'] ?? '');
    $shade     = trim($_POST['shade'] ?? '');
    $charge    = max(0.0, (float) ($_POST['lab_charge'] ?? 0));
    $sent      = trim($_POST['sent_date'] ?? '') ?: date('Y-m-d');
    $expected  = trim($_POST['expected_date'] ?? '');
    $notes     = trim($_POST['notes'] ?? '');

    if ($tooth !== '' && !is_valid_fdi($tooth)) {
        $error = 'That is not a valid FDI tooth number.';
    } elseif ($patientId <= 0 || $vendor === '' || $desc === '') {
        $error = 'A lab entry needs a patient, a vendor and a description of the work.';
    } elseif ($expected !== '' && $expected < $sent) {
        $error = 'The expected date cannot be before the date the work was sent.';
    } else {
        try {
            $pdo->prepare('
                INSERT INTO dental_lab_work
                    (patient_id, account_id, doctor_id, vendor_name, work_description, tooth_fdi,
                     shade, lab_charge, status, sent_date, expected_date, notes, created_by_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'SENT\', ?, ?, ?, ?)
            ')->execute([$patientId, $accountId, $doctorId, $vendor, $desc,
                         $tooth !== '' ? $tooth : null, $shade !== '' ? $shade : null,
                         $charge, $sent, $expected !== '' ? $expected : null,
                         $notes !== '' ? $notes : null, $userId]);
            audit_log($pdo, 'dental_lab_logged', "Sent \"$desc\" to $vendor for patient #$patientId" . ($tooth !== '' ? " (tooth $tooth)" : ''), $userId);
            $success = 'Lab work logged as SENT.';
        } catch (PDOException $e) {
            error_log('[dental_lab log] ' . $e->getMessage());
            $error = 'Could not log the lab work. Please try again.';
        }
    }
}

// ---- Advance the status ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'advance_status') {
    $labId = (int) ($_POST['lab_id'] ?? 0);
    $to    = $_POST['to_status'] ?? '';

    $s = $pdo->prepare('SELECT id, status, work_description, vendor_name FROM dental_lab_work
                         WHERE id = ? AND voided_at IS NULL');
    $s->execute([$labId]);
    $lab = $s->fetch();

    // One direction only. Letting a FITTED crown go back to SENT would make the
    // dates meaningless — a correction is a void and a re-log, not a rewind.
    $allowed = ['SENT' => 'RECEIVED', 'RECEIVED' => 'FITTED'];
    if (!$lab) {
        $error = 'Lab entry not found.';
    } elseif (($allowed[$lab['status']] ?? null) !== $to) {
        $error = 'That is not the next step for this entry.';
    } else {
        $dateCol = $to === 'RECEIVED' ? 'received_date' : 'fitted_date';
        $pdo->prepare("UPDATE dental_lab_work
                          SET status = ?, $dateCol = CURDATE(), updated_by_id = ?, updated_at = NOW()
                        WHERE id = ? AND voided_at IS NULL")
            ->execute([$to, $userId, $labId]);
        audit_log($pdo, 'dental_lab_status_changed', "\"{$lab['work_description']}\" ({$lab['vendor_name']}) → $to", $userId);
        $success = 'Marked ' . strtolower($to) . '.';
    }
}

// ---- Filters ----
$tab = $_GET['tab'] ?? 'OPEN';
if (!in_array($tab, ['OPEN', 'SENT', 'RECEIVED', 'FITTED', 'OVERDUE', 'ALL'], true)) {
    $tab = 'OPEN';
}
$q = trim($_GET['q'] ?? '');

$sql = "
    SELECT l.*, p.mrn, p.name AS patient_name, d.name AS doctor_name,
           a.account_number, cb.name AS created_by_name
      FROM dental_lab_work l
      JOIN patients p ON p.id = l.patient_id
      LEFT JOIN users d  ON d.id = l.doctor_id
      LEFT JOIN users cb ON cb.id = l.created_by_id
      LEFT JOIN dental_procedure_accounts a ON a.id = l.account_id
     WHERE l.voided_at IS NULL
";
$args = [];
if ($tab === 'OVERDUE') {
    // Promised by a date that has passed, and still not back.
    $sql .= " AND l.status = 'SENT' AND l.expected_date IS NOT NULL AND l.expected_date < CURDATE()";
} elseif ($tab === 'OPEN') {
    // Anything still in flight — the default worklist.
    $sql .= " AND l.status <> 'FITTED'";
} elseif ($tab !== 'ALL') {
    $sql .= ' AND l.status = ?';
    $args[] = $tab;
}
if ($q !== '') {
    $sql .= ' AND (p.name LIKE ? OR p.mrn LIKE ? OR l.vendor_name LIKE ? OR l.work_description LIKE ?)';
    $like = '%' . $q . '%';
    array_push($args, $like, $like, $like, $like);
}
$sql .= ' ORDER BY (l.status = \'FITTED\'), l.expected_date IS NULL, l.expected_date, l.id DESC LIMIT 200';

$stmt = $pdo->prepare($sql);
$stmt->execute($args);
$labRows = $stmt->fetchAll();

$counts = ['OPEN' => 0, 'SENT' => 0, 'RECEIVED' => 0, 'FITTED' => 0, 'OVERDUE' => 0, 'ALL' => 0];
foreach ($pdo->query("
    SELECT status, COUNT(*) AS n,
           SUM(CASE WHEN status = 'SENT' AND expected_date IS NOT NULL AND expected_date < CURDATE()
                    THEN 1 ELSE 0 END) AS overdue
      FROM dental_lab_work WHERE voided_at IS NULL GROUP BY status
")->fetchAll() as $r) {
    $counts[$r['status']] = (int) $r['n'];
    $counts['ALL'] += (int) $r['n'];
    $counts['OVERDUE'] += (int) $r['overdue'];
    if ($r['status'] !== 'FITTED') { $counts['OPEN'] += (int) $r['n']; }
}

// ---- New-entry form data ----
$forAccount = (int) ($_GET['account_id'] ?? 0);
$prefill = null;
if ($forAccount > 0) {
    $s = $pdo->prepare('SELECT a.id, a.account_number, a.title, a.patient_id, a.doctor_id,
                               p.mrn, p.name AS patient_name
                          FROM dental_procedure_accounts a
                          JOIN patients p ON p.id = a.patient_id
                         WHERE a.id = ?');
    $s->execute([$forAccount]);
    $prefill = $s->fetch() ?: null;
}
$dentists = $pdo->query("SELECT id, name FROM users
                          WHERE base_role = 'DOCTOR' AND is_active = 1 AND specialty = 'DENTAL'
                          ORDER BY name")->fetchAll();
// Vendors already used, offered as a datalist so the name stays consistent.
$vendors = $pdo->query("SELECT DISTINCT vendor_name FROM dental_lab_work
                         WHERE voided_at IS NULL ORDER BY vendor_name LIMIT 50")->fetchAll();

$pageTitle = 'Dental Lab Work';
$headExtra = <<<CSS
<style>
.tabs { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:14px; }
.tabs a { padding:7px 13px; border-radius:var(--radius-pill); background:var(--card-alt);
  color:var(--text-secondary); font-size:var(--fs-btn); font-weight:600; text-decoration:none;
  border:1px solid var(--border); }
.tabs a.on { background:var(--primary); color:var(--on-primary); border-color:var(--primary); }
.tabs a.warn.on { background:var(--warn); border-color:var(--warn); color:#fff; }
.tabs a .n { opacity:.72; margin-left:5px; }
.tooth-pill { display:inline-block; min-width:32px; text-align:center; padding:2px 6px;
  border-radius:var(--radius-pill); background:var(--primary-light); color:var(--primary-dark);
  font-weight:700; font-size:var(--fs-pill); }
.late { color:var(--warn); font-weight:700; }
.note-line { font-size:var(--fs-meta); color:var(--text-muted); margin-top:2px; }
.lab-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; }
.lab-grid .full { grid-column:1 / -1; }
.track { display:flex; align-items:center; gap:5px; font-size:var(--fs-micro); font-weight:700;
  color:var(--text-muted); }
.track .step.on { color:var(--ok); }
@media (max-width:900px) { .lab-grid { grid-template-columns:1fr; } }
</style>
CSS;
require __DIR__ . '/partials/head.php';
$navActive = 'dental_lab';
require __DIR__ . '/partials/sidebar.php';
?>
        <?php require __DIR__ . '/partials/quick_header.php'; ?>

        <div class="content">
            <div class="page-head">
                <div>
                    <div class="page-title">Dental Lab Work</div>
                    <div class="page-sub">Crowns, dentures and bridges out at the lab — and which ones are late.</div>
                </div>
            </div>

            <?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($success): ?><div class="alert success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

            <!-- ---- Log new work ---- -->
            <div class="card">
                <div class="section-title">Send work to a lab</div>
                <div class="section-sub">
                    <?php if ($prefill): ?>
                    For <b><?= htmlspecialchars($prefill['patient_name']) ?></b> (<?= htmlspecialchars($prefill['mrn']) ?>),
                    package <?= htmlspecialchars($prefill['account_number']) ?>.
                    <?php else: ?>
                    Open this from a package account to attach the work to it, or log it standalone below.
                    <?php endif; ?>
                </div>

                <?php if (!$prefill): ?>
                <div class="empty" style="margin-top:14px;">
                    Pick the package first — <a href="dental_accounts.php">Dental Accounts</a> → open one → <b>Log lab work</b>.
                </div>
                <?php else: ?>
                <form method="POST" action="dental_lab.php">
                    <input type="hidden" name="action" value="log_lab">
                    <input type="hidden" name="patient_id" value="<?= (int) $prefill['patient_id'] ?>">
                    <input type="hidden" name="account_id" value="<?= (int) $prefill['id'] ?>">
                    <div class="lab-grid" style="margin-top:12px;">
                        <div class="field">
                            <label>Lab / vendor</label>
                            <input type="text" name="vendor_name" list="vendorList" required placeholder="e.g. Precision Dental Lab">
                            <datalist id="vendorList">
                                <?php foreach ($vendors as $v): ?>
                                <option value="<?= htmlspecialchars($v['vendor_name']) ?>"></option>
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="field">
                            <label>Dentist</label>
                            <select name="doctor_id">
                                <option value="">— none —</option>
                                <?php foreach ($dentists as $d): ?>
                                <option value="<?= (int) $d['id'] ?>" <?= (int) $prefill['doctor_id'] === (int) $d['id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>Tooth (FDI)</label>
                            <?= fdi_select_html('tooth_fdi') ?>
                        </div>
                        <div class="field full">
                            <label>Work sent</label>
                            <input type="text" name="work_description" required placeholder="e.g. PFM crown, upper right first molar">
                        </div>
                        <div class="field">
                            <label>Shade</label>
                            <input type="text" name="shade" placeholder="e.g. A2">
                        </div>
                        <div class="field">
                            <label>Lab charge (Rs)</label>
                            <input type="number" step="0.01" min="0" name="lab_charge" value="0">
                            <div class="hint">What the clinic pays the vendor.</div>
                        </div>
                        <div class="field">
                            <label>Sent on</label>
                            <input type="date" name="sent_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="field">
                            <label>Expected back</label>
                            <input type="date" name="expected_date">
                            <div class="hint">Drives the Overdue list — worth filling in.</div>
                        </div>
                        <div class="field full">
                            <label>Notes</label>
                            <input type="text" name="notes" placeholder="Anything the lab was told">
                        </div>
                    </div>
                    <div style="display:flex;justify-content:flex-end;margin-top:14px;">
                        <button type="submit" class="btn">Log as sent</button>
                    </div>
                </form>
                <?php endif; ?>
            </div>

            <!-- ---- The worklist ---- -->
            <div class="card">
                <div class="tabs">
                    <?php
                    $tabLabels = ['OPEN' => 'In flight', 'SENT' => 'At the lab', 'RECEIVED' => 'Back, not fitted',
                                  'FITTED' => 'Fitted', 'OVERDUE' => 'Overdue', 'ALL' => 'All'];
                    foreach ($tabLabels as $tv => $tl):
                        $href = 'dental_lab.php?tab=' . $tv . ($q !== '' ? '&q=' . urlencode($q) : '')
                              . ($forAccount ? '&account_id=' . $forAccount : '');
                    ?>
                    <a class="<?= $tv === 'OVERDUE' ? 'warn ' : '' ?><?= $tab === $tv ? 'on' : '' ?>" href="<?= $href ?>"><?= $tl ?><span class="n"><?= (int) $counts[$tv] ?></span></a>
                    <?php endforeach; ?>
                </div>

                <form method="GET" action="dental_lab.php" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;">
                    <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
                    <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Patient, MR #, vendor or work…" style="flex:1;min-width:220px;">
                    <button type="submit" class="btn secondary">Search</button>
                    <?php if ($q !== ''): ?><a class="btn secondary" href="dental_lab.php?tab=<?= htmlspecialchars($tab) ?>">Clear</a><?php endif; ?>
                </form>

                <?php if (!$labRows): ?>
                <div class="empty">Nothing here.</div>
                <?php else: ?>
                <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Work</th>
                            <th style="width:170px;">Patient</th>
                            <th style="width:150px;">Vendor</th>
                            <th style="width:150px;">Dates</th>
                            <th style="width:110px;" class="text-right">Charge</th>
                            <th style="width:180px;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($labRows as $l):
                            $isLate = $l['status'] === 'SENT' && $l['expected_date']
                                   && $l['expected_date'] < date('Y-m-d');
                            $next = ['SENT' => 'RECEIVED', 'RECEIVED' => 'FITTED'][$l['status']] ?? null;
                        ?>
                        <tr>
                            <td>
                                <div style="font-weight:600;">
                                    <?= htmlspecialchars($l['work_description']) ?>
                                    <?php if ($l['tooth_fdi']): ?> <span class="tooth-pill" title="<?= htmlspecialchars(fdi_label($l['tooth_fdi'])) ?>"><?= htmlspecialchars($l['tooth_fdi']) ?></span><?php endif; ?>
                                </div>
                                <div class="note-line">
                                    <?= $l['shade'] ? 'Shade ' . htmlspecialchars($l['shade']) : '' ?>
                                    <?= $l['account_number'] ? ' · ' . htmlspecialchars($l['account_number']) : '' ?>
                                    <?= $l['doctor_name'] ? ' · ' . htmlspecialchars($l['doctor_name']) : '' ?>
                                </div>
                                <?php if ($l['notes']): ?><div class="note-line"><?= htmlspecialchars($l['notes']) ?></div><?php endif; ?>
                            </td>
                            <td>
                                <?= htmlspecialchars($l['patient_name']) ?>
                                <div class="note-line"><?= htmlspecialchars($l['mrn']) ?></div>
                            </td>
                            <td><?= htmlspecialchars($l['vendor_name']) ?></td>
                            <td>
                                <div class="note-line">Sent <?= date('d/m/Y', strtotime($l['sent_date'])) ?></div>
                                <?php if ($l['expected_date']): ?>
                                <div class="note-line <?= $isLate ? 'late' : '' ?>">
                                    Due <?= date('d/m/Y', strtotime($l['expected_date'])) ?>
                                    <?= $isLate ? ' — ' . (int) ((strtotime('today') - strtotime($l['expected_date'])) / 86400) . 'd late' : '' ?>
                                </div>
                                <?php endif; ?>
                                <?php if ($l['received_date']): ?><div class="note-line">Back <?= date('d/m/Y', strtotime($l['received_date'])) ?></div><?php endif; ?>
                                <?php if ($l['fitted_date']): ?><div class="note-line">Fitted <?= date('d/m/Y', strtotime($l['fitted_date'])) ?></div><?php endif; ?>
                            </td>
                            <td class="text-right">Rs <?= number_format((float) $l['lab_charge'], 2) ?></td>
                            <td>
                                <div class="track" style="margin-bottom:6px;">
                                    <span class="step on">SENT</span> →
                                    <span class="step <?= in_array($l['status'], ['RECEIVED', 'FITTED'], true) ? 'on' : '' ?>">RECEIVED</span> →
                                    <span class="step <?= $l['status'] === 'FITTED' ? 'on' : '' ?>">FITTED</span>
                                </div>
                                <?php if ($next): ?>
                                <form method="POST" action="dental_lab.php">
                                    <input type="hidden" name="action" value="advance_status">
                                    <input type="hidden" name="lab_id" value="<?= (int) $l['id'] ?>">
                                    <input type="hidden" name="to_status" value="<?= $next ?>">
                                    <button type="submit" class="btn small">Mark <?= strtolower($next) ?></button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<script src="assets/js/date-picker.js?v=<?= @filemtime(__DIR__ . "/assets/js/date-picker.js") ?: 1 ?>"></script>
</body>
</html>
