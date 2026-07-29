<?php
/**
 * A5 receipt for one IPD advance payment (or the return of an excess).
 *
 * Given to the patient when money changes hands, so it must state what was
 * received, what is now held in total, and that it is adjustable against the
 * final bill — an advance is not a settled charge and the slip should not read
 * like one.
 *
 * Mirrors views/er_invoice_print_partial.php's header/footer so every document
 * the counter hands out looks like it came from the same clinic.
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
require_once __DIR__ . '/config/ipd_advances.php';
require_once __DIR__ . '/config/brand.php';
refresh_session_permissions($pdo);

// Doctors never handle cash and have no reason to print a money receipt.
if (($_SESSION['base_role'] ?? '') === 'DOCTOR') {
    http_response_code(403);
    exit('Forbidden.');
}

$advanceId = (int) ($_GET['id'] ?? 0);

if (!ipd_advances_ready($pdo)) {
    http_response_code(404);
    exit('Advance payments are not enabled — run sql/ipd/add_ipd_advances.sql.');
}

$stmt = $pdo->prepare('
    SELECT a.*, u.name AS received_by_name,
           adm.room_category, adm.room_no, adm.admitted_at,
           p.name AS patient_name, p.mrn, p.phone, p.dob
    FROM ipd_advances a
    JOIN ipd_admissions adm ON adm.id = a.admission_id
    JOIN visits v ON v.id = adm.visit_id
    JOIN patients p ON p.id = v.patient_id
    LEFT JOIN users u ON u.id = a.received_by_id
    WHERE a.id = ?
');
$stmt->execute([$advanceId]);
$r = $stmt->fetch();

if (!$r) {
    http_response_code(404);
    exit('Receipt not found.');
}

// Balance AFTER this receipt, which is what the patient wants to see on it.
$heldNow = ipd_advance_balance($pdo, (int) $r['admission_id']);
$isReturn = $r['direction'] === 'REFUND';
$isVoid = !empty($r['voided_at']);

// Mark the first print, same pattern as the other slips.
try {
    $pdo->prepare('UPDATE ipd_advances SET printed_at = COALESCE(printed_at, NOW()), printed_by_id = COALESCE(printed_by_id, ?) WHERE id = ?')
        ->execute([(int) $_SESSION['user_id'], $advanceId]);
} catch (Throwable $e) { /* printing must never fail on a bookkeeping write */ }

