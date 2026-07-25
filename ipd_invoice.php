<?php
/**
 * IPD invoice — A5 print view. Itemised (stay + consultant visits + services +
 * totals), own "I" series. Browser print -> Save as PDF.
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
refresh_session_permissions($pdo);
require_permission('IPD_VIEW_WARD');

$admissionId = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare("
    SELECT b.*, a.ward, a.room_no, a.admitted_at, a.discharged_at,
           v.token_no, p.mrn, p.name AS patient_name, p.father_name, p.phone,
           COALESCE(du.name, a.admitting_consultant_manual) AS consultant_name
    FROM ipd_bills b
    JOIN ipd_admissions a ON a.id = b.admission_id
    JOIN visits v ON v.id = a.visit_id
    JOIN patients p ON p.id = v.patient_id
    LEFT JOIN users du ON du.id = a.admitting_consultant_id
    WHERE b.admission_id = ?
");
$stmt->execute([$admissionId]);
$bill = $stmt->fetch();
if (!$bill) { http_response_code(404); exit('IPD invoice not found — the bill is raised at discharge.'); }

$it = $pdo->prepare('SELECT * FROM ipd_bill_items WHERE ipd_bill_id = ? ORDER BY FIELD(item_kind,\'STAY\',\'CONSULT_VISIT\',\'SERVICE\'), id');
$it->execute([(int) $bill['id']]);
$items = $it->fetchAll();

$discount = (float) $bill['subtotal'] - (float) $bill['grand_total'];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>IPD Invoice <?= htmlspecialchars($bill['invoice_number']) ?></title>
<style>
@page { size: A5; margin: 10mm; }
* { box-sizing: border-box; }
body { font-family: 'Lora', Georgia, serif; color: #1a1a1a; margin: 0; padding: 16px; font-size: 12.5px; }
.wrap { max-width: 148mm; margin: 0 auto; }
.hdr { border: 1.5px solid #1a1a1a; border-radius: 6px; padding: 12px 16px; margin-bottom: 14px; }
.hdr h1 { font-size: 18px; margin: 0 0 2px; }
.hdr .sub { font-size: 11px; color: #444; }
.meta { display: flex; justify-content: space-between; gap: 16px; margin-bottom: 12px; }
.meta table { font-size: 11.5px; } .meta td { padding: 1px 0; } .meta td:first-child { color: #555; padding-right: 10px; }
table.items { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
table.items th, table.items td { padding: 6px 8px; border-bottom: 1px solid #ccc; text-align: left; }
table.items th { font-size: 10px; text-transform: uppercase; letter-spacing: .04em; color: #555; }
table.items td.amt, table.items th.amt { text-align: right; }
.tot { width: 55%; margin-left: auto; }
.tot .r { display: flex; justify-content: space-between; padding: 3px 8px; font-size: 12.5px; }
.tot .r.grand { border-top: 1.5px solid #1a1a1a; font-weight: 700; font-size: 14px; margin-top: 4px; padding-top: 6px; }
.foot { margin-top: 20px; font-size: 10.5px; color: #666; text-align: center; }
.print-btn { margin: 12px 0; }
@media print { .print-btn { display: none; } body { padding: 0; } }
</style>
</head>
<body>
<div class="print-btn"><button onclick="window.print()">Print / Save PDF</button></div>
<div class="wrap">
    <div class="hdr">
        <h1>Polymedics Hospital</h1>
        <div class="sub">In-Door (IPD) Invoice &middot; <?= htmlspecialchars($bill['invoice_number']) ?></div>
    </div>

    <div class="meta">
        <table>
            <tr><td>Patient</td><td><b><?= htmlspecialchars($bill['patient_name']) ?></b></td></tr>
            <tr><td>MR No.</td><td><?= htmlspecialchars($bill['mrn']) ?></td></tr>
            <tr><td>Consultant</td><td><?= htmlspecialchars($bill['consultant_name'] ?: '—') ?></td></tr>
        </table>
        <table>
            <tr><td>Ward / Room</td><td><?= htmlspecialchars($bill['ward']) ?> &middot; <?= (int) $bill['room_no'] ?></td></tr>
            <tr><td>Admitted</td><td><?= date('d/m/Y H:i', strtotime($bill['admitted_at'])) ?></td></tr>
            <tr><td>Discharged</td><td><?= $bill['discharged_at'] ? date('d/m/Y H:i', strtotime($bill['discharged_at'])) : '—' ?></td></tr>
        </table>
    </div>

    <table class="items">
        <thead><tr><th>Description</th><th>Qty</th><th class="amt">Amount (Rs)</th></tr></thead>
        <tbody>
            <?php foreach ($items as $li): ?>
            <tr>
                <td><?= htmlspecialchars($li['description']) ?></td>
                <td><?= rtrim(rtrim(number_format((float) $li['quantity'], 2), '0'), '.') ?></td>
                <td class="amt"><?= number_format((float) $li['amount']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="tot">
        <div class="r"><span>Subtotal</span><span>Rs <?= number_format((float) $bill['subtotal']) ?></span></div>
        <?php if ($discount > 0.009): ?>
        <div class="r"><span>Discount</span><span>&minus; Rs <?= number_format($discount) ?></span></div>
        <?php endif; ?>
        <div class="r grand"><span>Grand Total</span><span>Rs <?= number_format((float) $bill['grand_total']) ?></span></div>
        <?php if ($bill['status'] === 'paid'): ?>
        <div class="r"><span>Paid (<?= htmlspecialchars($bill['payment_method']) ?>)</span><span>Rs <?= number_format((float) $bill['paid_amount']) ?></span></div>
        <?php if ((float) $bill['write_off_amount'] > 0): ?><div class="r"><span>Written off</span><span>Rs <?= number_format((float) $bill['write_off_amount']) ?></span></div><?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="foot">Generated <?= date('d/m/Y H:i') ?> &middot; This is a computer-generated invoice.</div>
</div>
</body>
</html>
