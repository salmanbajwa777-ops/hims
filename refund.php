<?php
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
require_once __DIR__ . '/config/billing.php';
require_once __DIR__ . '/config/notify.php';
refresh_session_permissions($pdo);
require_permission('RECEPTION_ISSUE_REFUNDS');

$error = '';

// Full context for one bill: patient, visit, doctor, and what has been refunded so far.
function load_refund_context(PDO $pdo, int $billId): ?array {
    $stmt = $pdo->prepare('
        SELECT b.id, b.invoice_number, b.grand_total, b.paid_amount, b.status, b.payment_method,
               v.id AS visit_id, v.doctor_id,
               p.mrn, p.name AS patient_name, p.father_name, p.dob,
               d.name AS doctor_name, d.specialty AS doctor_specialty,
               dct.label AS consult_label
        FROM bills b
        JOIN visits v ON v.id = b.visit_id
        JOIN patients p ON p.id = v.patient_id
        JOIN users d ON d.id = v.doctor_id
        JOIN doctor_consult_types dct ON dct.id = v.doctor_consult_type_id
        WHERE b.id = ?
    ');
    $stmt->execute([$billId]);
    $bill = $stmt->fetch();
    return $bill ?: null;
}

// ---------------- Print view (voucher) ----------------
if (isset($_GET['print']) && isset($_GET['refund_id'])) {
    $refundId = (int) $_GET['refund_id'];

    $stmt = $pdo->prepare('
        SELECT r.*, b.invoice_number, b.paid_amount,
               p.mrn, p.name AS patient_name, p.father_name, p.dob,
               doc.name AS doctor_name, doc.specialty AS doctor_specialty,
               appr.name AS approved_by_name, gen.name AS generated_by_name,
               dct.label AS consult_label
        FROM refunds r
        JOIN bills b ON b.id = r.bill_id
        JOIN visits v ON v.id = b.visit_id
        JOIN patients p ON p.id = v.patient_id
        JOIN users doc ON doc.id = v.doctor_id
        JOIN users appr ON appr.id = r.approved_by_id
        JOIN users gen ON gen.id = r.generated_by_id
        JOIN doctor_consult_types dct ON dct.id = v.doctor_consult_type_id
        WHERE r.id = ?
    ');
    $stmt->execute([$refundId]);
    $refund = $stmt->fetch();

    if (!$refund) {
        http_response_code(404);
        die('Refund not found.');
    }

    // Refunds raised against this bill BEFORE this one — the "previously refunded"
    // figure on the voucher, so a reprint always shows the same numbers.
    $priorStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) AS t FROM refunds WHERE bill_id = ? AND id < ?');
    $priorStmt->execute([$refund['bill_id'], $refundId]);
    $priorRefunded = (float) $priorStmt->fetch()['t'];

    $pdo->prepare('UPDATE refunds SET printed_at = NOW() WHERE id = ?')->execute([$refundId]);

    include __DIR__ . '/views/refund_print_partial.php';
    exit;
}

// ---------------- Issue refund ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'issue_refund') {
    $billId = (int) ($_POST['bill_id'] ?? 0);
    $amount = round((float) str_replace(',', '', $_POST['amount'] ?? '0'), 2);
    $reason = trim($_POST['reason'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $refundMode = $_POST['refund_mode'] ?? 'cash';
    $approvedById = (int) ($_POST['approved_by_id'] ?? 0);

    // A closed day accepts no more refunds — cash refunds come out of the
    // drawer that has already been counted and signed for.
    $dayLock = require_day_open($pdo);

    try {
        $pdo->beginTransaction();

        // Lock the bill row so two concurrent refunds cannot both read the same
        // remaining balance and each pass the cap check.
        $lockStmt = $pdo->prepare('SELECT id, paid_amount, status FROM bills WHERE id = ? FOR UPDATE');
        $lockStmt->execute([$billId]);
        $bill = $lockStmt->fetch();

        $visitStmt = $pdo->prepare('SELECT v.doctor_id FROM visits v JOIN bills b ON b.visit_id = v.id WHERE b.id = ?');
        $visitStmt->execute([$billId]);
        $visit = $visitStmt->fetch();

        $alreadyRefunded = refunded_total($pdo, $billId);
        $paid = (float) ($bill['paid_amount'] ?? 0);
        $remaining = round($paid - $alreadyRefunded, 2);

        if ($dayLock) {
            $error = $dayLock;
        } elseif (!$bill || $bill['status'] !== 'paid') {
            $error = 'Only a paid invoice can be refunded.';
        } elseif ($amount <= 0) {
            $error = 'Enter a refund amount greater than zero.';
        } elseif ($amount > $remaining) {
            $error = 'Refund cannot exceed Rs ' . number_format($remaining, 2) . ' still refundable on this invoice.';
        } elseif ($reason === '') {
            $error = 'Select a reason for the refund.';
        } elseif (!in_array($refundMode, ['cash', 'card', 'bank_transfer'], true)) {
            $error = 'Select a valid refund mode.';
        } elseif ($approvedById !== (int) $visit['doctor_id']) {
            // The approver must be the doctor on that visit (confirmed).
            $error = 'The refund must be approved by the doctor who saw this patient.';
        } else {
            $refundNumber = generate_refund_number($pdo);

            $pdo->prepare('
                INSERT INTO refunds (refund_number, bill_id, amount, reason, notes, refund_mode, approved_by_id, generated_by_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ')->execute([
                $refundNumber, $billId, $amount, $reason, $notes ?: null,
                $refundMode, $approvedById, $_SESSION['user_id'],
            ]);
            $refundId = (int) $pdo->lastInsertId();

            $log = $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)');
            $log->execute([
                $_SESSION['user_id'],
                'refund_issued',
                "Refund $refundNumber of Rs $amount against bill #$billId, reason: $reason",
            ]);

            $pdo->commit();

            // Alert admin + the approving doctor (best-effort, after commit).
            notify_refund_issued($pdo, $refundId);

            header('Location: refund.php?print=1&refund_id=' . $refundId);
            exit;
        }

        $pdo->rollBack();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = 'Could not issue the refund. Please try again.';
    }
}

// ---------------- Page data ----------------
$billId = (int) ($_GET['bill_id'] ?? $_POST['bill_id'] ?? 0);
$bill = $billId ? load_refund_context($pdo, $billId) : null;

if (!$bill) {
    http_response_code(404);
    die('Invoice not found. Open a refund from the Today list.');
}

$paid = (float) ($bill['paid_amount'] ?? 0);
$alreadyRefunded = refunded_total($pdo, $billId);
$remaining = round($paid - $alreadyRefunded, 2);

// Login only stores user_id/base_role in the session, so the display name is read here.
$meStmt = $pdo->prepare('SELECT name FROM users WHERE id = ?');
$meStmt->execute([$_SESSION['user_id']]);
$currentUserName = $meStmt->fetch()['name'] ?? 'Signed-in user';

$historyStmt = $pdo->prepare('
    SELECT r.refund_number, r.amount, r.reason, r.created_at, u.name AS generated_by_name
    FROM refunds r JOIN users u ON u.id = r.generated_by_id
    WHERE r.bill_id = ? ORDER BY r.id
');
$historyStmt->execute([$billId]);
$history = $historyStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Refund &middot; Invoice <?= htmlspecialchars($bill['invoice_number']) ?> - HIMS</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root {
    --primary-dark: #0E5456; --primary: #1A7F7E; --primary-light: #E0F2F1;
    --bg: #F8FAFC; --card: #FFFFFF; --border: #E2E8F0;
    --text: #000000; --text-muted: #111827;
    --red-bg: #FEE2E2; --red-text: #B91C1C;
    --green-bg: #DCFCE7; --green-text: #15803D;
    --radius: 12px;
}
* { box-sizing: border-box; }
body { font-family: 'Inter', system-ui, -apple-system, "Segoe UI", sans-serif; background: var(--bg); color: var(--text); font-size: 14px; line-height: 1.5; margin: 0; }
.shell { max-width: 880px; margin: 0 auto; padding: 28px 20px 60px; display: flex; flex-direction: column; gap: 16px; }
.page-title { font-size: 20px; font-weight: 700; }
.page-sub { font-size: 13px; color: var(--text-muted); margin-top: 2px; }
.card { background: var(--card); border: 1px solid var(--border); border-radius: 14px; padding: 20px; }
.alert { padding: 12px 16px; border-radius: var(--radius); font-size: 13px; }
.alert.error { background: var(--red-bg); color: var(--red-text); }
.ctx { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; background: #fff; }
.ctx > div { padding: 10px 14px; border-right: 1px solid var(--border); }
.ctx > div:last-child { border-right: none; }
.ctx .k { font-size: 10.5px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; color: var(--text-muted); }
.ctx .v { font-weight: 650; font-size: 14px; margin-top: 2px; }
.mono { font-variant-numeric: tabular-nums; }
label.lbl { font-size: 11.5px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: var(--text-muted); display: block; margin-bottom: 5px; }
.inp { width: 100%; border: 1px solid var(--border); background: #fff; color: var(--text); border-radius: var(--radius); padding: 10px 12px; font: 400 14px inherit; font-family: inherit; }
.row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.amtbox { border: 1px solid var(--border); border-radius: var(--radius); padding: 14px 16px; background: var(--bg); display: flex; flex-direction: column; gap: 10px; }
.amtline { display: flex; align-items: baseline; gap: 10px; }
.cur { font-weight: 700; color: var(--text-muted); font-size: 15px; }
.amt { flex: 1; border: none; border-bottom: 2px solid var(--primary); background: transparent; color: var(--text); font: 700 27px/1.1 inherit; font-family: inherit; font-variant-numeric: tabular-nums; padding: 2px 0; }
.amt:focus { outline: none; }
.amtfoot { display: flex; justify-content: space-between; font-size: 12px; color: var(--text-muted); font-variant-numeric: tabular-nums; flex-wrap: wrap; gap: 8px; }
.quick { display: flex; gap: 6px; flex-wrap: wrap; }
.qbtn { border: 1px solid var(--border); background: #fff; color: var(--text); border-radius: 999px; padding: 4px 11px; font: 600 12px inherit; font-family: inherit; cursor: pointer; }
.overcap { display: none; margin-top: 10px; background: var(--red-bg); color: var(--red-text); border-radius: 8px; padding: 9px 13px; font-size: 12.5px; font-weight: 600; }
.overcap.show { display: block; }
.btn { display: inline-flex; align-items: center; gap: 8px; padding: 11px 20px; border-radius: var(--radius); border: none; background: linear-gradient(135deg, var(--primary-dark), var(--primary)); color: #fff; font-size: 13.5px; font-weight: 600; cursor: pointer; font-family: inherit; text-decoration: none; }
.btn.secondary { background: var(--card); color: var(--text); border: 1px solid var(--border); }
.btn.danger { background: #B91C1C; }
.btn[disabled] { opacity: .5; cursor: not-allowed; }
.foot { display: flex; justify-content: flex-end; gap: 9px; margin-top: 18px; }
table { width: 100%; border-collapse: collapse; }
th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted); padding: 0 10px 8px; font-weight: 600; }
td { padding: 9px 10px; border-top: 1px solid var(--border); font-size: 13px; }
.pill { display: inline-flex; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; text-transform: uppercase; background: var(--green-bg); color: var(--green-text); }
.section-title { font-size: 14.5px; font-weight: 700; margin-bottom: 12px; }
</style>
</head>
<body>
<div class="shell">

    <div>
        <div class="page-title">Refund</div>
        <div class="page-sub">Invoice <?= htmlspecialchars($bill['invoice_number']) ?></div>
    </div>

    <?php if ($error): ?>
        <div class="alert error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="ctx">
        <div><div class="k">Patient</div><div class="v"><?= htmlspecialchars($bill['patient_name']) ?></div></div>
        <div><div class="k">MR #</div><div class="v mono"><?= htmlspecialchars($bill['mrn']) ?></div></div>
        <div><div class="k">DOB</div><div class="v mono"><?= $bill['dob'] ? date('d/m/Y', strtotime($bill['dob'])) : '—' ?></div></div>
        <div><div class="k">Doctor</div><div class="v"><?= htmlspecialchars($bill['doctor_name']) ?></div></div>
        <div><div class="k">Service</div><div class="v"><?= htmlspecialchars($bill['consult_label']) ?></div></div>
        <div><div class="k">Paid</div><div class="v mono">Rs <?= number_format($paid, 2) ?></div></div>
    </div>

    <?php if ($bill['status'] !== 'paid'): ?>
        <div class="alert error">This invoice has not been paid, so there is nothing to refund.</div>
        <div><a class="btn secondary" href="checkout.php?bill_id=<?= (int) $bill['id'] ?>">Back to invoice</a></div>
    <?php elseif ($remaining <= 0): ?>
        <div class="alert error">This invoice has been fully refunded (Rs <?= number_format($alreadyRefunded, 2) ?>).</div>
        <div><a class="btn secondary" href="checkout.php?bill_id=<?= (int) $bill['id'] ?>">Back to invoice</a></div>
    <?php else: ?>

    <form method="POST" action="refund.php" class="card" id="refundForm">
        <input type="hidden" name="action" value="issue_refund">
        <input type="hidden" name="bill_id" value="<?= (int) $bill['id'] ?>">
        <input type="hidden" name="approved_by_id" value="<?= (int) $bill['doctor_id'] ?>">

        <label class="lbl" for="amount">Refund amount</label>
        <div class="amtbox">
            <div class="amtline">
                <span class="cur">Rs</span>
                <input class="amt" id="amount" name="amount" inputmode="decimal" autocomplete="off"
                       value="<?= number_format($remaining, 2, '.', '') ?>"
                       data-max="<?= $remaining ?>" aria-describedby="amthelp" required>
            </div>
            <div class="amtfoot" id="amthelp">
                <span>Paid <b class="mono"><?= number_format($paid, 2) ?></b> &middot; already refunded <b class="mono"><?= number_format($alreadyRefunded, 2) ?></b></span>
                <span>Maximum refundable <b class="mono"><?= number_format($remaining, 2) ?></b></span>
            </div>
            <div class="quick">
                <button type="button" class="qbtn" data-set="<?= $remaining ?>">Full <?= number_format($remaining, 2) ?></button>
                <button type="button" class="qbtn" data-set="<?= round($remaining / 2, 2) ?>">Half <?= number_format($remaining / 2, 2) ?></button>
            </div>
        </div>
        <div class="overcap" id="overcap">Cannot exceed Rs <?= number_format($remaining, 2) ?> — the amount still refundable on this invoice.</div>

        <div class="row2" style="margin-top:16px;">
            <div>
                <label class="lbl" for="reason">Reason</label>
                <select class="inp" id="reason" name="reason" required>
                    <option value="">Select a reason…</option>
                    <option>Consultation not provided</option>
                    <option>Procedure cancelled</option>
                    <option>Overcharged / billing correction</option>
                    <option>Duplicate invoice</option>
                    <option>Other</option>
                </select>
            </div>
            <div>
                <label class="lbl" for="refund_mode">Refund mode</label>
                <select class="inp" id="refund_mode" name="refund_mode" required>
                    <option value="cash" selected>Cash</option>
                    <option value="card">Card reversal</option>
                    <option value="bank_transfer">Bank transfer</option>
                </select>
            </div>
        </div>

        <div style="margin-top:14px;">
            <label class="lbl" for="notes">Notes <span style="text-transform:none;letter-spacing:0;font-weight:500;">(printed on the voucher)</span></label>
            <input class="inp" id="notes" name="notes" maxlength="255" placeholder="Optional detail for the record">
        </div>

        <div class="row2" style="margin-top:16px;">
            <div>
                <label class="lbl">Approved by</label>
                <input class="inp" value="<?= htmlspecialchars($bill['doctor_name']) ?>" readonly style="background:var(--bg); color:var(--text-muted);">
            </div>
            <div>
                <label class="lbl">Generated by</label>
                <input class="inp" value="<?= htmlspecialchars($currentUserName) ?>" readonly style="background:var(--bg); color:var(--text-muted);">
            </div>
        </div>

        <div class="foot">
            <a class="btn secondary" href="checkout.php?bill_id=<?= (int) $bill['id'] ?>">Cancel</a>
            <button type="submit" class="btn danger" id="submitBtn">Refund &amp; print voucher</button>
        </div>
    </form>

    <?php endif; ?>

    <?php if ($history): ?>
    <div class="card">
        <div class="section-title">Earlier refunds on this invoice</div>
        <table>
            <thead><tr><th>Voucher</th><th>Amount</th><th>Reason</th><th>By</th><th>When</th></tr></thead>
            <tbody>
            <?php foreach ($history as $h): ?>
                <tr>
                    <td class="mono"><?= htmlspecialchars($h['refund_number']) ?></td>
                    <td class="mono">Rs <?= number_format((float) $h['amount'], 2) ?></td>
                    <td><?= htmlspecialchars($h['reason']) ?></td>
                    <td><?= htmlspecialchars($h['generated_by_name']) ?></td>
                    <td><?= date('d M Y H:i', strtotime($h['created_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

</div>

<script>
// Client-side cap is a convenience only — refund.php re-checks against a locked
// bill row, so editing this page cannot push a refund past the amount paid.
(function () {
    const amount = document.getElementById('amount');
    if (!amount) return;
    const max = parseFloat(amount.dataset.max);
    const warn = document.getElementById('overcap');
    const submit = document.getElementById('submitBtn');

    function check() {
        const value = parseFloat(amount.value.replace(/,/g, ''));
        const bad = isNaN(value) || value <= 0 || value > max + 0.001;
        warn.classList.toggle('show', !isNaN(value) && value > max + 0.001);
        submit.disabled = bad;
    }

    amount.addEventListener('input', check);
    document.querySelectorAll('.qbtn').forEach(function (b) {
        b.addEventListener('click', function () {
            amount.value = parseFloat(b.dataset.set).toFixed(2);
            check();
        });
    });
    check();
})();
</script>
</body>
</html>
