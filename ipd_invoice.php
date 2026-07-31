<?php
/**
 * IPD invoice — A5 print view. Itemised (stay + consultant visits + services +
 * totals), own "I" series. Browser print -> Save as PDF.
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
refresh_session_permissions($pdo);
require_permission('IPD_VIEW_WARD');

$admissionId = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare("
    SELECT b.*, a.room_category, a.room_no, a.admitted_at, a.discharged_at,
           v.token_no, p.mrn, p.name AS patient_name, p.father_name, p.phone,
           COALESCE(du.name, a.admitting_consultant_manual) AS consultant_name,
           du.specialty AS consultant_specialty
    FROM ipd_bills b
    JOIN ipd_admissions a ON a.id = b.admission_id
    JOIN visits v ON v.id = a.visit_id
    JOIN patients p ON p.id = v.patient_id
    LEFT JOIN users du ON du.id = a.admitting_consultant_id
    WHERE b.admission_id = ?
");
$stmt->execute([$admissionId]);
$bill = $stmt->fetch();
if (!$bill) { http_response_code(404); exit('IPD invoice not found — the bill is raised at discharge.'); }

// FIELD() returns 0 for an unlisted value, which would sort NURSING/MO above
// everything — every kind must be named.
$it = $pdo->prepare('SELECT * FROM ipd_bill_items WHERE ipd_bill_id = ? ORDER BY FIELD(item_kind,\'STAY\',\'NURSING\',\'MO\',\'CONSULT_VISIT\',\'SERVICE\'), id');
$it->execute([(int) $bill['id']]);
$items = $it->fetchAll();

// Two printed sections. Room/nursing/MO/consultant are what the stay itself
// cost; services are what was done during it.
$groups = [
    'Room & daily charges' => [],
    'Services & procedures' => [],
];
foreach ($items as $li) {
    $key = $li['item_kind'] === 'SERVICE' ? 'Services & procedures' : 'Room & daily charges';
    $groups[$key][] = $li;
}

$discount = (float) $bill['subtotal'] - (float) $bill['grand_total'];

// Advances. The invoice used to print a single combined "Paid" figure — which
// per ipd_discharge.php is cash + advance — so a patient who paid Rs 20,000 up
// front saw no trace of it on their own invoice. Show it explicitly.
$advApplied = (float) ($bill['advance_applied'] ?? 0);
$advRefunded = (float) ($bill['advance_refunded'] ?? 0);
if ($bill['status'] !== 'paid') {
    // Not settled yet: compute what the advance WOULD cover, live.
    try {
        require_once __DIR__ . '/config/ipd_advances.php';
        $held = ipd_advance_balance($pdo, $admissionId);
        [$advApplied, ] = ipd_settle_advance((float) $bill['grand_total'], $held);
    } catch (Throwable $e) { $advApplied = 0.0; }
}
$balanceDue = max(0, round((float) $bill['grand_total'] - $advApplied, 2));

// The printed name follows the treating consultant — a dental admission prints
// the dental identity, everything else the house brand. This page previously
// hardcoded a name that matched neither.
require_once __DIR__ . '/config/brand.php';
require_once __DIR__ . '/config/payment_methods.php';
$brand = brand($bill['consultant_specialty'] ?? null);
$logoFile = brand_logo($bill['consultant_specialty'] ?? null);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>IPD Invoice <?= htmlspecialchars($bill['invoice_number']) ?></title>
<style>
/* @page is written by partials/paper_size.php so the size can be switched. */
* { box-sizing: border-box; }
body { font-family: 'Lora', Georgia, serif; color: #1a1a1a; margin: 0; padding: 16px; font-size: 12.5px; }
.wrap { max-width: 148mm; margin: 0 auto; }
:root[data-paper="A4"] .wrap { max-width: 186mm; }
.hdr { border: 1.5px solid #1a1a1a; border-radius: 6px; padding: 12px 16px; margin-bottom: 14px; }
.clinic-logo { height: 34px; display: block; background: #fff; margin-bottom: 2px; }
.hdr h1 { font-size: 18px; margin: 0 0 2px; }
.hdr .sub { font-size: 11px; color: #444; }
.meta { display: flex; justify-content: space-between; gap: 16px; margin-bottom: 12px; }
.meta table { font-size: 11.5px; } .meta td { padding: 1px 0; } .meta td:first-child { color: #555; padding-right: 10px; }
table.items { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
table.items th, table.items td { padding: 6px 8px; border-bottom: 1px solid #ccc; text-align: left; }
table.items th { font-size: 10px; text-transform: uppercase; letter-spacing: .04em; color: #555; }
table.items td.amt, table.items th.amt { text-align: right; }
.tot { width: 55%; margin-left: auto; }
.tot .r { display: flex; justify-content: space-between; padding: 3px 8px; font-size: 12.5px; }
.tot .r.grand { border-top: 1.5px solid #1a1a1a; font-weight: 700; font-size: 14px; margin-top: 4px; padding-top: 6px; }
table.items tr.grp td { background: #f0f0ec; font-weight: 700; font-size: 10px; text-transform: uppercase; letter-spacing: .05em; color: #444; padding: 4px 8px; }
.tot .r.due { border-top: 1px solid #999; font-weight: 700; font-size: 13px; margin-top: 3px; padding-top: 5px; }
.foot { margin-top: 20px; font-size: 10.5px; color: #666; text-align: center; }
.print-btn { margin: 12px 0; display: flex; align-items: center; gap: 14px; }
.print-btn .paper-pick { padding-left: 14px; border-left: 1px solid #ccc; }
@media print { .print-btn { display: none; } body { padding: 0; } }
</style>
</head>
<body>
<div class="print-btn"><button onclick="window.print()">Print / Save PDF</button><?php $paperDefault = 'A5'; $paperMargin = '10mm'; require __DIR__ . '/partials/paper_size.php'; ?></div>
<div class="wrap">
    <div class="hdr">
        <img class="clinic-logo" src="assets/images/<?= htmlspecialchars($logoFile) ?>" alt="">
        <h1><?= htmlspecialchars($brand['name']) ?></h1>
        <div class="sub">In-Door (IPD) Invoice &middot; <?= htmlspecialchars($bill['invoice_number']) ?></div>
    </div>

    <div class="meta">
        <table>
            <tr><td>Patient</td><td><b><?= htmlspecialchars($bill['patient_name']) ?></b></td></tr>
            <tr><td>MR No.</td><td><?= htmlspecialchars($bill['mrn']) ?></td></tr>
            <tr><td>Consultant</td><td><?= htmlspecialchars($bill['consultant_name'] ?: '—') ?></td></tr>
        </table>
        <table>
            <tr><td>Room</td><td><?= htmlspecialchars($bill['room_category']) ?> &middot; <?= (int) $bill['room_no'] ?></td></tr>
            <tr><td>Admitted</td><td><?= date('d/m/Y H:i', strtotime($bill['admitted_at'])) ?></td></tr>
            <tr><td>Discharged</td><td><?= $bill['discharged_at'] ? date('d/m/Y H:i', strtotime($bill['discharged_at'])) : '—' ?></td></tr>
        </table>
    </div>

    <table class="items">
        <thead><tr><th>Description</th><th>Qty</th><th class="amt">Amount (Rs)</th></tr></thead>
        <tbody>
            <?php foreach ($groups as $groupName => $groupItems): ?>
                <?php if (!$groupItems) continue; ?>
                <tr class="grp"><td colspan="3"><?= htmlspecialchars($groupName) ?></td></tr>
                <?php foreach ($groupItems as $li): ?>
                <tr>
                    <td><?= htmlspecialchars($li['description']) ?></td>
                    <td><?= rtrim(rtrim(number_format((float) $li['quantity'], 2), '0'), '.') ?></td>
                    <td class="amt"><?= number_format((float) $li['amount']) ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="tot">
        <div class="r"><span>Subtotal</span><span>Rs <?= number_format((float) $bill['subtotal']) ?></span></div>
        <?php if ($discount > 0.009): ?>
        <div class="r"><span>Discount</span><span>&minus; Rs <?= number_format($discount) ?></span></div>
        <?php endif; ?>
        <div class="r grand"><span>Grand Total</span><span>Rs <?= number_format((float) $bill['grand_total']) ?></span></div>
        <?php if ($advApplied > 0.009): ?>
        <div class="r"><span>Advance received</span><span>&minus; Rs <?= number_format($advApplied) ?></span></div>
        <?php endif; ?>
        <?php if ($bill['status'] === 'paid'): ?>
        <?php
        // paid_amount is cash + advance combined; show the cash part on its own
        // so the two lines add up to what the patient actually handed over.
        $cashPart = max(0, round((float) $bill['paid_amount'] - $advApplied, 2));
        ?>
        <?php if ($cashPart > 0.009): ?>
        <div class="r"><span>Paid (<?= htmlspecialchars(pay_method_label($bill['payment_method'])) ?>)</span><span>Rs <?= number_format($cashPart) ?></span></div>
        <?php endif; ?>
        <?php if ($advRefunded > 0.009): ?><div class="r"><span>Advance returned</span><span>Rs <?= number_format($advRefunded) ?></span></div><?php endif; ?>
        <?php if ((float) $bill['write_off_amount'] > 0): ?><div class="r"><span>Written off</span><span>Rs <?= number_format((float) $bill['write_off_amount']) ?></span></div><?php endif; ?>
        <?php else: ?>
        <div class="r due"><span>Balance payable</span><span>Rs <?= number_format($balanceDue) ?></span></div>
        <?php endif; ?>
    </div>

    <div class="foot">Generated <?= date('d/m/Y H:i') ?> &middot; This is a computer-generated invoice.</div>
</div>
</body>
</html>
