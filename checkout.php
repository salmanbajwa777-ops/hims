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

function clinic_setting(PDO $pdo, string $key, string $default): string {
    $stmt = $pdo->prepare('SELECT setting_value FROM clinic_settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? $row['setting_value'] : $default;
}

// Daily sequential invoice number: "94345 - 2026-07-17 14:03:00" — matches the
// original spec's format. Race-safe under concurrent checkouts via the same
// atomic-upsert + LAST_INSERT_ID() pattern used for visit queue tokens in patients.php.
function generate_invoice_number(PDO $pdo): string {
    $today = date('Y-m-d');
    $now = date('H:i:s');

    $pdo->prepare('
        INSERT INTO invoice_sequences (sequence_date, last_sequence)
        VALUES (?, 94345)
        ON DUPLICATE KEY UPDATE last_sequence = LAST_INSERT_ID(last_sequence) + 1
    ')->execute([$today]);
    $lastId = (int) $pdo->lastInsertId();
    $seq = $lastId > 0 ? $lastId : 94345;

    return $seq . ' - ' . $today . ' ' . $now;
}

function recalc_bill_totals(PDO $pdo, int $billId): void {
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) AS subtotal FROM bill_items WHERE bill_id = ?');
    $stmt->execute([$billId]);
    $subtotal = (float) $stmt->fetch()['subtotal'];

    $taxPct = (float) clinic_setting($pdo, 'sales_tax_percent', '17');
    $consolPct = (float) clinic_setting($pdo, 'consolidation_rate_percent', '2');

    $taxAmount = round($subtotal * ($taxPct / 100), 2);
    $consolAmount = round(($subtotal + $taxAmount) * ($consolPct / 100), 2);
    $grandTotal = round($subtotal + $taxAmount + $consolAmount, 2);

    $pdo->prepare('
        UPDATE bills
        SET subtotal = ?, sales_tax_percent = ?, sales_tax_amount = ?,
            consolidation_rate_percent = ?, consolidation_amount = ?, grand_total = ?
        WHERE id = ?
    ')->execute([$subtotal, $taxPct, $taxAmount, $consolPct, $consolAmount, $grandTotal, $billId]);
}

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

            $invoiceNumber = generate_invoice_number($pdo);
            $taxPct = (float) clinic_setting($pdo, 'sales_tax_percent', '17');
            $consolPct = (float) clinic_setting($pdo, 'consolidation_rate_percent', '2');

            $pdo->prepare('
                INSERT INTO bills (invoice_number, visit_id, sales_tax_percent, consolidation_rate_percent, created_by_id)
                VALUES (?, ?, ?, ?, ?)
            ')->execute([$invoiceNumber, $visitId, $taxPct, $consolPct, $_SESSION['user_id']]);
            $billId = (int) $pdo->lastInsertId();

            $consultFee = (float) $visit['fee'] * (1 - ((float) $visit['discount_pct'] / 100));
            $pdo->prepare('
                INSERT INTO bill_items (bill_id, description, quantity, unit_rate, amount)
                VALUES (?, ?, 1, ?, ?)
            ')->execute([$billId, $visit['consult_label'], $consultFee, $consultFee]);

            recalc_bill_totals($pdo, $billId);

            $log = $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)');
            $log->execute([$_SESSION['user_id'], 'bill_created', "Created bill #$billId (invoice $invoiceNumber) for visit #$visitId"]);

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

    if (!$bill) {
        $error = 'Bill not found or not yet finalized.';
    } elseif (!in_array($paymentMethod, $allowedMethods, true)) {
        $error = 'Please select a valid payment method.';
    } else {
        $pdo->prepare("
            UPDATE bills SET status = 'paid', payment_method = ?, paid_amount = ?, paid_at = NOW()
            WHERE id = ?
        ")->execute([$paymentMethod, $bill['grand_total'], $billId]);

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
        SELECT b.*, v.fee, v.discount_pct, p.name AS patient_name, p.father_name, p.dob,
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

// Today's un-billed visits, most recent first.
$pendingVisits = $pdo->query("
    SELECT v.id, v.token_no, v.fee, v.discount_pct, p.name AS patient_name, p.mrn, dr.name AS doctor_name, dct.label AS consult_label
    FROM visits v
    JOIN patients p ON p.id = v.patient_id
    JOIN users dr ON dr.id = v.doctor_id
    JOIN doctor_consult_types dct ON dct.id = v.doctor_consult_type_id
    LEFT JOIN bills b ON b.visit_id = v.id
    WHERE b.id IS NULL AND v.visit_date = CURDATE()
    ORDER BY v.created_at DESC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Checkout &amp; Billing - HIMS</title>
<style>
:root {
    --primary-dark: #1E3A8A;
    --primary: #2563EB;
    --primary-light: #DBEAFE;
    --bg: #F8FAFC;
    --card: #FFFFFF;
    --border: #E2E8F0;
    --text: #1E293B;
    --text-muted: #94A3B8;
    --red-bg: #FEE2E2;
    --red-text: #B91C1C;
    --green-bg: #DCFCE7;
    --green-text: #15803D;
    --radius-input: 10px;
    --radius-btn: 10px;
}
* { box-sizing: border-box; }
body { font-family: 'Inter', system-ui, -apple-system, "Segoe UI", sans-serif; background: var(--bg); color: var(--text); font-size: 14px; line-height: 1.5; margin: 0; }
.app { display: grid; grid-template-columns: 236px 1fr; min-height: 100vh; }
.sidebar { background: #fff; border-right: 1px solid var(--border); padding: 20px 14px; }
.sidebar-brand { display: flex; align-items: center; gap: 10px; font-weight: 700; font-size: 15px; padding: 0 8px 20px; }
.logo-mark { width: 34px; height: 34px; border-radius: 10px; background: linear-gradient(135deg, var(--primary-dark), var(--primary)); display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 700; font-size: 14px; }
.nav-group { margin-bottom: 18px; }
.nav-group-label { font-size: 11px; font-weight: 600; letter-spacing: .06em; color: var(--text-muted); padding: 0 12px 8px; text-transform: uppercase; }
.nav-item { display: flex; align-items: center; gap: 10px; padding: 9px 12px; border-radius: 8px; color: var(--text); text-decoration: none; font-size: 13.5px; font-weight: 500; }
.nav-item.active { background: var(--primary-light); color: var(--primary-dark); font-weight: 600; }
.main { display: flex; flex-direction: column; }
.header { height: 64px; display: flex; align-items: center; justify-content: space-between; padding: 0 28px; border-bottom: 1px solid var(--border); background: #fff; }
.header-right { display: flex; align-items: center; gap: 16px; }
.header-date { font-size: 12.5px; color: var(--text-muted); }
.logout-link { font-size: 12.5px; color: var(--text-muted); text-decoration: none; }
.content { padding: 24px 28px; display: flex; flex-direction: column; gap: 20px; }
.page-head { display: flex; align-items: flex-start; justify-content: space-between; }
.page-title { font-size: 20px; font-weight: 700; }
.page-sub { font-size: 13px; color: var(--text-muted); margin-top: 2px; }
.card { background: var(--card); border: 1px solid var(--border); border-radius: 14px; padding: 20px; }
.section-title { font-size: 14.5px; font-weight: 700; margin-bottom: 4px; }
.section-sub { font-size: 12.5px; color: var(--text-muted); margin-bottom: 16px; }
table { width: 100%; border-collapse: collapse; }
th { text-align: left; font-size: 11.5px; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted); padding: 0 10px 10px; font-weight: 600; }
td { padding: 10px; border-top: 1px solid var(--border); font-size: 13.5px; }
.muted { color: var(--text-muted); font-size: 12.5px; }
.btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: var(--radius-btn); border: none; background: linear-gradient(135deg, var(--primary-dark), var(--primary)); color: #fff; font-size: 13.5px; font-weight: 600; cursor: pointer; font-family: inherit; }
.btn.secondary { background: var(--card); color: var(--text); border: 1px solid var(--border); }
.btn.small { padding: 6px 12px; font-size: 12.5px; }
.btn.danger { background: var(--red-bg); color: var(--red-text); }
.alert { padding: 12px 16px; border-radius: 10px; font-size: 13px; }
.alert.error { background: var(--red-bg); color: var(--red-text); }
.alert.success { background: var(--green-bg); color: var(--green-text); }
.status-pill { display: inline-flex; padding: 3px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; }
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
</head>
<body>
<div class="app">
    <aside class="sidebar">
        <div class="sidebar-brand"><div class="logo-mark">H</div>HIMS</div>
        <div class="nav-group">
            <div class="nav-group-label">Overview</div>
            <a class="nav-item" href="dashboard.php"><span class="nav-icon">▦</span> Dashboard</a>
        </div>
        <div class="nav-group">
            <div class="nav-group-label">Reception</div>
            <a class="nav-item" href="patients.php"><span class="nav-icon">👥</span> Patients</a>
            <a class="nav-item active" href="checkout.php"><span class="nav-icon">🧾</span> Checkout &amp; Billing</a>
        </div>
        <?php if (($_SESSION['base_role'] ?? '') === 'ADMIN'): ?>
        <div class="nav-group">
            <div class="nav-group-label">Management</div>
            <a class="nav-item" href="staff.php"><span class="nav-icon">🩺</span> Staff &amp; Doctors</a>
            <a class="nav-item" href="locations.php"><span class="nav-icon">📍</span> Cities &amp; Areas</a>
        </div>
        <?php endif; ?>
    </aside>

    <div class="main">
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
                    <div class="page-sub">Generate and print A5 invoices for today's visits</div>
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
                <div class="section-title">Unbilled visits today</div>
                <div class="section-sub"><?= count($pendingVisits) ?> waiting for checkout</div>
                <?php if (empty($pendingVisits)): ?>
                    <div class="empty-state">No unbilled visits today.</div>
                <?php else: ?>
                    <?php foreach ($pendingVisits as $v): ?>
                    <div class="visit-pick-row">
                        <div>
                            <strong><?= htmlspecialchars($v['patient_name']) ?></strong>
                            <span class="muted"> &middot; MRN <?= htmlspecialchars($v['mrn']) ?> &middot; Token #<?= (int) $v['token_no'] ?></span>
                            <div class="muted"><?= htmlspecialchars($v['doctor_name']) ?> &middot; <?= htmlspecialchars($v['consult_label']) ?> &middot; Rs <?= number_format((float) $v['fee'], 2) ?><?= $v['discount_pct'] > 0 ? ' (' . $v['discount_pct'] . '% discount)' : '' ?></div>
                        </div>
                        <form method="POST" action="checkout.php">
                            <input type="hidden" name="action" value="start_checkout">
                            <input type="hidden" name="visit_id" value="<?= (int) $v['id'] ?>">
                            <button type="submit" class="btn small">Start Checkout</button>
                        </form>
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
                    <div class="row"><span>Sales Tax (<?= $activeBill['sales_tax_percent'] ?>%)</span><span>Rs <?= number_format((float) $activeBill['sales_tax_amount'], 2) ?></span></div>
                    <div class="row"><span>Consolidation (<?= $activeBill['consolidation_rate_percent'] ?>%)</span><span>Rs <?= number_format((float) $activeBill['consolidation_amount'], 2) ?></span></div>
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
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
