<?php
/**
 * Lab bill printout (Metacare Diagnostics), A5 portrait. Admin only. Browser "Save as PDF".
 * ?copy=lab prints the "Lab Copy" tag, default is "Patient Copy". No barcode by design.
 * Total / Net / Balance are computed here from the lines; they are never stored.
 */
require_once __DIR__ . '/config/guard_admin.php';
require_once __DIR__ . '/config/lab.php';
require_once __DIR__ . '/config/brand.php';

$id = (int) ($_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT i.*, u.name AS by_name FROM lab_invoices i LEFT JOIN users u ON u.id = i.created_by_id WHERE i.id = ?');
$st->execute([$id]);
$inv = $st->fetch();
if (!$inv) { http_response_code(404); exit('Invoice not found.'); }
$o = lab_load_order($pdo, (int) $inv['order_id']);
$st = $pdo->prepare('SELECT * FROM lab_invoice_lines WHERE invoice_id = ? ORDER BY sort_order'); $st->execute([$id]);
$lines = $st->fetchAll();
$pt = lab_patient_block($o);
$b = brand(null);
$labAddr = $b['address_line1'] . ', ' . $b['address_line2'];
$copy = ($_GET['copy'] ?? '') === 'lab' ? 'Lab Copy' : 'Patient Copy';

$total = 0.0; foreach ($lines as $l) { $total += (float) $l['amount']; }
$adj = (float) $inv['adjustment']; $net = $total - $adj; $rec = (float) $inv['received']; $bal = $net - $rec;
$n = fn($v) => ((float) $v) > 0 ? number_format((float) $v, 0) : '-';
?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Lab Bill <?= lab_h($inv['invoice_no']) ?></title>
<style>
@page{size:A5 portrait;margin:0}
*{box-sizing:border-box}
body{margin:0;background:#e9ecea;font:11px -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;color:#111}
.bar{max-width:559px;margin:10px auto;text-align:right}
.bar button{background:#02624f;color:#fff;border:0;border-radius:8px;padding:9px 16px;font:inherit;cursor:pointer}
.paper{width:559px;height:794px;margin:0 auto 20px;background:#fff;padding:22px 26px 20px;position:relative;overflow:hidden}
.ph{display:flex;align-items:center;gap:14px;padding-bottom:10px;border-bottom:3px solid #02624f;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.ph img{width:48px;height:48px}.ph .n{font-size:20px;font-weight:800;color:#02624f;line-height:1}.ph .t{color:#444;font-size:11px;margin-top:3px}
.ph .r{margin-left:auto;text-align:right;color:#444;font-size:10px}
.tags{display:flex;justify-content:space-between;margin:12px 0}
.tag{border:1.5px solid #777;background:#eee;font-weight:700;padding:3px 12px;font-size:13px;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.pi{display:grid;grid-template-columns:1fr 1fr;gap:3px 14px;margin:4px 0 8px}.pi b{display:inline-block;min-width:76px}
table.b{width:100%;border-collapse:collapse}
table.b th{text-align:left;border-top:1px solid #999;border-bottom:1px solid #999;padding:4px 3px}
table.b td{padding:4px 3px;border-bottom:1px solid #ddd}.r{text-align:right}
.ft{display:flex;justify-content:space-between;margin-top:10px}
.tt{width:210px}.tt div{display:flex;justify-content:space-between;padding:2px 0}.tt .bd{font-weight:700;color:#b42318}
.note{margin-top:16px;font-size:10px;color:#7a1f1f;font-style:italic;font-weight:600}
.pf{position:absolute;left:26px;right:26px;bottom:14px;border-top:3px solid #02624f;padding-top:5px;font-size:9px;color:#333;display:flex;justify-content:space-between;-webkit-print-color-adjust:exact;print-color-adjust:exact}
@media print{body{background:#fff}.bar{display:none}.paper{margin:0}}
</style></head><body>
<div class="bar"><button onclick="print()">Print / Save as PDF</button></div>
<div class="paper">
  <div class="ph"><img src="assets/img/metacare_logo.png" alt=""><div><div class="n"><?= lab_h(LAB_NAME) ?></div><div class="t">Your Health Comes First</div></div>
    <div class="r">Phone: <?= lab_h($b['phone']) ?><br><?= lab_h($b['address_line1']) ?><br><?= lab_h($b['address_line2']) ?></div></div>
  <div class="tags"><span class="tag"><?= lab_h($inv['invoice_no']) ?></span><span class="tag"><?= lab_h($copy) ?></span></div>
  <div class="pi">
    <div><b>Name:</b> <strong><?= lab_h($pt['name']) ?></strong></div><div><b>Age / Gender:</b> <?= lab_h($pt['agegender']) ?></div>
    <div><b>Ref By:</b> <?= lab_h($o['referred_by']) ?></div><div><b>Contact No:</b> <?= lab_h($pt['phone']) ?></div>
    <div></div><div><?php if ($pt['mrn']): ?><b>MR No:</b> <?= lab_h($pt['mrn']) ?><?php endif; ?></div>
  </div>
  <table class="b"><thead><tr><th>Title</th><th>Process</th><th>Reporting</th><th>Sample</th><th class="r">Payable</th></tr></thead><tbody>
  <?php foreach ($lines as $l): ?>
    <tr><td><?= lab_h($l['title']) ?></td><td><?= lab_h($l['process']) ?></td>
        <td><?= $l['reporting_at'] ? lab_h(date('d-M g:i a', strtotime($l['reporting_at']))) : '' ?></td>
        <td><?= lab_h($l['sample_no']) ?></td><td class="r"><?= $n($l['amount']) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <div class="ft">
    <div style="font-size:11px"><b>Initial By:</b> <?= lab_h($inv['by_name']) ?><br><b>Init. Time:</b> <?= lab_h(date('d-M-Y g:i a', strtotime($inv['created_at']))) ?></div>
    <div class="tt">
      <div><b>Total:</b><span><?= $n($total) ?></span></div>
      <div><b>Adjustment:</b><span><?= $n($adj) ?></span></div>
      <div><b>Net Amount:</b><span><?= $n($net) ?></span></div>
      <div><b>Received:</b><b><?= $n($rec) ?></b></div>
      <?php if ($bal > 0): ?><div class="bd"><b>Balance Due:</b><span><?= $n($bal) ?></span></div><?php endif; ?>
    </div>
  </div>
  <div class="note">Note : Reporting time is subject to the sample submission.<br>&nbsp;&nbsp;&nbsp;&nbsp;Please bring this form to collect the reports.<br>&nbsp;&nbsp;&nbsp;&nbsp;For any query related to test results, please contact within 48 Hrs.</div>
  <div class="pf"><span><?= lab_h(LAB_NAME) ?></span><span><?= lab_h($b['phone']) ?> &middot; <?= lab_h($labAddr) ?></span></div>
</div>
</body></html>
