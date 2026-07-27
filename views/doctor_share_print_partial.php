<?php
// A5 doctor share statement, included from doctor_share_statement.php's
// ?print=1 branch. Deliberately built as its own standalone document in the
// same shape as views/closing_print_partial.php — letterhead, black-ruled
// tables on white, two signature lines — rather than printing the on-screen
// sage card, which came out as an unbordered, two-page list.
//
// Expects in scope: $doc, $from, $to, $opdRows, $freeUnbilled, $opdGross,
// $opdCount, $opdDiscount, $refundAmt, $refundCount, $ipdGross, $ipdCount,
// $grossCollected, $netCollected, $split, $paidOut, $netPayable, $sharePct,
// $hasTax, $taxPct, $feeLabels.

$clinicName = 'BABY MEDICS';
$clinicTagline = 'Premium Healthcare | Vaccines';
$clinicAddress = 'Polymedics, 2165-F, NPF, PWD Double Road, Islamabad, Pakistan.';
$clinicEmail = 'info@babymedics.com';
$clinicPhone = '+92 51 5735006';
$clinicWebsite = 'b a b y m e d i c s . c o m';

$printTimestamp = date('Y-m-d H:i:s');
$docNameUpper = mb_strtoupper($doc['name'] ?? '', 'UTF-8');
// Preparer resolved from the DB — there is no name in $_SESSION, only user_id.
$preparedBy = '';
try {
    $s = $pdo->prepare('SELECT name FROM users WHERE id = ?');
    $s->execute([(int) ($_SESSION['user_id'] ?? 0)]);
    $preparedBy = mb_strtoupper((string) $s->fetchColumn(), 'UTF-8');
} catch (PDOException $e) { /* leave blank, the line is signed by hand anyway */ }

