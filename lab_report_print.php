<?php
/**
 * Lab report printout (Metacare Diagnostics). Admin only. Browser "Save as PDF".
 * No QR code, no doctor block, no verification claim — by design.
 */
require_once __DIR__ . '/config/guard_admin.php';
require_once __DIR__ . '/config/lab.php';
require_once __DIR__ . '/config/brand.php';

$id = (int) ($_GET['id'] ?? 0);
$o = lab_load_order($pdo, $id);
if (!$o) { http_response_code(404); exit('Report not found.'); }
$st = $pdo->prepare('SELECT * FROM lab_results WHERE order_id = ? ORDER BY sort_order'); $st->execute([$id]);
$rows = $st->fetchAll();
if (!$rows) { exit('This order has no typed results to print.'); }
$pt = lab_patient_block($o);
$b = brand(null);
$labAddr = $b['address_line1'] . ', ' . $b['address_line2']; // no clinic name: the lab footer shows the street address only
$fmt = fn($t) => $t ? date('d-M-Y g:i a', strtotime($t)) : '';
?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Lab Report <?= lab_h($pt['name']) ?></title>
<style>
@page{size:A4;margin:12mm 12mm 26mm}
*{box-sizing:border-box}
body{margin:0;background:#e9ecea;font:12.5px -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;color:#111}
.bar{max-width:794px;margin:10px auto;text-align:right}
.bar button{background:#02624f;color:#fff;border:0;border-radius:8px;padding:9px 16px;font:inherit;cursor:pointer}
.paper{width:794px;max-width:100%;min-height:1000px;margin:0 auto 20px;background:#fff;padding:34px 40px 90px;position:relative}
.ph{display:flex;align-items:center;gap:14px;padding-bottom:10px;border-bottom:3px solid #02624f}
.ph img{width:62px;height:62px}.ph .n{font-size:26px;font-weight:800;color:#02624f;line-height:1}.ph .t{color:#444;font-size:12px;margin-top:3px}
.pi{display:grid;grid-template-columns:1fr 1fr;gap:4px 24px;margin:14px 0 10px}.pi b{display:inline-block;min-width:96px}
.fb{background:#02624f;color:#fff;text-align:center;font-weight:700;padding:3px;margin-top:6px;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.cols,.rr{display:grid;grid-template-columns:2.2fr 1fr .8fr 1.4fr;gap:6px;padding:4px 2px}
.cols{font-weight:700;border-bottom:1px solid #999}
.sect{background:#eee;border:1px solid #bbb;font-weight:700;color:#7a1f1f;padding:3px 6px;margin-top:8px;-webkit-print-color-adjust:exact;print-color-adjust:exact;break-after:avoid}
.sub1{font-weight:700;margin:8px 0 1px;break-after:avoid}.sub2{text-decoration:underline;margin:6px 0 0;break-after:avoid}
.rr{border-bottom:1px dotted #aaa;break-inside:avoid}.rr .v.o{font-weight:800}.rr .name{padding-left:10px}
.nt{margin-top:10px;white-space:pre-line}
.pf{position:fixed;left:12mm;right:12mm;bottom:0;border-top:3px solid #02624f;padding-top:6px;font-size:11px;color:#333;display:flex;justify-content:space-between;background:#fff;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.pfx{position:absolute;left:40px;right:40px;bottom:20px;border-top:3px solid #02624f;padding-top:6px;font-size:11px;color:#333;display:flex;justify-content:space-between}
@media print{body{background:#fff}.bar,.pfx{display:none}.paper{width:auto;margin:0;padding:0;min-height:0}}
@media screen{.pf{display:none}}
</style></head><body>
<div class="bar"><button onclick="print()">Print / Save as PDF</button></div>
<div class="paper">
  <div class="ph"><img src="assets/img/metacare_logo.png" alt=""><div><div class="n"><?= lab_h(LAB_NAME) ?></div><div class="t"><?= lab_h(LAB_TAGLINE) ?></div></div></div>
  <div class="pi">
    <div><b>Name:</b> <?= lab_h($pt['name']) ?></div><div><b>Age/Gender:</b> <?= lab_h($pt['agegender']) ?></div>
    <?php if ($pt['mrn']): ?><div><b>MR Number:</b> <?= lab_h($pt['mrn']) ?></div><?php else: ?><div></div><?php endif; ?>
    <div><b>Referred by:</b> <?= lab_h($o['referred_by']) ?></div>
    <div><b>Specimen Date:</b> <?= lab_h($fmt($o['specimen_at'])) ?></div><div><b>Report Date:</b> <?= lab_h($fmt($o['report_at'])) ?></div>
  </div>
  <div class="fb">Final Report</div>
  <div class="cols"><div>Test Name</div><div>Results</div><div>Unit</div><div>Reference Ranges</div></div>
<?php
$lastSec = null; $lastTest = null; $lastGrp = null;
foreach ($rows as $r) {
    if ($r['section'] !== $lastSec) { echo '<div class="sect">' . lab_h($r['section']) . '</div>'; $lastSec = $r['section']; $lastTest = null; }
    if ($r['test_name'] !== $lastTest) { echo '<div class="sub1">' . lab_h($r['test_name']) . '</div>'; $lastTest = $r['test_name']; $lastGrp = ''; }
    if ($r['group_label'] !== '' && $r['group_label'] !== $lastGrp) { echo '<div class="sub2">' . lab_h($r['group_label']) . '</div>'; }
    $lastGrp = $r['group_label'];
    $bold = $r['is_out'] || $r['interp'] !== '';
    echo '<div class="rr"><div class="name">' . lab_h($r['param_name']) . '</div>'
       . '<div class="v' . ($bold ? ' o' : '') . '">' . lab_h(lab_fmt_value($r['result_value'])) . ($r['interp'] !== '' ? '<br>' . lab_h($r['interp']) : '') . '</div>'
       . '<div>' . lab_h($r['unit']) . '</div><div>' . str_replace(' | ', '<br>', lab_h($r['ref_range'])) . '</div></div>';
}
?>
  <?php if (trim((string) $o['note']) !== ''): ?><div class="nt"><?= lab_h($o['note']) ?></div><?php endif; ?>
  <div class="pfx"><span><?= lab_h($labAddr) ?> &middot; <?= lab_h($b['phone']) ?></span><span>Printed <?= date('d-M-Y') ?></span></div>
</div>
<div class="pf"><span><?= lab_h($labAddr) ?> &middot; <?= lab_h($b['phone']) ?></span><span>Printed <?= date('d-M-Y') ?></span></div>
</body></html>
