<?php
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
refresh_session_permissions($pdo);
require_permission('RECEPTION_GENERATE_INVOICES');

$currentUser = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$currentUser->execute([$_SESSION['user_id']]);
$currentUser = $currentUser->fetch();

require_once __DIR__ . '/config/billing.php';

$error = '';
$success = '';

// ---------------- Start checkout for a visit (creates draft bill) ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'start_checkout') {
    $visitId = (int) ($_POST['visit_id'] ?? 0);

    $visitStmt = $pdo->prepare('SELECT v.*, dct.label AS consult_label FROM visits v JOIN doctor_consult_types dct ON dct.id = v.doctor_consult_type_id WHERE v.id = ?');
    $visitStmt->execute([$visitId]);
    $visit = $visitStmt->fetch();

    $existingStmt = $pdo->prepare('SELECT id FROM bills WHERE visit_id = ?');
    $existingStmt->execute([$visitId]);

    if (!$visit) {
        $error = 'Visit not found.';
    } elseif ($existingStmt->fetch()) {
        $error = 'This visit already has a bill.';
    } else {
        try {
            $pdo->beginTransaction();

            $billId = create_bill_for_visit(
                $pdo,
                $visitId,
                $visit['consult_label'],
                (float) $visit['fee'],
                (float) $visit['discount_pct'],
                (int) $_SESSION['user_id']
            );

            $log = $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)');
            $log->execute([$_SESSION['user_id'], 'bill_created', "Created bill #$billId for visit #$visitId"]);

            $pdo->commit();
            header('Location: checkout.php?bill_id=' . $billId);
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Could not start checkout. Please try again.';
        }
    }
}

// ---------------- Add line item ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_item') {
    $billId = (int) ($_POST['bill_id'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $quantity = max(1, (int) ($_POST['quantity'] ?? 1));
    $unitRate = (float) ($_POST['unit_rate'] ?? 0);

    $billStmt = $pdo->prepare("SELECT * FROM bills WHERE id = ? AND status = 'draft'");
    $billStmt->execute([$billId]);
    $bill = $billStmt->fetch();

    if (!$bill) {
        $error = 'Bill not found or already finalized.';
    } elseif ($description === '' || $unitRate < 0) {
        $error = 'A description and a non-negative rate are required.';
    } else {
        $amount = $quantity * $unitRate;
        $pdo->prepare('
            INSERT INTO bill_items (bill_id, description, quantity, unit_rate, amount)
            VALUES (?, ?, ?, ?, ?)
        ')->execute([$billId, $description, $quantity, $unitRate, $amount]);
        recalc_bill_totals($pdo, $billId);
        header('Location: checkout.php?bill_id=' . $billId);
        exit;
    }
}

// ---------------- Remove line item ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_item') {
    $itemId = (int) ($_POST['item_id'] ?? 0);
    $billId = (int) ($_POST['bill_id'] ?? 0);

    $billStmt = $pdo->prepare("SELECT id FROM bills WHERE id = ? AND status = 'draft'");
    $billStmt->execute([$billId]);

    if ($billStmt->fetch()) {
        $pdo->prepare('DELETE FROM bill_items WHERE id = ? AND bill_id = ?')->execute([$itemId, $billId]);
        recalc_bill_totals($pdo, $billId);
    }
    header('Location: checkout.php?bill_id=' . $billId);
    exit;
}

// ---------------- Finalize bill (locks line items) ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'finalize_bill') {
    $billId = (int) ($_POST['bill_id'] ?? 0);
    $billStmt = $pdo->prepare("SELECT * FROM bills WHERE id = ? AND status = 'draft'");
    $billStmt->execute([$billId]);
    $bill = $billStmt->fetch();

    if (!$bill) {
        $error = 'Bill not found or already finalized.';
    } else {
        $pdo->prepare("UPDATE bills SET status = 'finalized' WHERE id = ?")->execute([$billId]);
        $log = $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)');
        $log->execute([$_SESSION['user_id'], 'bill_finalized', "Finalized bill #$billId ({$bill['invoice_number']})"]);
        $success = 'Invoice finalized.';
        header('Location: checkout.php?bill_id=' . $billId);
        exit;
    }
}

