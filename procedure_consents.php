<?php
/**
 * Consent register — printed procedure consents, and the signed scans filed
 * back against them.
 *
 * THE LOOP THIS CLOSES. Billing a procedure with a consent template prints the
 * sheet and records a PENDING consent. Paper is then signed at the desk. Until
 * that signed sheet is scanned back in, the clinic's record of the consent is a
 * piece of paper in a drawer — this page is the list of consents still in that
 * state, and the place the scan is uploaded.
 *
 * A consent becomes SIGNED when the scan lands. Nothing marks it signed just
 * because it printed: the whole point of the document is the signature on it.
 *
 * Storage is deliberately the dental module's: dental_consent_validate() and
 * dental_consent_store() already implement the app's upload contract (generated
 * filename, extension allow-list, size cap checked before anything is written,
 * runtime deny-all .htaccess). Reimplementing it here would mean two upload
 * paths with two subtly different guards, which is how a private document ends
 * up world-readable.
 *
 * Requires sql/add_procedure_consent.sql.
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
require_once __DIR__ . '/config/dental.php';    // the upload contract, reused as-is
require_once __DIR__ . '/config/consent.php';
refresh_session_permissions($pdo);
require_permission('RECEPTION_MANAGE_CONSENT');

$error = '';
$success = '';

// Make sure the directory and its deny-all guard exist before any upload.
dental_consent_dir();

// ---- Upload a signed scan ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_scan') {
    $consentId = (int) ($_POST['consent_id'] ?? 0);

    [$pending, $fileErr] = dental_consent_validate($_FILES['scan'] ?? null);

    if ($fileErr) {
        $error = $fileErr;
    } elseif (!$pending) {
        $error = 'Choose the scanned consent to upload.';
    } elseif ($consentId <= 0) {
        $error = 'No consent selected.';
    } else {
        // Only a live procedure consent may be written to here. Without this a
        // crafted post could stamp a scan onto a dental consent (or a voided
        // one) that this page never lists.
        $chk = $pdo->prepare('SELECT id FROM dental_consents
                               WHERE id = ? AND procedure_bill_id IS NOT NULL AND voided_at IS NULL');
        $chk->execute([$consentId]);

        if (!$chk->fetchColumn()) {
            $error = 'That consent could not be found.';
        } elseif (dental_consent_store($pdo, $pending, $consentId, (int) $_SESSION['user_id'])) {
            audit_log($pdo, 'procedure_consent_signed',
                "Signed consent scan filed against consent #$consentId", (int) $_SESSION['user_id']);
            $success = 'Signed consent filed. This consent is now marked SIGNED.';
        } else {
            $error = 'The scan could not be saved. Please try again.';
        }
    }
}

// ---- List ----
// Pending first: this page exists to work through the ones still unsigned, and
// sorting purely by date buries them under everything already filed.
$statusFilter = $_GET['status'] ?? 'PENDING';
if (!in_array($statusFilter, ['PENDING', 'SIGNED', 'ALL'], true)) {
    $statusFilter = 'PENDING';
}

$consents = [];
$tableLive = true;
try {
    $sql = "
        SELECT c.id, c.procedure_ref, c.status, c.signed_name, c.signed_relation,
               c.created_at, c.signed_at, c.scan_path,
               p.mrn, p.name AS patient_name,
               d.name AS doctor_name,
               pb.invoice_number, pb.id AS bill_id
          FROM dental_consents c
          JOIN patients p ON p.id = c.patient_id
          LEFT JOIN users d ON d.id = c.doctor_id
          JOIN procedure_bills pb ON pb.id = c.procedure_bill_id
         WHERE c.procedure_bill_id IS NOT NULL AND c.voided_at IS NULL";
    if ($statusFilter !== 'ALL') {
        $sql .= " AND c.status = :st";
    }
    $sql .= " ORDER BY (c.status = 'PENDING') DESC, c.created_at DESC LIMIT 200";

    $stmt = $pdo->prepare($sql);
    if ($statusFilter !== 'ALL') {
        $stmt->execute([':st' => $statusFilter]);
    } else {
        $stmt->execute();
    }
    $consents = $stmt->fetchAll();
} catch (PDOException $e) {
    // procedure_bill_id absent = sql/add_procedure_consent.sql not run.
    $tableLive = false;
}

$pageTitle = 'Consents';
$headExtra = <<<CSS
<style>
.filters { display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }
.filters a { padding: 7px 14px; border: 1px solid var(--border); border-radius: 999px; text-decoration: none; font-size: 13px; font-weight: 600; color: var(--text-secondary); }
.filters a.on { background: var(--primary-dark); border-color: var(--primary-dark); color: #fff; }
.ctable { width: 100%; border-collapse: collapse; }
.ctable th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted); padding: 8px; border-bottom: 1px solid var(--border); white-space: nowrap; }
.ctable td { padding: 11px 8px; border-bottom: 1px solid var(--border); font-size: 13.5px; vertical-align: middle; }
.ctable .sub { display: block; font-size: 11.5px; color: var(--text-muted); }
.cpill { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; padding: 2.5px 9px; border-radius: 999px; white-space: nowrap; }
.cpill.pending { background: var(--amber-bg, #fef3c7); color: var(--amber-text, #92400e); }
.cpill.signed { background: var(--green-bg); color: var(--green-text); }
.acts { display: flex; gap: 10px; align-items: center; justify-content: flex-end; white-space: nowrap; }
.acts a { color: var(--primary-dark); font-weight: 600; font-size: 12.5px; text-decoration: underline; }
.upform { display: flex; gap: 7px; align-items: center; }
.upform input[type=file] { font-size: 11.5px; max-width: 168px; }
.upform button { padding: 5px 11px; border: 1px solid var(--primary-dark); background: var(--primary-dark); color: #fff; border-radius: var(--radius-input); font: inherit; font-size: 12px; font-weight: 600; cursor: pointer; }
.empty-note { padding: 26px; text-align: center; color: var(--text-muted); font-size: 14px; }
</style>
CSS;
require __DIR__ . '/partials/head.php';
$navActive = 'procedure_consents';
require __DIR__ . '/partials/sidebar.php';
?>
        <?php require __DIR__ . '/partials/quick_header.php'; ?>
        <div class="content">
            <div class="page-head">
                <h1>Consents</h1>
                <p>Consent forms printed with a procedure bill. A consent stays <b>Pending</b> until the signed sheet is scanned back in.</p>
            </div>

            <?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($success): ?><div class="alert success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

            <?php if (!$tableLive): ?>
            <div class="alert error">
                Procedure consents are not enabled on this database yet.
                Run <code>sql/add_procedure_consent.sql</code>, then reload this page.
            </div>
            <?php else: ?>

            <div class="filters">
                <?php foreach (['PENDING' => 'Awaiting signature', 'SIGNED' => 'Signed', 'ALL' => 'All'] as $k => $label): ?>
                <a href="procedure_consents.php?status=<?= $k ?>" class="<?= $statusFilter === $k ? 'on' : '' ?>"><?= $label ?></a>
                <?php endforeach; ?>
            </div>

            <div class="card">
                <?php if (!$consents): ?>
                    <p class="empty-note">
                        <?= $statusFilter === 'PENDING'
                            ? 'Nothing awaiting a signature. Every printed consent has its signed scan on file.'
                            : 'No consents to show.' ?>
                    </p>
                <?php else: ?>
                <div style="overflow-x:auto;">
                <table class="ctable">
                    <thead>
                        <tr>
                            <th>Printed</th>
                            <th>Patient</th>
                            <th>Procedure</th>
                            <th>Invoice</th>
                            <th>Signed by</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($consents as $c): ?>
                        <tr>
                            <td>
                                <?= date('d/m/Y', strtotime($c['created_at'])) ?>
                                <span class="sub"><?= date('H:i', strtotime($c['created_at'])) ?></span>
                            </td>
                            <td>
                                <?= htmlspecialchars($c['patient_name']) ?>
                                <span class="sub">MR# <?= htmlspecialchars($c['mrn']) ?></span>
                            </td>
                            <td>
                                <?= htmlspecialchars($c['procedure_ref']) ?>
                                <span class="sub"><?= htmlspecialchars($c['doctor_name'] ?? '—') ?></span>
                            </td>
                            <td><?= htmlspecialchars($c['invoice_number']) ?></td>
                            <td>
                                <?php if (!empty($c['signed_name'])): ?>
                                    <?= htmlspecialchars($c['signed_name']) ?>
                                    <?php if (!empty($c['signed_relation'])): ?>
                                    <span class="sub"><?= htmlspecialchars($c['signed_relation']) ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color:var(--text-muted);">Filled by hand</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="cpill <?= $c['status'] === 'SIGNED' ? 'signed' : 'pending' ?>">
                                    <?= htmlspecialchars($c['status']) ?>
                                </span>
                            </td>
                            <td>
                                <div class="acts">
                                    <!-- Reprint goes through the BILL, not the consent: the sheet is
                                         printed as part of that document and reprinting it alone
                                         would separate the consent from the receipt it belongs to. -->
                                    <a href="procedure_bill.php?print=1&procedure_bill_id=<?= (int) $c['bill_id'] ?>" target="_blank">Reprint</a>
                                    <?php if ($c['status'] === 'SIGNED' && $c['scan_path']): ?>
                                        <a href="dental_consent_file.php?id=<?= (int) $c['id'] ?>" target="_blank">View scan</a>
                                    <?php else: ?>
                                        <form method="POST" action="procedure_consents.php?status=<?= $statusFilter ?>"
                                              enctype="multipart/form-data" class="upform">
                                            <input type="hidden" name="action" value="upload_scan">
                                            <input type="hidden" name="consent_id" value="<?= (int) $c['id'] ?>">
                                            <input type="file" name="scan" accept=".pdf,.jpg,.jpeg,.png" required>
                                            <button type="submit">File signed</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
</body>
</html>
