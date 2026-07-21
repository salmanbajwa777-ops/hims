<?php
// A5 invoice print view, included from checkout.php's ?print=1 branch.
// Expects $bill (row from bills joined to visits/patients/users) and $items (bill_items) in scope.

$clinicName = 'BABY MEDICS';
$clinicTagline = 'Premium Healthcare | Emergency | Vaccines';
$clinicAddress = 'Metacare, Main PWD Road, Police Foundation, Islamabad, Pakistan.';
$clinicEmail = 'info@babymedics.com';
$clinicPhone = '+92 51 5735006';
$clinicWebsite = 'b a b y m e d i c s . c o m';

$logoFile = $bill['doctor_specialty'] === 'DENTAL' ? 'logo-dental.png' : 'logo-general.png';

$patientDobDisplay = $bill['dob'] ? date('d/m/Y', strtotime($bill['dob'])) : '—';
$printTimestamp = date('Y-m-d H:i:s');
$printedByStmt = $pdo->prepare('SELECT name FROM users WHERE id = ?');
$printedByStmt->execute([$_SESSION['user_id']]);
$printedBy = $printedByStmt->fetch()['name'] ?? 'Front Desk';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice - <?= htmlspecialchars($bill['invoice_number']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { width: 148mm; height: 210mm; margin: 0; padding: 0; }
        body { font-family: 'IBM Plex Mono', 'Courier New', monospace; font-size: 11px; line-height: 1.3; color: #000; background: #fff; }
        .invoice-container { width: 100%; height: 100%; padding: 8mm; overflow: hidden; display: flex; flex-direction: column; }
        .header { text-align: center; margin-bottom: 6px; }
        .clinic-logo { height: 28px; vertical-align: middle; margin-right: 4px; }
        /* Wordmark keeps the original spec's font (exempt from the IBM Plex Mono switch)
           and takes its teal from the line art in assets/images/logo-*.png. */
        .clinic-name { font-family: Arial, Helvetica, sans-serif; font-size: 16px; font-weight: bold; margin: 2px 0; letter-spacing: 1px; color: #0A6B5E; }
        /* Website line mirrors the letter-spaced "b a b y m e d i c s . c o m" in the printed sample. */
        .website { font-family: Arial, Helvetica, sans-serif; font-size: 9px; font-weight: bold; letter-spacing: 2px; margin-bottom: 2px; color: #0A6B5E; }
        .clinic-tagline { font-size: 10px; margin: 2px 0; font-weight: bold; }
        .contact-info { font-size: 9px; line-height: 1.2; margin-top: 3px; }
        .contact-info p { margin: 1px 0; }
        hr { border: none; border-top: 1px solid #000; margin: 4px 0; }
        .metadata-table { width: 100%; border: 1px solid #000; border-collapse: collapse; font-size: 10px; margin-bottom: 4px; }
        .metadata-table td { border: 1px solid #000; padding: 3px; vertical-align: top; }
        .metadata-table td:first-child { width: 35%; background-color: #f0f0f0; font-weight: bold; }
        .items-table { width: 100%; border-collapse: collapse; border: 1px solid #000; font-size: 10px; margin-bottom: 4px; }
        .items-table thead { background-color: #f0f0f0; }
        .items-table th { border: 1px solid #000; padding: 3px; text-align: left; font-weight: bold; font-size: 9px; }
        .items-table td { border: 1px solid #000; padding: 3px; vertical-align: top; }
        .text-right { text-align: right; }
        .totals-table { width: 100%; border-collapse: collapse; font-size: 10px; margin-bottom: 4px; }
        .totals-table td { padding: 3px; }
        .totals-table .net-total { background-color: #f0f0f0; font-weight: bold; border: 1px solid #000; border-top: 2px solid #000; }
        .thank-you { text-align: center; font-size: 10px; margin: 4px 0; font-weight: bold; }
        .payment-table { width: 100%; border-collapse: collapse; border: 1px solid #000; margin-bottom: 4px; font-size: 10px; }
        .payment-table td { border: 1px solid #000; padding: 3px; text-align: right; }
        .payment-table td:first-child { text-align: left; }
        .quote { text-align: center; font-size: 9px; font-style: italic; margin: 3px 0; }
        .footer { text-align: center; font-size: 8px; line-height: 1.2; margin-top: auto; border-top: 1px solid #000; padding-top: 2px; }
        .footer p { margin: 1px 0; }
        .footer .text-right { text-align: right; }
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
            <div class="clinic-info">
                <h1 class="clinic-name">
                    <img class="clinic-logo" src="assets/images/<?= htmlspecialchars($logoFile) ?>" alt="">
                    <?= $clinicName ?>
                </h1>
                <p class="website"><?= $clinicWebsite ?></p>
            </div>
            <div class="clinic-tagline"><strong><?= $clinicTagline ?></strong></div>
            <div class="contact-info">
                <p><?= $clinicAddress ?></p>
                <p>Email: <?= $clinicEmail ?></p>
                <p>Phone: <?= $clinicPhone ?></p>
            </div>
        </div>

        <hr>

        <table class="metadata-table">
            <tr><td><strong>Invoice #</strong></td><td><?= htmlspecialchars($bill['invoice_number']) ?></td></tr>
            <tr><td><strong>MR #</strong></td><td><?= htmlspecialchars($bill['mrn']) ?></td></tr>
            <tr><td><strong>Name:</strong></td><td><?= htmlspecialchars($bill['patient_name']) ?></td></tr>
            <tr><td><strong>S/D/W Of:</strong></td><td><?= htmlspecialchars($bill['father_name'] ?: '—') ?></td></tr>
            <tr><td><strong>DOB:</strong></td><td><?= $patientDobDisplay ?></td></tr>
            <tr><td><strong>Doctor:</strong></td><td><?= htmlspecialchars($bill['doctor_name']) ?></td></tr>
        </table>

        <table class="items-table">
            <thead>
                <tr><th>Checkup/Procedure</th><th class="text-right">Rate (Rs)</th><th class="text-right">Qty</th><th class="text-right">Amount (Rs)</th></tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                <tr>
                    <td><?= htmlspecialchars($item['description']) ?></td>
                    <td class="text-right"><?= number_format((float) $item['unit_rate'], 2) ?></td>
                    <td class="text-right"><?= (int) $item['quantity'] ?></td>
                    <td class="text-right"><strong><?= number_format((float) $item['amount'], 2) ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <table class="totals-table">
            <tr><td colspan="3" class="text-right">Total Amount</td><td class="text-right"><strong><?= number_format((float) $bill['subtotal'], 2) ?></strong></td></tr>
            <tr class="net-total"><td colspan="3" class="text-right"><strong>Net Total</strong></td><td class="text-right"><strong><?= number_format((float) $bill['grand_total'], 2) ?></strong></td></tr>
        </table>

        <p class="thank-you">Thank you! We wish you best of health.</p>

        <table class="payment-table">
            <tr><td colspan="3">Payment Mode</td><td><strong><?= htmlspecialchars($bill['payment_method'] ? ucfirst(str_replace('_', ' ', $bill['payment_method'])) : 'Pending') ?></strong></td></tr>
        </table>

        <hr>

        <p class="quote">"What is called genius is the abundance of life and health"</p>

        <div class="footer">
            <p>This is a computer generated receipt printed on <?= $printTimestamp ?></p>
            <p class="text-right">Front Desk: <?= htmlspecialchars($printedBy) ?></p>
        </div>

    </div>

    <script>
        window.addEventListener('load', function() { window.print(); });
    </script>
</body>
</html>