// ---------------- Record payment ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_payment') {
    require_permission('RECEPTION_PROCESS_PAYMENTS');
    $billId = (int) ($_POST['bill_id'] ?? 0);
    $paymentMethod = $_POST['payment_method'] ?? '';
    $allowedMethods = ['cash', 'card', 'bank_transfer', 'cheque'];

    $billStmt = $pdo->prepare("SELECT * FROM bills WHERE id = ? AND status = 'finalized'");
    $billStmt->execute([$billId]);
    $bill = $billStmt->fetch();

    // Once the day is closed (signed cash tally), no more payments may be
    // recorded against it — see shift_closing.php.
    $dayLock = require_day_open($pdo);

    if ($dayLock) {
        $error = $dayLock;
    } elseif (!$bill) {
        $error = 'Bill not found or not yet finalized.';
    } elseif (!in_array($paymentMethod, $allowedMethods, true)) {
        $error = 'Please select a valid payment method.';
    } else {
        // paid_by_id = who collected the money — that user's shift tally owns it.
        // Falls back to the legacy statement if add_per_user_closings.sql hasn't
        // been applied yet, so taking payments never breaks mid-deploy.
        try {
            $pdo->prepare("
                UPDATE bills SET status = 'paid', payment_method = ?, paid_amount = ?, paid_at = NOW(), paid_by_id = ?
                WHERE id = ?
            ")->execute([$paymentMethod, $bill['grand_total'], $_SESSION['user_id'], $billId]);
        } catch (PDOException $e) {
            $pdo->prepare("
                UPDATE bills SET status = 'paid', payment_method = ?, paid_amount = ?, paid_at = NOW()
                WHERE id = ?
            ")->execute([$paymentMethod, $bill['grand_total'], $billId]);
        }

        $log = $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)');
        $log->execute([$_SESSION['user_id'], 'bill_paid', "Recorded payment for bill #$billId ({$bill['invoice_number']}), method $paymentMethod, amount {$bill['grand_total']}"]);

        header('Location: checkout.php?bill_id=' . $billId);
        exit;
    }
}

