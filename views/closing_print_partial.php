<?php
// A5 day-closing slip, included from shift_closing.php's ?print=1 branch (and
// reprintable from admin_handovers.php). Expects $closing (shift_closings row
// joined to cashier/admin names) and $denominations (face_value/qty/amount
// rows, largest first) in scope.
//
// Two signature lines — handed over by (receptionist) and received by (admin).
// The signed paper copy is filed for audit; admin ticks "slip filed" in
// admin_handovers.php when it lands in the file.

$clinicName = 'BABY MEDICS';
$clinicTagline = 'Premium Healthcare | Vaccines';
$clinicAddress = 'Polymedics, 2165-F, NPF, PWD Double Road, Islamabad, Pakistan.';
$clinicEmail = 'info@babymedics.com';
$clinicPhone = '+92 51 5735006';
$clinicWebsite = 'b a b y m e d i c s . c o m';

$printTimestamp = date('Y-m-d H:i:s');
$varianceVal = (float) $closing['variance'];
$varianceLabel = abs($varianceVal) < 0.01 ? 'Balanced'
    : number_format(abs($varianceVal), 2) . ($varianceVal < 0 ? ' SHORT' : ' OVER');
// Per-user model: this slip is one receptionist's shift, not the whole day.
$isEdited = ($closing['status'] ?? '') === 'EDITED' || (int) ($closing['edit_count'] ?? 0) > 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Closing <?= htmlspecialchars($closing['closing_number']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Lora:ital,wght@0,400;0,600;0,700;1,400&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { width: 148mm; height: 210mm; margin: 0; padding: 0; }
        body { font-family: 'Lora', Georgia, 'Times New Roman', serif; font-size: 11px; line-height: 1.3; color: #000; background: #fff; }
        .slip-container { width: 100%; height: 100%; padding: 8mm; overflow: hidden; display: flex; flex-direction: column; }
        .header { text-align: center; margin-bottom: 4px; }
        .clinic-logo { height: 28px; vertical-align: middle; margin-right: 4px; background: #fff; }
        .clinic-name { font-family: Arial, Helvetica, sans-serif; font-size: 16px; font-weight: bold; margin: 2px 0; letter-spacing: 1px; color: #0A6B5E; }
        .website { font-family: Arial, Helvetica, sans-serif; font-size: 9px; font-weight: bold; letter-spacing: 2px; margin-bottom: 2px; color: #0A6B5E; }
        .clinic-tagline { font-size: 10px; margin: 2px 0; font-weight: bold; }
        .contact-info { font-size: 9px; line-height: 1.2; margin-top: 2px; }
        .contact-info p { margin: 1px 0; }
        .doctype { text-align: center; font-weight: bold; font-size: 12px; letter-spacing: 2px; margin: 6px 0 2px; }
        .refno { text-align: center; font-size: 10px; }
        hr { border: none; border-top: 1px solid #000; margin: 4px 0; }
        .meta-table { width: 100%; border: 1px solid #000; border-collapse: collapse; font-size: 10px; margin-bottom: 4px; }
        .meta-table td { border: 1px solid #000; padding: 2px 3px; vertical-align: top; }
        .meta-table td.k { width: 26%; background-color: #f0f0f0; font-weight: bold; }
        .amounts-table { width: 100%; border-collapse: collapse; border: 1px solid #000; font-size: 10px; margin-bottom: 4px; }
        .amounts-table th { border: 1px solid #000; padding: 2px 3px; background-color: #f0f0f0; text-align: left; font-size: 9px; letter-spacing: .5px; }
        .amounts-table td { border: 1px solid #000; padding: 2px 3px; }
        .text-right { text-align: right; }
        .amounts-table .net { background-color: #f0f0f0; font-weight: bold; border-top: 2px solid #000; font-size: 11px; }
        .section-title { font-size: 9px; font-weight: bold; letter-spacing: 1px; margin: 4px 0 2px; }
        .detail { font-size: 9.5px; margin: 2px 0; }
        .signatures { display: flex; gap: 12px; margin-top: auto; padding-top: 24px; }
        .sig { flex: 1; text-align: center; }
        .sig .line { border-top: 1px solid #000; margin-bottom: 3px; }
        .sig .role { font-size: 8.5px; font-weight: bold; letter-spacing: .5px; }
        .sig .nm { font-size: 8.5px; }
        .footer { text-align: center; font-size: 8px; line-height: 1.2; margin-top: 8px; border-top: 1px solid #000; padding-top: 2px; display: flex; justify-content: space-between; }
        @media print {
            body { margin: 0; padding: 0; width: 148mm; height: 210mm; }
            .slip-container { margin: 0; padding: 8mm; height: 210mm; }
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            @page { size: A5; margin: 0; }
        }
    </style>
</head>
<body>
    <div class="slip-container">

        <div class="header">
            <h1 class="clinic-name">
                <img class="clinic-logo" src="assets/images/logo-general.png" alt="">
                <?= $clinicName ?>
            </h1>
            <p class="website"><?= $clinicWebsite ?></p>
            <div class="clinic-tagline"><strong><?= $clinicTagline ?></strong></div>
            <div class="contact-info">
                <p><?= $clinicAddress ?></p>
                <p>Email: <?= $clinicEmail ?> &nbsp; Phone: <?= $clinicPhone ?></p>
            </div>
        </div>

        <div class="doctype">SHIFT CLOSING SLIP</div>
        <div class="refno"><?= htmlspecialchars($closing['closing_number']) ?> &middot; <?= date('Y-m-d H:i', strtotime($closing['created_at'])) ?></div>
        <?php if ($isEdited): ?>
        <div style="text-align:center;font-size:9.5px;font-weight:bold;letter-spacing:1px;border:1.5px solid #000;padding:2px 6px;margin:3px auto 0;width:fit-content;">
            REVISED — EDIT #<?= (int) ($closing['edit_count'] ?? 0) ?> ON <?= $closing['edited_at'] ? date('d M Y H:i', strtotime($closing['edited_at'])) : '—' ?> · REPLACES EARLIER PRINT
        </div>
        <?php endif; ?>

        <hr>

        <table class="meta-table">
            <tr>
                <td class="k">Shift date</td><td><?= date('D d M Y', strtotime($closing['closing_date'])) ?></td>
                <td class="k">Closed at</td><td><?= date('H:i', strtotime($closing['created_at'])) ?></td>
            </tr>
            <tr>
                <td class="k">Cashier</td><td><?= htmlspecialchars($closing['cashier_name']) ?></td>
                <td class="k">Handing to</td><td><?= htmlspecialchars($closing['admin_name']) ?></td>
            </tr>
        </table>

        <div class="section-title">COLLECTIONS — THIS CASHIER ONLY</div>
        <table class="amounts-table">
            <tr><th>Method</th><th class="text-right">Count</th><th class="text-right">Amount (Rs)</th></tr>
            <tr><td>Cash — consultations</td><td class="text-right"><?= (int) $closing['cash_consult_count'] ?></td><td class="text-right"><?= number_format((float) $closing['cash_consult_total'], 2) ?></td></tr>
            <tr><td>Cash — admissions</td><td class="text-right"><?= (int) $closing['cash_admission_count'] ?></td><td class="text-right"><?= number_format((float) $closing['cash_admission_total'], 2) ?></td></tr>
            <tr><td>Online — all</td><td class="text-right"><?= (int) $closing['online_count'] ?></td><td class="text-right"><?= number_format((float) $closing['online_total'], 2) ?></td></tr>
            <tr><td>Refunds paid (cash)</td><td class="text-right"><?= (int) $closing['cash_refund_count'] ?></td><td class="text-right">(<?= number_format((float) $closing['cash_refund_total'], 2) ?>)</td></tr>
            <?php $slipExpenses = (float) ($closing['expense_total'] ?? 0); ?>
            <tr><td>Counter expenses (EXP)</td><td class="text-right"><?= (int) ($closing['expense_count'] ?? 0) ?></td><td class="text-right">(<?= number_format($slipExpenses, 2) ?>)</td></tr>
            <?php
            $netCollected = (float) $closing['cash_consult_total'] + (float) $closing['cash_admission_total']
                          + (float) $closing['online_total'] - (float) $closing['cash_refund_total'];
            ?>
            <tr class="net"><td><strong>TOTAL COLLECTED</strong></td><td class="text-right"><?= (int) $closing['cash_consult_count'] + (int) $closing['cash_admission_count'] + (int) $closing['online_count'] ?></td><td class="text-right"><strong><?= number_format($netCollected, 2) ?></strong></td></tr>
        </table>

        <div class="section-title">CASH IN HAND</div>
        <table class="amounts-table">
            <tr><td>Cash payments received</td><td class="text-right"><?= number_format((float) $closing['cash_consult_total'] + (float) $closing['cash_admission_total'], 2) ?></td></tr>
            <tr><td>Less: cash refunds paid</td><td class="text-right">(<?= number_format((float) $closing['cash_refund_total'], 2) ?>)</td></tr>
            <tr><td>Less: counter expenses</td><td class="text-right">(<?= number_format($slipExpenses, 2) ?>)</td></tr>
            <tr><td>Expected cash in hand</td><td class="text-right"><?= number_format((float) $closing['expected_cash'], 2) ?></td></tr>
            <tr><td>Counted cash</td><td class="text-right"><?= number_format((float) $closing['counted_cash'], 2) ?></td></tr>
            <tr><td>Variance</td><td class="text-right"><?= $varianceLabel ?></td></tr>
            <tr class="net"><td><strong>CASH HANDED TO ADMIN (Rs)</strong></td><td class="text-right"><strong><?= number_format((float) $closing['handover_declared'], 2) ?></strong></td></tr>
        </table>

        <?php if ($denominations): ?>
        <div class="section-title">DENOMINATIONS COUNTED</div>
        <table class="amounts-table">
            <tr><th>Note</th><th class="text-right">Qty</th><th class="text-right">Amount (Rs)</th></tr>
            <?php foreach ($denominations as $d): ?>
            <tr>
                <td><?= (int) $d['face_value'] === 1 ? 'Coins' : number_format((int) $d['face_value']) ?></td>
                <td class="text-right"><?= (int) $d['face_value'] === 1 ? '—' : (int) $d['qty'] ?></td>
                <td class="text-right"><?= number_format((float) $d['amount'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>

        <?php if ($closing['variance_note']): ?>
        <p class="detail"><strong>Variance note:</strong> <?= htmlspecialchars($closing['variance_note']) ?></p>
        <?php endif; ?>

        <div class="signatures">
            <div class="sig">
                <div class="line"></div>
                <div class="role">HANDED OVER BY</div>
                <div class="nm"><?= htmlspecialchars($closing['cashier_name']) ?> (Reception)</div>
            </div>
            <div class="sig">
                <div class="line"></div>
                <div class="role">RECEIVED BY</div>
                <div class="nm"><?= htmlspecialchars($closing['admin_name']) ?> (Admin)</div>
            </div>
        </div>

        <div class="footer">
            <span>Computer generated on <?= $printTimestamp ?></span>
            <span>Signed copy to be filed for audit &middot; <?= htmlspecialchars($closing['closing_number']) ?></span>
        </div>

    </div>

    <script>
        window.addEventListener('load', function() { window.print(); });
    </script>
</body>
</html>
