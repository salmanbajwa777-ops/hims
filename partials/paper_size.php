<?php
/**
 * A4 / A5 paper-size picker for a print view.
 *
 * DELIBERATELY NOT the per-doctor users.invoice_paper_size setting — that one is
 * for the CONSULTATION slip and is chosen by the doctor, not at print time. This
 * is a print-time control on the IPD documents, where the same sheet is
 * sometimes wanted on A5 for the patient's file and A4 for the ward folder.
 *
 * @page size cannot be changed by a class, so the rule is written into a
 * <style> element that the picker rewrites live. The choice is stored in
 * localStorage per browser: reception sets A4 once on their terminal and it
 * sticks, while another terminal can sit on A5.
 *
 * Usage — before requiring, set:
 *   $paperDefault  'A4' | 'A5'   the size this document uses when nothing is stored
 *   $paperMargin   optional, default '10mm'
 * Then place the returned markup inside the on-screen (non-printing) toolbar.
 */
$paperDefault = (isset($paperDefault) && $paperDefault === 'A4') ? 'A4' : 'A5';
$paperMargin  = $paperMargin ?? '10mm';
?>
<span class="paper-pick">
    <span class="paper-label">Paper</span>
    <label><input type="radio" name="paper_size" value="A4"> A4</label>
    <label><input type="radio" name="paper_size" value="A5"> A5</label>
</span>
<style id="paperRule"></style>
<style>
.paper-pick { display: inline-flex; align-items: center; gap: 8px; }
.paper-pick .paper-label { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; opacity: .75; }
.paper-pick label { display: inline-flex; align-items: center; gap: 4px; cursor: pointer; font-size: 12.5px; }
@media print { .paper-pick { display: none; } }
</style>
<script>
(function () {
    var DEFAULT = <?= json_encode($paperDefault) ?>;
    var MARGIN  = <?= json_encode($paperMargin) ?>;
    var KEY     = 'hims.paperSize';
    var rule    = document.getElementById('paperRule');
    var radios  = document.querySelectorAll('input[name="paper_size"]');

    function apply(size) {
        // Rewriting the @page rule is the only way to change print paper size;
        // a class on <body> cannot reach it.
        rule.textContent = '@page { size: ' + size + '; margin: ' + MARGIN + '; }';
        radios.forEach(function (r) { r.checked = (r.value === size); });
        // Documents laid out at A5 width would print as a narrow column down the
        // middle of an A4 sheet, so the content width follows the paper.
        document.documentElement.setAttribute('data-paper', size);
    }

    var stored = null;
    try { stored = window.localStorage.getItem(KEY); } catch (e) { /* private mode */ }
    apply(stored === 'A4' || stored === 'A5' ? stored : DEFAULT);

    radios.forEach(function (r) {
        r.addEventListener('change', function () {
            if (!r.checked) { return; }
            apply(r.value);
            try { window.localStorage.setItem(KEY, r.value); } catch (e) { /* ignore */ }
        });
    });
})();
</script>
