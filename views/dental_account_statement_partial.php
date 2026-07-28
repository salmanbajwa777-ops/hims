<?php
// A5 dental package STATEMENT, included from dental_account.php's
// ?print_statement=1 branch. Expects $account, $items (live only), $payments
// (live only) and $totals (from dental_account_totals()).
//
// This is the itemised document — the quote explaining itself, line by line
// with tooth numbers, plus every payment made against it. The per-payment
// RECEIPT (dental_account_receipt_partial) deliberately does not carry this;
// a patient asking "what am I paying for?" gets this, and a patient asking
// "what's left?" gets that.
//
// Reads LIVE totals on purpose, unlike the consent form, which prints a frozen
// snapshot: a statement is meant to show where the account stands right now.
//
// Voided items are omitted — the caller passes live rows only. The audit trail
// for a dropped item lives on screen, not on the patient's copy.
//
// Prints on A5 and can run to a second page when a package has many lines,
// which is why the sheet has no fixed min-height here.

require_once __DIR__ . '/../config/brand.php';
$b = brand($account['doctor_specialty'] ?? null);
$clinicName = $b['name'];
$clinicTagline = $b['tagline'];
$clinicEmail = $b['email'];
$clinicPhone = $b['phone'];
$clinicWebsite = $b['website'];

$logoFile = brand_logo($account['doctor_specialty'] ?? null);

$patientNameUpper = mb_strtoupper($account['patient_name'], 'UTF-8');
$fatherNameUpper = $account['father_name'] ? mb_strtoupper($account['father_name'], 'UTF-8') : '';
$doctorNameUpper = mb_strtoupper($account['doctor_name'] ?? '', 'UTF-8');
$patientDobDisplay = $account['dob'] ? date('d/m/Y', strtotime($account['dob'])) : '';
$printTimestamp = date('Y-m-d H:i:s');

