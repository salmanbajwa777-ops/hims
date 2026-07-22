<?php
/**
 * Admission invoice — A5 print view.
 *
 * The SEPARATE admission bill, in the same visual format as the consultation
 * slip (views/invoice_print_partial.php) — same bordered header box, IBM Plex
 * Mono, A5 — but ITEMISED (stay + services + totals) rather than a single
 * consultation-fee line. Browser print -> Save as PDF, like the consultation slip.
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
refresh_session_permissions($pdo);

$admissionId = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT ab.*, a.admission_type, a.admitted_at, a.discharged_at,
           v.token_no, p.mrn, p.name AS patient_name, p.father_name, p.dob, p.phone,
           COALESCE(du.name, a.admitting_doctor_manual) AS doctor_name,
           du.specialty AS doctor_specialty
    FROM admission_bills ab
    JOIN admissions a ON a.id = ab.admission_id
    JOIN visits v ON v.id = a.visit_id
    JOIN patients p ON p.id = v.patient_id
    LEFT JOIN users du ON du.id = a.admitting_doctor_id
    WHERE ab.admission_id = ?
");
$stmt->execute([$admissionId]);
$bill = $stmt->fetch();
if (!$bill) { http_response_code(404); exit('Admission invoice not found.'); }

$items = $pdo->prepare('SELECT * FROM admission_bill_items WHERE admission_bill_id = ? ORDER BY item_kind DESC, id');
$items->execute([(int) $bill['id']]);
$items = $items->fetchAll();

// Mark printed (locks further edits) the first time it's opened after finalize.
if (!$bill['printed_at'] && $bill['status'] !== 'draft') {
    $pdo->prepare('UPDATE admission_bills SET printed_at = NOW(), printed_by_id = ? WHERE id = ?')
        ->execute([(int) $_SESSION['user_id'], (int) $bill['id']]);
}

$clinicName = 'BABY MEDICS';
$clinicTagline = 'Premium Healthcare | Vaccines';
$clinicEmail = 'info@babymedics.com';
$clinicPhone = '+92 51 5735006';
$clinicWebsite = 'b a b y m e d i c s . c o m';
$logoFile = ($bill['doctor_specialty'] ?? '') === 'DENTAL' ? 'logo-dental.png' : 'logo-general.png';

$patientNameUpper = mb_strtoupper($bill['patient_name'], 'UTF-8');
$fatherNameUpper = $bill['father_name'] ? mb_strtoupper($bill['father_name'], 'UTF-8') : '';
$dobDisplay = $bill['dob'] ? date('d/m/Y', strtotime($bill['dob'])) : '';

$total = (float) $bill['grand_total'];
$paid = (float) ($bill['paid_amount'] ?? 0);
$writeoff = (float) $bill['write_off_amount'];
$methodLabels = ['cash' => 'Cash', 'card' => 'Card', 'bank_transfer' => 'Bank Transfer', 'cheque' => 'Cheque'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admission Invoice - <?= htmlspecialchars($bill['invoice_number']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { width: 148mm; margin: 0; padding: 0; }
        body { font-family: 'IBM Plex Mono', 'Courier New', monospace; font-size: 9.5px; line-height: 1.3; color: #000; background: #fff; }
        .sheet { width: 100%; padding: 6mm 6mm 4mm; display: flex; flex-direction: column; min-height: 210mm; }

        .head-box { border: 1px solid #B0B0B0; padding: 3mm 3.5mm 1mm; display: flex; gap: 5mm; align-items: stretch; }
        .head-left, .head-right { width: 50%; display: flex; flex-direction: column; }
        .head-left > .ids, .head-right > .meta { margin-top: auto; }
        .brandline { display: flex; align-items: center; gap: 3px; }
        .clinic-logo { height: 30px; display: block; background: #fff; }
        .brandtext { display: flex; flex-direction: column; justify-content: center; height: 30px; }
        .clinic-name { font-family: Arial, Helvetica, sans-serif; font-size: 18px; font-weight: bold; letter-spacing: .2px; color: #0F7362; white-space: nowrap; line-height: 1; }
        .website { font-family: Arial, Helvetica, sans-serif; font-size: 8px; font-weight: bold; letter-spacing: 1.7px; color: #4A4A4A; line-height: 1; margin-top: 2px; }
        .addr { font-size: 9px; line-height: 1.35; margin-top: 5px; margin-bottom: 4px; }
        .tagline { font-family: Arial, Helvetica, sans-serif; font-size: 14px; font-weight: bold; line-height: 1.15; margin-bottom: 9px; white-space: nowrap; }
        .meta { width: 100%; border-collapse: collapse; font-size: 9px; }
        .meta td { border: 1px solid #C8C8C8; padding: 3px 5px; vertical-align: top; }
        .meta td.k { background: #F4F4F4; font-weight: bold; color: #000; width: 40%; }
        .meta td.v { font-weight: bold; }
        .clinic-contact { margin-top: 0; margin-bottom: 4px; }
        .ids { width: 100%; border-collapse: collapse; font-size: 9px; }
        .ids td { border: 1px solid #C8C8C8; padding: 3px 5px; }
        .ids td.k { background: #F4F4F4; font-weight: bold; width: 42%; }
        .ids td.v { font-weight: bold; }

        .doc-title { text-align: center; font-weight: bold; font-size: 11px; letter-spacing: 1px; margin: 3mm 0 1mm; }
        .items { width: 100%; border-collapse: collapse; font-size: 9.5px; margin-top: 0; }
        .items th, .items td { border: 1px solid #C8C8C8; padding: 4px 6px; }
        .items th { background: #F4F4F4; font-weight: bold; text-align: center; }
        .items .desc { text-align: left; }
        .items .num { text-align: right; white-space: nowrap; }
        .items .kind { color: #666; font-size: 8px; }
        .totals { width: 55%; margin-left: auto; border-collapse: collapse; font-size: 10px; margin-top: -1px; }
        .totals td { border: 1px solid #C8C8C8; padding: 4px 6px; }
        .totals td.k { background: #F4F4F4; font-weight: bold; }
        .totals td.v { text-align: right; font-weight: bold; white-space: nowrap; }
        .totals tr.grand td { font-size: 11px; }
        .paystat { margin-top: 3mm; font-size: 9.5px; }
        .paystat b { font-weight: bold; }
        .quote { text-align: center; font-size: 9.5px; font-style: italic; margin-top: auto; padding-top: 5mm; }
        .foot { display: flex; justify-content: space-between; gap: 10px; border-top: 1px solid #B0B0B0; margin-top: 1.5mm; padding-top: 2px; font-size: 7.5px; }

        @media print {
            html, body { width: 148mm; height: 210mm; }
            .sheet { min-height: 210mm; padding: 6mm 6mm 4mm; }
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            @page { size: A5; margin: 0; }
        }
    </style>
</head>
<body>
    <div class="sheet">
        <div class="head-box">
            <div class="head-left">
                <div class="brandline">
                    <img class="clinic-logo" src="assets/images/<?= htmlspecialchars($logoFile) ?>" alt="">
                    <span class="brandtext">
                        <span class="clinic-name"><?= $clinicName ?></span>
                        <span class="website"><?= $clinicWebsite ?></span>
                    </span>
                </div>
                <div class="addr"><b>Polymedics,</b> 2165-F, NPF, PWD Double Road<br>Islamabad, Pakistan.</div>
                <table class="ids">
                    <tr><td class="k">MR #</td><td class="v"><?= htmlspecialchars($bill['mrn']) ?></td></tr>
                    <tr><td class="k">Invoice #</td><td class="v"><?= htmlspecialchars($bill['invoice_number']) ?></td></tr>
                    <tr><td class="k">Type</td><td class="v"><?= htmlspecialchars($bill['admission_type']) ?></td></tr>
                    <tr><td class="k">Doctor</td><td class="v"><?= htmlspecialchars($bill['doctor_name'] ?: '—') ?></td></tr>
                </table>
            </div>
            <div class="head-right">
                <div class="tagline"><?= $clinicTagline ?></div>
                <div class="addr clinic-contact"><b>Email:</b> <?= $clinicEmail ?><br><b>Phone:</b> <?= $clinicPhone ?></div>
                <table class="meta">
                    <tr><td class="k">Name:</td><td class="v"><?= htmlspecialchars($patientNameUpper) ?></td></tr>
                    <tr><td class="k">S/D/W Of:</td><td class="v"><?= htmlspecialchars($fatherNameUpper) ?></td></tr>
                    <tr><td class="k">DOB:</td><td><?= $dobDisplay ?></td></tr>
                    <tr><td class="k">Phone:</td><td><?= htmlspecialchars($bill['phone']) ?></td></tr>
                </table>
            </div>
        </div>

        <div class="doc-title">ADMISSION INVOICE</div>

        <table class="items">
            <thead>
                <tr><th class="desc">Description</th><th>Qty</th><th>Rate</th><th>Amount</th></tr>
            </thead>
            <tbody>
                <?php foreach ($items as $it): ?>
                <tr>
                    <td class="desc"><?= htmlspecialchars($it['description']) ?> <span class="kind"><?= $it['item_kind'] === 'STAY' ? '' : '' ?></span></td>
                    <td class="num"><?= htmlspecialchars((string) (float) $it['quantity']) ?></td>
                    <td class="num"><?= number_format((float) $it['unit_rate'], 0) ?></td>
                    <td class="num"><?= number_format((float) $it['amount'], 0) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <table class="totals">
            <tr class="grand"><td class="k">Total</td><td class="v">Rs <?= number_format($total, 0) ?></td></tr>
            <?php if ($bill['status'] !== 'draft'): ?>
            <tr><td class="k">Paid (<?= htmlspecialchars($methodLabels[$bill['payment_method']] ?? '—') ?>)</td><td class="v">Rs <?= number_format($paid, 0) ?></td></tr>
            <?php if ($writeoff > 0): ?><tr><td class="k">Written off</td><td class="v">Rs <?= number_format($writeoff, 0) ?></td></tr><?php endif; ?>
            <?php endif; ?>
        </table>

        <div class="paystat">
            <?php if ($bill['status'] === 'paid'): ?>
                <b>Status:</b> PAID<?= $writeoff > 0 ? ' (balance written off)' : '' ?> &middot; <?= $bill['paid_at'] ? date('d/m/Y H:i', strtotime($bill['paid_at'])) : '' ?>
            <?php elseif ($bill['status'] === 'finalized'): ?>
                <b>Status:</b> PARTIAL — balance pending approval
            <?php else: ?>
                <b>Status:</b> DRAFT
            <?php endif; ?>
        </div>

        <div class="quote">Get well soon.</div>
        <div class="foot">
            <span>Printed <?= date('Y-m-d H:i') ?></span>
            <span><?= htmlspecialchars($clinicName) ?></span>
        </div>
    </div>
    <script>window.onload = function () { window.print(); };</script>
</body>
</html>
