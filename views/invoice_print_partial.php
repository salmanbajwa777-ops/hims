<?php
// A5 invoice print view, included from checkout.php's ?print=1 branch.
// Expects $bill (row from bills joined to visits/patients/users) and $items (bill_items) in scope.
//
// Mirrors the clinic's existing printed receipt. Two structural points matter for
// matching it: the header is a bordered box split clinic-left / metadata-right, and
// everything below it (items, totals, payment mode, vitals) is ONE table sharing a
// single column grid — that is what makes the vertical rules line up down the page.
// Only the typeface differs from the original: IBM Plex Mono throughout, except the
// wordmark and tagline which stay on the original's sans face.

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

$paymentModeLabel = $bill['payment_method']
    ? ucfirst(str_replace('_', ' ', $bill['payment_method']))
    : 'Pending';

// The original keeps the body of the table a fixed height regardless of how many
// items were billed, so the totals always land in the same place on the page.
$fillerRows = max(0, 3 - count($items));
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
            font-size: 9.5px; line-height: 1.35; color: #000; background: #fff;
        }
        .sheet { width: 100%; padding: 6mm; display: flex; flex-direction: column; min-height: 210mm; }

        /* ---------- Header box ---------- */
        .head-box { border: 1px solid #B0B0B0; padding: 3.5mm 4mm; display: flex; gap: 5mm; }
        .head-left { width: 54%; }
        .head-right { width: 46%; }

        .brandline { display: flex; align-items: center; gap: 4px; }
        .clinic-logo { height: 27px; }
        /* Wordmark and tagline keep the original receipt's sans face. */
        .clinic-name {
            font-family: Arial, Helvetica, sans-serif; font-size: 18px; font-weight: bold;
            letter-spacing: .2px; color: #0F7362; white-space: nowrap;
        }
        .website {
            font-family: Arial, Helvetica, sans-serif; font-size: 8px; font-weight: bold;
            letter-spacing: 1.7px; color: #4A4A4A; margin: 2px 0 0 32px;
        }
        .addr { font-size: 9px; line-height: 1.4; margin-top: 8px; }

        .tagline {
            font-family: Arial, Helvetica, sans-serif; font-size: 12px; font-weight: bold;
            margin-bottom: 4px; white-space: nowrap;
        }
        .meta { width: 100%; border-collapse: collapse; font-size: 9px; }
        .meta td { border: 1px solid #C8C8C8; padding: 3px 5px; vertical-align: top; }
        .meta td.k { background: #EDEDED; font-weight: bold; width: 40%; }
        .meta td.v { font-weight: bold; }

        /* ---------- Body: one grid for items + totals + payment + vitals ---------- */
        .body-table { width: 100%; border-collapse: collapse; font-size: 9.5px; margin-top: 5mm; }
        .body-table td, .body-table th { border: 1px solid #C8C8C8; padding: 4px 6px; }
        .body-table th { background: #FFFFFF; font-weight: bold; text-align: center; }
        .body-table .desc { text-align: left; }
        .body-table .num { text-align: center; }
        .band td { background: #E8E8E8; height: 10px; padding: 0; }
        .empty td { height: 16px; }
        /* Totals label sits in the Qty column, value in Amount — the two cells to the
           left stay bordered so the grid reads continuously down the page. */
        .tot .lbl { text-align: right; font-weight: bold; }
        .tot .val { text-align: center; font-weight: bold; font-size: 11px; }
        .tot .val.net { font-style: italic; }
        .payline .thanks { text-align: left; }
        .payline .mode { text-align: right; }
        .vitals-head th { font-weight: bold; }
        .vitals-cell td { height: 13mm; }

        .quote {
            text-align: center; font-size: 9.5px; font-style: italic; font-weight: bold;
            margin-top: auto; padding: 14mm 0 0;
        }
        .foot {
            display: flex; justify-content: space-between; gap: 10px;
            border-top: 1px solid #B0B0B0; margin-top: 4mm; padding-top: 3px; font-size: 7.5px;
        }

        @media print {
            html, body { width: 148mm; height: 210mm; }
            .sheet { min-height: 210mm; padding: 6mm; }
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

        <table class="body-table">
            <colgroup>
                <col style="width:40%"><col style="width:20%"><col style="width:18%"><col style="width:22%">
            </colgroup>

            <thead>
                <tr>
                    <th>Checkup/Procedure</th>
                    <th>Fee/Price (Rs)</th>
                    <th>Qty</th>
                    <th>Amount (Rs)</th>
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

                <tr class="band"><td></td><td></td><td></td><td></td></tr>

                <tr class="tot">
                    <td>&nbsp;</td><td></td>
                    <td class="lbl">Total Amount</td>
                    <td class="val"><?= number_format((float) $bill['subtotal'], 0) ?></td>
                </tr>

                <?php for ($i = 0; $i < $fillerRows; $i++): ?>
                <tr class="empty"><td>&nbsp;</td><td></td><td></td><td></td></tr>
                <?php endfor; ?>

                <tr class="tot">
                    <td>&nbsp;</td><td></td>
                    <td class="lbl">Net Total</td>
                    <td class="val net"><?= number_format((float) $bill['grand_total'], 0) ?></td>
                </tr>

                <tr class="payline">
                    <td class="thanks" colspan="2">Thank you! We wish you best of health.</td>
                    <td class="mode" colspan="2"><b>Payment Mode:</b> <?= htmlspecialchars($paymentModeLabel) ?></td>
                </tr>

                <tr class="vitals-head">
                    <th colspan="2">Temperature</th>
                    <th>Weight</th>
                    <th>Height</th>
                </tr>
                <tr class="vitals-cell">
                    <td colspan="2"></td><td></td><td></td>
                </tr>
            </tbody>
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
