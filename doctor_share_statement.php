<?php
/**
 * Doctor Share Statement — earned share for a date range. (Accounts Phase 6a)
 *
 * The document the clinic and the doctor both sign off against: every stream
 * the doctor earned from, broken down by fee type, then gross -> tax -> the
 * doctor/clinic split -> what has already been disbursed -> net still payable.
 *
 * THE RULE (unchanged since 2026-07-26): tax comes off the FULL amount first,
 * and the remainder is split by the doctor's share %. Rs 100 at 10% tax on a
 * 70/30 split = tax 10, doctor 63, clinic 27. Computed here through
 * doctor_split() / doctor_split_sql() in config/billing.php — never a local
 * copy of the formula.
 *
 * Cash basis throughout: a line counts in the range when the MONEY ARRIVED
 * (bills.paid_at / ipd_bills.paid_at), not when the visit happened. That is how
 * every other money figure in this app is recognised, and it is what makes the
 * statement agree with the day-closing tallies for the same dates.
 *
 * FREE FOLLOW-UPS appear as a line with a count and Rs 0 — deliberately. A
 * doctor seeing 40 patients of whom 12 were free needs to see those 12, or the
 * statement reads as if the work never happened.
 *
 * Screen + A5 single-page print (portrait). Gated on
 * FINANCIAL_VIEW_ALL_COMMISSIONS — the second of the four dead financial keys
 * to finally get a page.
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
refresh_session_permissions($pdo);
require_once __DIR__ . '/config/billing.php';
require_permission('FINANCIAL_VIEW_ALL_COMMISSIONS');

// ---- Range: defaults to last month, the usual payout period ----------------
$defFrom = date('Y-m-01', strtotime('-1 month'));
$defTo   = date('Y-m-t', strtotime('-1 month'));
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : $defFrom;
$to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '')   ? $_GET['to']   : $defTo;
if ($to < $from) { [$from, $to] = [$to, $from]; }
// Exclusive upper bound so a payment at 23:59 on the last day is included.
$toExcl = date('Y-m-d', strtotime($to . ' +1 day'));

$doctorId = (int) ($_GET['doctor_id'] ?? 0);

$doctors = [];
try {
    $doctors = $pdo->query(
        "SELECT id, name, consult_share_pct, consult_has_tax, consult_tax_pct
           FROM users WHERE base_role = 'DOCTOR' ORDER BY name"
    )->fetchAll();
} catch (PDOException $e) { /* leave empty */ }

$doc = null;
foreach ($doctors as $d) { if ((int) $d['id'] === $doctorId) { $doc = $d; break; } }

$sharePct = $doc ? (float) $doc['consult_share_pct'] : 0.0;
$hasTax   = $doc ? (bool) $doc['consult_has_tax'] : false;
$taxPct   = $doc ? (float) $doc['consult_tax_pct'] : 0.0;

