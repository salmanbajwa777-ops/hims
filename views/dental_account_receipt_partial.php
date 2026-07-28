<?php
// A5 dental package payment receipt, included from dental_account.php's
// ?print_receipt= branch. Expects $payment (a dental_procedure_payments row
// joined to the account + patient + doctor), plus $accountTotal,
// $previouslyPaid, $balanceAfter, computed by the caller.
//
// Forked from views/procedure_invoice_print_partial.php: same clinic header
// box, same row grid and footer. What differs is the whole point of the
// document — a package receipt is NOT an invoice for goods. One account issues
// many of these, so instead of listing items it answers the only question the
// patient has at the counter:
//
//     what is the package · what had I paid · what did I just pay · what is left
//
// The itemised breakdown lives on the statement (dental_account_statement_partial),
// which is printed separately when the patient wants to see the quote itself.
//
// $previouslyPaid / $balanceAfter are computed AS AT THIS RECEIPT, not from the
// live account, so a reprint months and three payments later still shows what
// the patient was told at the time.
//
// A5 only, and no tax, matching every other slip in the system.

require_once __DIR__ . '/../config/brand.php';
$b = brand();
$clinicName = $b['name'];
$clinicTagline = $b['tagline'];
$clinicEmail = $b['email'];
$clinicPhone = $b['phone'];
$clinicWebsite = $b['website'];

// A package is always a dentist's, so the dental logo is the normal case here —
// but the specialty is still read rather than assumed, in case an account was
// opened under a doctor whose specialty was later changed.
$logoFile = brand_logo($payment['doctor_specialty'] ?? null);

$patientDobDisplay = $payment['dob'] ? date('d/m/Y', strtotime($payment['dob'])) : '';
$printTimestamp = date('Y-m-d H:i:s');
$printedBy = $payment['received_by_name'] ?? 'Front Desk';

$patientNameUpper = mb_strtoupper($payment['patient_name'], 'UTF-8');
$fatherNameUpper = $payment['father_name'] ? mb_strtoupper($payment['father_name'], 'UTF-8') : '';
$doctorNameUpper = mb_strtoupper($payment['doctor_name'] ?? '', 'UTF-8');

$thisPayment = (float) $payment['amount'];
$isVoided = !empty($payment['voided_at']) || ($payment['status'] ?? '') === 'voided';

$paymentModeLabels = ['cash' => 'Cash', 'card' => 'Online / Card',
                      'bank_transfer' => 'Bank Transfer', 'cheque' => 'Cheque'];
$paymentModeDisplay = $paymentModeLabels[$payment['payment_method'] ?? ''] ?? '—';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dental Receipt - <?= htmlspecialchars($payment['receipt_number']) ?></title>
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

        .docrow { width: 100%; border-collapse: collapse; font-size: 9.5px; margin-top: -1px; }
        .docrow td { border: 1px solid #C8C8C8; padding: 0 6px; height: 18px; vertical-align: middle; }
        .docrow td.k { background: #F4F4F4; font-weight: bold; width: 30%; }
        .docrow td.v { font-weight: bold; }

        .fee-table { width: 100%; border-collapse: collapse; font-size: 9.5px; margin-top: 2mm; }
        .fee-table td, .fee-table th { border: 1px solid #C8C8C8; padding: 0 6px; height: 19px; vertical-align: middle; }
        .fee-table th { background: #F4F4F4; font-weight: bold; text-align: left; }
        .fee-table .num { text-align: right; }
        .fee-table tr.paid td { background: #F4F4F4; font-weight: bold; border-top: 1.5px solid #000; border-bottom: 1.5px solid #000; font-size: 11px; height: 24px; }
        .fee-table tr.bal td { font-weight: bold; }

        .note { font-size: 8.5px; color: #444; margin-top: 2mm; line-height: 1.4; }
        .sigblock { display: flex; justify-content: space-between; gap: 12mm; margin-top: 8mm; }
        .sigbox { flex: 1; text-align: center; }
        .sigline { border-top: 1px solid #666; margin-top: 12mm; padding-top: 2px; font-size: 8px; }

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
                    <tr><td class="k">MR #</td><td class="v"><?= htmlspecialchars($payment['mrn']) ?></td></tr>
                    <tr><td class="k">Receipt #</td><td class="v"><?= htmlspecialchars($payment['receipt_number']) ?></td></tr>
                    <tr><td class="k">Account #</td><td class="v"><?= htmlspecialchars($payment['account_number']) ?></td></tr>
                    <tr><td class="k">Date</td><td class="v"><?= date('d/m/Y H:i', strtotime($payment['paid_at'])) ?></td></tr>
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
                    <tr><td class="k">Phone:</td><td><?= htmlspecialchars($payment['phone']) ?></td></tr>
                </table>
            </div>
        </div>

        <div class="doctype">DENTAL PAYMENT RECEIPT</div>
        <?php if ($isVoided): ?>
        <div class="voidmark">VOID &mdash; this receipt has been reversed</div>
        <?php endif; ?>

        <table class="docrow">
            <tr><td class="k">Treatment plan</td><td class="v"><?= htmlspecialchars($payment['title']) ?></td></tr>
            <tr><td class="k">Dentist</td><td class="v"><?= htmlspecialchars($doctorNameUpper) ?></td></tr>
            <tr><td class="k">Paid by</td><td class="v"><?= htmlspecialchars($paymentModeDisplay) ?></td></tr>
        </table>

        <!-- The running position. This, not an item list, is what a package
             receipt is for: one account issues many receipts, and the patient's
             question at the counter is always "how much is left?". -->
        <table class="fee-table">
            <thead>
                <tr><th>Treatment account</th><th class="num">Amount (Rs)</th></tr>
            </thead>
            <tbody>
                <tr><td>Package total</td><td class="num"><?= number_format($accountTotal, 2) ?></td></tr>
                <tr><td>Previously paid</td><td class="num"><?= number_format($previouslyPaid, 2) ?></td></tr>
                <tr class="paid"><td>PAID NOW</td><td class="num"><?= number_format($thisPayment, 2) ?></td></tr>
                <tr class="bal">
                    <td><?= $balanceAfter < -0.004 ? 'Overpaid — refund due' : 'Balance remaining' ?></td>
                    <td class="num"><?= number_format(abs($balanceAfter), 2) ?></td>
                </tr>
            </tbody>
        </table>

        <div class="note">
            This receipt is for treatment booked under account <b><?= htmlspecialchars($payment['account_number']) ?></b>.
            Consultation fees are billed separately and do not form part of this account.
            <?php if ($balanceAfter > 0.004): ?>
            The remaining balance is payable over the course of treatment.
            <?php endif; ?>
        </div>

        <div class="sigblock">
            <div class="sigbox"><div class="sigline">Received by</div></div>
            <div class="sigbox"><div class="sigline">Patient / Guardian</div></div>
        </div>

        <div class="quote">Thank you — <?= $clinicTagline ?>.</div>

        <div class="foot">
            <span>Printed <?= $printTimestamp ?> by <?= htmlspecialchars($printedBy) ?></span>
            <span><?= $clinicName ?> &middot; <?= $clinicPhone ?></span>
        </div>
    </div>
    <script>window.addEventListener('load', function () { window.print(); });</script>
</body>
</html>