$b = brand();
$methodLabel = ucfirst(str_replace('_', ' ', $r['payment_method']));
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= htmlspecialchars($r['receipt_number']) ?></title>
<style>
    @page { size: A5; margin: 10mm; }
    * { box-sizing: border-box; }
    body { font-family: "Segoe UI", Arial, sans-serif; color: #1b1f1e; margin: 0; font-size: 12px; }
    .sheet { width: 128mm; margin: 0 auto; }
    .hdr { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #0E5456; padding-bottom: 8px; }
    .cname { font-size: 19px; font-weight: 800; color: #0E5456; letter-spacing: .01em; }
    .ctag { font-size: 10px; color: #5b6360; margin-top: 1px; }
    .cmeta { font-size: 9.5px; color: #5b6360; text-align: right; line-height: 1.5; }
    .doctype { margin-top: 12px; display: flex; justify-content: space-between; align-items: baseline; }
    .doctype .t { font-size: 14px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; }
    .doctype .no { font-size: 12px; font-weight: 700; font-variant-numeric: tabular-nums; }
    .rows { margin-top: 10px; border: 1px solid #d8ddda; border-radius: 4px; overflow: hidden; }
    .row { display: flex; border-bottom: 1px solid #e8ecea; }
    .row:last-child { border-bottom: none; }
    .row .k { width: 38%; padding: 5px 8px; background: #f4f6f5; font-size: 10.5px; color: #5b6360; font-weight: 600; }
    .row .v { flex: 1; padding: 5px 8px; font-size: 11.5px; font-weight: 600; }
    .amtbox { margin-top: 12px; border: 2px solid #0E5456; border-radius: 5px; padding: 9px 12px; display: flex; justify-content: space-between; align-items: baseline; }
    .amtbox .l { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #0E5456; }
    .amtbox .a { font-size: 21px; font-weight: 800; font-variant-numeric: tabular-nums; }
    .held { margin-top: 8px; display: flex; justify-content: space-between; font-size: 11.5px; padding: 6px 12px; background: #f4f6f5; border-radius: 4px; }
    .held b { font-variant-numeric: tabular-nums; }
    .note { margin-top: 10px; font-size: 10px; color: #5b6360; line-height: 1.5; }
    .sigs { margin-top: 20px; display: flex; gap: 24px; }
    .sig { flex: 1; border-top: 1px solid #9aa3a0; padding-top: 4px; font-size: 9.5px; color: #5b6360; text-align: center; }
    .foot { margin-top: 14px; border-top: 1px solid #d8ddda; padding-top: 6px; font-size: 8.5px; color: #8a918e; display: flex; justify-content: space-between; }
    .void { position: fixed; top: 42%; left: 50%; transform: translate(-50%,-50%) rotate(-22deg); font-size: 62px; font-weight: 800; color: rgba(179,38,30,.16); letter-spacing: .1em; pointer-events: none; }
    @media print { .noprint { display: none; } }
    .noprint { text-align: center; margin: 14px 0; }
    .noprint button { padding: 8px 20px; font: inherit; font-size: 13px; font-weight: 600; background: #0E5456; color: #fff; border: none; border-radius: 7px; cursor: pointer; }
</style>
</head>
<body onload="window.print()">
<?php if ($isVoid): ?><div class="void">VOID</div><?php endif; ?>
<div class="sheet">
    <div class="hdr">
        <div>
            <div class="cname"><?= htmlspecialchars($b['name']) ?></div>
            <div class="ctag"><?= htmlspecialchars($b['tagline']) ?></div>
        </div>
        <div class="cmeta">
            <?= htmlspecialchars($b['phone']) ?><br>
            <?= htmlspecialchars($b['email']) ?><br>
            <?= htmlspecialchars($b['website']) ?>
        </div>
    </div>

    <div class="doctype">
        <span class="t"><?= $isReturn ? 'Advance Return' : 'Advance Receipt' ?></span>
        <span class="no"><?= htmlspecialchars($r['receipt_number']) ?></span>
    </div>

    <div class="rows">
        <div class="row"><div class="k">Patient</div><div class="v"><?= htmlspecialchars(mb_strtoupper($r['patient_name'], 'UTF-8')) ?></div></div>
        <div class="row"><div class="k">MR No.</div><div class="v"><?= htmlspecialchars($r['mrn']) ?></div></div>
        <div class="row"><div class="k">Admission</div><div class="v">#<?= (int) $r['admission_id'] ?> &middot; <?= htmlspecialchars($r["room_category"]) ?>, room <?= (int) $r['room_no'] ?></div></div>
        <div class="row"><div class="k">Admitted</div><div class="v"><?= date('d/m/Y', strtotime($r['admitted_at'])) ?></div></div>
        <div class="row"><div class="k"><?= $isReturn ? 'Returned as' : 'Received as' ?></div><div class="v"><?= htmlspecialchars($methodLabel) ?></div></div>
        <div class="row"><div class="k">Date &amp; time</div><div class="v"><?= date('d/m/Y, h:i A', strtotime($r['created_at'])) ?></div></div>
        <div class="row"><div class="k"><?= $isReturn ? 'Returned by' : 'Received by' ?></div><div class="v"><?= htmlspecialchars($r['received_by_name'] ?: '—') ?></div></div>
        <?php if (!empty($r['note'])): ?>
        <div class="row"><div class="k">Note</div><div class="v"><?= htmlspecialchars($r['note']) ?></div></div>
        <?php endif; ?>
    </div>

    <div class="amtbox">
        <span class="l"><?= $isReturn ? 'Amount returned' : 'Advance received' ?></span>
        <span class="a">Rs <?= number_format((float) $r['amount'], 2) ?></span>
    </div>

    <?php if (!$isVoid): ?>
    <div class="held"><span>Total advance held after this receipt</span><b>Rs <?= number_format($heldNow, 2) ?></b></div>
    <?php endif; ?>

    <div class="note">
        <?php if ($isReturn): ?>
            This is the return of an advance that exceeded the final bill for this stay.
            The discharge invoice shows the full settlement.
        <?php else: ?>
            This is an ADVANCE against the in-door stay, not a final bill. It is adjusted
            against the discharge invoice; any excess is returned at discharge. Please
            present this receipt when settling.
        <?php endif; ?>
        <?php if ($isVoid): ?>
            <br><b style="color:#b3261e;">This receipt was cancelled<?= $r['void_reason'] ? ' — ' . htmlspecialchars($r['void_reason']) : '' ?>.</b>
        <?php endif; ?>
    </div>

    <div class="sigs">
        <div class="sig">Received by (Front Desk)</div>
        <div class="sig">Patient / Attendant</div>
    </div>

    <div class="foot">
        <span><?= htmlspecialchars($b['address']) ?></span>
        <span>Printed <?= date('d/m/Y H:i') ?></span>
    </div>
</div>
<div class="noprint"><button onclick="window.print()">Print</button></div>
</body>
</html>
