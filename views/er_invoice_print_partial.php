<?php
// A5 ER-service slip, included from er_bill.php's ?print=1 branch.
// Expects $bill (row from er_bills joined to patients) and $items (er_bill_items)
// in scope. Forked from views/invoice_print_partial.php: same clinic header box,
// same row grid and footer, but this slip ITERATES the line items (an ER bill can
// carry several services) and drops the vitals rows — there is no clinical exam
// on a walk-in service charge. The attending doctor IS shown: naming one is now
// mandatory when raising the bill, so the slip says who the service was given
// under. Bills predating that requirement have none, and the row is omitted.
//
// A5 only: the per-doctor A4 preference deliberately does NOT apply here — an ER
// walk-in is a front-desk document, not that doctor's consultation slip. No tax,
// matching every other slip in the system.

require_once __DIR__ . '/../config/brand.php';
$b = brand();
$clinicName = $b['name'];
$clinicTagline = $b['tagline'];
$clinicEmail = $b['email'];
$clinicPhone = $b['phone'];
$clinicWebsite = $b['website'];

// ER has no specialty/doctor — always the general logo.
$logoFile = 'logo-general.png';

$patientDobDisplay = $bill['dob'] ? date('d/m/Y', strtotime($bill['dob'])) : '';
// Frozen at the first print so a duplicate repeats the original's footer rather
// than today's date — see config/reprint.php. The generating user stays the
// fallback for rows that have never been printed.
require_once __DIR__ . '/../config/reprint.php';
$printTimestamp = print_stamp($bill);
$printedBy = print_stamp_by($pdo, $bill, $bill['generated_by_name'] ?? 'Front Desk');

$patientNameUpper = mb_strtoupper($bill['patient_name'], 'UTF-8');
// Attending doctor (a system user or a typed name). Blank on bills raised before
// the doctor became mandatory — the row is omitted rather than printing a dash.
$doctorNameUpper = ($bill['doctor_name'] ?? '') !== '' ? mb_strtoupper($bill['doctor_name'], 'UTF-8') : '';
$fatherNameUpper = $bill['father_name'] ? mb_strtoupper($bill['father_name'], 'UTF-8') : '';

$grandTotal = (float) $bill['grand_total'];
$subtotal = (float) $bill['subtotal'];
$discountAmount = round($subtotal - $grandTotal, 2);   // any category discount applied

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
    <title>ER Bill - <?= htmlspecialchars($bill['invoice_number']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Lora:ital,wght@0,400;0,600;0,700;1,400&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { width: 148mm; margin: 0; padding: 0; }
        body { font-family: 'Lora', Georgia, 'Times New Roman', serif; font-size: 9.5px; line-height: 1.3; color: #000; background: #fff; }
        /* position:relative anchors the reprint DUPLICATE mark to the sheet rather
           than the browser window — see config/reprint.php. */
        .sheet { width: 100%; padding: 6mm 6mm 4mm; display: flex; flex-direction: column; min-height: 210mm; position: relative; }

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

        .fee-table { width: 100%; border-collapse: collapse; font-size: 9.5px; margin-top: -1px; }
        .fee-table td, .fee-table th { border: 1px solid #C8C8C8; padding: 0 6px; height: 18px; vertical-align: middle; }
        .fee-table th { background: #F4F4F4; font-weight: bold; text-align: center; }
        .fee-table .desc { text-align: left; }
        .fee-table .num { text-align: right; }
        .fee-table tr.sub td { font-weight: normal; }
        .fee-table tr.total td { background: #F4F4F4; font-weight: bold; border-top: 1.5px solid #000; }

        .quote { text-align: center; font-size: 9.5px; font-style: italic; font-weight: normal; margin-top: auto; padding-top: 5mm; }
        .foot { display: flex; justify-content: space-between; gap: 10px; border-top: 1px solid #B0B0B0; margin-top: 1.5mm; padding-top: 2px; font-size: 7.5px; }
        .pay-note { font-weight: normal; color: #555; white-space: nowrap; text-align: center; }

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
        <?= reprint_watermark($bill) ?>

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
                    <tr><td class="k">Type</td><td class="v">ER Service</td></tr>
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
                    <?php if ($doctorNameUpper !== ''): ?>
                    <tr><td class="k">Doctor:</td><td class="v"><?= htmlspecialchars($doctorNameUpper) ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <div class="doctype">ER SERVICE RECEIPT</div>
        <?php if ($isVoided): ?>
        <div class="voidmark">VOID — this bill has been reversed</div>
        <?php endif; ?>

        <table class="fee-table">
            <colgroup>
                <col style="width:52%"><col style="width:14%"><col style="width:16%"><col style="width:18%">
            </colgroup>
            <thead>
                <tr><th class="desc">Service</th><th class="num">Qty</th><th class="num">Rate</th><th class="num">Amount (Rs)</th></tr>
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
                <tr class="sub"><td class="desc" colspan="3">Discount</td><td class="num">− <?= number_format($discountAmount, 0) ?></td></tr>
                <?php endif; ?>
                <tr class="total"><td class="desc" colspan="3">TOTAL PAID</td><td class="num"><?= number_format($grandTotal, 0) ?></td></tr>
            </tbody>
        </table>

        <p class="quote">"What is called genius is the abundance of life and health"</p>

        <div class="foot">
            <span>This is a computer generated receipt printed on <?= $printTimestamp ?></span>
            <span class="pay-note">Paid: <?= htmlspecialchars($paymentModeDisplay) ?></span>
            <span>Front Desk: <?= htmlspecialchars(mb_strtoupper($printedBy, 'UTF-8')) ?></span>
        </div>

    </div>

    <script>
        window.addEventListener('load', function() { window.print(); });
    </script>
</body>
</html>
