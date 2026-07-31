<?php
/**
 * "Write the treatment sheet" prompt + persistent warning banner.
 *
 * Two related pieces of UI, kept together because they answer the same
 * question — has a doctor signed this patient's drug chart yet?
 *
 *   1. The MODAL fires once, right after an IPD admit (?admitted=1). It is
 *      skippable on purpose: the person who admits is often reception, and a
 *      hard block at that moment would stall a genuine emergency admission.
 *   2. The BANNER is not skippable. It renders on every subsequent view of the
 *      stay until at least one order has been approved by a doctor, so a
 *      skipped modal can never turn into a patient with no drug chart.
 *
 * Expects, from the including page:
 *   $pdo, $admissionId  — required
 *   $sheetState         — optional; computed here when absent
 *   $isOpen             — optional; suppresses both when the stay is closed
 *
 * Include AFTER partials/head.php (it emits markup and its own scoped styles).
 */

require_once __DIR__ . '/../config/ipd_treatment.php';

$tsAdmissionId = (int) ($admissionId ?? 0);
$tsOpen  = $isOpen ?? true;
$tsState = $sheetState ?? ($tsAdmissionId ? ipd_sheet_state($pdo, $tsAdmissionId) : ['cleared' => true, 'pending' => 0, 'has_orders' => false]);

// A closed stay has nothing left to prescribe, so neither piece applies.
if ($tsAdmissionId && $tsOpen && (!$tsState['cleared'] || $tsState['pending'])):

// Fire the modal only on the post-admit hop, and only when nothing is signed
// yet. Re-prompting a doctor who is mid-round would be noise.
$tsJustAdmitted = isset($_GET['admitted']) && !$tsState['has_orders'];
$tsCanWrite = function_exists('has_permission') && has_permission('IPD_WRITE_MED_ORDER');
$tsCanApprove = function_exists('has_permission') && has_permission('IPD_APPROVE_MED_ORDER');
?>
<style>
.tsp-banner { background: var(--warn-bg); border-left: 5px solid var(--warn); border-radius: var(--radius-card); padding: 14px 18px; margin-bottom: 16px; display: flex; gap: 14px; align-items: center; flex-wrap: wrap; justify-content: space-between; }
.tsp-banner strong { color: var(--warn); display: block; font-size: var(--fs-card); margin-bottom: 3px; }
.tsp-banner p { margin: 0; font-size: var(--fs-cell); color: var(--text-secondary); }
.tsp-cta { background: var(--primary); color: var(--on-primary); border: none; border-radius: var(--radius-btn); padding: 9px 18px; font-size: var(--fs-btn); font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; white-space: nowrap; }
.tsp-cta:hover { filter: brightness(1.08); }
dialog.tsp-modal { border: none; border-radius: var(--radius-card); padding: 0; max-width: 470px; width: 92vw; box-shadow: var(--shadow-lg); }
dialog.tsp-modal::backdrop { background: rgba(22,33,28,.5); }
.tsp-in { padding: 24px 26px; }
.tsp-in h3 { margin: 0 0 8px; font-size: var(--fs-page); }
.tsp-in p { font-size: var(--fs-cell); color: var(--text-secondary); margin: 0 0 10px; line-height: 1.55; }
.tsp-note { background: var(--warn-bg); border-radius: var(--radius-input); padding: 10px 12px; font-size: 12.5px; color: var(--text-secondary); margin-bottom: 16px; }
.tsp-row { display: flex; gap: 8px; justify-content: flex-end; flex-wrap: wrap; }
.tsp-skip { background: var(--card); border: 1px solid var(--border-strong); border-radius: var(--radius-btn); padding: 9px 16px; font-size: var(--fs-btn); font-weight: 600; cursor: pointer; color: var(--text-secondary); }
@media print { .tsp-banner, dialog.tsp-modal { display: none !important; } }
</style>

<div class="tsp-banner no-print">
  <div>
    <?php if (!$tsState['cleared']): ?>
      <strong>&#9888; No approved treatment sheet</strong>
      <p>
        <?php if ($tsState['pending']): ?>
          <?= (int) $tsState['pending'] ?> medication order(s) are waiting for a doctor's approval.
          Nothing can be administered until a doctor signs.
        <?php else: ?>
          This admitted patient has no medication orders yet. Treatment cannot start until a doctor writes and approves the sheet.
        <?php endif; ?>
      </p>
    <?php else: ?>
      <strong>&#9888; <?= (int) $tsState['pending'] ?> order(s) awaiting approval</strong>
      <p>Approved drugs are running. The pending ones cannot be given until a doctor signs them.</p>
    <?php endif; ?>
  </div>
  <a class="tsp-cta" href="ipd_treatment_sheet.php?id=<?= $tsAdmissionId ?>">
    <?= $tsState['pending'] && $tsCanApprove ? 'Review &amp; approve' : 'Open treatment sheet' ?>
  </a>
</div>

<?php if ($tsJustAdmitted && $tsCanWrite): ?>
<dialog class="tsp-modal" id="tspModal">
  <div class="tsp-in">
    <h3>Write the treatment sheet</h3>
    <p>This patient is now admitted. The next step is the medication chart &mdash; drug, dose, route and frequency for each order.</p>
    <?php if ($tsCanApprove): ?>
      <div class="tsp-note">Orders you write are approved under your name straight away, and the administration schedule is generated immediately.</div>
    <?php else: ?>
      <div class="tsp-note">You can fill the sheet now, but a <b>doctor must approve each order</b> before any dose can be given.</div>
    <?php endif; ?>
    <div class="tsp-row">
      <button type="button" class="tsp-skip" onclick="document.getElementById('tspModal').close()">Not now</button>
      <a class="tsp-cta" href="ipd_treatment_sheet.php?id=<?= $tsAdmissionId ?>">Fill treatment sheet</a>
    </div>
  </div>
</dialog>
<script>
/* Deliberately dismissible — see the header comment. The banner above is what
   guarantees a skipped prompt cannot be forgotten. */
(function () {
    var d = document.getElementById('tspModal');
    if (d && typeof d.showModal === 'function') { d.showModal(); }
})();
</script>
<?php endif; ?>

<?php endif; /* $tsAdmissionId && $tsOpen && not cleared */ ?>
