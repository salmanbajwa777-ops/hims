<?php
// A5 day-closing slip, included from shift_closing.php's ?print=1 branch (and
// reprintable from admin_handovers.php). Expects $closing (shift_closings row
// joined to cashier/admin names) in scope. $denominations may be passed but is
// no longer rendered — the note-by-note breakdown was removed from the flow.
//
// Two signature lines — handed over by (receptionist) and received by (admin).
// The signed paper copy is filed for audit; admin ticks "slip filed" in
// admin_handovers.php when it lands in the file.

require_once __DIR__ . '/../config/brand.php';
$b = brand();
$clinicName = $b['name'];
$clinicTagline = $b['tagline'];
$clinicAddress = $b['address'];
$clinicEmail = $b['email'];
$clinicPhone = $b['phone'];
$clinicWebsite = $b['website'];

$printTimestamp = date('Y-m-d H:i:s');
$varianceVal = (float) $closing['variance'];
$varianceLabel = abs($varianceVal) < 0.01 ? 'Balanced'
    : number_format(abs($varianceVal), 2) . ($varianceVal < 0 ? ' SHORT' : ' OVER');
// Per-user model: this slip is one receptionist's shift, not the whole day.
$isEdited = ($closing['status'] ?? '') === 'EDITED' || (int) ($closing['edit_count'] ?? 0) > 0;

