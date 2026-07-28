<?php
/**
 * Dental package account — the workbench.
 *
 * THE FINANCIAL CORE of the dental module. An account is an itemised quote for
 * multi-visit work, paid down over months.
 *
 * THE NO-TOTAL RULE: there is no stored total anywhere. What the patient owes is
 * always SUM(live items) + SUM(live lab charges) − SUM(live payments), computed
 * by dental_account_totals() on every read. A stored total is a second source of
 * truth that drifts the first time an item is voided and a recompute is missed,
 * and on a figure the patient signed for, drift is a dispute.
 *
 * WHO CAN DO WHAT (the split that matters):
 *   Reception SEES the whole breakdown and TAKES money against it, but cannot
 *   add or void an item — that moves a quoted total the patient agreed to, so
 *   it is the dentist's call. Two permissions, not one.
 *
 * BALANCE NEVER BLOCKS TREATMENT. Nothing here refuses an action because money
 * is owed. The page shows what is outstanding; whether to proceed is a
 * front-desk policy call, not a database lock.
 *
 * CANCELLED freezes the account by REFUSING FURTHER WRITES rather than storing
 * a snapshot — that is how the balance stops moving without breaking the
 * no-total rule. If the patient had paid ahead, the balance is negative and
 * surfaces as REFUND DUE instead of being swallowed.
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

$userId = (int) $_SESSION['user_id'];
$accountId = (int) ($_GET['id'] ?? $_POST['account_id'] ?? 0);
if ($accountId <= 0) {
    header('Location: dental_accounts.php');
    exit;
}

$error = '';
$success = '';
if (isset($_GET['opened'])) { $success = 'Package account opened. Add the quoted items below.'; }
if (isset($_GET['paid']))   { $success = 'Payment recorded.'; }

$canEditItems = has_permission('DENTAL_EDIT_ACCOUNT_ITEMS');
$canTakeMoney = has_permission('DENTAL_TAKE_ACCOUNT_PAYMENT');
$canCancel    = has_permission('DENTAL_CANCEL_ACCOUNT');

// ---- Print a payment receipt ----
// Handled before head.php: the partial is a standalone A5 document.
if (isset($_GET['print_receipt'])) {
    $payId = (int) $_GET['print_receipt'];
    $s = $pdo->prepare('
        SELECT p.*, a.account_number, a.title, a.patient_id, a.doctor_id,
               pt.mrn, pt.name AS patient_name, pt.father_name, pt.dob, pt.phone,
               d.name AS doctor_name, d.specialty AS doctor_specialty,
               rb.name AS received_by_name
          FROM dental_procedure_payments p
          JOIN dental_procedure_accounts a ON a.id = p.account_id
          JOIN patients pt ON pt.id = a.patient_id
          LEFT JOIN users d  ON d.id = a.doctor_id
          LEFT JOIN users rb ON rb.id = p.paid_by_id
         WHERE p.id = ? AND p.account_id = ?
    ');
    $s->execute([$payId, $accountId]);
    $payment = $s->fetch();
    if (!$payment) {
        http_response_code(404);
        die('Receipt not found.');
    }
    // Position as at THIS receipt: everything paid up to and including it, so a
    // reprint months later still shows what the patient was told at the time.
    $s = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM dental_procedure_payments
                         WHERE account_id = ? AND status = 'paid' AND voided_at IS NULL AND id <= ?");
    $s->execute([$accountId, $payId]);
    $paidThrough = (float) $s->fetchColumn();

    $totals = dental_account_totals($pdo, $accountId);
    $accountTotal   = $totals['total'];
    $previouslyPaid = $paidThrough - (float) $payment['amount'];
    $balanceAfter   = $accountTotal - $paidThrough;

    if (!$payment['printed_at']) {
        $pdo->prepare('UPDATE dental_procedure_payments SET printed_at = NOW(), printed_by_id = ? WHERE id = ?')
            ->execute([$userId, $payId]);
    }
    require __DIR__ . '/views/dental_account_receipt_partial.php';
    exit;
}

// ---- Print the full itemised statement ----
if (isset($_GET['print_statement'])) {
    $s = $pdo->prepare('
        SELECT a.*, pt.mrn, pt.name AS patient_name, pt.father_name, pt.dob, pt.phone,
               d.name AS doctor_name, d.specialty AS doctor_specialty
          FROM dental_procedure_accounts a
          JOIN patients pt ON pt.id = a.patient_id
          LEFT JOIN users d ON d.id = a.doctor_id
         WHERE a.id = ?
    ');
    $s->execute([$accountId]);
    $account = $s->fetch();
    if (!$account) { http_response_code(404); die('Account not found.'); }

    $s = $pdo->prepare('SELECT * FROM dental_procedure_account_items
                         WHERE account_id = ? AND voided_at IS NULL ORDER BY id');
    $s->execute([$accountId]);
    $items = $s->fetchAll();

    $s = $pdo->prepare("SELECT * FROM dental_procedure_payments
                         WHERE account_id = ? AND status = 'paid' AND voided_at IS NULL ORDER BY paid_at");
    $s->execute([$accountId]);
    $payments = $s->fetchAll();

    $totals = dental_account_totals($pdo, $accountId);
    require __DIR__ . '/views/dental_account_statement_partial.php';
    exit;
}

// ---- Load the account (needed by every handler below) ----
$s = $pdo->prepare('
    SELECT a.*, pt.mrn, pt.name AS patient_name, pt.father_name, pt.phone, pt.dob,
           d.name AS doctor_name, d.specialty AS doctor_specialty,
           ob.name AS opened_by_name, cb.name AS cancelled_by_name
      FROM dental_procedure_accounts a
      JOIN patients pt ON pt.id = a.patient_id
      LEFT JOIN users d  ON d.id = a.doctor_id
      LEFT JOIN users ob ON ob.id = a.opened_by_id
      LEFT JOIN users cb ON cb.id = a.cancelled_by_id
     WHERE a.id = ?
');
$s->execute([$accountId]);
$account = $s->fetch();
if (!$account) {
    http_response_code(404);
    die('Account not found.');
}
$isOpen = $account['status'] === 'OPEN';

// ---- Add an item ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_item') {
    if (!$canEditItems) {
        $error = 'You do not have permission to change what this package contains.';
    } elseif (!$isOpen) {
        $error = 'This account is ' . strtolower($account['status']) . ' — its items can no longer change.';
    } else {
        $procId  = (int) ($_POST['procedure_master_id'] ?? 0);
        $tooth   = trim($_POST['tooth_fdi'] ?? '');
        $qty     = max(1, (int) ($_POST['quantity'] ?? 1));
        $labIn   = (float) ($_POST['lab_charge'] ?? 0);

        if ($tooth !== '' && !is_valid_fdi($tooth)) {
            $error = 'That is not a valid FDI tooth number.';
        } elseif ($procId <= 0) {
            $error = 'Pick a procedure.';
        } else {
            // Rate AND share are re-read from the database inside the handler,
            // never trusted from the POST. Resolved through doctor_procedures
            // for THIS account's dentist, so an item can only be added for a
            // procedure that dentist actually performs — which is also what
            // guarantees the share snapshot has a real source.
            //
            // This join is ALSO what keeps consultation fees out of a package:
            // a consultation lives in consult_types and has no procedure_master
            // row, so there is no way to select one here.
            $s = $pdo->prepare('
                SELECT pm.id, pm.name, pm.mandatory_consent, pm.has_lab_component,
                       pm.default_lab_charge,
                       COALESCE(dp.fee, pm.fee) AS fee,
                       dp.doctor_share_pct, dp.has_tax, dp.tax_percent
                  FROM doctor_procedures dp
                  JOIN procedure_master pm ON pm.id = dp.procedure_master_id
                 WHERE dp.doctor_id = ? AND dp.procedure_master_id = ?
                   AND dp.is_active = 1 AND pm.is_active = 1 AND pm.is_dental = 1
            ');
            $s->execute([(int) $account['doctor_id'], $procId]);
            $proc = $s->fetch();

            if (!$proc) {
                $error = 'That procedure is not assigned to this dentist, or is no longer active.';
            } elseif ((int) $proc['mandatory_consent'] === 1
                      && !dental_consent_satisfied($pdo, (int) $account['patient_id'], $procId, $accountId)) {
                $error = '“' . $proc['name'] . '” requires a signed consent before it can be quoted. '
                       . 'Capture it on the Consent page first.';
            } else {
                // The account's snapshotted discount, not the patient's current
                // one — a category change must not re-price a signed quote.
                $discountPct = (float) $account['discount_pct'];
                $unitRate = (float) $proc['fee'];
                $amount   = round($unitRate * $qty * (1 - $discountPct / 100), 2);
                // Lab is only accepted for a procedure actually flagged as
                // sending work out, re-read from the DB — the same hardening
                // procedure_bill.php applies to disposables.
                $labCharge = ((int) $proc['has_lab_component'] === 1) ? max(0.0, $labIn) : 0.0;

                try {
                    $pdo->prepare('
                        INSERT INTO dental_procedure_account_items
                            (account_id, procedure_master_id, description, tooth_fdi, quantity,
                             unit_rate, amount, lab_charge, doctor_share_pct, has_tax, tax_percent, added_by_id)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ')->execute([
                        $accountId, $procId, $proc['name'], $tooth !== '' ? $tooth : null, $qty,
                        $unitRate, $amount, $labCharge,
                        (float) $proc['doctor_share_pct'], (int) $proc['has_tax'], (float) $proc['tax_percent'],
                        $userId,
                    ]);
                    $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
                        ->execute([$userId, 'dental_account_item_added',
                                   "Added \"{$proc['name']}\"" . ($tooth !== '' ? " tooth $tooth" : '')
                                   . " Rs $amount" . ($labCharge > 0 ? " + lab Rs $labCharge" : '')
                                   . " to account {$account['account_number']}"]);
                    $success = 'Item added. The package total has been updated.';
                } catch (PDOException $e) {
                    error_log('[dental_account add_item] ' . $e->getMessage());
                    $error = 'Could not add the item. Please try again.';
                }
            }
        }
    }
}

// ---- Void an item ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'void_item') {
    if (!$canEditItems) {
        $error = 'You do not have permission to change what this package contains.';
    } else {
        [$ok, $msg] = void_dental_account_item($pdo, (int) ($_POST['item_id'] ?? 0), $userId,
                                               (string) ($_POST['void_reason'] ?? ''));
        $ok ? $success = $msg : $error = $msg;
    }
}

// ---- Take a payment ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'take_payment') {
    if (!$canTakeMoney) {
        $error = 'You do not have permission to take payments.';
    } elseif (!$isOpen) {
        $error = 'This account is ' . strtolower($account['status']) . ' — no further payments can be taken.';
    } else {
        // The day lock comes FIRST: money must not move on a shift that has
        // already been counted and signed off.
        $dayLock = require_day_open($pdo);
        $amount  = round((float) ($_POST['amount'] ?? 0), 2);
        $method  = $_POST['payment_method'] ?? 'cash';
        $notes   = trim($_POST['notes'] ?? '');

        if ($dayLock) {
            $error = $dayLock;
        } elseif ($amount <= 0) {
            $error = 'Enter an amount greater than zero.';
        } elseif (!in_array($method, ['cash', 'card', 'bank_transfer', 'cheque'], true)) {
            $error = 'Pick a valid payment method.';
        } else {
            $pdo->beginTransaction();
            try {
                $receiptNumber = generate_dental_payment_number($pdo);
                $pdo->prepare('
                    INSERT INTO dental_procedure_payments
                        (receipt_number, account_id, amount, payment_method, status,
                         paid_at, paid_by_id, created_by_id, notes)
                    VALUES (?, ?, ?, ?, \'paid\', NOW(), ?, ?, ?)
                ')->execute([$receiptNumber, $accountId, $amount, $method, $userId, $userId,
                             $notes !== '' ? $notes : null]);
                $payId = (int) $pdo->lastInsertId();
                $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
                    ->execute([$userId, 'dental_account_payment_taken',
                               "Receipt $receiptNumber — Rs $amount ($method) against account {$account['account_number']}"]);
                $pdo->commit();
                // Straight to the receipt, mirroring how every other bill in the
                // app settles: take the money, hand over the paper.
                header('Location: dental_account.php?id=' . $accountId . '&print_receipt=' . $payId);
                exit;
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                error_log('[dental_account take_payment] ' . $e->getMessage());
                $error = 'Could not record the payment. Please try again.';
            }
        }
    }
}

// ---- Void a payment ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'void_payment') {
    if (!$canTakeMoney && ($_SESSION['base_role'] ?? '') !== 'ADMIN') {
        $error = 'You do not have permission to void a payment.';
    } else {
        [$ok, $msg] = void_dental_payment($pdo, (int) ($_POST['payment_id'] ?? 0), $userId,
                                          (string) ($_POST['void_reason'] ?? ''));
        $ok ? $success = $msg : $error = $msg;
    }
}

// ---- Settle / cancel ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'settle_account') {
    $t = dental_account_totals($pdo, $accountId);
    if (!$isOpen) {
        $error = 'This account is already ' . strtolower($account['status']) . '.';
    } elseif ($t['balance'] > 0.004) {
        $error = 'Rs ' . number_format($t['balance'], 2) . ' is still outstanding — settle the balance first.';
    } else {
        $pdo->prepare("UPDATE dental_procedure_accounts
                          SET status = 'SETTLED', settled_at = NOW(), settled_by_id = ?
                        WHERE id = ? AND status = 'OPEN'")->execute([$userId, $accountId]);
        $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
            ->execute([$userId, 'dental_account_settled', "Settled account {$account['account_number']}"]);
        $success = 'Account settled.';
        $account['status'] = 'SETTLED';
        $isOpen = false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_account') {
    $reason = trim($_POST['cancel_reason'] ?? '');
    if (!$canCancel) {
        $error = 'You do not have permission to cancel an account.';
    } elseif ($reason === '') {
        $error = 'A cancellation needs a reason.';
    } elseif (!$isOpen) {
        $error = 'This account is already ' . strtolower($account['status']) . '.';
    } else {
        $t = dental_account_totals($pdo, $accountId);
        $pdo->prepare("UPDATE dental_procedure_accounts
                          SET status = 'CANCELLED', cancelled_at = NOW(), cancelled_by_id = ?, cancel_reason = ?
                        WHERE id = ? AND status = 'OPEN'")->execute([$userId, $reason, $accountId]);
        // The overpayment is named in the audit trail, not just shown on screen:
        // a refund that has to be raised by hand needs a record that says why.
        $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
            ->execute([$userId, 'dental_account_cancelled',
                       "Cancelled account {$account['account_number']} — $reason"
                       . ($t['overpaid'] > 0 ? " — OVERPAID Rs {$t['overpaid']}, refund due" : '')]);
        $success = 'Account cancelled. The balance is frozen.'
                 . ($t['overpaid'] > 0 ? ' A refund of Rs ' . number_format($t['overpaid'], 2) . ' is due.' : '');
        $account['status'] = 'CANCELLED';
        $isOpen = false;
    }
}

// ---- Page data ----
$totals = dental_account_totals($pdo, $accountId);

$s = $pdo->prepare('
    SELECT i.*, ab.name AS added_by_name, vb.name AS voided_by_name
      FROM dental_procedure_account_items i
      LEFT JOIN users ab ON ab.id = i.added_by_id
      LEFT JOIN users vb ON vb.id = i.voided_by_id
     WHERE i.account_id = ?
     ORDER BY i.id
');
$s->execute([$accountId]);
$items = $s->fetchAll();

$s = $pdo->prepare('
    SELECT p.*, rb.name AS received_by_name, vb.name AS voided_by_name
      FROM dental_procedure_payments p
      LEFT JOIN users rb ON rb.id = p.paid_by_id
      LEFT JOIN users vb ON vb.id = p.voided_by_id
     WHERE p.account_id = ?
     ORDER BY p.paid_at DESC, p.id DESC
');
$s->execute([$accountId]);
$payments = $s->fetchAll();

$s = $pdo->prepare('
    SELECT c.*, u.name AS captured_by_name
      FROM dental_consents c
      LEFT JOIN users u ON u.id = c.captured_by_id
     WHERE c.account_id = ? AND c.voided_at IS NULL
     ORDER BY c.id DESC
');
$s->execute([$accountId]);
$consents = $s->fetchAll();

$labRows = [];
try {
    $s = $pdo->prepare('SELECT * FROM dental_lab_work WHERE account_id = ? AND voided_at IS NULL ORDER BY id DESC');
    $s->execute([$accountId]);
    $labRows = $s->fetchAll();
} catch (PDOException $e) { /* lab table absent */ }

