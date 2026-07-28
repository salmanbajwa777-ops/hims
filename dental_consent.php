<?php
/**
 * Dental consent — capture, print, sign, scan back in.
 *
 * TWO JOBS, one record:
 *
 *  1. CLINICAL. A procedure flagged mandatory_consent cannot be billed until a
 *     signed consent exists. procedure_master.mandatory_consent has been in the
 *     catalogue since it was built, but nothing enforced it — the biller only
 *     warned, because "the consent form is a later phase". This is that phase.
 *
 *  2. FINANCIAL. For a package account the consent doubles as the contract: it
 *     freezes the itemised quote, the total, what was paid up front and what
 *     remains, at the moment of signing. Items added or voided afterwards
 *     change the live account but NOT this snapshot — which is the point. The
 *     signed figure is what the patient agreed to.
 *
 * METHOD: print → sign on paper → upload the scan. No signature pad in v1.
 * Uploading the scan is what marks a consent SIGNED.
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
require_permission('DENTAL_CAPTURE_CONSENT');

$userId = (int) $_SESSION['user_id'];
$error = '';
$success = '';

// Make sure the directory and its deny-all guard exist before any upload.
dental_consent_dir();

// ---- Print a consent form ----
if (isset($_GET['print'])) {
    $cid = (int) $_GET['print'];
    $s = $pdo->prepare('
        SELECT c.*, pt.mrn, pt.name AS patient_name, pt.father_name, pt.dob, pt.phone,
               d.name AS doctor_name, d.specialty AS doctor_specialty,
               a.account_number, a.title AS account_title
          FROM dental_consents c
          JOIN patients pt ON pt.id = c.patient_id
          LEFT JOIN users d ON d.id = c.doctor_id
          LEFT JOIN dental_procedure_accounts a ON a.id = c.account_id
         WHERE c.id = ?
    ');
    $s->execute([$cid]);
    $consent = $s->fetch();
    if (!$consent) { http_response_code(404); die('Consent not found.'); }

    // The snapshot is rendered back exactly as it was frozen at generation —
    // never recomputed from the live account, or the printed contract would
    // silently change every time an item moved.
    $snapshot = $consent['financial_snapshot'] ? json_decode($consent['financial_snapshot'], true) : null;
    require __DIR__ . '/views/dental_consent_print_partial.php';
    exit;
}

// ---- Generate a consent ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_consent') {
    $patientId = (int) ($_POST['patient_id'] ?? 0);
    $accountId = (int) ($_POST['account_id'] ?? 0) ?: null;
    $procId    = (int) ($_POST['procedure_master_id'] ?? 0) ?: null;
    $doctorId  = (int) ($_POST['doctor_id'] ?? 0) ?: null;
    $text      = trim($_POST['consent_text'] ?? '');

    if ($patientId <= 0) {
        $error = 'Pick a patient.';
    } elseif (!$accountId && !$procId) {
        $error = 'A consent must cover either a package account or a specific procedure.';
    } else {
        $snapshot = null;
        $procRef  = null;

        if ($accountId) {
            // FREEZE the account as it stands right now. Read straight from the
            // live rows here — this is the one moment the snapshot is allowed to
            // track the account, and from here on it never changes again.
            $s = $pdo->prepare('SELECT * FROM dental_procedure_accounts WHERE id = ?');
            $s->execute([$accountId]);
            $acc = $s->fetch();
            if (!$acc) {
                $error = 'That package account no longer exists.';
            } else {
                $s = $pdo->prepare('SELECT description, tooth_fdi, quantity, amount, lab_charge
                                      FROM dental_procedure_account_items
                                     WHERE account_id = ? AND voided_at IS NULL ORDER BY id');
                $s->execute([$accountId]);
                $liveItems = $s->fetchAll();
                $t = dental_account_totals($pdo, $accountId);
                $snapshot = [
                    'account_number' => $acc['account_number'],
                    'title'          => $acc['title'],
                    'frozen_at'      => date('Y-m-d H:i:s'),
                    'items'          => $liveItems,
                    'charged'        => $t['charged'],
                    'lab'            => $t['lab'],
                    'total'          => $t['total'],
                    'paid_upfront'   => $t['paid'],
                    'remaining'      => $t['balance'],
                ];
                $procRef = $acc['title'];
                $doctorId = $doctorId ?: (int) $acc['doctor_id'];
            }
        }

        if (!$error && $procId) {
            $s = $pdo->prepare('SELECT name FROM procedure_master WHERE id = ?');
            $s->execute([$procId]);
            $pn = $s->fetchColumn();
            if (!$pn) {
                $error = 'That procedure no longer exists.';
            } else {
                $procRef = $procRef ? $procRef . ' — ' . $pn : $pn;
            }
        }

        if (!$error) {
            try {
                $pdo->prepare('
                    INSERT INTO dental_consents
                        (patient_id, doctor_id, account_id, procedure_master_id, procedure_ref,
                         consent_text, financial_snapshot, status, captured_by_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, \'PENDING\', ?)
                ')->execute([$patientId, $doctorId, $accountId, $procId, $procRef,
                             $text !== '' ? $text : null,
                             $snapshot ? json_encode($snapshot) : null, $userId]);
                $newId = (int) $pdo->lastInsertId();
                audit_log($pdo, 'dental_consent_generated', "Generated consent #$newId for patient #$patientId" . ($snapshot ? " (package {$snapshot['account_number']}, total Rs {$snapshot['total']})" : ''), $userId);
                header('Location: dental_consent.php?patient_id=' . $patientId . '&generated=' . $newId);
                exit;
            } catch (PDOException $e) {
                error_log('[dental_consent generate] ' . $e->getMessage());
                $error = 'Could not create the consent. Please try again.';
            }
        }
    }
}

// ---- Upload the signed scan ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_scan') {
    $cid        = (int) ($_POST['consent_id'] ?? 0);
    $signedName = mb_strtoupper(trim($_POST['signed_name'] ?? ''), 'UTF-8');
    $relation   = trim($_POST['signed_relation'] ?? '');

    [$pending, $upErr] = dental_consent_validate($_FILES['scan'] ?? null);

    $s = $pdo->prepare('SELECT id, patient_id FROM dental_consents WHERE id = ? AND voided_at IS NULL');
    $s->execute([$cid]);
    $row = $s->fetch();

    if (!$row) {
        $error = 'Consent not found.';
    } elseif ($upErr) {
        $error = $upErr;
    } elseif (!$pending) {
        $error = 'Choose the scanned, signed consent to upload.';
    } elseif ($signedName === '') {
        $error = 'Enter the name of the person who signed.';
    } else {
        // Who signed is recorded before the file moves, so a failed move leaves
        // the row unchanged rather than SIGNED-with-no-scan.
        $pdo->prepare('UPDATE dental_consents SET signed_name = ?, signed_relation = ? WHERE id = ?')
            ->execute([$signedName, $relation !== '' ? $relation : null, $cid]);

        if (dental_consent_store($pdo, $pending, $cid, $userId)) {
            audit_log($pdo, 'dental_consent_signed', "Signed consent #$cid uploaded — signed by $signedName", $userId);
            $success = 'Consent recorded as signed.';
        } else {
            $error = 'Could not save the scan. Please try again.';
        }
    }
}

// ---- Page data ----
$patientId = (int) ($_GET['patient_id'] ?? $_POST['patient_id'] ?? 0);
$accountId = (int) ($_GET['account_id'] ?? 0);

// Reaching this page from an account carries the patient with it.
if ($accountId > 0 && $patientId <= 0) {
    $s = $pdo->prepare('SELECT patient_id FROM dental_procedure_accounts WHERE id = ?');
    $s->execute([$accountId]);
    $patientId = (int) $s->fetchColumn();
}

$q = trim($_GET['q'] ?? '');
$patientResults = [];
if ($q !== '' && $patientId <= 0) {
    $like = '%' . $q . '%';
    $s = $pdo->prepare('SELECT id, mrn, name, father_name, phone FROM patients
                         WHERE name LIKE ? OR phone LIKE ? OR father_name LIKE ? OR mrn LIKE ?
                         ORDER BY name LIMIT 25');
    $s->execute([$like, $like, $like, $like]);
    $patientResults = $s->fetchAll();
}

$patient = null;
$consents = [];
$openAccounts = [];
$consentProcs = [];
if ($patientId > 0) {
    $s = $pdo->prepare('SELECT id, mrn, name, father_name, phone, dob FROM patients WHERE id = ?');
    $s->execute([$patientId]);
    $patient = $s->fetch() ?: null;

    $s = $pdo->prepare('
        SELECT c.*, u.name AS captured_by_name, a.account_number, a.title AS account_title,
               pm.name AS procedure_name
          FROM dental_consents c
          LEFT JOIN users u ON u.id = c.captured_by_id
          LEFT JOIN dental_procedure_accounts a ON a.id = c.account_id
          LEFT JOIN procedure_master pm ON pm.id = c.procedure_master_id
         WHERE c.patient_id = ? AND c.voided_at IS NULL
         ORDER BY c.id DESC
    ');
    $s->execute([$patientId]);
    $consents = $s->fetchAll();

    $s = $pdo->prepare("SELECT id, account_number, title, doctor_id FROM dental_procedure_accounts
                         WHERE patient_id = ? AND status = 'OPEN' ORDER BY id DESC");
    $s->execute([$patientId]);
    $openAccounts = $s->fetchAll();

    // Only consent-flagged procedures are offered: consenting to something that
    // does not require it would be noise in the record.
    $consentProcs = $pdo->query("
        SELECT id, name FROM procedure_master
        WHERE is_dental = 1 AND is_active = 1 AND mandatory_consent = 1
        ORDER BY name
    ")->fetchAll();
}

$generatedId = (int) ($_GET['generated'] ?? 0);
if ($generatedId > 0) {
    $success = 'Consent created. Print it, have it signed, then upload the scan below.';
}

$pageTitle = 'Dental Consent';
$headExtra = <<<CSS
<style>
.cn-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.cn-grid .full { grid-column:1 / -1; }
.res-row { display:flex; align-items:center; justify-content:space-between; gap:10px;
  padding:9px 0; border-bottom:1px solid var(--row-line); }
.res-row:last-child { border-bottom:0; }
.note-line { font-size:var(--fs-meta); color:var(--text-secondary); margin-top:2px; }
.up-box { border:1px dashed var(--border-strong); border-radius:var(--radius-btn);
  padding:12px 14px; background:var(--card-alt); margin-top:10px; }
@media (max-width:900px) { .cn-grid { grid-template-columns:1fr; } }
</style>
CSS;
require __DIR__ . '/partials/head.php';
$navActive = 'dental_accounts';
require __DIR__ . '/partials/sidebar.php';
?>
        <?php require __DIR__ . '/partials/quick_header.php'; ?>

        <div class="content">
            <div class="page-head">
                <div>
                    <div class="page-title">Dental Consent</div>
                    <div class="page-sub">Print, sign on paper, scan back in. For a package the consent is also the financial contract.</div>
                </div>
            </div>

            <?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($success): ?><div class="alert success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

            <?php if (!$patient): ?>
            <div class="card">
                <div class="section-title">Find a patient</div>
                <form method="GET" action="dental_consent.php" style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;">
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
                                <div class="note-line"><?= htmlspecialchars($p['mrn']) ?><?= $p['phone'] ? ' · ' . htmlspecialchars($p['phone']) : '' ?></div>
                            </div>
                            <a class="btn small" href="dental_consent.php?patient_id=<?= (int) $p['id'] ?>">Open</a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <?php else: ?>
            <div class="card">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                    <div>
                        <div style="font-weight:700;font-size:var(--fs-card);"><?= htmlspecialchars($patient['name']) ?></div>
                        <div class="note-line"><?= htmlspecialchars($patient['mrn']) ?><?= $patient['phone'] ? ' · ' . htmlspecialchars($patient['phone']) : '' ?></div>
                    </div>
                    <a class="btn secondary small" href="dental_consent.php">Change patient</a>
                </div>
            </div>

            <!-- ---- New consent ---- -->
            <div class="card">
                <div class="section-title">Create a consent</div>
                <div class="section-sub">Attach it to a package (freezes the quote as the contract) or to one flagged procedure.</div>
                <form method="POST" action="dental_consent.php">
                    <input type="hidden" name="action" value="generate_consent">
                    <input type="hidden" name="patient_id" value="<?= (int) $patient['id'] ?>">
                    <div class="cn-grid" style="margin-top:12px;">
                        <div class="field">
                            <label>Package account (optional)</label>
                            <select name="account_id">
                                <option value="">— none —</option>
                                <?php foreach ($openAccounts as $a): ?>
                                <option value="<?= (int) $a['id'] ?>" <?= $accountId === (int) $a['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($a['account_number']) ?> — <?= htmlspecialchars($a['title']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="hint">Freezes the itemised quote, total, paid and remaining at signing.</div>
                        </div>
                        <div class="field">
                            <label>Procedure (optional)</label>
                            <select name="procedure_master_id">
                                <option value="">— none —</option>
                                <?php foreach ($consentProcs as $p): ?>
                                <option value="<?= (int) $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="hint">Only procedures flagged as requiring consent are listed.</div>
                        </div>
                        <div class="field full">
                            <label>Additional wording (optional)</label>
                            <textarea name="consent_text" rows="3" placeholder="Risks explained, alternatives discussed, anything case-specific…"></textarea>
                        </div>
                    </div>
                    <div style="display:flex;justify-content:flex-end;margin-top:14px;">
                        <button type="submit" class="btn">Create consent</button>
                    </div>
                </form>
            </div>

            <!-- ---- Existing consents ---- -->
            <div class="card">
                <div class="section-title">Consents on file</div>
                <?php if (!$consents): ?>
                <div class="empty" style="margin-top:14px;">No consent recorded for this patient.</div>
                <?php else: ?>
                <div style="margin-top:12px;">
                    <?php foreach ($consents as $c): ?>
                    <div style="border-bottom:1px solid var(--row-line);padding:12px 0;">
                        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                            <div>
                                <div style="font-weight:600;">
                                    <?= htmlspecialchars($c['procedure_ref'] ?: ($c['procedure_name'] ?: 'Consent')) ?>
                                    <span class="status-pill <?= $c['status'] === 'SIGNED' ? 'paid' : 'pending' ?>" style="margin-left:6px;"><?= htmlspecialchars($c['status']) ?></span>
                                </div>
                                <div class="note-line">
                                    Created <?= date('d/m/Y', strtotime($c['created_at'])) ?>
                                    <?= $c['captured_by_name'] ? ' by ' . htmlspecialchars($c['captured_by_name']) : '' ?>
                                    <?= $c['account_number'] ? ' · package ' . htmlspecialchars($c['account_number']) : '' ?>
                                    <?php if ($c['signed_name']): ?> · signed by <?= htmlspecialchars($c['signed_name']) ?><?= $c['signed_relation'] ? ' (' . htmlspecialchars($c['signed_relation']) . ')' : '' ?><?php endif; ?>
                                </div>
                            </div>
                            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                <a class="btn small secondary" href="dental_consent.php?print=<?= (int) $c['id'] ?>" target="_blank">Print</a>
                                <?php if ($c['scan_path']): ?>
                                <a class="btn small secondary" href="dental_consent_file.php?id=<?= (int) $c['id'] ?>" target="_blank">Signed scan</a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($c['status'] !== 'SIGNED'): ?>
                        <div class="up-box">
                            <form method="POST" action="dental_consent.php" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="upload_scan">
                                <input type="hidden" name="patient_id" value="<?= (int) $patient['id'] ?>">
                                <input type="hidden" name="consent_id" value="<?= (int) $c['id'] ?>">
                                <div class="cn-grid">
                                    <div class="field" style="margin:0;">
                                        <label>Signed by</label>
                                        <input type="text" name="signed_name" class="uc" placeholder="Name as signed" required>
                                    </div>
                                    <div class="field" style="margin:0;">
                                        <label>Relationship (if not the patient)</label>
                                        <input type="text" name="signed_relation" placeholder="e.g. Father, Guardian">
                                    </div>
                                    <div class="field full" style="margin:0;">
                                        <label>Scanned signed form</label>
                                        <input type="file" name="scan" accept=".pdf,.jpg,.jpeg,.png" required>
                                        <div class="hint">PDF, JPG or PNG, up to 10MB. Uploading the scan marks this consent signed.</div>
                                    </div>
                                </div>
                                <div style="display:flex;justify-content:flex-end;margin-top:12px;">
                                    <button type="submit" class="btn" data-busy="Uploading…">Upload signed consent</button>
                                </div>
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<script src="assets/js/date-picker.js?v=<?= @filemtime(__DIR__ . "/assets/js/date-picker.js") ?: 1 ?>"></script>
</body>
</html>
