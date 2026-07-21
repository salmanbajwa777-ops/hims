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
$clinicTagline = 'Premium Healthcare | Vaccines';
$clinicEmail = 'info@babymedics.com';
$clinicPhone = '+92 51 5735006';
$clinicWebsite = 'b a b y m e d i c s . c o m';

$logoFile = $bill['doctor_specialty'] === 'DENTAL' ? 'logo-dental.png' : 'logo-general.png';

$patientDobDisplay = $bill['dob'] ? date('d/m/Y', strtotime($bill['dob'])) : '';
$printTimestamp = date('Y-m-d H:i:s');
$printedByStmt = $pdo->prepare('SELECT name FROM users WHERE id = ?');
$printedByStmt->execute([$_SESSION['user_id']]);
$printedBy = $printedByStmt->fetch()['name'] ?? 'Front Desk';

// Names print in caps regardless of how reception typed them. mb_strtoupper (not
// strtoupper) so non-ASCII characters aren't mangled.
$patientNameUpper = mb_strtoupper($bill['patient_name'], 'UTF-8');
$fatherNameUpper = $bill['father_name'] ? mb_strtoupper($bill['father_name'], 'UTF-8') : '';

// This is the consultation-fee slip: always exactly one priced line, whatever the
// visit's consultation type was. Services and procedures get their own invoice
// design later, so nothing here iterates $items.
//
// The gross fee and the discount come from the visit, not from bill_items —
// bill_items.unit_rate already has the discount applied (see config/billing.php),
// so the pre-discount figure only exists on visits.fee.
$grossFee = (float) $bill['fee'];
$discountPct = (float) $bill['discount_pct'];
$discountAmount = round($grossFee * ($discountPct / 100), 2);
$netFee = round($grossFee - $discountAmount, 2);
$consultLabel = $items[0]['description'] ?? 'Consultation';

