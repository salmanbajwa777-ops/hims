<?php
// A5 refund voucher, included from refund.php's ?print=1 branch.
// Expects $refund (refunds row joined to bill/patient/doctor/staff) and
// $priorRefunded (sum of earlier refunds on the same bill) in scope.

$clinicName = 'BABY MEDICS';
$clinicTagline = 'Premium Healthcare | Emergency | Vaccines';
$clinicAddress = 'Metacare, Main PWD Road, Police Foundation, Islamabad, Pakistan.';
$clinicEmail = 'info@babymedics.com';
$clinicPhone = '+92 51 5735006';
$clinicWebsite = 'b a b y m e d i c s . c o m';

$logoFile = $refund['doctor_specialty'] === 'DENTAL' ? 'logo-dental.png' : 'logo-general.png';

$patientDobDisplay = $refund['dob'] ? date('d/m/Y', strtotime($refund['dob'])) : '—';
$printTimestamp = date('Y-m-d H:i:s');
$refundModeLabel = ucfirst(str_replace('_', ' ', $refund['refund_mode']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Refund <?= htmlspecialchars($refund['refund_number']) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { width: 148mm; height: 210mm; margin: 0; padding: 0; }
        body { font-family: 'IBM Plex Mono', 'Courier New', monospace; font-size: 11px; line-height: 1.3; color: #000; background: #fff; }
        .invoice-container { width: 100%; height: 100%; padding: 8mm; overflow: hidden; display: flex; flex-direction: column; }
        .header { text-align: center; margin-bottom: 6px; }
        .clinic-logo { height: 28px; vertical-align: middle; margin-right: 4px; }
        /* Wordmark keeps the original spec's font and the logo art's teal. */
        .clinic-name { font-family: Arial, Helvetica, sans-serif; font-size: 16px; font-weight: bold; margin: 2px 0; letter-spacing: 1px; color: #0A6B5E; }
        .website { font-family: Arial, Helvetica, sans-serif; font-size: 9px; font-weight: bold; letter-spacing: 2px; margin-bottom: 2px; color: #0A6B5E; }
        .clinic-tagline { font-size: 10px; margin: 2px 0; font-weight: bold; }
        .contact-info { font-size: 9px; line-height: 1.2; margin-top: 3px; }
        .contact-info p { margin: 1px 0; }
        .doctype { text-align: center; font-weight: bold; font-size: 12px; letter-spacing: 2px; margin: 8px 0 2px; }
        .refno { text-align: center; font-size: 10px; }
        hr { border: none; border-top: 1px solid #000; margin: 4px 0; }
        .metadata-table { width: 100%; border: 1px solid #000; border-collapse: collapse; font-size: 10px; margin-bottom: 4px; }
        .metadata-table td { border: 1px solid #000; padding: 3px; vertical-align: top; }
        .metadata-table td:first-child { width: 38%; background-color: #f0f0f0; font-weight: bold; }
        .amounts-table { width: 100%; border-collapse: collapse; border: 1px solid #000; font-size: 10px; margin-bottom: 4px; }
        .amounts-table td { border: 1px solid #000; padding: 3px; }
        .text-right { text-align: right; }
        .amounts-table .net { background-color: #f0f0f0; font-weight: bold; border-top: 2px solid #000; font-size: 12px; }
        .detail { font-size: 10px; margin: 2px 0; }
        .signatures { display: flex; gap: 8px; margin-top: 20px; }
        .sig { flex: 1; text-align: center; }
        .sig .line { border-top: 1px solid #000; margin-bottom: 3px; }
        .sig .role { font-size: 8.5px; font-weight: bold; letter-spacing: .5px; }
        .sig .nm { font-size: 8.5px; }
        .footer { text-align: center; font-size: 8px; line-height: 1.2; margin-top: auto; border-top: 1px solid #000; padding-top: 2px; display: flex; justify-content: space-between; }
        @media print {
            body { margin: 0; padding: 0; width: 148mm; height: 210mm; }
            .invoice-container { margin: 0; padding: 8mm; height: 210mm; }
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            @page { size: A5; margin: 0; }
        }
    </style>
</head>
<body>
    <div class="invoice-container">

        <div class="header">
            <h1 class="clinic-name">
                <img class="clinic-logo" src="assets/images/<?= htmlspecialchars($logoFile) ?>" alt="">
                <?= $clinicName ?>
            </h1>
            <p class="website"><?= $clinicWebsite ?></p>
            <div class="clinic-tagline"><strong><?= $clinicTagline ?></strong></div>
            <div class="contact-info">
                <p><?= $clinicAddress ?></p>
                <p>Email: <?= $clinicEmail ?> &nbsp; Phone: <?= $clinicPhone ?></p>
            </div>
        </div>

        <div class="doctype">REFUND VOUCHER</div>
        <div class="refno"><?= htmlspecialchars($refund['refund_number']) ?> &middot; <?= date('Y-m-d H:i', strtotime($refund['created_at'])) ?></div>

        <hr>

        <table class="metadata-table">
            <tr><td><strong>Against Invoice #</strong></td><td><?= htmlspecialchars($refund['invoice_number']) ?></td></tr>
            <tr><td><strong>MR #</strong></td><td><?= htmlspecialchars($refund['mrn']) ?></td></tr>
            <tr><td><strong>Name:</strong></td><td><?= htmlspecialchars($refund['patient_name']) ?></td></tr>
            <tr><td><strong>S/D/W Of:</strong></td><td><?= htmlspecialchars($refund['father_name'] ?: '—') ?></td></tr>
            <tr><td><strong>DOB:</strong></td><td><?= $patientDobDisplay ?></td></tr>
            <tr><td><strong>Doctor:</strong></td><td><?= htmlspecialchars($refund['doctor_name']) ?></td></tr>
            <tr><td><strong>Service:</strong></td><td><?= htmlspecialchars($refund['consult_label']) ?></td></tr>
        </table>

        <table class="amounts-table">
            <tr><td>Amount paid</td><td class="text-right"><?= number_format((float) $refund['paid_amount'], 2) ?></td></tr>
            <tr><td>Previously refunded</td><td class="text-right"><?= number_format($priorRefunded, 2) ?></td></tr>
            <tr class="net"><td><strong>REFUNDED NOW (Rs)</strong></td><td class="text-right"><strong><?= number_format((float) $refund['amount'], 2) ?></strong></td></tr>
        </table>

        <p class="detail"><strong>Reason:</strong> <?= htmlspecialchars($refund['reason']) ?></p>
        <p class="detail"><strong>Mode:</strong> <?= htmlspecialchars($refundModeLabel) ?></p>
        <?php if ($refund['notes']): ?>
        <p class="detail"><?= htmlspecialchars($refund['notes']) ?></p>
        <?php endif; ?>

        <div class="signatures">
            <div class="sig">
                <div class="line"></div>
                <div class="role">APPROVED BY</div>
                <div class="nm"><?= htmlspecialchars($refund['approved_by_name']) ?></div>
            </div>
            <div class="sig">
                <div class="line"></div>
                <div class="role">GENERATED BY</div>
                <div class="nm"><?= htmlspecialchars($refund['generated_by_name']) ?></div>
            </div>
            <div class="sig">
                <div class="line"></div>
                <div class="role">RECEIVED BY</div>
                <div class="nm">Patient / guardian</div>
            </div>
        </div>

        <div class="footer">
            <span>Computer generated on <?= $printTimestamp ?></span>
            <span>Front Desk: <?= htmlspecialchars($refund['generated_by_name']) ?></span>
        </div>

    </div>

    <script>
        window.addEventListener('load', function() { window.print(); });
    </script>
</body>
</html>
