<?php
// A5 invoice print view, included from checkout.php's ?print=1 branch.
// Expects $bill (row from bills joined to visits/patients/users) and $items (bill_items) in scope.
//
// Layout mirrors the clinic's existing printed receipt exactly: a bordered header
// box split into a left clinic block and a right metadata table, then the items
// table, totals, payment mode and an empty vitals row for the nurse to fill in by
// hand. Only the typeface differs from the original (IBM Plex Mono, except the
// wordmark which keeps Arial).

$clinicName = 'BABY MEDICS';
$clinicTagline = 'Premium Healthcare | Emergency | Vaccines';
$clinicEmail = 'info@babymedics.com';
$clinicPhone = '+92 51 5735006';
$clinicWebsite = 'b a b y m e d i c s . c o m';

$logoFile = $bill['doctor_specialty'] === 'DENTAL' ? 'logo-dental.png' : 'logo-general.png';

$patientDobDisplay = $bill['dob'] ? date('d/m/Y', strtotime($bill['dob'])) : '';
$printTimestamp = date('Y-m-d H:i:s');
$printedByStmt = $pdo->prepare('SELECT name FROM users WHERE id = ?');
$printedByStmt->execute([$_SESSION['user_id']]);
$printedBy = $printedByStmt->fetch()['name'] ?? 'Front Desk';