// The pickable procedures: this dentist's dental assignments only.
$pickable = [];
if ($canEditItems && $isOpen) {
    $s = $pdo->prepare('
        SELECT pm.id, pm.name, pm.category, pm.mandatory_consent, pm.has_lab_component,
               pm.default_lab_charge, COALESCE(dp.fee, pm.fee) AS fee
          FROM doctor_procedures dp
          JOIN procedure_master pm ON pm.id = dp.procedure_master_id
         WHERE dp.doctor_id = ? AND dp.is_active = 1 AND pm.is_active = 1 AND pm.is_dental = 1
         ORDER BY pm.category IS NULL, pm.category, pm.name
    ');
    $s->execute([(int) $account['doctor_id']]);
    $pickable = $s->fetchAll();
}

$pageTitle = 'Account ' . $account['account_number'];
$headExtra = <<<CSS
<style>
.money-strip { display:grid; grid-template-columns:repeat(4,1fr); gap:1px; background:var(--border);
  border:1px solid var(--border); border-radius:var(--radius-card); overflow:hidden; margin-bottom:16px; }
.money-cell { background:var(--card); padding:14px 16px; }
.money-cell .k { font-size:var(--fs-eyebrow); text-transform:uppercase; letter-spacing:.06em;
  color:var(--text-muted); font-weight:700; }
.money-cell .v { font-size:var(--fs-kpi); font-weight:700; margin-top:4px; font-variant-numeric:tabular-nums; }
.money-cell .s { font-size:var(--fs-micro); color:var(--text-muted); margin-top:2px; }
.v.owed { color:var(--warn); }
.v.clear { color:var(--ok); }
.v.refund { color:var(--danger); }
.item-row.voided { opacity:.5; }
.item-row.voided .item-name { text-decoration:line-through; }
.tooth-pill { display:inline-block; min-width:32px; text-align:center; padding:2px 6px;
  border-radius:var(--radius-pill); background:var(--primary-light); color:var(--primary-dark);
  font-weight:700; font-size:var(--fs-pill); }
.tooth-pill.none { background:var(--card-alt); color:var(--text-muted); }
.void-tag { color:var(--danger); font-size:var(--fs-micro); font-weight:700; margin-top:3px; }
.lab-tag { font-size:var(--fs-micro); color:var(--text-secondary); }
.add-grid { display:grid; grid-template-columns:2fr 1fr 80px 1fr auto; gap:10px; align-items:end; }
.pay-grid { display:grid; grid-template-columns:1fr 1fr 2fr auto; gap:10px; align-items:end; }
.note-line { font-size:var(--fs-meta); color:var(--text-secondary); }
.frozen { background:var(--warn-bg); border-left:3px solid var(--warn); padding:10px 14px;
  border-radius:var(--radius-btn); margin-bottom:16px; font-size:var(--fs-body); }
.refund-due { background:var(--danger-bg); border-left:3px solid var(--danger); padding:10px 14px;
  border-radius:var(--radius-btn); margin-bottom:16px; font-size:var(--fs-body); }
.sep-note { border-top:1px solid var(--row-line); margin-top:14px; padding-top:10px;
  font-size:var(--fs-meta); color:var(--text-muted); }
@media (max-width:900px) {
  .money-strip { grid-template-columns:repeat(2,1fr); }
  .add-grid, .pay-grid { grid-template-columns:1fr; }
}
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
                    <div class="page-title"><?= htmlspecialchars($account['account_number']) ?> — <?= htmlspecialchars($account['title']) ?></div>
                    <div class="page-sub">
                        <?= htmlspecialchars($account['patient_name']) ?> (<?= htmlspecialchars($account['mrn']) ?>)
                        · <?= htmlspecialchars($account['doctor_name'] ?? '—') ?>
                        · opened <?= date('d/m/Y', strtotime($account['opened_at'])) ?>
                        <?= $account['opened_by_name'] ? ' by ' . htmlspecialchars($account['opened_by_name']) : '' ?>
                    </div>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <a class="btn secondary small" href="dental_account.php?id=<?= $accountId ?>&print_statement=1" target="_blank">Print statement</a>
                    <a class="btn secondary small" href="dental_accounts.php">All accounts</a>
                </div>
            </div>

            <?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($success): ?><div class="alert success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

            <?php if ($account['status'] === 'CANCELLED'): ?>
            <div class="frozen">
                <b>This account is cancelled.</b> Its balance is frozen — no further items or payments can be recorded.
                <?= $account['cancel_reason'] ? '<br>Reason: ' . htmlspecialchars($account['cancel_reason']) : '' ?>
                <?= $account['cancelled_by_name'] ? ' (' . htmlspecialchars($account['cancelled_by_name']) . ', ' . date('d/m/Y', strtotime($account['cancelled_at'])) . ')' : '' ?>
            </div>
            <?php elseif ($account['status'] === 'SETTLED'): ?>
            <div class="frozen" style="background:var(--ok-bg);border-left-color:var(--ok);">
                <b>This account is settled.</b> The balance was cleared on <?= $account['settled_at'] ? date('d/m/Y', strtotime($account['settled_at'])) : '—' ?>.
            </div>
            <?php endif; ?>

            <?php if ($totals['overpaid'] > 0.004): ?>
            <div class="refund-due">
                <b>Refund due: Rs <?= number_format($totals['overpaid'], 2) ?>.</b>
                The patient has paid more than this package now totals.
                Raise the refund at the counter — it is not issued automatically, because a refund
                is tied to a bill and a package is a ledger.
            </div>
            <?php endif; ?>

            <!-- ==== The money position ==== -->
            <div class="money-strip">
                <div class="money-cell">
                    <div class="k">Procedures</div>
                    <div class="v">Rs <?= number_format($totals['charged'], 2) ?></div>
                    <div class="s"><?= $totals['items'] ?> live item<?= $totals['items'] === 1 ? '' : 's' ?></div>
                </div>
                <div class="money-cell">
                    <div class="k">Lab charges</div>
                    <div class="v">Rs <?= number_format($totals['lab'], 2) ?></div>
                    <div class="s">charged on top, never buried</div>
                </div>
                <div class="money-cell">
                    <div class="k">Package total</div>
                    <div class="v">Rs <?= number_format($totals['total'], 2) ?></div>
                    <div class="s">paid Rs <?= number_format($totals['paid'], 2) ?> · <?= $totals['payments'] ?> receipt<?= $totals['payments'] === 1 ? '' : 's' ?></div>
                </div>
                <div class="money-cell">
                    <div class="k">Balance</div>
                    <?php if ($totals['balance'] > 0.004): ?>
                    <div class="v owed">Rs <?= number_format($totals['balance'], 2) ?></div>
                    <div class="s">outstanding</div>
                    <?php elseif ($totals['balance'] < -0.004): ?>
                    <div class="v refund">Rs <?= number_format(-$totals['balance'], 2) ?></div>
                    <div class="s">refund due</div>
                    <?php else: ?>
                    <div class="v clear">Clear</div>
                    <div class="s">nothing outstanding</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ==== Items ==== -->
            <div class="card">
                <div class="section-title">Quoted items</div>
                <div class="section-sub">
                    The total is always the sum of these lines — nothing is stored separately.
                    <?php if (!$canEditItems): ?>
                    <br><b>You can see the full breakdown but not change it</b> — items are the dentist's to add or void.
                    <?php endif; ?>
                </div>

                <?php if (!$items): ?>
                <div class="empty" style="margin-top:14px;">No items quoted yet.</div>
                <?php else: ?>
                <div style="overflow-x:auto;margin-top:12px;">
                <table>
                    <thead>
                        <tr>
                            <th style="width:70px;">Tooth</th>
                            <th>Procedure</th>
                            <th style="width:60px;" class="text-right">Qty</th>
                            <th style="width:110px;" class="text-right">Rate</th>
                            <th style="width:120px;" class="text-right">Amount</th>
                            <th style="width:110px;" class="text-right">Lab</th>
                            <?php if ($canEditItems): ?><th style="width:70px;"></th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $it): $v = $it['voided_at'] !== null; ?>
                        <tr class="item-row <?= $v ? 'voided' : '' ?>">
                            <td>
                                <?php if ($it['tooth_fdi']): ?>
                                <span class="tooth-pill" title="<?= htmlspecialchars(fdi_label($it['tooth_fdi'])) ?>"><?= htmlspecialchars($it['tooth_fdi']) ?></span>
                                <?php else: ?><span class="tooth-pill none">—</span><?php endif; ?>
                            </td>
                            <td>
                                <span class="item-name" style="font-weight:600;"><?= htmlspecialchars($it['description']) ?></span>
                                <?php if ($v): ?>
                                <div class="void-tag">VOIDED<?= $it['void_reason'] ? ' — ' . htmlspecialchars($it['void_reason']) : '' ?><?= $it['voided_by_name'] ? ' (' . htmlspecialchars($it['voided_by_name']) . ')' : '' ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-right"><?= (int) $it['quantity'] ?></td>
                            <td class="text-right">Rs <?= number_format((float) $it['unit_rate'], 2) ?></td>
                            <td class="text-right">Rs <?= number_format((float) $it['amount'], 2) ?></td>
                            <td class="text-right"><?= (float) $it['lab_charge'] > 0 ? 'Rs ' . number_format((float) $it['lab_charge'], 2) : '—' ?></td>
                            <?php if ($canEditItems): ?>
                            <td>
                                <?php if (!$v && $isOpen): ?>
                                <form method="POST" action="dental_account.php" data-no-once
                                      onsubmit="var r=prompt('Reason for voiding this item?'); if(!r){return false;} this.void_reason.value=r;">
                                    <input type="hidden" name="action" value="void_item">
                                    <input type="hidden" name="account_id" value="<?= $accountId ?>">
                                    <input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>">
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

                <?php if ($canEditItems && $isOpen): ?>
                <?php if (!$pickable): ?>
                <div class="alert error" style="margin-top:14px;">
                    No dental procedures are assigned to <?= htmlspecialchars($account['doctor_name'] ?? 'this dentist') ?>.
                    An admin assigns them on <a href="procedure_master.php">Procedures</a>.
                </div>
                <?php else: ?>
                <form method="POST" action="dental_account.php" style="margin-top:16px;border-top:1px solid var(--row-line);padding-top:14px;">
                    <input type="hidden" name="action" value="add_item">
                    <input type="hidden" name="account_id" value="<?= $accountId ?>">
                    <div class="add-grid">
                        <div class="field" style="margin:0;">
                            <label>Procedure</label>
                            <select name="procedure_master_id" id="itemProc" required>
                                <option value="">— select —</option>
                                <?php
                                $lastCat = '__none__';
                                foreach ($pickable as $p):
                                    $cat = $p['category'] ?: 'Uncategorised';
                                    if ($cat !== $lastCat) {
                                        if ($lastCat !== '__none__') { echo '</optgroup>'; }
                                        echo '<optgroup label="' . htmlspecialchars(DENTAL_CAT_LABELS[$p['category']] ?? 'Uncategorised') . '">';
                                        $lastCat = $cat;
                                    }
                                ?>
                                <option value="<?= (int) $p['id'] ?>"
                                        data-lab="<?= (int) $p['has_lab_component'] ?>"
                                        data-labfee="<?= htmlspecialchars((string) $p['default_lab_charge']) ?>"
                                        data-consent="<?= (int) $p['mandatory_consent'] ?>">
                                    <?= htmlspecialchars($p['name']) ?> — Rs <?= number_format((float) $p['fee'], 2) ?>
                                </option>
                                <?php endforeach; if ($lastCat !== '__none__') { echo '</optgroup>'; } ?>
                            </select>
                        </div>
                        <div class="field" style="margin:0;">
                            <label>Tooth (FDI)</label>
                            <?= fdi_select_html('tooth_fdi') ?>
                        </div>
                        <div class="field" style="margin:0;">
                            <label>Qty</label>
                            <input type="number" name="quantity" min="1" value="1">
                        </div>
                        <div class="field" style="margin:0;">
                            <label>Lab charge (Rs)</label>
                            <input type="number" step="0.01" min="0" name="lab_charge" id="itemLab" value="0" disabled>
                            <div class="hint" id="labHint">Only for lab procedures.</div>
                        </div>
                        <button type="submit" class="btn">Add item</button>
                    </div>
                    <?php if ((float) $account['discount_pct'] > 0): ?>
                    <div class="note-line" style="margin-top:8px;">
                        A <?= number_format((float) $account['discount_pct'], 2) ?>% discount was snapshotted when this
                        account opened and is applied to every item — a later category change will not re-price it.
                    </div>
                    <?php endif; ?>
                </form>
                <?php endif; ?>
                <?php endif; ?>

                <div class="sep-note">
                    Consultation fees are billed separately at checkout and never appear on a package account.
                </div>
            </div>

            <!-- ==== Payments ==== -->
            <div class="card">
                <div class="section-title">Payments</div>
                <div class="section-sub">Partial payments across visits. Each one prints its own receipt.</div>

                <?php if (!$payments): ?>
                <div class="empty" style="margin-top:14px;">Nothing paid yet.</div>
                <?php else: ?>
                <div style="overflow-x:auto;margin-top:12px;">
                <table>
                    <thead>
                        <tr>
                            <th style="width:130px;">Receipt</th>
                            <th style="width:150px;">When</th>
                            <th>Taken by</th>
                            <th style="width:120px;">Method</th>
                            <th style="width:130px;" class="text-right">Amount</th>
                            <th style="width:150px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $methodLabels = ['cash' => 'Cash', 'card' => 'Online / Card',
                                         'bank_transfer' => 'Bank Transfer', 'cheque' => 'Cheque'];
                        foreach ($payments as $p): $v = $p['voided_at'] !== null || $p['status'] === 'voided'; ?>
                        <tr class="item-row <?= $v ? 'voided' : '' ?>">
                            <td style="font-weight:700;"><?= htmlspecialchars($p['receipt_number']) ?></td>
                            <td><?= date('d/m/Y H:i', strtotime($p['paid_at'])) ?></td>
                            <td>
                                <?= htmlspecialchars($p['received_by_name'] ?? '—') ?>
                                <?php if ($p['notes']): ?><div class="note-line"><?= htmlspecialchars($p['notes']) ?></div><?php endif; ?>
                                <?php if ($v): ?>
                                <div class="void-tag">VOIDED<?= $p['void_reason'] ? ' — ' . htmlspecialchars($p['void_reason']) : '' ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($methodLabels[$p['payment_method']] ?? $p['payment_method']) ?></td>
                            <td class="text-right">Rs <?= number_format((float) $p['amount'], 2) ?></td>
                            <td>
                                <div style="display:flex;gap:6px;justify-content:flex-end;">
                                <a class="btn small secondary" href="dental_account.php?id=<?= $accountId ?>&print_receipt=<?= (int) $p['id'] ?>" target="_blank">Receipt</a>
                                <?php if (!$v && ($canTakeMoney || ($_SESSION['base_role'] ?? '') === 'ADMIN')): ?>
                                <form method="POST" action="dental_account.php" data-no-once
                                      onsubmit="var r=prompt('Reason for voiding this receipt?'); if(!r){return false;} this.void_reason.value=r;">
                                    <input type="hidden" name="action" value="void_payment">
                                    <input type="hidden" name="account_id" value="<?= $accountId ?>">
                                    <input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>">
                                    <input type="hidden" name="void_reason" value="">
                                    <button type="submit" class="btn small danger">Void</button>
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

                <?php if ($canTakeMoney && $isOpen): ?>
                <form method="POST" action="dental_account.php" id="payForm" style="margin-top:16px;border-top:1px solid var(--row-line);padding-top:14px;">
                    <input type="hidden" name="action" value="take_payment">
                    <input type="hidden" name="account_id" value="<?= $accountId ?>">
                    <div class="pay-grid">
                        <div class="field" style="margin:0;">
                            <label>Amount (Rs)</label>
                            <input type="number" step="0.01" min="0.01" name="amount" id="payAmount"
                                   value="<?= $totals['balance'] > 0 ? number_format($totals['balance'], 2, '.', '') : '' ?>" required>
                            <div class="hint" id="payHint"></div>
                        </div>
                        <div class="field" style="margin:0;">
                            <label>Method</label>
                            <select name="payment_method">
                                <option value="cash">Cash</option>
                                <option value="card">Online / Card</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="cheque">Cheque</option>
                            </select>
                        </div>
                        <div class="field" style="margin:0;">
                            <label>Note (optional)</label>
                            <input type="text" name="notes" placeholder="e.g. 2nd instalment">
                        </div>
                        <button type="submit" class="btn">Take payment</button>
                    </div>
                </form>
                <?php endif; ?>
            </div>

            <!-- ==== Consent ==== -->
            <div class="card">
                <div class="section-title">Consent</div>
                <div class="section-sub">For a package the consent is also the financial contract — it freezes the itemised quote at signing.</div>
                <?php if (!$consents): ?>
                <div class="empty" style="margin-top:14px;">No consent captured for this package.</div>
                <?php else: ?>
                <div style="overflow-x:auto;margin-top:12px;">
                <table>
                    <thead><tr><th>Signed by</th><th style="width:130px;">Status</th><th style="width:130px;">When</th><th style="width:110px;"></th></tr></thead>
                    <tbody>
                        <?php foreach ($consents as $c): ?>
                        <tr>
                            <td>
                                <?= htmlspecialchars($c['signed_name'] ?: '—') ?>
                                <?= $c['signed_relation'] ? ' <span class="note-line">(' . htmlspecialchars($c['signed_relation']) . ')</span>' : '' ?>
                            </td>
                            <td><span class="status-pill <?= $c['status'] === 'SIGNED' ? 'paid' : 'pending' ?>"><?= htmlspecialchars($c['status']) ?></span></td>
                            <td><?= $c['signed_at'] ? date('d/m/Y', strtotime($c['signed_at'])) : date('d/m/Y', strtotime($c['created_at'])) ?></td>
                            <td>
                                <?php if ($c['scan_path']): ?>
                                <a class="btn small secondary" href="dental_consent_file.php?id=<?= (int) $c['id'] ?>" target="_blank">Scan</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
                <?php if (has_permission('DENTAL_CAPTURE_CONSENT')): ?>
                <div style="margin-top:14px;">
                    <a class="btn secondary" href="dental_consent.php?account_id=<?= $accountId ?>">Capture consent for this package</a>
                </div>
                <?php endif; ?>
            </div>

            <!-- ==== Lab ==== -->
            <div class="card">
                <div class="section-title">Lab work</div>
                <div class="section-sub">Crowns and dentures sent out — where each one is right now.</div>
                <?php if (!$labRows): ?>
                <div class="empty" style="margin-top:14px;">No lab work logged for this package.</div>
                <?php else: ?>
                <div style="overflow-x:auto;margin-top:12px;">
                <table>
                    <thead><tr><th>Work</th><th style="width:150px;">Vendor</th><th style="width:110px;">Status</th><th style="width:120px;">Sent</th><th style="width:110px;" class="text-right">Charge</th></tr></thead>
                    <tbody>
                        <?php foreach ($labRows as $l): ?>
                        <tr>
                            <td>
                                <?= htmlspecialchars($l['work_description']) ?>
                                <?php if ($l['tooth_fdi']): ?> <span class="tooth-pill"><?= htmlspecialchars($l['tooth_fdi']) ?></span><?php endif; ?>
                                <?php if ($l['shade']): ?><div class="lab-tag">Shade <?= htmlspecialchars($l['shade']) ?></div><?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($l['vendor_name']) ?></td>
                            <td><span class="status-pill <?= $l['status'] === 'FITTED' ? 'done' : ($l['status'] === 'RECEIVED' ? 'waiting' : 'pending') ?>"><?= htmlspecialchars($l['status']) ?></span></td>
                            <td><?= date('d/m/Y', strtotime($l['sent_date'])) ?></td>
                            <td class="text-right">Rs <?= number_format((float) $l['lab_charge'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
                <?php if (has_permission('DENTAL_MANAGE_LAB_WORK')): ?>
                <div style="margin-top:14px;">
                    <a class="btn secondary" href="dental_lab.php?account_id=<?= $accountId ?>">Log lab work</a>
                </div>
                <?php endif; ?>
            </div>

            <!-- ==== Close the account ==== -->
            <?php if ($isOpen && ($canCancel || $canTakeMoney)): ?>
            <div class="card">
                <div class="section-title">Close this account</div>
                <div class="section-sub">Settling needs a clear balance. Cancelling freezes the account wherever it stands.</div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;">
                    <?php if ($canTakeMoney): ?>
                    <form method="POST" action="dental_account.php">
                        <input type="hidden" name="action" value="settle_account">
                        <input type="hidden" name="account_id" value="<?= $accountId ?>">
                        <button type="submit" class="btn" <?= $totals['balance'] > 0.004 ? 'disabled title="Rs ' . number_format($totals['balance'], 2) . ' still outstanding"' : '' ?>>Mark settled</button>
                    </form>
                    <?php endif; ?>
                    <?php if ($canCancel): ?>
                    <form method="POST" action="dental_account.php" data-no-once
                          onsubmit="var r=prompt('Why is this package being cancelled?'); if(!r){return false;} this.cancel_reason.value=r;">
                        <input type="hidden" name="action" value="cancel_account">
                        <input type="hidden" name="account_id" value="<?= $accountId ?>">
                        <input type="hidden" name="cancel_reason" value="">
                        <button type="submit" class="btn danger">Cancel account</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<script>
// Lab charge is only enabled for a procedure flagged as sending work out, and
// prefills the catalogue's usual figure. The server re-reads that flag from the
// database, so this is convenience only — a crafted POST cannot slip a lab
// charge onto a non-lab procedure.
(function () {
    var sel = document.getElementById('itemProc');
    var lab = document.getElementById('itemLab');
    var hint = document.getElementById('labHint');
    if (!sel || !lab) { return; }
    sel.addEventListener('change', function () {
        var o = sel.options[sel.selectedIndex];
        var isLab = o && o.dataset.lab === '1';
        lab.disabled = !isLab;
        if (isLab) {
            if (!parseFloat(lab.value)) { lab.value = o.dataset.labfee || 0; }
            hint.textContent = 'Charged on top of the fee, shown as its own line.';
        } else {
            lab.value = 0;
            hint.textContent = 'Only for lab procedures.';
        }
        if (o && o.dataset.consent === '1' && hint) {
            hint.textContent += ' Consent required before this can be quoted.';
        }
    });
})();

// Warn when a payment would take the account past its total. Allowed — a
// deposit ahead of the next visit is normal — but worth saying out loud.
(function () {
    var amt = document.getElementById('payAmount');
    var hint = document.getElementById('payHint');
    var balance = <?= json_encode(round($totals['balance'], 2)) ?>;
    if (!amt || !hint) { return; }
    function upd() {
        var v = parseFloat(amt.value || '0');
        if (v > balance + 0.004) {
            hint.textContent = 'That is Rs ' + (v - balance).toFixed(2) + ' more than the outstanding balance.';
            hint.style.color = 'var(--warn)';
        } else {
            hint.textContent = balance > 0 ? 'Outstanding: Rs ' + balance.toFixed(2) : 'Nothing outstanding.';
            hint.style.color = '';
        }
    }
    amt.addEventListener('input', upd);
    upd();
})();
</script>
<script src="assets/js/date-picker.js?v=<?= @filemtime(__DIR__ . "/assets/js/date-picker.js") ?: 1 ?>"></script>
</body>
</html>