// Everything below the vitals row is left blank on purpose: it is the doctor's
// handwriting area, so the sheet must not stretch to fill it.
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
            font-size: 9.5px; line-height: 1.3; color: #000; background: #fff;
        }
        .sheet { width: 100%; padding: 6mm 6mm 4mm; display: flex; flex-direction: column; min-height: 210mm; }

        /* ---------- Header box ---------- */
        .head-box { border: 1px solid #B0B0B0; padding: 3mm 3.5mm; display: flex; gap: 5mm; }
        .head-left, .head-right { width: 50%; }

        /* Wordmark and web address are a tight pair whose combined height matches the
           logo, so the three read as one lockup rather than a loose stack. */
        .brandline { display: flex; align-items: center; gap: 3px; }
        .clinic-logo {
            height: 30px; display: block;
            /* The PNGs carry real transparency; without an explicit white ground the
               print pipeline composites them against a shaded backdrop and a grey
               box appears behind the artwork. */
            background: #fff;
        }
        .brandtext { display: flex; flex-direction: column; justify-content: center; height: 30px; }
        .clinic-name {
            font-family: Arial, Helvetica, sans-serif; font-size: 18px; font-weight: bold;
            letter-spacing: .2px; color: #0F7362; white-space: nowrap; line-height: 1;
        }
        .website {
            font-family: Arial, Helvetica, sans-serif; font-size: 8px; font-weight: bold;
            letter-spacing: 1.7px; color: #4A4A4A; line-height: 1; margin-top: 2px;
        }
        .addr { font-size: 9px; line-height: 1.4; margin-top: 7px; }

        .tagline {
            font-family: Arial, Helvetica, sans-serif; font-size: 12px; font-weight: bold;
            margin-bottom: 3px; white-space: nowrap;
        }
        .meta { width: 100%; border-collapse: collapse; font-size: 9px; }
        .meta td { border: 1px solid #C8C8C8; padding: 3px 5px; vertical-align: top; }
        .meta td.k { background: #EDEDED; font-weight: bold; width: 40%; }
        .meta td.v { font-weight: bold; }

        /* Clinic contact sits above the patient table, mirroring the street address
           opposite so both columns carry two lines before their tables begin. */
        .clinic-contact { margin-top: 0; margin-bottom: 3px; }

        /* Identifiers sit under the clinic block, opposite the patient details. The
           top margin is what lands row 1 level with row 1 of the table opposite. */
        .ids { width: 100%; border-collapse: collapse; font-size: 9px; margin-top: 7mm; }
        .ids td { border: 1px solid #C8C8C8; padding: 3px 5px; }
        .ids td.k { background: #EDEDED; font-weight: bold; width: 42%; }
        .ids td.v { font-weight: bold; }

        /* ---------- Fee line + vitals ---------- */
        .fee-table, .vitals-table { width: 100%; border-collapse: collapse; font-size: 9.5px; }
        .fee-table { margin-top: 4mm; }
        .fee-table td, .fee-table th,
        .vitals-table td, .vitals-table th { border: 1px solid #C8C8C8; padding: 4px 6px; }
        .fee-table th, .vitals-table th { background: #FFFFFF; font-weight: bold; text-align: center; }
        .fee-table .desc { text-align: left; }
        .fee-table .num { text-align: center; }
        /* No gap and no doubled rule between the two tables. */
        .vitals-table { margin-top: -1px; }
        .vitals-cell td { font-size: 10px; line-height: 1; padding: 5px 6px; }

        /* The area below is the doctor's handwriting space, so the quote is NOT pushed
           to the foot of the page — it sits just under the vitals block and the sheet
           simply ends, leaving the rest blank. */
        .quote {
            text-align: center; font-size: 9.5px; font-style: italic; font-weight: normal;
            margin-top: auto; padding-top: 5mm;
        }
        .foot {
            display: flex; justify-content: space-between; gap: 10px;
            border-top: 1px solid #B0B0B0; margin-top: 1.5mm; padding-top: 2px; font-size: 7.5px;
        }

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
                <div class="addr">
                    <b>Metacare,</b> Main PWD Road, Police Foundation,<br>
                    Islamabad, Pakistan.
                </div>
                <!-- Pushed down so row 1 lines up with row 1 of the patient table opposite. -->
                <table class="ids">
                    <tr><td class="k">MR #</td><td class="v"><?= htmlspecialchars($bill['mrn']) ?></td></tr>
                    <tr><td class="k">Invoice #</td><td class="v"><?= htmlspecialchars($bill['invoice_number']) ?></td></tr>
                    <tr><td class="k">Token</td><td class="v"><?= (int) $bill['token_no'] ?></td></tr>
                    <tr><td class="k">Doctor</td><td class="v"><?= htmlspecialchars($bill['doctor_name']) ?></td></tr>
                </table>
            </div>

            <div class="head-right">
                <div class="tagline"><?= $clinicTagline ?></div>
                <div class="addr clinic-contact">
                    <b>Email:</b> <?= $clinicEmail ?><br>
                    <b>Phone:</b> <?= $clinicPhone ?>
                </div>
                <table class="meta">
                    <tr><td class="k">Name:</td><td class="v"><?= htmlspecialchars($patientNameUpper) ?></td></tr>
                    <tr><td class="k">S/D/W Of:</td><td class="v"><?= htmlspecialchars($fatherNameUpper) ?></td></tr>
                    <tr><td class="k">DOB:</td><td><?= $patientDobDisplay ?></td></tr>
                    <tr><td class="k">Phone:</td><td><?= htmlspecialchars($bill['phone']) ?></td></tr>
                </table>
            </div>
        </div>

        <table class="fee-table">
            <colgroup>
                <col style="width:40%"><col style="width:20%"><col style="width:18%"><col style="width:22%">
            </colgroup>
            <thead>
                <tr>
                    <th>Checkup/Procedure</th>
                    <th>Fee (Rs)</th>
                    <th>Discount</th>
                    <th>Net Total (Rs)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="desc"><?= htmlspecialchars($consultLabel) ?></td>
                    <td class="num"><?= number_format($grossFee, 0) ?></td>
                    <td class="num"><?= $discountAmount > 0 ? number_format($discountAmount, 0) : '—' ?></td>
                    <td class="num"><?= number_format($netFee, 0) ?></td>
                </tr>
            </tbody>
        </table>

        <!-- Butted directly against the fee table: border-top is suppressed so the two
             read as one block rather than two tables with a seam. -->
        <table class="vitals-table">
            <colgroup>
                <col style="width:25%"><col style="width:25%"><col style="width:25%"><col style="width:25%">
            </colgroup>
            <tr><th>Temperature</th><th>Weight</th><th>Height</th><th>OFC</th></tr>
            <tr class="vitals-cell"><td></td><td></td><td></td><td></td></tr>
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