// The original receipt prints four blank filler rows under the single line item so
// the table always reaches the totals block at the same height.
$fillerRows = max(0, 4 - count($items));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice - <?= htmlspecialchars($bill['invoice_number']) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { width: 148mm; margin: 0; padding: 0; }
        body {
            font-family: 'IBM Plex Mono', 'Courier New', monospace;
            font-size: 10px; line-height: 1.35; color: #000; background: #fff;
        }
        .sheet { width: 100%; padding: 7mm; }

        /* ---------- Header: bordered box, clinic left / metadata right ---------- */
        .head-box { border: 1px solid #999; padding: 4mm; display: flex; gap: 4mm; }
        .head-left { width: 52%; }
        .head-right { width: 48%; }

        .brandline { display: flex; align-items: center; gap: 3px; }
        .clinic-logo { height: 26px; }
        /* Wordmark keeps the original receipt's sans face and the logo art's teal. */
        .clinic-name {
            font-family: Arial, Helvetica, sans-serif; font-size: 17px; font-weight: bold;
            letter-spacing: .3px; color: #0A6B5E; white-space: nowrap;
        }
        .website {
            font-family: Arial, Helvetica, sans-serif; font-size: 8px; font-weight: bold;
            letter-spacing: 1.6px; color: #0A6B5E; margin: 1px 0 0 30px;
        }
        .addr { font-size: 9px; line-height: 1.35; margin-top: 7px; }
        .addr b { font-weight: bold; }

        .tagline {
            font-family: Arial, Helvetica, sans-serif; font-size: 11.5px; font-weight: bold;
            margin-bottom: 3px;
        }
        .meta { width: 100%; border-collapse: collapse; font-size: 9.5px; }
        .meta td { border: 1px solid #999; padding: 2.5px 4px; vertical-align: top; }
        .meta td.k { background: #EDEDED; font-weight: bold; width: 38%; }
        .meta td.v { font-weight: bold; }

        /* ---------- Items ---------- */
        .items { width: 100%; border-collapse: collapse; font-size: 9.5px; margin-top: 5mm; }
        .items th {
            border: 1px solid #999; background: #EDEDED; padding: 4px 5px;
            font-weight: bold; text-align: center;
        }
        .items td { border: 1px solid #999; padding: 3.5px 5px; }
        .items .desc { text-align: left; }
        .items .num { text-align: center; }
        .items tr.spacer td { background: #EDEDED; height: 9px; padding: 0; }
        .items tr.blank td { height: 15px; }
        .items .lbl { text-align: right; font-weight: bold; border-left: none; border-right: none; }
        .items .grand { font-weight: bold; font-size: 11px; }
        .items .net { font-style: italic; }

        /* ---------- Footer blocks ---------- */
        .payrow { width: 100%; border-collapse: collapse; font-size: 9.5px; }
        .payrow td { border: 1px solid #999; border-top: none; padding: 4px 5px; }
        .payrow .thanks { width: 60%; }
        .payrow .mode { text-align: right; }
        .payrow .mode b { font-weight: bold; }

        .vitals { width: 100%; border-collapse: collapse; font-size: 9.5px; }
        .vitals th {
            border: 1px solid #999; border-top: none; background: #fff; padding: 5px;
            font-weight: bold; text-align: center;
        }
        .vitals td { border: 1px solid #999; border-top: none; height: 12mm; }

        .quote {
            text-align: center; font-size: 9.5px; font-style: italic; font-weight: bold;
            margin-top: 22mm;
        }
        .foot {
            display: flex; justify-content: space-between; gap: 8px;
            border-top: 1px solid #999; margin-top: 3mm; padding-top: 2px; font-size: 8px;
        }

        @media print {
            html, body { width: 148mm; height: 210mm; }
            .sheet { padding: 7mm; }
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
                    <span class="clinic-name"><?= $clinicName ?></span>
                </div>
                <div class="website"><?= $clinicWebsite ?></div>
                <div class="addr">
                    <b>Metacare,</b> Main PWD Road, Police Foundation,<br>
                    Islamabad, Pakistan.<br>
                    <b>Email:</b> <?= $clinicEmail ?><br>
                    <b>Phone:</b> <?= $clinicPhone ?>
                </div>
            </div>

            <div class="head-right">
                <div class="tagline"><?= $clinicTagline ?></div>
                <table class="meta">
                    <tr><td class="k">Invoice #</td><td class="v"><?= htmlspecialchars($bill['invoice_number']) ?></td></tr>
                    <tr><td class="k">MR #</td><td class="v"><?= htmlspecialchars($bill['mrn']) ?></td></tr>
                    <tr><td class="k">Name:</td><td class="v"><?= htmlspecialchars($bill['patient_name']) ?></td></tr>
                    <tr><td class="k">S/D/W Of:</td><td><?= htmlspecialchars($bill['father_name'] ?: '') ?></td></tr>
                    <tr><td class="k">DOB:</td><td><?= $patientDobDisplay ?></td></tr>
                    <tr><td class="k">Doctor:</td><td><?= htmlspecialchars($bill['doctor_name']) ?></td></tr>
                </table>
            </div>
        </div>

        <table class="items">
            <thead>
                <tr>
                    <th style="width:42%;">Checkup/Procedure</th>
                    <th style="width:20%;">Fee/Price (Rs)</th>
                    <th style="width:16%;">Qty</th>
                    <th style="width:22%;">Amount (Rs)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                <tr>
                    <td class="desc"><?= htmlspecialchars($item['description']) ?></td>
                    <td class="num"><?= number_format((float) $item['unit_rate'], 0) ?></td>
                    <td class="num"><?= (int) $item['quantity'] > 1 ? (int) $item['quantity'] : '' ?></td>
                    <td class="num"><?= number_format((float) $item['amount'], 0) ?></td>
                </tr>
                <?php endforeach; ?>

                <tr class="spacer"><td colspan="4"></td></tr>

                <tr>
                    <td class="blank">&nbsp;</td><td></td>
                    <td class="lbl">Total Amount</td>
                    <td class="num grand"><?= number_format((float) $bill['subtotal'], 0) ?></td>
                </tr>
                <?php for ($i = 0; $i < $fillerRows; $i++): ?>
                <tr class="blank"><td>&nbsp;</td><td></td><td></td><td></td></tr>
                <?php endfor; ?>
                <tr>
                    <td class="blank">&nbsp;</td><td></td>
                    <td class="lbl">Net Total</td>
                    <td class="num grand net"><?= number_format((float) $bill['grand_total'], 0) ?></td>
                </tr>
            </tbody>
        </table>

        <table class="payrow">
            <tr>
                <td class="thanks">Thank you! We wish you best of health.</td>
                <td class="mode"><b>Payment Mode:</b> <?= htmlspecialchars($bill['payment_method'] ? ucfirst(str_replace('_', ' ', $bill['payment_method'])) : 'Pending') ?></td>
            </tr>
        </table>

        <table class="vitals">
            <tr><th style="width:38%;">Temperature</th><th style="width:32%;">Weight</th><th style="width:30%;">Height</th></tr>
            <tr><td></td><td></td><td></td></tr>
        </table>

        <p class="quote">"What is called genius is the abundance of life and health"</p>

        <div class="foot">
            <span>This is a computer generated receipt printed on <?= $printTimestamp ?></span>
            <span>Front Desk: <?= htmlspecialchars($printedBy) ?></span>
        </div>

    </div>

    <script>
        window.addEventListener('load', function() { window.print(); });
    </script>
</body>
</html>