// ---------------- Print view (separate render path, no sidebar shell) ----------------
if (isset($_GET['print']) && isset($_GET['bill_id'])) {
    $billId = (int) $_GET['bill_id'];

    $stmt = $pdo->prepare('
        SELECT b.*, v.fee, v.discount_pct, v.token_no,
               p.mrn, p.name AS patient_name, p.father_name, p.dob, p.phone,
               d.name AS doctor_name, d.specialty AS doctor_specialty
        FROM bills b
        JOIN visits v ON v.id = b.visit_id
        JOIN patients p ON p.id = v.patient_id
        JOIN users d ON d.id = v.doctor_id
        WHERE b.id = ?
    ');
    $stmt->execute([$billId]);
    $bill = $stmt->fetch();

    if (!$bill) {
        http_response_code(404);
        die('Invoice not found.');
    }

    $itemsStmt = $pdo->prepare('SELECT * FROM bill_items WHERE bill_id = ? ORDER BY id');
    $itemsStmt->execute([$billId]);
    $items = $itemsStmt->fetchAll();

    $pdo->prepare('UPDATE bills SET printed_by_id = ?, printed_at = NOW() WHERE id = ?')
        ->execute([$_SESSION['user_id'], $billId]);

    include __DIR__ . '/views/invoice_print_partial.php';
    exit;
}

// ---------------- Page data ----------------
$activeBill = null;
$activeBillVisit = null;
$activeBillItems = [];

if (isset($_GET['bill_id'])) {
    $billId = (int) $_GET['bill_id'];
    $stmt = $pdo->prepare('
        SELECT b.*, v.token_no, v.doctor_id, p.name AS patient_name, p.mrn, dr.name AS doctor_name
        FROM bills b
        JOIN visits v ON v.id = b.visit_id
        JOIN patients p ON p.id = v.patient_id
        JOIN users dr ON dr.id = v.doctor_id
        WHERE b.id = ?
    ');
    $stmt->execute([$billId]);
    $activeBill = $stmt->fetch();

    if ($activeBill) {
        $itemsStmt = $pdo->prepare('SELECT * FROM bill_items WHERE bill_id = ? ORDER BY id');
        $itemsStmt->execute([$billId]);
        $activeBillItems = $itemsStmt->fetchAll();
    }
}

// Today's open bills, most recent first. Bills are raised automatically at registration
// (see patients.php), so this list is what's still awaiting items/finalize/payment —
// not un-billed visits, which no longer occur.
$pendingBills = $pdo->query("
    SELECT b.id, b.invoice_number, b.status, b.grand_total,
           v.token_no, p.name AS patient_name, p.mrn, dr.name AS doctor_name, dct.label AS consult_label
    FROM bills b
    JOIN visits v ON v.id = b.visit_id
    JOIN patients p ON p.id = v.patient_id
    JOIN users dr ON dr.id = v.doctor_id
    JOIN doctor_consult_types dct ON dct.id = v.doctor_consult_type_id
    WHERE v.visit_date = CURDATE() AND b.status <> 'paid'
    ORDER BY b.id DESC
")->fetchAll();

$pageTitle = 'Checkout & Billing';
$headExtra = <<<CSS
<style>
.header { height: 64px; display: flex; align-items: center; justify-content: space-between; padding: 0 28px; border-bottom: 1px solid var(--border); background: #fff; }
.header-right { display: flex; align-items: center; gap: 16px; }
.header-date { font-size: 12.5px; color: var(--text-muted); }
.logout-link { font-size: 12.5px; color: var(--text-muted); text-decoration: none; }
.status-pill.draft { background: #FEF3C7; color: #92400E; }
.status-pill.finalized { background: var(--primary-light); color: var(--primary-dark); }
.status-pill.paid { background: var(--green-bg); color: var(--green-text); }
.item-row-form { display: grid; grid-template-columns: 1fr 90px 130px auto; gap: 10px; align-items: end; margin-top: 14px; }
.item-row-form label { display: block; font-size: 11.5px; font-weight: 600; color: var(--text-muted); margin-bottom: 4px; }
.item-row-form input { width: 100%; padding: 9px 11px; border: 1px solid var(--border); border-radius: var(--radius-input); font-size: 13.5px; font-family: inherit; }
.totals-box { margin-top: 16px; padding-top: 14px; border-top: 1px solid var(--border); display: flex; flex-direction: column; gap: 6px; align-items: flex-end; }
.totals-box .row { display: flex; gap: 24px; font-size: 13px; color: var(--text-muted); }
.totals-box .row.grand { font-size: 16px; font-weight: 700; color: var(--text); }
.visit-pick-row { display: flex; align-items: center; justify-content: space-between; padding: 12px 10px; border-top: 1px solid var(--border); }
.visit-pick-row:first-child { border-top: none; }
.empty-state { padding: 32px 10px; text-align: center; color: var(--text-muted); font-size: 13px; }
</style>
CSS;
require __DIR__ . '/partials/head.php';
$navActive = 'checkout';
require __DIR__ . '/partials/sidebar.php';
?>
        <header class="header">
            <div class="page-title" style="font-size:16px;">Checkout &amp; Billing</div>
            <div class="header-right">
                <span class="header-date"><?= date('D, d M Y') ?></span>
                <a class="logout-link" href="logout.php">Logout</a>
            </div>
        </header>

        <div class="content">
            <div class="page-head">
                <div>
                    <div class="page-title">Checkout &amp; Billing</div>
                    <div class="page-sub">Add items, finalize and take payment on today's A5 invoices</div>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="alert error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <?php if (!$activeBill): ?>
            <div class="card">
                <div class="section-title">Open invoices today</div>
                <div class="section-sub"><?= count($pendingBills) ?> awaiting payment</div>
                <?php if (empty($pendingBills)): ?>
                    <div class="empty-state">No open invoices today.</div>
                <?php else: ?>
                    <?php foreach ($pendingBills as $b): ?>
                    <div class="visit-pick-row">
                        <div>
                            <strong><?= htmlspecialchars($b['patient_name']) ?></strong>
                            <span class="muted"> &middot; MRN <?= htmlspecialchars($b['mrn']) ?> &middot; Token #<?= (int) $b['token_no'] ?></span>
                            <div class="muted">Invoice <?= htmlspecialchars($b['invoice_number']) ?> &middot; <?= htmlspecialchars($b['doctor_name']) ?> &middot; <?= htmlspecialchars($b['consult_label']) ?> &middot; Rs <?= number_format((float) $b['grand_total'], 2) ?></div>
                        </div>
                        <div style="display:flex; align-items:center; gap:10px;">
                            <span class="status-pill <?= htmlspecialchars($b['status']) ?>"><?= htmlspecialchars($b['status']) ?></span>
                            <a class="btn small" href="checkout.php?bill_id=<?= (int) $b['id'] ?>">Open</a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <?php else: ?>

            <div class="card">
                <div class="page-head">
                    <div>
                        <div class="section-title"><?= htmlspecialchars($activeBill['patient_name']) ?> &middot; MRN <?= htmlspecialchars($activeBill['mrn']) ?></div>
                        <div class="section-sub">Invoice <?= htmlspecialchars($activeBill['invoice_number']) ?> &middot; <?= htmlspecialchars($activeBill['doctor_name']) ?> &middot; Token #<?= (int) $activeBill['token_no'] ?></div>
                    </div>
                    <span class="status-pill <?= htmlspecialchars($activeBill['status']) ?>"><?= htmlspecialchars($activeBill['status']) ?></span>
                </div>

                <table>
                    <thead>
                        <tr><th>Description</th><th>Qty</th><th>Rate</th><th>Amount</th><?php if ($activeBill['status'] === 'draft'): ?><th></th><?php endif; ?></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($activeBillItems as $item): ?>
                        <tr>
                            <td><?= htmlspecialchars($item['description']) ?></td>
                            <td><?= (int) $item['quantity'] ?></td>
                            <td>Rs <?= number_format((float) $item['unit_rate'], 2) ?></td>
                            <td><strong>Rs <?= number_format((float) $item['amount'], 2) ?></strong></td>
                            <?php if ($activeBill['status'] === 'draft'): ?>
                            <td>
                                <form method="POST" action="checkout.php">
                                    <input type="hidden" name="action" value="remove_item">
                                    <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                    <input type="hidden" name="bill_id" value="<?= (int) $activeBill['id'] ?>">
                                    <button type="submit" class="btn danger small">Remove</button>
                                </form>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($activeBill['status'] === 'draft'): ?>
                <form method="POST" action="checkout.php" class="item-row-form">
                    <input type="hidden" name="action" value="add_item">
                    <input type="hidden" name="bill_id" value="<?= (int) $activeBill['id'] ?>">
                    <div>
                        <label for="description">Description</label>
                        <input type="text" id="description" name="description" required placeholder="e.g. Injection, Dressing">
                    </div>
                    <div>
                        <label for="quantity">Qty</label>
                        <input type="number" id="quantity" name="quantity" value="1" min="1" required>
                    </div>
                    <div>
                        <label for="unit_rate">Rate (Rs)</label>
                        <input type="number" id="unit_rate" name="unit_rate" min="0" step="0.01" required>
                    </div>
                    <button type="submit" class="btn secondary">+ Add Item</button>
                </form>
                <?php endif; ?>

                <div class="totals-box">
                    <div class="row"><span>Subtotal</span><span>Rs <?= number_format((float) $activeBill['subtotal'], 2) ?></span></div>
                    <div class="row grand"><span>Grand Total</span><span>Rs <?= number_format((float) $activeBill['grand_total'], 2) ?></span></div>
                </div>

                <div style="display:flex; gap:10px; margin-top:18px; justify-content:flex-end;">
                    <a class="btn secondary" href="checkout.php">Back to list</a>
                    <?php if ($activeBill['status'] === 'draft'): ?>
                        <form method="POST" action="checkout.php" onsubmit="return confirm('Finalize this invoice? Line items can\'t be edited after this.');">
                            <input type="hidden" name="action" value="finalize_bill">
                            <input type="hidden" name="bill_id" value="<?= (int) $activeBill['id'] ?>">
                            <button type="submit" class="btn">Finalize Invoice</button>
                        </form>
                    <?php elseif ($activeBill['status'] === 'finalized'): ?>
                        <a class="btn secondary" href="checkout.php?print=1&bill_id=<?= (int) $activeBill['id'] ?>" target="_blank">Print Invoice</a>
                        <?php if (has_permission('RECEPTION_PROCESS_PAYMENTS')): ?>
                        <form method="POST" action="checkout.php" style="display:flex; gap:8px; align-items:center;">
                            <input type="hidden" name="action" value="record_payment">
                            <input type="hidden" name="bill_id" value="<?= (int) $activeBill['id'] ?>">
                            <select name="payment_method" required style="padding:9px 11px; border:1px solid var(--border); border-radius:var(--radius-input); font-family:inherit;">
                                <option value="">Payment method...</option>
                                <option value="cash">Cash</option>
                                <option value="card">Card</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="cheque">Cheque</option>
                            </select>
                            <button type="submit" class="btn">Record Payment</button>
                        </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <a class="btn secondary" href="checkout.php?print=1&bill_id=<?= (int) $activeBill['id'] ?>" target="_blank">Print Invoice</a>
                        <?php if (has_permission('RECEPTION_ISSUE_REFUNDS')): ?>
                            <a class="btn secondary" href="refund.php?bill_id=<?= (int) $activeBill['id'] ?>" style="color:var(--red-text);">Refund</a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<script src="assets/js/date-picker.js"></script>
</body>
</html>
