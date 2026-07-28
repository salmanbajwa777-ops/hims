<?php
// A5 procedure slip, included from procedure_bill.php's ?print=1 branch.
// Expects $bill (procedure_bills joined to patients + the performing doctor) and
// $items (procedure_bill_items) in scope.
//
// Forked from views/er_invoice_print_partial.php: same clinic header box, same
// row grid and footer, and it ITERATES line items the same way (a bill can carry
// several procedures). Two deliberate differences from the ER slip:
//   1. A procedure HAS a performing doctor, so the doctor is named on the slip —
//      the ER slip drops that row because a walk-in service has none.
//   2. A patient signature block, since a procedure is a physical intervention.
//      This is the receipt's acknowledgement line, NOT the clinical consent
//      form. That document now exists: when the bill carries a procedure with a
//      consent template, views/procedure_consent_print_partial.php appends two
//      copies of it as further pages of this same document, so reception gets
//      the receipt and both consent copies from one print action.
//
// A5 only, and no tax, matching every other slip in the system.

require_once __DIR__ . '/../config/brand.php';
$b = brand();
$clinicName = $b['name'];
$clinicTagline = $b['tagline'];
$clinicEmail = $b['email'];
$clinicPhone = $b['phone'];
$clinicWebsite = $b['website'];

// This used to hardcode logo-general.png ("procedures aren't tied to the
// dental/specialty icon swap"), which was wrong once dental went live: a crown
// billed by a dentist printed the paediatric logo. A procedure DOES have a
// performing doctor, so it follows the same rule as the consultation slip.
$logoFile = brand_logo($bill['doctor_specialty'] ?? null);
$isDentalDoc = ($bill['doctor_specialty'] ?? '') === 'DENTAL';

$patientDobDisplay = $bill['dob'] ? date('d/m/Y', strtotime($bill['dob'])) : '';
$printTimestamp = date('Y-m-d H:i:s');
$printedBy = $bill['generated_by_name'] ?? 'Front Desk';

$patientNameUpper = mb_strtoupper($bill['patient_name'], 'UTF-8');
$fatherNameUpper = $bill['father_name'] ? mb_strtoupper($bill['father_name'], 'UTF-8') : '';
$doctorNameUpper = mb_strtoupper($bill['doctor_name'] ?? '', 'UTF-8');

$grandTotal = (float) $bill['grand_total'];
$subtotal = (float) $bill['subtotal'];
// Stored on the bill rather than re-derived, so a later rate change can't shift
// what the reprinted slip says was discounted.
$discountAmount = (float) $bill['discount_amount'];

