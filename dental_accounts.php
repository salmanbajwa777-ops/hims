<?php
/**
 * Dental package accounts — the list.
 *
 * A package account is for multi-visit / high-value work: quoted once, paid
 * down over months. Small same-visit work does NOT get an account — it bills
 * on the ordinary P-series procedure bill and is finished the same day.
 *
 * This page is the worklist: who owes what, which packages are done, and which
 * ended up overpaid and need a refund raising.
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
require_permission('DENTAL_VIEW_ACCOUNTS');

$error = '';
$success = '';
$userId = (int) $_SESSION['user_id'];

// ---- Open an account ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'open_account') {
    if (!has_permission('DENTAL_OPEN_ACCOUNT')) {
        $error = 'You do not have permission to open a package account.';
    } else {
        $patientId = (int) ($_POST['patient_id'] ?? 0);
        $doctorId  = (int) ($_POST['doctor_id'] ?? 0);
        $title     = trim($_POST['title'] ?? '');
        $notes     = trim($_POST['notes'] ?? '');

        if ($patientId <= 0 || $doctorId <= 0 || $title === '') {
            $error = 'A package needs a patient, a dentist and a short title.';
        } else {
            // The patient's category discount is SNAPSHOTTED at opening, so a
            // later change to their category cannot re-price a quote they have
            // already signed. procedures_pct is the right bucket: a package is
            // procedure work, not a consultation.
            $cat = patient_discount_category($pdo, $patientId);
            $discountPct = (float) ($cat['procedures_pct'] ?? 0);

            $pdo->beginTransaction();
            try {
                $accountNumber = generate_dental_account_number($pdo);
                $pdo->prepare('
                    INSERT INTO dental_procedure_accounts
                        (account_number, patient_id, doctor_id, title, status, discount_pct, notes, opened_by_id)
                    VALUES (?, ?, ?, ?, \'OPEN\', ?, ?, ?)
                ')->execute([$accountNumber, $patientId, $doctorId, $title, $discountPct,
                             $notes !== '' ? $notes : null, $userId]);
                $newId = (int) $pdo->lastInsertId();
                audit_log($pdo, 'dental_account_opened', "Opened package $accountNumber \"$title\" for patient #$patientId" . ($discountPct > 0 ? " (discount {$discountPct}%)" : ''), $userId);
                $pdo->commit();
                header('Location: dental_account.php?id=' . $newId . '&opened=1');
                exit;
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                error_log('[dental_accounts open] ' . $e->getMessage());
                $error = 'Could not open the account. Please try again.';
            }
        }
    }
}

// ---- Filters ----
$tab = $_GET['tab'] ?? 'OPEN';
if (!in_array($tab, ['OPEN', 'SETTLED', 'CANCELLED', 'OVERPAID', 'ALL'], true)) {
    $tab = 'OPEN';
}
$q = trim($_GET['q'] ?? '');

// ONE query with derived joins rather than calling dental_account_totals() per
// row — that would be an N+1 on a page whose whole job is showing many accounts.
// The two derived tables mirror that function's definitions exactly; if either
// is ever changed, change both.
$sql = "
    SELECT a.*, p.mrn, p.name AS patient_name, p.phone,
           d.name AS doctor_name,
           COALESCE(i.charged, 0) AS charged,
           COALESCE(i.lab, 0)     AS lab,
           COALESCE(pm.paid, 0)   AS paid,
           (COALESCE(i.charged, 0) + COALESCE(i.lab, 0) - COALESCE(pm.paid, 0)) AS balance
      FROM dental_procedure_accounts a
      JOIN patients p ON p.id = a.patient_id
      LEFT JOIN users d ON d.id = a.doctor_id
      LEFT JOIN (
            SELECT account_id, SUM(amount) AS charged, SUM(lab_charge) AS lab
              FROM dental_procedure_account_items
             WHERE voided_at IS NULL
             GROUP BY account_id
      ) i ON i.account_id = a.id
      LEFT JOIN (
            SELECT account_id, SUM(amount) AS paid
              FROM dental_procedure_payments
             WHERE status = 'paid' AND voided_at IS NULL
             GROUP BY account_id
      ) pm ON pm.account_id = a.id
     WHERE 1 = 1
";
$args = [];
if ($tab === 'OVERPAID') {
    // Money the clinic is holding that it should not be. Surfaced as its own
    // tab because a cancelled package that was paid ahead is the one state
    // nobody goes looking for.
    $sql .= ' AND (COALESCE(i.charged,0) + COALESCE(i.lab,0) - COALESCE(pm.paid,0)) < -0.004';
} elseif ($tab !== 'ALL') {
    $sql .= ' AND a.status = ?';
    $args[] = $tab;
}
if ($q !== '') {
    $sql .= ' AND (p.name LIKE ? OR p.mrn LIKE ? OR p.phone LIKE ? OR a.account_number LIKE ? OR a.title LIKE ?)';
    $like = '%' . $q . '%';
    array_push($args, $like, $like, $like, $like, $like);
}
$sql .= ' ORDER BY a.opened_at DESC, a.id DESC LIMIT 200';

$stmt = $pdo->prepare($sql);
$stmt->execute($args);
$accounts = $stmt->fetchAll();

// Tab counts, same derived shape, so the tabs show what they will open.
$counts = ['OPEN' => 0, 'SETTLED' => 0, 'CANCELLED' => 0, 'OVERPAID' => 0, 'ALL' => 0];
foreach ($pdo->query("
    SELECT a.status, COUNT(*) AS n,
           SUM(CASE WHEN (COALESCE(i.charged,0) + COALESCE(i.lab,0) - COALESCE(pm.paid,0)) < -0.004
                    THEN 1 ELSE 0 END) AS over
      FROM dental_procedure_accounts a
      LEFT JOIN (SELECT account_id, SUM(amount) AS charged, SUM(lab_charge) AS lab
                   FROM dental_procedure_account_items WHERE voided_at IS NULL GROUP BY account_id) i
             ON i.account_id = a.id
      LEFT JOIN (SELECT account_id, SUM(amount) AS paid
                   FROM dental_procedure_payments WHERE status = 'paid' AND voided_at IS NULL
                  GROUP BY account_id) pm ON pm.account_id = a.id
     GROUP BY a.status
")->fetchAll() as $r) {
    $counts[$r['status']] = (int) $r['n'];
    $counts['ALL'] += (int) $r['n'];
    $counts['OVERPAID'] += (int) $r['over'];
}

// ---- "Open a package" form data ----
$newFor = (int) ($_GET['new_for'] ?? 0);
$newForPatient = null;
if ($newFor > 0) {
    $s = $pdo->prepare('SELECT id, mrn, name, father_name, phone FROM patients WHERE id = ?');
    $s->execute([$newFor]);
    $newForPatient = $s->fetch() ?: null;
}
$dentists = $pdo->query("
    SELECT id, name FROM users
    WHERE base_role = 'DOCTOR' AND is_active = 1 AND specialty = 'DENTAL'
    ORDER BY name
")->fetchAll();

$pageTitle = 'Dental Accounts';
$headExtra = <<<CSS
<style>
.tabs { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:14px; }
.tabs a { padding:7px 13px; border-radius:var(--radius-pill); background:var(--card-alt);
  color:var(--text-secondary); font-size:var(--fs-btn); font-weight:600; text-decoration:none;
  border:1px solid var(--border); }
.tabs a.on { background:var(--primary); color:var(--on-primary); border-color:var(--primary); }
.tabs a .n { opacity:.72; margin-left:5px; font-weight:600; }
.bal-owed { color:var(--warn); font-weight:700; }
.bal-clear { color:var(--ok); font-weight:700; }
.bal-refund { color:var(--danger); font-weight:700; }
.acc-num { font-weight:700; letter-spacing:.02em; }
.acc-sub { font-size:var(--fs-meta); color:var(--text-muted); margin-top:2px; }
.newbox { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.newbox .full { grid-column:1 / -1; }
@media (max-width:900px) { .newbox { grid-template-columns:1fr; } }
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
                    <div class="page-title">Dental Accounts</div>
                    <div class="page-sub">Multi-visit packages — the itemised quote and what is still owed on it.</div>
                </div>
            </div>

            <?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($success): ?><div class="alert success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

            <?php if ($newForPatient && has_permission('DENTAL_OPEN_ACCOUNT')): ?>
            <!-- ---- Open a package for a specific patient ---- -->
            <div class="card">
                <div class="section-title">Open a package account</div>
                <div class="section-sub">
                    For <b><?= htmlspecialchars($newForPatient['name']) ?></b> (<?= htmlspecialchars($newForPatient['mrn']) ?>).
                    Only for multi-visit or high-value work — a single-visit filling bills on the ordinary procedure bill instead.
                </div>
                <form method="POST" action="dental_accounts.php">
                    <input type="hidden" name="action" value="open_account">
                    <input type="hidden" name="patient_id" value="<?= (int) $newForPatient['id'] ?>">
                    <div class="newbox" style="margin-top:12px;">
                        <div class="field">
                            <label>Dentist</label>
                            <select name="doctor_id" required>
                                <option value="">— select —</option>
                                <?php foreach ($dentists as $d): ?>
                                <option value="<?= (int) $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="hint">Owns the case and earns the share on its items.</div>
                        </div>
                        <div class="field">
                            <label>Title</label>
                            <input type="text" name="title" placeholder="e.g. Upper arch rehabilitation" required>
                            <div class="hint">How reception will recognise this package.</div>
                        </div>
                        <div class="field full">
                            <label>Notes (optional)</label>
                            <textarea name="notes" rows="2" placeholder="Anything the front desk should know about this case"></textarea>
                        </div>
                    </div>
                    <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:14px;">
                        <a class="btn secondary" href="dental_accounts.php">Cancel</a>
                        <button type="submit" class="btn">Open account</button>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <div class="card">
                <div class="tabs">
                    <?php
                    $tabLabels = ['OPEN' => 'Open', 'SETTLED' => 'Settled', 'CANCELLED' => 'Cancelled',
                                  'OVERPAID' => 'Refund due', 'ALL' => 'All'];
                    foreach ($tabLabels as $tv => $tl):
                        $href = 'dental_accounts.php?tab=' . $tv . ($q !== '' ? '&q=' . urlencode($q) : '');
                    ?>
                    <a class="<?= $tab === $tv ? 'on' : '' ?>" href="<?= $href ?>"><?= $tl ?><span class="n"><?= (int) $counts[$tv] ?></span></a>
                    <?php endforeach; ?>
                </div>

                <form method="GET" action="dental_accounts.php" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;">
                    <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
                    <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Patient, MR #, account number or title…" style="flex:1;min-width:220px;">
                    <button type="submit" class="btn secondary">Search</button>
                    <?php if ($q !== ''): ?><a class="btn secondary" href="dental_accounts.php?tab=<?= htmlspecialchars($tab) ?>">Clear</a><?php endif; ?>
                </form>

                <?php if (!$accounts): ?>
                <div class="empty">
                    <?= $q !== '' ? 'No account matches that search.' : 'No ' . strtolower($tabLabels[$tab]) . ' accounts.' ?>
                </div>
                <?php else: ?>
                <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr>
                            <th style="width:130px;">Account</th>
                            <th>Patient</th>
                            <th style="width:150px;">Dentist</th>
                            <th style="width:110px;" class="text-right">Total</th>
                            <th style="width:110px;" class="text-right">Paid</th>
                            <th style="width:130px;" class="text-right">Balance</th>
                            <th style="width:80px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($accounts as $a):
                            $total = (float) $a['charged'] + (float) $a['lab'];
                            $bal   = (float) $a['balance'];
                        ?>
                        <tr>
                            <td>
                                <div class="acc-num"><?= htmlspecialchars($a['account_number']) ?></div>
                                <div class="acc-sub"><?= date('d/m/Y', strtotime($a['opened_at'])) ?></div>
                            </td>
                            <td>
                                <div style="font-weight:600;"><?= htmlspecialchars($a['patient_name']) ?></div>
                                <div class="acc-sub"><?= htmlspecialchars($a['mrn']) ?> · <?= htmlspecialchars($a['title']) ?></div>
                            </td>
                            <td><?= htmlspecialchars($a['doctor_name'] ?? '—') ?></td>
                            <td class="text-right">Rs <?= number_format($total, 2) ?></td>
                            <td class="text-right">Rs <?= number_format((float) $a['paid'], 2) ?></td>
                            <td class="text-right">
                                <?php if ($bal < -0.004): ?>
                                    <!-- Money LEAVING the clinic: --danger is reserved for exactly this. -->
                                    <span class="bal-refund">Rs <?= number_format(-$bal, 2) ?> refund</span>
                                <?php elseif ($bal > 0.004): ?>
                                    <span class="bal-owed">Rs <?= number_format($bal, 2) ?></span>
                                <?php else: ?>
                                    <span class="bal-clear">Clear</span>
                                <?php endif; ?>
                                <?php if ($a['status'] === 'CANCELLED'): ?>
                                <div class="acc-sub">cancelled — frozen</div>
                                <?php endif; ?>
                            </td>
                            <td><a class="btn small secondary" href="dental_account.php?id=<?= (int) $a['id'] ?>">Open</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!$newForPatient && has_permission('DENTAL_OPEN_ACCOUNT')): ?>
            <div class="card">
                <div class="section-title">Open a new package</div>
                <div class="section-sub">Find the patient first — packages are opened from their treatment page.</div>
                <div style="margin-top:12px;">
                    <a class="btn secondary" href="dental_treatment.php">Go to Dental Treatment</a>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<script src="assets/js/date-picker.js?v=<?= @filemtime(__DIR__ . "/assets/js/date-picker.js") ?: 1 ?>"></script>
</body>
</html>