$methodLabels = ['cash' => 'Cash', 'card' => 'Online / Card',
                 'bank_transfer' => 'Bank Transfer', 'cheque' => 'Cheque'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dental Statement - <?= htmlspecialchars($account['account_number']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Lora:ital,wght@0,400;0,600;0,700;1,400&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { width: 148mm; margin: 0; padding: 0; }
        body { font-family: 'Lora', Georgia, 'Times New Roman', serif; font-size: 9.5px; line-height: 1.3; color: #000; background: #fff; }
        .sheet { width: 100%; padding: 6mm 6mm 4mm; display: flex; flex-direction: column; }

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
        .ids td.k, .meta td.k { background: #F4F4F4; font-weight: bold; width: 40%; }
        .ids td.v, .meta td.v { font-weight: bold; }

        .doctype { text-align: center; font-weight: bold; font-size: 11px; letter-spacing: 2px; margin: 3mm 0 1mm; }
        .statusmark { text-align: center; font-size: 10px; font-weight: bold; letter-spacing: 1px; border: 1.5px solid #000; padding: 2px 6px; margin: 0 auto 2mm; width: fit-content; }

        .sect { font-size: 9px; font-weight: bold; letter-spacing: 1px; margin: 3mm 0 1mm; text-transform: uppercase; }

        .tbl { width: 100%; border-collapse: collapse; font-size: 9px; }
        .tbl td, .tbl th { border: 1px solid #C8C8C8; padding: 0 5px; height: 18px; vertical-align: middle; }
        .tbl th { background: #F4F4F4; font-weight: bold; text-align: left; }
        .tbl .num { text-align: right; }
        .tbl .ctr { text-align: center; }
        .tbl tr.sub td { font-weight: normal; }
        .tbl tr.total td { background: #F4F4F4; font-weight: bold; border-top: 1.5px solid #000; }
        .tbl tr.bal td { background: #EDEDED; font-weight: bold; font-size: 10.5px; height: 22px; border-top: 1.5px solid #000; }

        .note { font-size: 8.5px; color: #444; margin-top: 3mm; line-height: 1.45; }
        .foot { display: flex; justify-content: space-between; gap: 10px; border-top: 1px solid #B0B0B0; margin-top: 5mm; padding-top: 2px; font-size: 7.5px; }

        @media print {
            html, body { width: 148mm; }
            .sheet { padding: 6mm 6mm 4mm; }
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            @page { size: A5; margin: 0; }
            tr { page-break-inside: avoid; }
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
                    <tr><td class="k">MR #</td><td class="v"><?= htmlspecialchars($account['mrn']) ?></td></tr>
                    <tr><td class="k">Account #</td><td class="v"><?= htmlspecialchars($account['account_number']) ?></td></tr>
                    <tr><td class="k">Opened</td><td class="v"><?= date('d/m/Y', strtotime($account['opened_at'])) ?></td></tr>
                    <tr><td class="k">Statement</td><td class="v"><?= date('d/m/Y') ?></td></tr>
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
                    <tr><td class="k">Dentist:</td><td class="v"><?= htmlspecialchars($doctorNameUpper) ?></td></tr>
                </table>
            </div>
        </div>

        <div class="doctype">TREATMENT ACCOUNT STATEMENT</div>
        <?php if ($account['status'] === 'CANCELLED'): ?>
        <div class="statusmark">CANCELLED &mdash; balance frozen</div>
        <?php elseif ($account['status'] === 'SETTLED'): ?>
        <div class="statusmark">SETTLED</div>
        <?php endif; ?>

        <div class="sect"><?= htmlspecialchars($account['title']) ?></div>

        <!-- The quote, line by line. Tooth numbers are FDI throughout. -->
        <table class="tbl">
            <colgroup>
                <col style="width:44%"><col style="width:10%"><col style="width:8%">
                <col style="width:19%"><col style="width:19%">
            </colgroup>
            <thead>
                <tr>
                    <th>Treatment</th><th class="ctr">Tooth</th><th class="ctr">Qty</th>
                    <th class="num">Fee (Rs)</th><th class="num">Lab (Rs)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$items): ?>
                <tr><td colspan="5" class="ctr">No items quoted yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($items as $it): ?>
                <tr>
                    <td><?= htmlspecialchars($it['description']) ?></td>
                    <td class="ctr"><?= $it['tooth_fdi'] ? htmlspecialchars($it['tooth_fdi']) : '—' ?></td>
                    <td class="ctr"><?= (int) $it['quantity'] ?></td>
                    <td class="num"><?= number_format((float) $it['amount'], 2) ?></td>
                    <td class="num"><?= (float) $it['lab_charge'] > 0 ? number_format((float) $it['lab_charge'], 2) : '—' ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="sub"><td colspan="4">Treatment total</td><td class="num"><?= number_format($totals['charged'], 2) ?></td></tr>
                <?php if ($totals['lab'] > 0): ?>
                <!-- Lab is shown as its own line, never folded into the treatment
                     total: burying it inside the quote hides what it costs. -->
                <tr class="sub"><td colspan="4">Laboratory charges</td><td class="num"><?= number_format($totals['lab'], 2) ?></td></tr>
                <?php endif; ?>
                <tr class="total"><td colspan="4">PACKAGE TOTAL</td><td class="num"><?= number_format($totals['total'], 2) ?></td></tr>
            </tbody>
        </table>

        <div class="sect">Payments received</div>
        <table class="tbl">
            <colgroup>
                <col style="width:26%"><col style="width:26%"><col style="width:26%"><col style="width:22%">
            </colgroup>
            <thead>
                <tr><th>Receipt</th><th>Date</th><th>Method</th><th class="num">Amount (Rs)</th></tr>
            </thead>
            <tbody>
                <?php if (!$payments): ?>
                <tr><td colspan="4" class="ctr">No payments received yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($payments as $p): ?>
                <tr>
                    <td><?= htmlspecialchars($p['receipt_number']) ?></td>
                    <td><?= date('d/m/Y', strtotime($p['paid_at'])) ?></td>
                    <td><?= htmlspecialchars($methodLabels[$p['payment_method']] ?? $p['payment_method']) ?></td>
                    <td class="num"><?= number_format((float) $p['amount'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="total"><td colspan="3">TOTAL PAID</td><td class="num"><?= number_format($totals['paid'], 2) ?></td></tr>
                <tr class="bal">
                    <td colspan="3"><?= $totals['balance'] < -0.004 ? 'OVERPAID — REFUND DUE' : 'BALANCE OUTSTANDING' ?></td>
                    <td class="num"><?= number_format(abs($totals['balance']), 2) ?></td>
                </tr>
            </tbody>
        </table>

        <div class="note">
            Consultation fees are billed separately at each visit and do not form part of this account.
            <?php if ((float) $account['discount_pct'] > 0): ?>
            A <?= number_format((float) $account['discount_pct'], 2) ?>% discount has been applied to the treatment fees above.
            <?php endif; ?>
            <?php if ($account['status'] === 'CANCELLED'): ?>
            This account was cancelled on <?= date('d/m/Y', strtotime($account['cancelled_at'])) ?> and no further
            treatment or payment will be recorded against it.
            <?php endif; ?>
        </div>

        <div class="foot">
            <span>Printed <?= $printTimestamp ?></span>
            <span><?= $clinicName ?> &middot; <?= $clinicPhone ?></span>
        </div>
    </div>
    <script>window.addEventListener('load', function () { window.print(); });</script>
</body>
</html>
