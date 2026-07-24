<?php
// A5 invoice print view, included from checkout.php's ?print=1 branch.
// Expects $bill (row from bills joined to visits/patients/users) and $items (bill_items) in scope.
//
// Mirrors the clinic's existing printed receipt. Two structural points matter for
// matching it: the header is a bordered box split clinic-left / metadata-right, and
// everything below it (items, totals, payment mode, vitals) is ONE table sharing a
// single column grid — that is what makes the vertical rules line up down the page.
// Only the typeface differs from the original: Lora throughout, except the
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

// Payment mode is captured and settled at registration (see config/billing.php),
// so it is a real, confirmed value on the printed slip — not "Pending". A free
// follow-up (net 0, status 'waived') prints "Waived" rather than a cash/card mode.
$paymentModeLabels = ['cash' => 'Cash', 'card' => 'Online / Card', 'bank_transfer' => 'Bank Transfer', 'cheque' => 'Cheque'];
if (($bill['status'] ?? '') === 'waived' || $netFee <= 0) {
    $paymentModeDisplay = 'Waived (free visit)';
} else {
    $paymentModeDisplay = $paymentModeLabels[$bill['payment_method'] ?? ''] ?? '—';
}

// Everything below the vitals row is left blank on purpose: it is the doctor's
// handwriting area, so the sheet must not stretch to fill it.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice - <?= htmlspecialchars($bill['invoice_number']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Lora:ital,wght@0,400;0,600;0,700;1,400&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { width: 148mm; margin: 0; padding: 0; }
        body {
            font-family: 'Lora', Georgia, 'Times New Roman', serif;
            font-size: 9.5px; line-height: 1.3; color: #000; background: #fff;
        }
        .sheet { width: 100%; padding: 6mm 6mm 4mm; display: flex; flex-direction: column; min-height: 210mm; }

        /* ---------- Header box ---------- */
        /* Bottom padding is ~1mm so the tables sit hard against the box edge and the
           fee table below butts straight onto it. */
        .head-box { border: 1px solid #B0B0B0; padding: 3mm 3.5mm 1mm; display: flex; gap: 5mm; align-items: stretch; }
        /* Both halves are flex columns whose table is pushed to the bottom by an auto
           top margin. Since the two tables have the same four rows, bottom-aligning
           them also top-aligns them — the rows line up without either side depending
           on a hand-tuned offset that would drift whenever the text above changes. */
        .head-left, .head-right { width: 50%; display: flex; flex-direction: column; }
        .head-left > .ids, .head-right > .meta { margin-top: auto; }

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
        .addr { font-size: 9px; line-height: 1.35; margin-top: 5px; margin-bottom: 4px; }

        /* Sized and spaced so the Email/Phone pair below lands level with the two
           address lines opposite — the tagline's height is what buys that alignment. */
        .tagline {
            font-family: Arial, Helvetica, sans-serif; font-size: 14px; font-weight: bold;
            line-height: 1.15; margin-bottom: 9px; white-space: nowrap;
        }
        .meta { width: 100%; border-collapse: collapse; font-size: 9px; }
        .meta td { border: 1px solid #C8C8C8; padding: 3px 5px; vertical-align: top; }
        /* Lighter grey than before, with the label text bolded so it still reads
           strongly against the paler ground. */
        .meta td.k { background: #F4F4F4; font-weight: bold; color: #000; width: 40%; }
        .meta td.v { font-weight: bold; }

        /* Clinic contact sits above the patient table, mirroring the street address
           opposite so both columns carry two lines before their tables begin. */
        .clinic-contact { margin-top: 0; margin-bottom: 4px; }

        /* Identifiers sit under the clinic block, opposite the patient details.
           Vertical placement comes from the flex rule above, not a fixed margin. */
        .ids { width: 100%; border-collapse: collapse; font-size: 9px; }
        .ids td { border: 1px solid #C8C8C8; padding: 3px 5px; }
        .ids td.k { background: #F4F4F4; font-weight: bold; width: 42%; }
        .ids td.v { font-weight: bold; }

        /* ---------- Fee line + vitals ---------- */
        .fee-table, .vitals-table { width: 100%; border-collapse: collapse; font-size: 9.5px; }
        /* Payment mode as a light footnote riding beside the patient name. */
        .pay-note { font-weight: normal; font-size: 8px; color: #555; white-space: nowrap; }
        /* Butted straight against the header box: -1px so the two share a single rule
           rather than showing a gap or a doubled border. */
        .fee-table { margin-top: -1px; }
        .fee-table td, .fee-table th,
        .vitals-table td, .vitals-table th { border: 1px solid #C8C8C8; padding: 4px 6px; }
        /* Heading cells share the same greyscale ground as the header tables' label
           column, so every heading on the sheet reads as one system: grey + bold. */
        .fee-table th, .vitals-table th { background: #F4F4F4; font-weight: bold; text-align: center; }
        .fee-table .desc { text-align: left; }
        .fee-table .num { text-align: center; }
        .vitals-table { margin-top: -1px; }
        /* Write-in row: tall enough to take a handwritten figure, at least the height
           of the label row above it. */
        .vitals-cell td { height: 9mm; }

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
                    <b>Polymedics,</b> 2165-F, NPF, PWD Double Road<br>
                    Islamabad, Pakistan.
                </div>
                <!-- Pushed down so row 1 lines up with row 1 of the patient table opposite. -->
                <table class="ids">
                    <tr><td class="k">MR #</td><td class="v"><?= htmlspecialchars($bill['mrn']) ?></td></tr>
                    <tr><td class="k">Invoice #</td><td class="v"><?= htmlspecialchars($bill['invoice_number']) ?></td></tr>
                    <tr><td class="k">Token</td><td class="v"><?= (int) $bill['token_no'] ?></td></tr>
                    <tr><td class="k">Doctor</td><td class="v"><?= htmlspecialchars(mb_strtoupper($bill['doctor_name'], 'UTF-8')) ?></td></tr>
                </table>
            </div>

            <div class="head-right">
                <div class="tagline"><?= $clinicTagline ?></div>
                <div class="addr clinic-contact">
                    <b>Email:</b> <?= $clinicEmail ?><br>
                    <b>Phone:</b> <?= $clinicPhone ?>
                </div>
                <table class="meta">
                    <tr><td class="k">Name:</td><td class="v"><?= htmlspecialchars($patientNameUpper) ?> <span class="pay-note">(Paid: <?= htmlspecialchars($paymentModeDisplay) ?>)</span></td></tr>
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

        <!-- Payment mode now prints as a small note beside the patient name in the
             header (see .pay-note), so the fee table butts straight onto the vitals block. -->
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
            <span>Front Desk: <?= htmlspecialchars(mb_strtoupper($printedBy, 'UTF-8')) ?></span>
        </div>

    </div>

    <script>
        window.addEventListener('load', function() { window.print(); });
    </script>
</body>
</html>