$isVoided = !empty($bill['voided_at']);
$paymentModeLabels = ['cash' => 'Cash', 'card' => 'Online / Card', 'bank_transfer' => 'Bank Transfer', 'cheque' => 'Cheque'];
if (($bill['status'] ?? '') === 'waived' || $grandTotal <= 0) {
    $paymentModeDisplay = 'Waived (no charge)';
} else {
    $paymentModeDisplay = $paymentModeLabels[$bill['payment_method'] ?? ''] ?? '—';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Procedure Bill - <?= htmlspecialchars($bill['invoice_number']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Lora:ital,wght@0,400;0,600;0,700;1,400&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { width: 148mm; margin: 0; padding: 0; }
        body { font-family: 'Lora', Georgia, 'Times New Roman', serif; font-size: 9.5px; line-height: 1.3; color: #000; background: #fff; }
        .sheet { width: 100%; padding: 6mm 6mm 4mm; display: flex; flex-direction: column; min-height: 210mm; }

        .head-box { border: 1px solid #B0B0B0; padding: 3mm 3.5mm 1.4mm; display: flex; gap: 5mm; }
        .head-left, .head-right { width: 50%; display: flex; flex-direction: column; }
        .band-1 { height: 30px; display: flex; align-items: center; }
        .band-2 { height: 27px; display: flex; flex-direction: column; justify-content: center; margin-bottom: 3px; }
        .brandline { display: flex; align-items: center; gap: 3px; }
        .clinic-logo { height: 30px; display: block; background: #fff; }
        .brandtext { display: flex; flex-direction: column; justify-content: center; height: 30px; }
        .clinic-name { font-family: Arial, Helvetica, sans-serif; font-size: 18px; font-weight: bold; letter-spacing: .2px; color: #0F7362; white-space: nowrap; line-height: 1; }
        .website { font-family: Arial, Helvetica, sans-serif; font-size: 8px; font-weight: bold; letter-spacing: 1.7px; color: #4A4A4A; line-height: 1; margin-top: 2px; }
        .addr { font-size: 9px; line-height: 1.35; }
        .tagline { font-family: Arial, Helvetica, sans-serif; font-size: 14px; font-weight: bold; line-height: 1.15; white-space: nowrap; }

        .ids, .meta { width: 100%; border-collapse: collapse; font-size: 9px; margin-top: auto; }
        .ids td, .meta td { border: 1px solid #C8C8C8; padding: 0 5px; height: 18px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; vertical-align: middle; }
        .ids td.k, .meta td.k { background: #F4F4F4; font-weight: bold; color: #000; width: 40%; }
        .ids td.v, .meta td.v { font-weight: bold; }

        .doctype { text-align: center; font-weight: bold; font-size: 11px; letter-spacing: 2px; margin: 3mm 0 1mm; }
        .voidmark { text-align: center; font-size: 10px; font-weight: bold; letter-spacing: 1px; border: 1.5px solid #000; padding: 2px 6px; margin: 2mm auto 0; width: fit-content; }

        /* Performing doctor — a full-width row above the line items. */
        .docrow { width: 100%; border-collapse: collapse; font-size: 9px; margin-bottom: 1mm; }
        .docrow td { border: 1px solid #C8C8C8; padding: 0 5px; height: 18px; vertical-align: middle; }
        .docrow td.k { background: #F4F4F4; font-weight: bold; width: 22%; }
        .docrow td.v { font-weight: bold; }

        .fee-table { width: 100%; border-collapse: collapse; font-size: 9.5px; margin-top: -1px; }
        .fee-table td, .fee-table th { border: 1px solid #C8C8C8; padding: 0 6px; height: 18px; vertical-align: middle; }
        .fee-table th { background: #F4F4F4; font-weight: bold; text-align: center; }
        .fee-table .desc { text-align: left; }
        .fee-table .num { text-align: right; }
        .fee-table tr.sub td { font-weight: normal; }
        .fee-table tr.total td { background: #F4F4F4; font-weight: bold; border-top: 1.5px solid #000; }

        /* Acknowledgement of the procedure having been performed. */
        .sigrow { display: flex; justify-content: space-between; gap: 12mm; margin-top: 12mm; }
        .sigbox { flex: 1; text-align: center; }
        .sigline { border-top: 1px solid #000; margin-bottom: 1.5px; }
        .siglabel { font-size: 8px; color: #333; }

        .quote { text-align: center; font-size: 9.5px; font-style: italic; font-weight: normal; margin-top: auto; padding-top: 5mm; }
        .foot { display: flex; justify-content: space-between; gap: 10px; border-top: 1px solid #B0B0B0; margin-top: 1.5mm; padding-top: 2px; font-size: 7.5px; }
        .pay-note { font-weight: normal; color: #555; white-space: nowrap; text-align: center; }

        /* ---- Consent sheets, appended after the receipt ------------------
           Only loaded into the same document; each consent copy is its own
           .sheet and starts on a new page (see .consent-sheet below). */
        .pt { width: 100%; border-collapse: collapse; font-size: 9px; margin-bottom: 2mm; }
        .pt td { border: 1px solid #C8C8C8; padding: 1px 5px; height: 18px; vertical-align: middle; }
        .pt td.k { background: #F4F4F4; font-weight: bold; width: 17%; }
        .pt td.v { font-weight: bold; width: 33%; }
        .sect { font-size: 9px; font-weight: bold; letter-spacing: 1.1px; margin: 3mm 0 1.5mm; text-transform: uppercase; border-bottom: 1px solid #B0B0B0; padding-bottom: 1.5px; }
        .body-text { font-size: 9.5px; line-height: 1.75; text-align: justify; overflow-wrap: break-word; }
        .body-text p { margin: 0 0 2.5mm; }
        /* Fill-in rule. A fixed width (not min-width) with max-width:100% so a
           long typed name can never push the justified paragraph off the sheet. */
        .blank { display: inline-block; border-bottom: 1px solid #000; width: 34mm; max-width: 100%; text-align: center; font-weight: bold; line-height: 1.15; vertical-align: baseline; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .fillhint { font-size: 7px; color: #666; font-style: italic; }
        /* margin-top:auto pins the signatures to the foot of the sheet, leaving
           the mid-page clear — that gap is where the doctor writes notes, as on
           the paper form this replaces. */
        .sigblock { display: flex; justify-content: space-between; gap: 6mm; margin-top: auto; padding-top: 8mm; }
        .sigbox2 { flex: 1; }
        .sigline2 { border-top: 1px solid #444; padding-top: 2px; font-size: 8.5px; font-weight: bold; }
        .sigsub { font-size: 7.5px; color: #555; margin-top: 1px; }

        @media print {
            html, body { width: 148mm; }
            .sheet { min-height: 210mm; padding: 6mm 6mm 4mm; }
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            @page { size: A5; margin: 0; }
            /* Each consent copy is its own page. Without this the sheets run on
               and the signature block lands halfway down the next sheet.
               `height: 210mm` was also dropped from html/body above: it caps the
               document at ONE page, so with consents attached the browser
               printed the receipt and silently discarded everything after it. */
            .consent-sheet { page-break-before: always; break-before: page; }
            .sheet { page-break-inside: avoid; break-inside: avoid; }
        }
    </style>
</head>
<body>
    <div class="sheet">

        <div class="head-box">
            <div class="head-left">
                <div class="band-1">
                    <div class="brandline">
                        <img class="clinic-logo" src="assets/images/<?= htmlspecialchars($logoFile) ?>" alt="">
                        <span class="brandtext">
                            <span class="clinic-name"><?= $clinicName ?></span>
                            <span class="website"><?= $clinicWebsite ?></span>
                        </span>
                    </div>
                </div>
                <div class="band-2 addr">
                    <div><b><?= htmlspecialchars($b['address_lead']) ?></b> <?= htmlspecialchars($b['address_line1']) ?></div>
                    <div><?= htmlspecialchars($b['address_line2']) ?></div>
                </div>
                <table class="ids">
                    <tr><td class="k">MR #</td><td class="v"><?= htmlspecialchars($bill['mrn']) ?></td></tr>
                    <tr><td class="k">Invoice #</td><td class="v"><?= htmlspecialchars($bill['invoice_number']) ?></td></tr>
                    <tr><td class="k">Date</td><td class="v"><?= date('d/m/Y H:i', strtotime($bill['created_at'])) ?></td></tr>
                    <tr><td class="k">Type</td><td class="v"><?= $isDentalDoc ? 'Dental Procedure' : 'Procedure' ?></td></tr>
                </table>
            </div>

            <div class="head-right">
                <div class="band-1">
                    <div class="tagline"><?= $clinicTagline ?></div>
                </div>
                <div class="band-2 addr">
                    <div><b>Email:</b> <?= $clinicEmail ?></div>
                    <div><b>Phone:</b> <?= $clinicPhone ?></div>
                </div>
                <table class="meta">
                    <tr><td class="k">Name:</td><td class="v"><?= htmlspecialchars($patientNameUpper) ?></td></tr>
                    <tr><td class="k">S/D/W Of:</td><td class="v"><?= htmlspecialchars($fatherNameUpper) ?></td></tr>
                    <tr><td class="k">DOB:</td><td><?= $patientDobDisplay ?></td></tr>
                    <tr><td class="k">Phone:</td><td><?= htmlspecialchars($bill['phone']) ?></td></tr>
                </table>
            </div>
        </div>

        <div class="doctype"><?= $isDentalDoc ? 'DENTAL PROCEDURE RECEIPT' : 'PROCEDURE RECEIPT' ?></div>
        <?php if ($isVoided): ?>
        <div class="voidmark">VOID &mdash; this bill has been reversed</div>
        <?php endif; ?>

        <table class="docrow">
            <tr><td class="k">Performed by</td><td class="v"><?= htmlspecialchars($doctorNameUpper) ?></td></tr>
        </table>

        <table class="fee-table">
            <colgroup>
                <col style="width:52%"><col style="width:14%"><col style="width:16%"><col style="width:18%">
            </colgroup>
            <thead>
                <tr><th class="desc">Procedure</th><th class="num">Qty</th><th class="num">Rate</th><th class="num">Amount (Rs)</th></tr>
            </thead>
            <tbody>
                <?php foreach ($items as $it): ?>
                <tr>
                    <td class="desc"><?= htmlspecialchars($it['description']) ?></td>
                    <td class="num"><?= (int) $it['quantity'] ?></td>
                    <td class="num"><?= number_format((float) $it['unit_rate'], 0) ?></td>
                    <td class="num"><?= number_format((float) $it['amount'], 0) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if ($discountAmount > 0): ?>
                <tr class="sub"><td class="desc" colspan="3">Subtotal</td><td class="num"><?= number_format($subtotal, 0) ?></td></tr>
                <tr class="sub"><td class="desc" colspan="3">Discount</td><td class="num">&minus; <?= number_format($discountAmount, 0) ?></td></tr>
                <?php endif; ?>
                <tr class="total"><td class="desc" colspan="3">TOTAL PAID</td><td class="num"><?= number_format($grandTotal, 0) ?></td></tr>
            </tbody>
        </table>

        <div class="sigrow">
            <div class="sigbox">
                <div class="sigline"></div>
                <div class="siglabel">Patient / Guardian signature</div>
            </div>
            <div class="sigbox">
                <div class="sigline"></div>
                <div class="siglabel">Performed by</div>
            </div>
        </div>

        <p class="quote">"What is called genius is the abundance of life and health"</p>

        <div class="foot">
            <span>This is a computer generated receipt printed on <?= $printTimestamp ?></span>
            <span class="pay-note">Paid: <?= htmlspecialchars($paymentModeDisplay) ?></span>
            <span>Front Desk: <?= htmlspecialchars(mb_strtoupper($printedBy, 'UTF-8')) ?></span>
        </div>

    </div>

    <?php
    // Consent sheet, one per consent, appended as a further page of the SAME
    // document so the whole set comes out of one print action. $consents is
    // empty for a procedure with no consent template, and this emits nothing.
    if (!empty($consents)) {
        require __DIR__ . '/procedure_consent_print_partial.php';
    }
    ?>

    <script>
        window.addEventListener('load', function() { window.print(); });
    </script>
</body>
</html>
