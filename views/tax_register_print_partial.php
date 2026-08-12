<?php
// A4 single-month tax register, included from tax_register.php's ?print=1
// branch. Same letterhead/black-ruled-table shape as closing_print_partial.php
// and doctor_share_print_partial.php, but A4 portrait instead of A5 — this
// document lists every doctor as a table row, not one doctor's own slip, so
// it needs the width and doesn't fit the 148mm slip frame.
//
// Expects in scope: $pdo, $ym (Y-m), $monthRows (doctor_id => ['name','self','gross','tax','net']),
// $totalGross, $totalTax, $totalNet.

require_once __DIR__ . '/../config/brand.php';
$b = brand();
$clinicName = $b['name'];
$clinicTagline = $b['tagline'];
$clinicAddress = $b['address'];
$clinicEmail = $b['email'];
$clinicPhone = $b['phone'];
$clinicWebsite = $b['website'];

$printTimestamp = date('Y-m-d H:i:s');
$monthLabel = date('F Y', strtotime($ym . '-01'));

$preparedBy = '';
try {
    $s = $pdo->prepare('SELECT name FROM users WHERE id = ?');
    $s->execute([(int) ($_SESSION['user_id'] ?? 0)]);
    $preparedBy = mb_strtoupper((string) $s->fetchColumn(), 'UTF-8');
} catch (PDOException $e) { /* leave blank, the line is signed by hand anyway */ }

$n2 = fn(float $v) => number_format($v, 2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tax Register <?= htmlspecialchars($monthLabel) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Lora:ital,wght@0,400;0,600;0,700;1,400&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { width: 210mm; min-height: 297mm; margin: 0; padding: 0; }
        body { font-family: 'Lora', Georgia, 'Times New Roman', serif; font-size: 12px; line-height: 1.3; color: #000; background: #fff; }
        .sheet { width: 100%; padding: 14mm; display: flex; flex-direction: column; min-height: 297mm; }
        .header { text-align: center; margin-bottom: 6px; }
        .clinic-logo { height: 32px; vertical-align: middle; margin-right: 5px; background: #fff; }
        .clinic-name { font-family: Arial, Helvetica, sans-serif; font-size: 19px; font-weight: bold; margin: 2px 0; letter-spacing: 1px; color: #0A6B5E; }
        .website { font-family: Arial, Helvetica, sans-serif; font-size: 10px; font-weight: bold; letter-spacing: 2px; margin-bottom: 2px; color: #0A6B5E; }
        .clinic-tagline { font-size: 11px; margin: 2px 0; font-weight: bold; }
        .contact-info { font-size: 10px; line-height: 1.2; margin-top: 2px; }
        .contact-info p { margin: 1px 0; }
        .doctype { text-align: center; font-weight: bold; font-size: 14px; letter-spacing: 2px; margin: 10px 0 2px; }
        .refno { text-align: center; font-size: 11px; }
        hr { border: none; border-top: 1px solid #000; margin: 6px 0; }
        .reg-table { width: 100%; border-collapse: collapse; border: 1px solid #000; font-size: 11px; margin: 10px 0; }
        .reg-table th { border: 1px solid #000; padding: 4px 6px; background-color: #f0f0f0; text-align: left; font-size: 10px; letter-spacing: .3px; }
        .reg-table td { border: 1px solid #000; padding: 4px 6px; }
        .text-right { text-align: right; font-variant-numeric: tabular-nums; }
        .reg-table .none td { font-style: italic; }
        .reg-table .grand { background-color: #f0f0f0; font-weight: bold; border-top: 2px solid #000; font-size: 12px; }
        .self-tag { font-size: 9px; font-style: italic; }
        .detail { font-size: 10px; margin: 8px 0; }
        .signatures { display: flex; gap: 16px; margin-top: auto; padding-top: 40px; }
        .sig { flex: 1; text-align: center; }
        .sig .line { border-top: 1px solid #000; margin-bottom: 3px; }
        .sig .role { font-size: 9.5px; font-weight: bold; letter-spacing: .5px; }
        .sig .nm { font-size: 9.5px; }
        .footer { text-align: center; font-size: 9px; line-height: 1.2; margin-top: 10px; border-top: 1px solid #000; padding-top: 3px; display: flex; justify-content: space-between; }
        @media print {
            body { margin: 0; padding: 0; width: 210mm; }
            .sheet { margin: 0; padding: 14mm; }
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            @page { size: A4; margin: 0; }
        }
    </style>
</head>
<body>
    <div class="sheet">

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

        <div class="doctype">TAX DEPOSIT REGISTER</div>
        <div class="refno"><?= htmlspecialchars($monthLabel) ?> &middot; Per doctor</div>

        <hr>

        <table class="reg-table">
            <thead>
                <tr><th>Doctor</th><th class="text-right">Gross fees (Rs)</th><th class="text-right">Tax withheld (Rs)</th><th class="text-right">Net paid (Rs)</th></tr>
            </thead>
            <tbody>
                <?php if (!$monthRows): ?>
                <tr class="none"><td colspan="4">No settled payouts in <?= htmlspecialchars($monthLabel) ?>.</td></tr>
                <?php endif; ?>
                <?php foreach ($monthRows as $d): ?>
                <tr>
                    <td><?= htmlspecialchars($d['name']) ?><?php if ($d['self']): ?> <span class="self-tag">(self-deposits)</span><?php endif; ?></td>
                    <td class="text-right"><?= $n2($d['gross']) ?></td>
                    <td class="text-right"><?= $d['self'] ? '&mdash;' : $n2($d['tax']) ?></td>
                    <td class="text-right"><?= $n2($d['net']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if ($monthRows): ?>
                <tr class="grand">
                    <td>Total &mdash; <?= htmlspecialchars($monthLabel) ?></td>
                    <td class="text-right"><?= $n2($totalGross) ?></td>
                    <td class="text-right"><?= $n2($totalTax) ?></td>
                    <td class="text-right"><?= $n2($totalNet) ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <p class="detail">
            Figures come from settled payouts, not live bills — a payout freezes what was withheld at the moment
            it was paid, so a bill voided later or a change to a doctor's percentage cannot alter a month already
            filed. Doctors marked self-deposits pay their own tax; the clinic withholds nothing on their behalf.
        </p>

        <div class="signatures">
            <div class="sig">
                <div class="line"></div>
                <div class="role">PREPARED BY</div>
                <div class="nm"><?= htmlspecialchars($preparedBy) ?> (Accounts)</div>
            </div>
            <div class="sig">
                <div class="line"></div>
                <div class="role">VERIFIED BY</div>
                <div class="nm">&nbsp;</div>
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