// Staff names print in ALL CAPS regardless of stored casing.
$cashierNameUpper = mb_strtoupper($closing['cashier_name'] ?? '', 'UTF-8');
$adminNameUpper = mb_strtoupper($closing['admin_name'] ?? '', 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Closing <?= htmlspecialchars($closing['closing_number']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Lora:ital,wght@0,400;0,600;0,700;1,400&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { width: 148mm; height: 210mm; margin: 0; padding: 0; }
        body { font-family: 'Lora', Georgia, 'Times New Roman', serif; font-size: 11px; line-height: 1.3; color: #06141B; background: #fff; }
        .slip-container { width: 100%; height: 100%; padding: 8mm; overflow: hidden; display: flex; flex-direction: column; }
        .header { text-align: center; margin-bottom: 4px; }
        .clinic-logo { height: 28px; vertical-align: middle; margin-right: 4px; background: transparent; }
        .clinic-name { font-family: Arial, Helvetica, sans-serif; font-size: 16px; font-weight: bold; margin: 2px 0; letter-spacing: 1px; color: #4A5C6A; }
        .website { font-family: Arial, Helvetica, sans-serif; font-size: 9px; font-weight: bold; letter-spacing: 2px; margin-bottom: 2px; color: #4A5C6A; }
        .clinic-tagline { font-size: 10px; margin: 2px 0; font-weight: bold; }
        .contact-info { font-size: 9px; line-height: 1.2; margin-top: 2px; }
        .contact-info p { margin: 1px 0; }
        .doctype { text-align: center; font-weight: bold; font-size: 12px; letter-spacing: 2px; margin: 6px 0 2px; }
        .refno { text-align: center; font-size: 10px; }
        hr { border: none; border-top: 1px solid #06141B; margin: 4px 0; }
        .meta-table { width: 100%; border: 1px solid #06141B; border-collapse: collapse; font-size: 10px; margin-bottom: 4px; }
        .meta-table td { border: 1px solid #06141B; padding: 2px 3px; vertical-align: top; }
        .meta-table td.k { width: 26%; background-color: #f0f0f0; font-weight: bold; }
        .amounts-table { width: 100%; border-collapse: collapse; border: 1px solid #06141B; font-size: 10px; margin-bottom: 4px; }
        .amounts-table th { border: 1px solid #06141B; padding: 2px 3px; background-color: #f0f0f0; text-align: left; font-size: 9px; letter-spacing: .5px; }
        .amounts-table td { border: 1px solid #06141B; padding: 2px 3px; }
        .text-right { text-align: right; }
        .amounts-table .net { background-color: #f0f0f0; font-weight: bold; border-top: 2px solid #06141B; font-size: 11px; }
        .notes-box { flex: 1 1 0; min-height: 12mm; max-height: 30mm; border: 1.5px solid #06141B; padding: 2px 4px; font-family: Arial, Helvetica, sans-serif; font-size: 8.5px; font-weight: bold; letter-spacing: .5px; margin-top: 4px; }
        .section-title { font-size: 9px; font-weight: bold; letter-spacing: 1px; margin: 4px 0 2px; }
        .detail { font-size: 9.5px; margin: 2px 0; }
        .signatures { display: flex; gap: 12px; margin-top: auto; padding-top: 14px; }
        .sig { flex: 1; text-align: center; }
        .sig .line { border-top: 1px solid #06141B; margin-bottom: 3px; }
        .sig .role { font-size: 8.5px; font-weight: bold; letter-spacing: .5px; }
        .sig .nm { font-size: 8.5px; }
        .footer { text-align: center; font-size: 8px; line-height: 1.2; margin-top: 8px; border-top: 1px solid #06141B; padding-top: 2px; display: flex; justify-content: space-between; }
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

        <div class="doctype">SHIFT CLOSING SLIP</div>
        <div class="refno"><?= htmlspecialchars($closing['closing_number']) ?> &middot; <?= date('Y-m-d H:i', strtotime($closing['created_at'])) ?></div>
        <?php if ($isEdited): ?>
        <div style="text-align:center;font-size:9.5px;font-weight:bold;letter-spacing:1px;border:1.5px solid #06141B;padding:2px 6px;margin:3px auto 0;width:fit-content;">
            REVISED — EDIT #<?= (int) ($closing['edit_count'] ?? 0) ?> ON <?= $closing['edited_at'] ? date('d/m/Y H:i', strtotime($closing['edited_at'])) : '—' ?> · REPLACES EARLIER PRINT
        </div>
        <?php endif; ?>

        <?php if (!empty($closing['closed_by_admin_name'])): ?>
        <?php /* No longer says "LATE CLOSE": an admin may now close a shift the same
                 day when the receptionist cannot, so the banner states the fact —
                 who closed it for whom — without asserting it was overdue. */ ?>
        <div style="text-align:center;font-size:9.5px;font-weight:bold;letter-spacing:1px;border:1.5px solid #06141B;padding:2px 6px;margin:3px auto 0;width:fit-content;">
            CLOSED BY <?= htmlspecialchars(mb_strtoupper($closing['closed_by_admin_name'], 'UTF-8')) ?> ON BEHALF OF <?= htmlspecialchars($cashierNameUpper) ?>
        </div>
        <?php endif; ?>

        <?php if (abs($varianceVal) >= 0.01): ?>
        <?php /* A cash mismatch is the single most important thing on this slip and
                 must not be a quiet row inside a table of eight numbers. Whoever
                 signs this is signing for a drawer that did not balance, so it is
                 stated at the top, in words, with the amount and the direction. */ ?>
        <div style="text-align:center;font-size:11px;font-weight:bold;letter-spacing:.5px;border:2px solid #06141B;padding:4px 8px;margin:4px auto 0;width:fit-content;">
            CASH MISMATCH — <?= $varianceVal < 0 ? 'SHORT' : 'OVER' ?> BY RS <?= number_format(abs($varianceVal), 2) ?>
        </div>
        <?php endif; ?>

        <hr>

        <table class="meta-table">
            <tr>
                <td class="k">Shift date</td><td><?= date('D d/m/Y', strtotime($closing['closing_date'])) ?></td>
                <td class="k">Closed at</td><td><?= date('H:i', strtotime($closing['created_at'])) ?></td>
            </tr>
            <tr>
                <td class="k">Cashier</td><td><?= htmlspecialchars($cashierNameUpper) ?></td>
                <td class="k">Handing to</td><td><?= htmlspecialchars($adminNameUpper) ?></td>
            </tr>
        </table>

        <?php
        // GROSS first: what the shift took before online, refunds and expenses come
        // off. The saved closing row holds only cash consults, cash+ER+procedure+
        // dental admissions and one online lump, so the per-stream split is rebuilt
        // from the live tally. Gross/cash/online always come from the STORED row;
        // the split only decides which row a rupee is shown on.
        $cashTot   = (float) $closing['cash_consult_total'] + (float) $closing['cash_admission_total'];
        $cashCnt   = (int) $closing['cash_consult_count'] + (int) $closing['cash_admission_count'];
        $onlineTot = (float) $closing['online_total'];
        $onlineCnt = (int) $closing['online_count'];
        $grossTot  = $cashTot + $onlineTot;
        $grossCnt  = $cashCnt + $onlineCnt;
        $streams = null;
        $streamDrift = false;
        try {
            $lt = day_cash_tally($pdo, $closing['closing_date'], (int) $closing['cashier_id']);
            $streams = [
                'Consultations' => [$lt['cash_consult_total'] + $lt['online_consult_total'], $lt['cash_consult_count'] + $lt['online_consult_count']],
                'Admissions'    => [$lt['cash_admission_total'] + $lt['online_admission_total'] - $lt['cash_er_total'] - $lt['online_er_total'],
                                    $lt['cash_admission_count'] + $lt['online_admission_count'] - $lt['cash_er_count'] - $lt['online_er_count']],
                'ER-Direct'     => [$lt['cash_er_total'] + $lt['online_er_total'], $lt['cash_er_count'] + $lt['online_er_count']],
            ];
            $streamSum = $streams['Consultations'][0] + $streams['Admissions'][0] + $streams['ER-Direct'][0];
            $streamDrift = abs($streamSum - $grossTot) >= 0.01;
        } catch (Throwable $e) {
            $streams = null;
        }
        $slipExpenses = (float) ($closing['expense_total'] ?? 0);
        ?>
        <div class="section-title">COLLECTIONS — THIS CASHIER ONLY — GROSS TOTAL</div>
        <table class="amounts-table">
            <tr><th>Stream</th><th class="text-right">Count</th><th class="text-right">Amount (Rs)</th></tr>
            <?php if ($streams): foreach ($streams as $sLabel => [$sAmt, $sCnt]): ?>
            <tr><td><?= $sLabel ?></td><td class="text-right"><?= (int) $sCnt ?></td><td class="text-right"><?= number_format((float) $sAmt, 2) ?></td></tr>
            <?php endforeach; endif; ?>
            <tr class="net"><td><strong>GROSS TOTAL</strong> <span style="font-weight:normal;font-size:8.5px;">(before online, refunds, expenses)</span></td><td class="text-right"><?= $grossCnt ?></td><td class="text-right"><strong><?= number_format($grossTot, 2) ?></strong></td></tr>
            <tr><td>Cash total</td><td class="text-right"><?= $cashCnt ?></td><td class="text-right"><?= number_format($cashTot, 2) ?></td></tr>
            <tr><td>Online total</td><td class="text-right"><?= $onlineCnt ?></td><td class="text-right"><?= number_format($onlineTot, 2) ?></td></tr>
            <tr><td>Refunds paid (cash)</td><td class="text-right"><?= (int) $closing['cash_refund_count'] ?></td><td class="text-right">(<?= number_format((float) $closing['cash_refund_total'], 2) ?>)</td></tr>
            <tr><td>Counter expenses (EXP)</td><td class="text-right"><?= (int) ($closing['expense_count'] ?? 0) ?></td><td class="text-right">(<?= number_format($slipExpenses, 2) ?>)</td></tr>
            <tr><td colspan="2">Expected cash in hand</td><td class="text-right"><?= number_format((float) $closing['expected_cash'], 2) ?></td></tr>
            <tr><td colspan="2">Counted cash</td><td class="text-right"><?= number_format((float) $closing['counted_cash'], 2) ?></td></tr>
            <?php /* Bold only when it is NOT balanced, so weight carries meaning
                     instead of decorating every slip. */ ?>
            <tr><td colspan="2"<?= abs($varianceVal) >= 0.01 ? ' style="font-weight:bold;"' : '' ?>>Variance</td>
                <td class="text-right"<?= abs($varianceVal) >= 0.01 ? ' style="font-weight:bold;"' : '' ?>><?= $varianceLabel ?></td></tr>
            <tr class="net"><td colspan="2"><strong>CASH HANDED TO ADMIN (Rs)</strong></td><td class="text-right"><strong><?= number_format((float) $closing['handover_declared'], 2) ?></strong></td></tr>
        </table>
        <?php if ($streamDrift): ?>
        <p class="detail" style="font-size:8.5px;">Stream rows were rebuilt from current records and no longer add up to the gross total closed at shift end (a bill was voided or changed since). Gross, cash and online are the closing-time figures.</p>
        <?php endif; ?>

        <?php if ($closing['variance_note']): ?>
        <p class="detail"><strong>Variance note:</strong> <?= htmlspecialchars($closing['variance_note']) ?></p>
        <?php endif; ?>

        <div class="notes-box">MISSED / TRANSFERRED TO NEXT SHIFT ETC.</div>

        <div class="signatures">
            <div class="sig">
                <div class="line"></div>
                <div class="role">HANDED OVER BY</div>
                <div class="nm"><?= htmlspecialchars($cashierNameUpper) ?> (Reception)</div>
            </div>
            <div class="sig">
                <div class="line"></div>
                <div class="role">RECEIVED BY</div>
                <div class="nm"><?= htmlspecialchars($adminNameUpper) ?> (Admin)</div>
            </div>
        </div>

        <div class="footer">
            <span>Computer generated on <?= $printTimestamp ?></span>
            <span>Signed copy to be filed for audit &middot; <?= htmlspecialchars($closing['closing_number']) ?></span>
        </div>

    </div>

    <script>
        window.addEventListener('load', function() { window.print(); });
    </script>
</body>
</html>