// Rs with no decimals on the sheet keeps the money column narrow; the closing
// slip uses 2dp because it reconciles physical cash, this one does not.
$n2 = fn(float $v) => number_format($v, 2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doctor Share <?= htmlspecialchars($docNameUpper) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Lora:ital,wght@0,400;0,600;0,700;1,400&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { width: 148mm; height: 210mm; margin: 0; padding: 0; }
        body { font-family: 'Lora', Georgia, 'Times New Roman', serif; font-size: 11px; line-height: 1.3; color: #000; background: #fff; }
        .slip-container { width: 100%; height: 100%; padding: 8mm; overflow: hidden; display: flex; flex-direction: column; }
        .header { text-align: center; margin-bottom: 4px; }
        .clinic-logo { height: 28px; vertical-align: middle; margin-right: 4px; background: #fff; }
        .clinic-name { font-family: Arial, Helvetica, sans-serif; font-size: 16px; font-weight: bold; margin: 2px 0; letter-spacing: 1px; color: #0A6B5E; }
        .website { font-family: Arial, Helvetica, sans-serif; font-size: 9px; font-weight: bold; letter-spacing: 2px; margin-bottom: 2px; color: #0A6B5E; }
        .clinic-tagline { font-size: 10px; margin: 2px 0; font-weight: bold; }
        .contact-info { font-size: 9px; line-height: 1.2; margin-top: 2px; }
        .contact-info p { margin: 1px 0; }
        .doctype { text-align: center; font-weight: bold; font-size: 12px; letter-spacing: 2px; margin: 6px 0 2px; }
        .refno { text-align: center; font-size: 10px; }
        hr { border: none; border-top: 1px solid #000; margin: 4px 0; }
        .meta-table { width: 100%; border: 1px solid #000; border-collapse: collapse; font-size: 10px; margin-bottom: 4px; }
        .meta-table td { border: 1px solid #000; padding: 2px 3px; vertical-align: top; }
        .meta-table td.k { width: 26%; background-color: #f0f0f0; font-weight: bold; }
        .amounts-table { width: 100%; border-collapse: collapse; border: 1px solid #000; font-size: 10px; margin-bottom: 4px; }
        .amounts-table th { border: 1px solid #000; padding: 2px 3px; background-color: #f0f0f0; text-align: left; font-size: 9px; letter-spacing: .5px; }
        .amounts-table td { border: 1px solid #000; padding: 2px 3px; }
        .text-right { text-align: right; }
        /* Sub-total inside a stream (OPD collected); the grand total uses .net. */
        .amounts-table .sub td { background-color: #f7f7f7; font-weight: bold; }
        .amounts-table .net { background-color: #f0f0f0; font-weight: bold; border-top: 2px solid #000; font-size: 11px; }
        .amounts-table .none td { font-style: italic; }
        .section-title { font-size: 9px; font-weight: bold; letter-spacing: 1px; margin: 4px 0 2px; }
        .detail { font-size: 9.5px; margin: 2px 0; }
        .signatures { display: flex; gap: 12px; margin-top: auto; padding-top: 24px; }
        .sig { flex: 1; text-align: center; }
        .sig .line { border-top: 1px solid #000; margin-bottom: 3px; }
        .sig .role { font-size: 8.5px; font-weight: bold; letter-spacing: .5px; }
        .sig .nm { font-size: 8.5px; }
        .footer { text-align: center; font-size: 8px; line-height: 1.2; margin-top: 8px; border-top: 1px solid #000; padding-top: 2px; display: flex; justify-content: space-between; }
        @media print {
            body { margin: 0; padding: 0; width: 148mm; height: 210mm; }
            .slip-container { margin: 0; padding: 8mm; height: 210mm; }
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            @page { size: A5; margin: 0; }
        }
    </style>
</head>
<body>
    <div class="slip-container">

        <div class="header">
            <h1 class="clinic-name">
                <img class="clinic-logo" src="assets/images/logo-general.png" alt="">
                <?= $clinicName ?>
            </h1>
            <p class="website"><?= $clinicWebsite ?></p>
            <div class="clinic-tagline"><strong><?= $clinicTagline ?></strong></div>
            <div class="contact-info">
                <p><?= $clinicAddress ?></p>
                <p>Email: <?= $clinicEmail ?> &nbsp; Phone: <?= $clinicPhone ?></p>
            </div>
        </div>

        <div class="doctype">DOCTOR SHARE STATEMENT</div>
        <div class="refno"><?= date('d/m/Y', strtotime($from)) ?> &ndash; <?= date('d/m/Y', strtotime($to)) ?></div>

        <hr>

        <table class="meta-table">
            <tr>
                <td class="k">Doctor</td><td><?= htmlspecialchars($docNameUpper) ?></td>
                <td class="k">Share</td><td><?= number_format($sharePct, 0) ?>%</td>
            </tr>
            <tr>
                <td class="k">Period</td><td><?= date('d/m/Y', strtotime($from)) ?> &ndash; <?= date('d/m/Y', strtotime($to)) ?></td>
                <td class="k">Tax</td><td><?= $hasTax ? number_format($taxPct, 0) . '% withheld' : 'Self-deposited' ?></td>
            </tr>
        </table>

        <div class="section-title">OPD CONSULTATIONS</div>
        <table class="amounts-table">
            <tr><th>Fee type</th><th class="text-right">Count</th><th class="text-right">Amount (Rs)</th></tr>
            <?php if (!$opdRows && !$freeUnbilled): ?>
            <tr class="none"><td>No paid consultations in this period</td><td class="text-right">&mdash;</td><td class="text-right">&mdash;</td></tr>
            <?php endif; ?>
            <?php foreach ($opdRows as $r): ?>
            <tr>
                <td><?= htmlspecialchars($feeLabels[$r['fee_type']] ?? $r['fee_type']) ?></td>
                <td class="text-right"><?= number_format((int) $r['n']) ?></td>
                <td class="text-right"><?= $n2((float) $r['amt']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if ($freeUnbilled > 0): ?>
            <tr>
                <td>Free follow-up (no invoice raised)</td>
                <td class="text-right"><?= number_format($freeUnbilled) ?></td>
                <td class="text-right">0.00</td>
            </tr>
            <?php endif; ?>
            <?php if ($opdDiscount > 0): ?>
            <tr><td>Discounts given (already reflected above)</td><td class="text-right">&mdash;</td><td class="text-right">(<?= $n2($opdDiscount) ?>)</td></tr>
            <?php endif; ?>
            <tr class="sub">
                <td>OPD collected</td>
                <td class="text-right"><?= number_format($opdCount + $freeUnbilled) ?></td>
                <td class="text-right"><?= $n2($opdGross) ?></td>
            </tr>
        </table>

        <div class="section-title">IN-DOOR WARD ROUNDS &amp; PROCEDURES</div>
        <table class="amounts-table">
            <tr><th>Item</th><th class="text-right">Count</th><th class="text-right">Amount (Rs)</th></tr>
            <?php if ($ipdCount > 0): ?>
            <tr>
                <td>Consultant visits (billed on discharge)</td>
                <td class="text-right"><?= number_format($ipdCount) ?></td>
                <td class="text-right"><?= $n2($ipdGross) ?></td>
            </tr>
            <?php else: ?>
            <tr class="none"><td>No paid ward rounds in this period</td><td class="text-right">&mdash;</td><td class="text-right">&mdash;</td></tr>
            <?php endif; ?>
            <?php if (!$procLive): ?>
            <tr class="none"><td>Procedures &mdash; billing not live yet</td><td class="text-right">&mdash;</td><td class="text-right">&mdash;</td></tr>
            <?php elseif ($procCount > 0): ?>
            <tr>
                <td>Procedures performed</td>
                <td class="text-right"><?= number_format($procCount) ?></td>
                <td class="text-right"><?= $n2($procGross) ?></td>
            </tr>
            <?php else: ?>
            <tr class="none"><td>No procedures billed in this period</td><td class="text-right">&mdash;</td><td class="text-right">&mdash;</td></tr>
            <?php endif; ?>
        </table>

        <div class="section-title">SETTLEMENT</div>
        <table class="amounts-table">
            <tr><td>Gross collected</td><td class="text-right"><?= $n2($grossCollected) ?></td></tr>
            <?php if ($refundAmt > 0): ?>
            <tr><td>Less: refunds issued (<?= number_format($refundCount) ?>)</td><td class="text-right">(<?= $n2($refundAmt) ?>)</td></tr>
            <tr class="sub"><td>Net collected</td><td class="text-right"><?= $n2($netCollected) ?></td></tr>
            <?php endif; ?>
            <?php
            // With procedures in the mix the split is no longer ONE percentage:
            // each procedure line carries its own share/tax rate, so the labels
            // drop the "(x%)" suffix rather than print a figure that doesn't
            // reconcile. Divisible amount is derived from the split itself
            // (doctor + clinic) instead of netCollected, which excludes
            // procedure money entirely.
            $mixedRates = $procLive && $procCount > 0;
            $pDisp      = (float) ($procDisp ?? 0);
            // clinic INCLUDES the recovered supplies cost; take it back out so
            // the divisible amount is what tax and the split ran on.
            $divisible  = $split['doctor'] + $split['clinic'] - $pDisp;
            ?>
            <?php if ($pDisp > 0): ?>
            <tr><td>Less: disposables (supplies cost)</td><td class="text-right">(<?= $n2($pDisp) ?>)</td></tr>
            <?php endif; ?>
            <tr><td>Less: tax withheld <?= $mixedRates ? '' : ($hasTax ? '(' . number_format($taxPct, 0) . '% of gross)' : '(doctor self-deposits)') ?></td><td class="text-right">(<?= $n2($split['tax']) ?>)</td></tr>
            <tr class="sub"><td>Divisible amount</td><td class="text-right"><?= $n2($divisible) ?></td></tr>
            <tr><td>Clinic share<?= $mixedRates ? '' : ' (' . number_format(100 - $sharePct, 0) . '%)' ?></td><td class="text-right"><?= $n2($split['clinic'] - $pDisp) ?></td></tr>
            <tr class="sub"><td>Doctor share<?= $mixedRates ? '' : ' (' . number_format($sharePct, 0) . '%)' ?></td><td class="text-right"><?= $n2($split['doctor']) ?></td></tr>
            <?php if ($mixedRates): ?>
            <tr class="none"><td colspan="2" style="font-size:8px;">Consultations and procedures carry their own share and tax rates; each is split at its own rate and totalled above.</td></tr>
            <?php endif; ?>
            <?php if ($paidOut > 0): ?>
            <tr><td>Less: already disbursed</td><td class="text-right">(<?= $n2($paidOut) ?>)</td></tr>
            <?php endif; ?>
            <tr class="net"><td><strong><?= $paidOut > 0 ? 'NET STILL PAYABLE (Rs)' : 'NET PAYABLE (Rs)' ?></strong></td><td class="text-right"><strong><?= $n2($netPayable) ?></strong></td></tr>
        </table>

        <p class="detail">Amounts are recognised on the date the money was collected, not the date of the visit.</p>

        <div class="signatures">
            <div class="sig">
                <div class="line"></div>
                <div class="role">PREPARED BY</div>
                <div class="nm"><?= htmlspecialchars($preparedBy) ?> (Accounts)</div>
            </div>
            <div class="sig">
                <div class="line"></div>
                <div class="role">RECEIVED BY</div>
                <div class="nm"><?= htmlspecialchars($docNameUpper) ?></div>
            </div>
        </div>

        <div class="footer">
            <span>Computer generated on <?= $printTimestamp ?></span>
            <span>Signed copy to be filed for audit</span>
        </div>

    </div>

    <script>
        window.addEventListener('load', function() { window.print(); });
    </script>
</body>
</html>