// ===========================================================================
// OPD consultations, grouped by fee type so free/part-paid follow-ups show.
// visits.consultation_fee_type is FULL | FREE_FOLLOWUP | HALF_FOLLOWUP |
// THREE_QUARTER_FOLLOWUP (NULL on pre-revisit rows — treated as FULL).
// ===========================================================================
$opdRows = [];
$opdGross = 0.0;
$opdCount = 0;
if ($doc) {
    try {
        $q = $pdo->prepare("
            SELECT COALESCE(v.consultation_fee_type, 'FULL') AS fee_type,
                   COUNT(*)                                  AS n,
                   COALESCE(SUM(b.paid_amount), 0)           AS amt
            FROM bills b
            JOIN visits v ON v.id = b.visit_id
            WHERE v.doctor_id = ?
              AND b.status = 'paid' AND b.voided_at IS NULL
              AND b.paid_at >= ? AND b.paid_at < ?
            GROUP BY fee_type
            ORDER BY FIELD(fee_type,'FULL','THREE_QUARTER_FOLLOWUP','HALF_FOLLOWUP','FREE_FOLLOWUP')
        ");
        $q->execute([$doctorId, $from, $toExcl]);
        $opdRows = $q->fetchAll();
        foreach ($opdRows as $r) { $opdGross += (float) $r['amt']; $opdCount += (int) $r['n']; }
    } catch (PDOException $e) { error_log('[share_stmt opd] ' . $e->getMessage()); }
}

// Free follow-ups that were never billed at all (no paid bill row, so the query
// above cannot see them). Counted separately so the consultation count reflects
// the work actually done rather than only what generated cash.
$freeUnbilled = 0;
if ($doc) {
    try {
        $q = $pdo->prepare("
            SELECT COUNT(*) FROM visits v
            LEFT JOIN bills b ON b.visit_id = v.id AND b.voided_at IS NULL
            WHERE v.doctor_id = ?
              AND v.consultation_fee_type = 'FREE_FOLLOWUP'
              AND v.visit_date >= ? AND v.visit_date < ?
              AND (b.id IS NULL OR b.status <> 'paid')
        ");
        $q->execute([$doctorId, $from, $toExcl]);
        $freeUnbilled = (int) $q->fetchColumn();
    } catch (PDOException $e) { /* pre-revisit schema */ }
}

// ---- Category discounts given away on this doctor's consultations ----------
// Not a deduction (the split already works off what was actually COLLECTED),
// shown so a low gross has a visible explanation.
$opdDiscount = 0.0;
if ($doc) {
    try {
        $q = $pdo->prepare("
            SELECT COALESCE(SUM(v.category_discount_amount), 0)
            FROM bills b JOIN visits v ON v.id = b.visit_id
            WHERE v.doctor_id = ? AND b.status = 'paid' AND b.voided_at IS NULL
              AND b.paid_at >= ? AND b.paid_at < ?
        ");
        $q->execute([$doctorId, $from, $toExcl]);
        $opdDiscount = (float) $q->fetchColumn();
    } catch (PDOException $e) { /* column absent */ }
}

// ---- Refunds against this doctor's consultations ---------------------------
// Keyed off the refund's own date: a refund reduces the period it was ISSUED
// in, so a settled statement stays settled.
$refundAmt = 0.0; $refundCount = 0;
if ($doc) {
    try {
        $q = $pdo->prepare("
            SELECT COUNT(*) AS n, COALESCE(SUM(r.amount), 0) AS amt
            FROM refunds r
            JOIN bills b  ON b.id = r.bill_id
            JOIN visits v ON v.id = b.visit_id
            WHERE v.doctor_id = ? AND r.voided_at IS NULL
              AND r.created_at >= ? AND r.created_at < ?
        ");
        $q->execute([$doctorId, $from, $toExcl]);
        $r = $q->fetch();
        $refundCount = (int) ($r['n'] ?? 0);
        $refundAmt   = (float) ($r['amt'] ?? 0);
    } catch (PDOException $e) { /* no refunds table */ }
}

// ===========================================================================
// IPD ward rounds — chargeable notes (is_paid = 1) on admissions whose IPD
// bill was PAID inside the range. Per-round attribution via each row's own
// visit_charge snapshot, so consultants sharing a stay each earn their own.
// ===========================================================================
$ipdGross = 0.0; $ipdCount = 0;
if ($doc) {
    try {
        $q = $pdo->prepare("
            SELECT COUNT(*) AS n, COALESCE(SUM(dv.visit_charge), 0) AS amt
            FROM ipd_doctor_visits dv
            JOIN ipd_bills ib ON ib.admission_id = dv.admission_id
            WHERE dv.doctor_id = ? AND dv.is_paid = 1 AND dv.visit_charge > 0
              AND ib.status = 'paid' AND ib.voided_at IS NULL
              AND ib.paid_at >= ? AND ib.paid_at < ?
        ");
        $q->execute([$doctorId, $from, $toExcl]);
        $r = $q->fetch();
        $ipdCount = (int) ($r['n'] ?? 0);
        $ipdGross = (float) ($r['amt'] ?? 0);
    } catch (PDOException $e) { /* IPD module absent */ }
}

// ---- Procedures ------------------------------------------------------------
// doctor_procedures holds rates and shares, but NOTHING has ever written a
// procedure transaction — the per-visit table named in HMIS-PHP-PLAN.md does
// not exist yet. The row is rendered as "not billed yet" rather than silently
// omitted, so nobody reads a missing line as a zero-earning month.
$procCount = 0; $procGross = 0.0; $procLive = false;

// ===========================================================================
// The split. Net collected = OPD + IPD - refunds, then tax off the top, then
// the share. doctor_split() is the single source of truth for that order.
// ===========================================================================
$grossCollected = $opdGross + $ipdGross;
$netCollected   = max(0.0, $grossCollected - $refundAmt);
$split = doctor_split($netCollected, $sharePct, $hasTax, $taxPct);

// ---- Already disbursed in this range (posted Doctor Shares expenses) -------
$paidOut = 0.0;
if ($doc) {
    try {
        $q = $pdo->prepare("
            SELECT COALESCE(SUM(e.amount), 0)
            FROM expenses e
            JOIN expense_categories ec ON ec.id = e.category_id
            WHERE e.paid_to_doctor_id = ? AND ec.is_disbursement = 1
              AND e.voided_at IS NULL AND e.approval_status <> 'REJECTED'
              AND COALESCE(e.period_month, e.expense_date) >= ?
              AND COALESCE(e.period_month, e.expense_date) < ?
        ");
        $q->execute([$doctorId, $from, $toExcl]);
        $paidOut = (float) $q->fetchColumn();
    } catch (PDOException $e) { /* paid_to_doctor_id not migrated */ }
}
$netPayable = $split['doctor'] - $paidOut;

$feeLabels = [
    'FULL'                   => 'Full consultation',
    'THREE_QUARTER_FOLLOWUP' => 'Follow-up (75%)',
    'HALF_FOLLOWUP'          => 'Follow-up (50%)',
    'FREE_FOLLOWUP'          => 'Free follow-up',
];

// ---- A5 print view ---------------------------------------------------------
// Its own standalone document (letterhead, ruled tables, white background), the
// same shape as the day-closing slip — NOT a print stylesheet over the screen
// card, which is what made the old output look like a web page on paper.
if (isset($_GET['print']) && $doc) {
    include __DIR__ . '/views/doctor_share_print_partial.php';
    exit;
}

$pageTitle = 'Doctor Share Statement';
// NB: the variable head.php actually renders is $headExtra. Naming this
// $extraCss silently dropped every rule on this page — the tiles, the ledger
// and the two-column layout all fell back to unstyled defaults, which is
// exactly what the "this doesn't match the app" report was.
$headExtra = <<<CSS
<style>
/* No .header rules: the sidebar partial supplies the app bar.
   Controls follow the same .f-group / .filters pattern as expenses.php, so the
   picker reads as one toolbar inside a card rather than three loose fields
   floating on the canvas. */
.f-group { margin-bottom: 14px; }
.f-group label { font-size: 11.5px; font-weight: 600; color: var(--text-secondary); display: block; margin-bottom: 5px; }
.f-group input, .f-group select {
    width: 100%; padding: 10px 12px; border: 1px solid var(--border); border-radius: 10px;
    font: inherit; font-size: 13.5px; background: var(--bg); color: var(--text);
}
.f-group input:focus, .f-group select:focus {
    outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-light); background: var(--card);
}
.filters { display: flex; gap: 10px; align-items: end; flex-wrap: wrap; }
.filters .f-group { margin: 0; }
.filters .f-group input, .filters .f-group select { width: auto; min-width: 150px; }
.filters .spacer { flex: 1; }

/* ---- Statement layout -----------------------------------------------------
   The old screen view was a bare .sheet on the canvas whose rows stretched the
   full width of a desktop monitor, so a four-line statement read as a wall of
   floating text. Now: a headline strip carrying the three numbers anyone
   actually opens this page for, then the working underneath in a real .card,
   width-capped so the money column never drifts a metre from its label. */
.stmt { display: grid; grid-template-columns: minmax(0, 1fr) 300px; gap: 20px; align-items: start; max-width: 1080px; }
@media (max-width: 980px) { .stmt { grid-template-columns: minmax(0, 1fr); } }

/* Headline tiles — net payable is the hero, the other two give it context. */
.tiles { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 20px; max-width: 1080px; }
@media (max-width: 700px) { .tiles { grid-template-columns: 1fr; } }
.tile { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius-card, 14px);
        box-shadow: var(--shadow-sm); padding: 16px 18px; }
.tile .k { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em;
           color: var(--text-muted); margin-bottom: 6px; }
.tile .v { font-size: 23px; font-weight: 800; letter-spacing: -.02em; font-variant-numeric: tabular-nums; color: var(--text); }
.tile .foot { font-size: 11.5px; color: var(--text-secondary); margin-top: 3px; }
.tile.hero { background: var(--primary); border-color: var(--primary); }
.tile.hero .k { color: rgba(255,255,255,.72); }
.tile.hero .v, .tile.hero .foot { color: #fff; }
.tile.hero .foot { color: rgba(255,255,255,.8); }

/* Doctor identity strip above the breakdown. */
.who-bar { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; flex-wrap: wrap;
           padding-bottom: 12px; margin-bottom: 4px; border-bottom: 1px solid var(--border); }
.who-bar .who { font-size: 15px; font-weight: 700; }
.who-bar .terms { font-size: 12px; color: var(--text-secondary); }

/* Line table. Every padding/border is stated explicitly because app.css has a
   global `td { padding:12px 10px; border-top }` that otherwise doubles the row
   height and draws a rule under every single line. */
/* Dotted rules per line and a solid rule above each total — the same ledger
   vocabulary as the day-closing sheet, which is what makes that page read as a
   document rather than a web table. */
.ltab { width: 100%; border-collapse: collapse; font-size: 13px; font-variant-numeric: tabular-nums; }
.ltab td { padding: 5px 0; vertical-align: baseline; border-top: none;
           border-bottom: 1px dotted var(--border); font-size: 13px; }
.ltab td.n { text-align: right; white-space: nowrap; width: 130px; }
.ltab td.qty { text-align: right; color: var(--text-muted); width: 60px; font-size: 11.5px; }
.ltab tr.sect td { padding: 16px 0 4px; font-weight: 700; font-size: 10.5px;
                   text-transform: uppercase; letter-spacing: .07em; color: var(--text-muted);
                   border-top: none; border-bottom: none; }
.ltab tr.sect:first-child td { padding-top: 2px; }
.ltab tr.sum td { font-weight: 700; font-size: 14px; border-top: 1.5px solid var(--border-strong);
                  border-bottom: none; padding-top: 7px; }
.ltab tr.grand td { font-weight: 700; font-size: 15px; padding-top: 8px;
                    border-top: 1.5px solid var(--border-strong); border-bottom: none; }
.ltab .muted { color: var(--text-muted); font-weight: 400; font-size: 11.5px; }
.ltab tr.neg td.n { color: var(--red-text); }

/* Right rail: the split as a ladder, ending in the dark payable block that
   mirrors the closing sheet's EXPECTED CASH IN HAND. */
.rail .step { display: flex; justify-content: space-between; gap: 10px; font-size: 13px; padding: 6px 0;
              border-bottom: 1px dotted var(--border); }
.rail .step .n { font-variant-numeric: tabular-nums; font-weight: 600; white-space: nowrap; }
.rail .step.neg .n { color: var(--red-text); }
.rail .step.muted, .rail .step.muted .n { color: var(--text-muted); font-weight: 400; }
.rail .payable { display: flex; justify-content: space-between; align-items: baseline;
                 background: var(--primary-dark); color: #fff; border-radius: var(--radius-input);
                 padding: 12px 15px; margin-top: 14px; }
.rail .payable .k { font-size: 11px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: #9BD4CF; }
.rail .payable .v { font-size: 22px; font-weight: 700; letter-spacing: -.02em; font-variant-numeric: tabular-nums; }

.empty { padding: 40px 20px; text-align: center; color: var(--text-muted); font-size: 13.5px; }

/* No @media print here on purpose: printing goes through ?print=1, which
   renders views/doctor_share_print_partial.php as its own A5 document. A print
   stylesheet over this screen card is what produced the two-page, borderless
   output it replaced. */
</style>
CSS;
require __DIR__ . '/partials/head.php';
$navActive = 'doctor_share_statement';
require __DIR__ . '/partials/sidebar.php';

$m = fn(float $v) => 'Rs ' . number_format($v);
?>
        <div class="content">
            <!-- No page-level <header>: the sidebar partial already provides the
                 app bar (Sage & Clay, 7940fdf). A second one repeated the title
                 and the date the chrome had just shown. -->
            <div class="page-head">
                <div>
                    <h1 class="page-title">Doctor Share Statement</h1>
                    <div class="page-meta">Earned share for a date range, by the day the money was collected</div>
                </div>
            </div>

            <!-- Picker lives in its own card, the same shape as the Expenses
                 filter bar, instead of floating loose in the page head. -->
            <div class="card pick-card">
                <div class="section-title">Build a statement</div>
                <div class="section-sub">Money is counted on the day it was collected, not the day of the visit.</div>
                <form class="filters" method="GET" action="doctor_share_statement.php">
                    <div class="f-group">
                        <label>Doctor</label>
                        <select name="doctor_id" required>
                            <option value="">Select a doctor&hellip;</option>
                            <?php foreach ($doctors as $d): ?>
                            <option value="<?= (int) $d['id'] ?>" <?= (int) $d['id'] === $doctorId ? 'selected' : '' ?>>
                                <?= htmlspecialchars($d['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="f-group">
                        <label>From</label>
                        <input type="date" name="from" value="<?= htmlspecialchars($from) ?>">
                    </div>
                    <div class="f-group">
                        <label>To</label>
                        <input type="date" name="to" value="<?= htmlspecialchars($to) ?>">
                    </div>
                    <button type="submit" class="btn">View</button>
                    <?php if ($doc): ?>
                    <a class="btn secondary print-btn" target="_blank"
                       href="doctor_share_statement.php?print=1&amp;doctor_id=<?= (int) $doctorId ?>&amp;from=<?= htmlspecialchars($from) ?>&amp;to=<?= htmlspecialchars($to) ?>">Print A5</a>
                    <?php endif; ?>
                </form>
            </div>

            <?php if (!$doc): ?>
            <div class="card"><div class="empty">Pick a doctor and a date range to build the statement.</div></div>
            <?php else: ?>
            <!-- The three numbers this page gets opened for, before any working. -->
            <div class="tiles">
                <div class="tile">
                    <div class="k">Gross collected</div>
                    <div class="v"><?= $m($grossCollected) ?></div>
                    <div class="foot"><?= number_format($opdCount + $freeUnbilled + $ipdCount) ?> consultation<?= ($opdCount + $freeUnbilled + $ipdCount) === 1 ? '' : 's' ?> &middot; <?= htmlspecialchars(date('d/m/Y', strtotime($from))) ?> &ndash; <?= htmlspecialchars(date('d/m/Y', strtotime($to))) ?></div>
                </div>
                <div class="tile">
                    <div class="k">Tax withheld</div>
                    <div class="v"><?= $split['tax'] > 0 ? '&minus;' . $m($split['tax']) : 'Rs 0' ?></div>
                    <div class="foot"><?= $hasTax ? number_format($taxPct, 0) . '% off the top' : 'Doctor self-deposits' ?></div>
                </div>
                <div class="tile hero">
                    <div class="k"><?= $paidOut > 0 ? 'Net still payable' : 'Net payable' ?></div>
                    <div class="v"><?= $m($netPayable) ?></div>
                    <div class="foot"><?= htmlspecialchars($doc['name']) ?> &middot; <?= number_format($sharePct, 0) ?>% share</div>
                </div>
            </div>

            <div class="stmt">
                <div class="card">
                    <div class="who-bar">
                        <span class="who"><?= htmlspecialchars($doc['name']) ?></span>
                        <span class="terms">
                            Share <?= number_format($sharePct, 0) ?>%<?php
                            echo $hasTax ? ' &middot; Tax ' . number_format($taxPct, 0) . '% withheld' : ' &middot; Self-deposits tax';
                            ?>
                        </span>
                    </div>

                    <table class="ltab">
                    <!-- OPD -->
                    <tr class="sect"><td colspan="3">OPD Consultations</td></tr>
                    <?php if (!$opdRows && !$freeUnbilled): ?>
                    <tr><td colspan="2" class="muted">No paid consultations in this period</td><td class="n">&mdash;</td></tr>
                    <?php endif; ?>
                    <?php foreach ($opdRows as $r): ?>
                    <tr>
                        <td><?= htmlspecialchars($feeLabels[$r['fee_type']] ?? $r['fee_type']) ?></td>
                        <td class="qty"><?= number_format((int) $r['n']) ?></td>
                        <td class="n"><?= $m((float) $r['amt']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if ($freeUnbilled > 0): ?>
                    <tr>
                        <td>Free follow-up <span class="muted">(no invoice raised)</span></td>
                        <td class="qty"><?= number_format($freeUnbilled) ?></td>
                        <td class="n">Rs 0</td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($opdDiscount > 0): ?>
                    <tr><td colspan="2" class="muted">Discounts given (already reflected above)</td>
                        <td class="n muted">&minus;<?= $m($opdDiscount) ?></td></tr>
                    <?php endif; ?>
                    <tr class="sum">
                        <td>OPD collected</td>
                        <td class="qty"><?= number_format($opdCount + $freeUnbilled) ?></td>
                        <td class="n"><?= $m($opdGross) ?></td>
                    </tr>

                    <!-- IPD -->
                    <tr class="sect"><td colspan="3">In-Door Ward Rounds</td></tr>
                    <?php if ($ipdCount > 0): ?>
                    <tr>
                        <td>Consultant visits <span class="muted">(billed on discharge)</span></td>
                        <td class="qty"><?= number_format($ipdCount) ?></td>
                        <td class="n"><?= $m($ipdGross) ?></td>
                    </tr>
                    <?php else: ?>
                    <tr><td colspan="2" class="muted">No paid ward rounds in this period</td><td class="n">&mdash;</td></tr>
                    <?php endif; ?>

                    <!-- Procedures -->
                    <tr class="sect"><td colspan="3">Procedures</td></tr>
                    <tr><td colspan="2" class="muted">Procedure billing is not live yet &mdash; nothing to include</td>
                        <td class="n">&mdash;</td></tr>

                    <tr class="sum">
                        <td colspan="2">Gross collected</td>
                        <td class="n"><?= $m($grossCollected) ?></td>
                    </tr>
                    </table>
                </div>

                <!-- Right rail: gross -> tax -> split -> payable, as a ladder.
                     Kept out of the line table so the breakdown of WORK and the
                     breakdown of MONEY stop competing for the same column. -->
                <div class="card rail">
                    <div class="section-title">Settlement</div>
                    <div class="section-sub">Tax comes off the full amount first, then the share is applied.</div>

                    <div class="step"><span>Gross collected</span><span class="n"><?= $m($grossCollected) ?></span></div>
                    <?php if ($refundAmt > 0): ?>
                    <div class="step neg"><span>Refunds issued (<?= number_format($refundCount) ?>)</span><span class="n">&minus;<?= $m($refundAmt) ?></span></div>
                    <div class="step"><span>Net collected</span><span class="n"><?= $m($netCollected) ?></span></div>
                    <?php endif; ?>
                    <div class="step neg">
                        <span>Tax withheld <?= $hasTax ? '(' . number_format($taxPct, 0) . '%)' : '(self-deposited)' ?></span>
                        <span class="n"><?= $split['tax'] > 0 ? '&minus;' . $m($split['tax']) : 'Rs 0' ?></span>
                    </div>
                    <div class="step"><span>Divisible amount</span><span class="n"><?= $m($netCollected - $split['tax']) ?></span></div>
                    <div class="step muted"><span>Clinic share (<?= number_format(100 - $sharePct, 0) ?>%)</span><span class="n"><?= $m($split['clinic']) ?></span></div>
                    <div class="step"><span>Doctor share (<?= number_format($sharePct, 0) ?>%)</span><span class="n"><?= $m($split['doctor']) ?></span></div>
                    <?php if ($paidOut > 0): ?>
                    <div class="step neg"><span>Already disbursed</span><span class="n">&minus;<?= $m($paidOut) ?></span></div>
                    <?php endif; ?>
                    <!-- Same dark block as the closing sheet's expected-cash
                         panel: the one figure the page exists to produce. -->
                    <div class="payable">
                        <span class="k"><?= $paidOut > 0 ? 'Net still payable' : 'Net payable' ?></span>
                        <span class="v"><?= $m($netPayable) ?></span>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<script src="assets/js/date-picker.js?v=<?= @filemtime(__DIR__ . "/assets/js/date-picker.js") ?: 1 ?>"></script>
</body>
</html>
