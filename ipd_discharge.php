<?php
/**
 * IPD discharge & billing.
 *
 * Raises the SEPARATE IPD bill ("I" invoice series), seeded from stay-days +
 * paid consultant visits + logged services. Reception reviews/edits lines,
 * applies an optional manual discount, takes payment (full or partial), and on
 * a shortfall an admin approves the write-off. Consultation + ER bills untouched.
 *
 * Also the place to LOG chargeable IPD services (before discharge) and to submit
 * the discharge itself.
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
require_once __DIR__ . '/config/billing.php';
require_once __DIR__ . '/config/ipd_billing.php';
refresh_session_permissions($pdo);

$baseRole = $_SESSION['base_role'] ?? '';
$uid = (int) $_SESSION['user_id'];
$admissionId = (int) ($_GET['id'] ?? 0);

function load_ipd_adm_bill(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("
        SELECT a.*, v.token_no, p.id AS patient_id, p.mrn, p.name AS patient_name, p.phone,
               COALESCE(du.name, a.admitting_consultant_manual) AS consultant_name
        FROM ipd_admissions a
        JOIN visits v ON v.id = a.visit_id
        JOIN patients p ON p.id = v.patient_id
        LEFT JOIN users du ON du.id = a.admitting_consultant_id
        WHERE a.id = ?
    ");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

$adm = load_ipd_adm_bill($pdo, $admissionId);
if (!$adm) { http_response_code(404); exit('IPD admission not found.'); }

$canFinalize = has_permission('IPD_FINALIZE_BILL');
$canApproveWriteoff = has_permission('IPD_APPROVE_WRITEOFF');
$canDischarge = has_permission('IPD_DISCHARGE_PATIENT');
$canLogServices = has_permission('IPD_LOG_SERVICES');
if (!$canFinalize && !$canApproveWriteoff && !$canDischarge && !$canLogServices) {
    http_response_code(403); exit('Forbidden.');
}

$flash = '';
$err = '';

// ---------------- Submit discharge (marks ready for billing) ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_discharge' && $canDischarge && $adm['status'] === 'ACTIVE') {
    $pdo->prepare("UPDATE ipd_admissions SET status = 'DISCHARGE_IN_PROGRESS', discharged_at = COALESCE(discharged_at, NOW()) WHERE id = ?")
        ->execute([$admissionId]);
    $pdo->prepare('UPDATE visits SET discharged_at = NOW() WHERE id = ?')->execute([(int) $adm['visit_id']]);
    $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
        ->execute([$uid, 'ipd_discharge_submitted', "IPD discharge submitted for admission #$admissionId"]);
    $flash = 'Discharge submitted — review the bill below.';
    $adm = load_ipd_adm_bill($pdo, $admissionId);
}

// ---------------- Log a chargeable service ----------------
// Services are logged BEFORE discharge is submitted — once the draft bill is
// seeded (at discharge submit) it reads the service list, so logging is only
// open while the stay is ACTIVE. This keeps the bill and the service log honest.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_service' && $canLogServices && $adm['status'] === 'ACTIVE') {
    $erId = (int) ($_POST['er_service_id'] ?? 0) ?: null;
    $qty = max(1, (int) ($_POST['quantity'] ?? 1));
    $dur = ($_POST['duration_minutes'] ?? '') !== '' ? (int) $_POST['duration_minutes'] : null;
    if (!$erId) {
        $err = 'Pick a service.';
    } else {
        $s = $pdo->prepare("SELECT * FROM er_services_master WHERE id = ? AND status = 'ACTIVE'");
        $s->execute([$erId]);
        $svc = $s->fetch();
        if (!$svc) {
            $err = 'That service is not available.';
        } else {
            $note = trim($_POST['clinical_note'] ?? '');
            $charge = ipd_service_charge($svc['charge_type'], (float) $svc['base_charge'], $qty, $dur);
            $loggedRole = in_array($baseRole, ['ADMIN','DOCTOR','STAFF'], true) ? $baseRole : 'STAFF';
            $pdo->prepare('
                INSERT INTO ipd_services (admission_id, er_service_id, service_type, service_name, charge_type, quantity, duration_minutes, unit_charge, calculated_charge, clinical_note, logged_by_id, logged_by_role)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ')->execute([
                $admissionId, $svc['id'], $svc['service_type'], $svc['service_name'], $svc['charge_type'],
                $qty, $dur, $svc['base_charge'], $charge, $note !== '' ? mb_substr($note, 0, 200) : null, $uid, $loggedRole,
            ]);
            $flash = 'Service logged.';
        }
    }
}

// A draft bill only exists once discharge is submitted (or someone opens billing).
$bill = null;
if ($adm['status'] !== 'ACTIVE' && $canFinalize) {
    $bill = ensure_ipd_bill($pdo, $adm, $uid);
}
$locked = $bill && ($bill['status'] === 'paid' || $bill['printed_at']);

// ---------------- Apply manual discount ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply_discount' && $bill && !$locked && $canFinalize) {
    $mode = $_POST['discount_mode'] ?? 'amount';
    $val = max(0, (float) ($_POST['discount_value'] ?? 0));
    if ($mode === 'pct') {
        $pdo->prepare('UPDATE ipd_bills SET manual_discount_pct = ?, manual_discount_amount = 0 WHERE id = ?')->execute([min(100, $val), $bill['id']]);
    } else {
        $pdo->prepare('UPDATE ipd_bills SET manual_discount_amount = ?, manual_discount_pct = 0 WHERE id = ?')->execute([$val, $bill['id']]);
    }
    recalc_ipd_bill_totals($pdo, (int) $bill['id']);
    $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
        ->execute([$uid, 'ipd_bill_discount', "Discount on IPD bill #{$bill['id']} ($mode $val)"]);
    $flash = 'Discount applied.';
    $bill = ensure_ipd_bill($pdo, $adm, $uid);
    $locked = ($bill['status'] === 'paid' || $bill['printed_at']);
}

// ---------------- Settle (take payment) ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'settle' && $bill && !$locked && $canFinalize) {
    $method = in_array($_POST['payment_method'] ?? '', ['cash','card','bank_transfer','cheque'], true) ? $_POST['payment_method'] : 'cash';
    $paid = max(0, (float) ($_POST['paid_amount'] ?? 0));
    $grand = (float) $bill['grand_total'];
    $shortfall = round($grand - $paid, 2);

    if ($shortfall > 0.009 && !$canApproveWriteoff) {
        $err = 'Short payment needs an admin to approve the write-off.';
    } else {
        $writeOff = $shortfall > 0.009 ? $shortfall : 0;
        $pdo->beginTransaction();
        try {
            $pdo->prepare("
                UPDATE ipd_bills
                SET status = 'paid', payment_method = ?, paid_amount = ?, write_off_amount = ?, paid_at = NOW(), paid_by_id = ?, finalized_by_id = COALESCE(finalized_by_id, ?)
                WHERE id = ?
            ")->execute([$method, $paid, $writeOff, $uid, $uid, $bill['id']]);

            $pdo->prepare("UPDATE ipd_admissions SET status = 'DISCHARGED', discharge_finalized_by_id = ?, discharge_finalized_at = NOW(), discharged_at = COALESCE(discharged_at, NOW()) WHERE id = ?")
                ->execute([$uid, $admissionId]);

            $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
                ->execute([$uid, 'ipd_bill_settled', "IPD bill {$bill['invoice_number']} settled: paid $paid, write-off $writeOff"]);
            $pdo->commit();
            header('Location: ipd_discharge.php?id=' . $admissionId . '&settled=1');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $err = 'Could not settle the bill — please try again.';
        }
    }
    if ($bill) { $bill = ensure_ipd_bill($pdo, $adm, $uid); }
}

// ---- Data for the view ----
$items = [];
if ($bill) {
    $it = $pdo->prepare('SELECT * FROM ipd_bill_items WHERE ipd_bill_id = ? ORDER BY FIELD(item_kind,\'STAY\',\'CONSULT_VISIT\',\'SERVICE\'), id');
    $it->execute([(int) $bill['id']]);
    $items = $it->fetchAll();
    $locked = ($bill['status'] === 'paid' || $bill['printed_at']);
}
$services = $pdo->prepare('SELECT * FROM ipd_services WHERE admission_id = ? ORDER BY logged_at');
$services->execute([$admissionId]);
$services = $services->fetchAll();
$erServices = $pdo->query("SELECT id, service_type, service_name, charge_type, base_charge FROM er_services_master WHERE status = 'ACTIVE' ORDER BY service_type, service_name")->fetchAll();

$days = ipd_stay_days($adm['admitted_at'], $adm['discharged_at'] ?? null);
$statusLabels = ['ACTIVE' => 'Active', 'DISCHARGE_IN_PROGRESS' => 'Discharge in progress', 'DISCHARGED' => 'Discharged'];

$pageTitle = 'IPD Discharge — ' . $adm['patient_name'];
$headExtra = <<<CSS
<style>
.bill-table { width:100%; border-collapse:collapse; font-size:13px; }
.bill-table th, .bill-table td { text-align:left; padding:9px 11px; border-bottom:1px solid var(--border); }
.bill-table th { font-size:10.5px; text-transform:uppercase; letter-spacing:.05em; color:var(--text-muted); font-weight:700; }
.bill-table td.amt, .bill-table th.amt { text-align:right; font-variant-numeric:tabular-nums; }
.kind-tag { font-size:10px; font-weight:700; padding:2px 7px; border-radius:20px; background:#F1F5F9; color:var(--text-secondary); }
.two-col { display:grid; grid-template-columns:1.5fr 1fr; gap:20px; align-items:start; }
@media (max-width:960px){ .two-col{ grid-template-columns:1fr; } }
.svc-add { display:grid; grid-template-columns:2fr .7fr .9fr auto; gap:10px; align-items:end; }
.svc-add label, .mini label { font-size:11.5px; font-weight:600; color:var(--text-secondary); display:block; margin-bottom:5px; }
.svc-add select, .svc-add input, .mini input, .mini select { width:100%; padding:9px 11px; border:1px solid var(--border); border-radius:10px; font:inherit; font-size:13.5px; background:var(--bg); }
.tot-row { display:flex; justify-content:space-between; font-size:13.5px; padding:4px 0; }
.tot-row.grand { border-top:1px solid var(--border); margin-top:6px; padding-top:10px; font-weight:700; font-size:16px; }
CSS;
$headExtra .= "\n</style>";
require __DIR__ . '/partials/head.php';
$navActive = 'ipd';
require __DIR__ . '/partials/sidebar.php';
?>
        <div class="content">
            <div class="page-head">
                <div>
                    <div class="page-title">IPD Discharge &amp; Billing</div>
                    <div class="page-sub"><a href="ipd_admission.php?id=<?= $admissionId ?>" style="color:var(--primary);font-weight:600;">&larr; Back to stay</a> &middot; <?= htmlspecialchars($adm['patient_name']) ?> &middot; <span class="mono"><?= htmlspecialchars($adm['mrn']) ?></span> &middot; <?= htmlspecialchars($statusLabels[$adm['status']] ?? $adm['status']) ?></div>
                </div>
            </div>

            <?php if (isset($_GET['settled'])): ?><div class="alert success">Bill settled and patient discharged.</div><?php endif; ?>
            <?php if ($flash): ?><div class="alert success"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
            <?php if ($err): ?><div class="alert error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

            <?php if ($adm['status'] === 'ACTIVE'): ?>
            <div class="card">
                <div class="section-title">Discharge</div>
                <div class="section-sub">Submitting discharge stops the stay clock and opens billing.</div>
                <?php if ($canDischarge): ?>
                <form method="POST" action="ipd_discharge.php?id=<?= $admissionId ?>" style="margin-top:12px;" onsubmit="return confirm('Submit discharge for this patient?');">
                    <input type="hidden" name="action" value="submit_discharge">
                    <button type="submit" class="btn">Submit discharge</button>
                </form>
                <?php else: ?>
                <div class="muted" style="margin-top:8px;">You don't have permission to submit the discharge.</div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="two-col" style="margin-top:20px;">
                <!-- Bill -->
                <div class="card">
                    <div class="section-title">IPD Bill<?= $bill ? ' — ' . htmlspecialchars($bill['invoice_number']) : '' ?></div>
                    <?php if (!$bill): ?>
                        <div class="muted" style="margin-top:8px;"><?= $adm['status'] === 'ACTIVE' ? 'The bill is raised once discharge is submitted.' : 'You do not have billing permission for IPD.' ?></div>
                    <?php else: ?>
                    <table class="bill-table" style="margin-top:12px;">
                        <thead><tr><th>Item</th><th>Qty</th><th class="amt">Amount</th></tr></thead>
                        <tbody>
                            <?php foreach ($items as $li): ?>
                            <tr>
                                <td><span class="kind-tag"><?= htmlspecialchars($li['item_kind']) ?></span> <?= htmlspecialchars($li['description']) ?></td>
                                <td><?= rtrim(rtrim(number_format((float) $li['quantity'], 2), '0'), '.') ?></td>
                                <td class="amt">Rs <?= number_format((float) $li['amount']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php
                    $discountShown = (float) $bill['grand_total'] < (float) $bill['subtotal'];
                    ?>
                    <div style="margin-top:12px;">
                        <div class="tot-row"><span>Subtotal</span><span>Rs <?= number_format((float) $bill['subtotal']) ?></span></div>
                        <?php if ($discountShown): ?>
                        <div class="tot-row"><span>Discount</span><span>&minus; Rs <?= number_format((float) $bill['subtotal'] - (float) $bill['grand_total']) ?></span></div>
                        <?php endif; ?>
                        <div class="tot-row grand"><span>Grand total</span><span>Rs <?= number_format((float) $bill['grand_total']) ?></span></div>
                        <?php if ($bill['status'] === 'paid'): ?>
                        <div class="tot-row"><span>Paid (<?= htmlspecialchars($bill['payment_method']) ?>)</span><span>Rs <?= number_format((float) $bill['paid_amount']) ?></span></div>
                        <?php if ((float) $bill['write_off_amount'] > 0): ?><div class="tot-row"><span>Written off</span><span>Rs <?= number_format((float) $bill['write_off_amount']) ?></span></div><?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;">
                        <a class="btn secondary" href="ipd_invoice.php?id=<?= $admissionId ?>" target="_blank">Print invoice</a>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Actions: services, discount, settle -->
                <div>
                    <?php if ($canLogServices && $adm['status'] === 'ACTIVE'): ?>
                    <div class="card">
                        <div class="section-title">Log a service</div>
                        <div class="section-sub">Log all chargeable services before submitting discharge — the bill reads them at discharge.</div>
                        <form method="POST" action="ipd_discharge.php?id=<?= $admissionId ?>" style="margin-top:12px;">
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
                                <div><label>Min (hrly)</label><input type="number" name="duration_minutes" min="0" placeholder="—"></div>
                                <button type="submit" class="btn secondary">Add</button>
                            </div>
                            <div style="margin-top:8px;"><input type="text" name="clinical_note" maxlength="200" placeholder="Detail (optional)" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;font:inherit;font-size:13px;background:var(--bg);"></div>
                        </form>
                        <?php if ($services): ?>
                        <div style="margin-top:12px;font-size:12.5px;">
                            <?php foreach ($services as $s): ?>
                            <div style="display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid var(--border);">
                                <span><?= htmlspecialchars($s['service_name']) ?> <span class="muted">×<?= (int) $s['quantity'] ?></span></span>
                                <span class="mono">Rs <?= number_format((float) $s['calculated_charge']) ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($bill && !$locked && $canFinalize): ?>
                    <div class="card" style="margin-top:20px;">
                        <div class="section-title">Discount</div>
                        <form method="POST" action="ipd_discharge.php?id=<?= $admissionId ?>" class="mini" style="margin-top:10px;display:flex;gap:8px;align-items:end;">
                            <input type="hidden" name="action" value="apply_discount">
                            <div style="width:110px;"><label>Mode</label><select name="discount_mode"><option value="amount">Rs</option><option value="pct">%</option></select></div>
                            <div style="flex:1;"><label>Value</label><input type="number" step="0.01" min="0" name="discount_value" value="0"></div>
                            <button type="submit" class="btn secondary">Apply</button>
                        </form>
                    </div>

                    <div class="card" style="margin-top:20px;">
                        <div class="section-title">Settle &amp; discharge</div>
                        <form method="POST" action="ipd_discharge.php?id=<?= $admissionId ?>" class="mini" style="margin-top:10px;display:flex;flex-direction:column;gap:10px;" onsubmit="return confirm('Settle this bill and discharge the patient?');">
                            <input type="hidden" name="action" value="settle">
                            <div><label>Payment method</label>
                                <select name="payment_method"><option value="cash">Cash</option><option value="card">Card</option><option value="bank_transfer">Bank transfer</option><option value="cheque">Cheque</option></select>
                            </div>
                            <div><label>Amount received</label><input type="number" step="0.01" min="0" name="paid_amount" value="<?= number_format((float) $bill['grand_total'], 2, '.', '') ?>"></div>
                            <button type="submit" class="btn">Settle &amp; discharge</button>
                            <?php if (!$canApproveWriteoff): ?><div class="muted" style="font-size:11.5px;">A short payment needs an admin to approve the write-off.</div><?php endif; ?>
                        </form>
                    </div>
                    <?php elseif ($bill && $bill['status'] === 'paid'): ?>
                    <div class="card" style="margin-top:20px;">
                        <div class="muted">Bill settled <?= $bill['paid_at'] ? date('d/m/Y H:i', strtotime($bill['paid_at'])) : '' ?>. Patient discharged.</div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
