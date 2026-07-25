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

$pageTitle = 'Doctor Share Statement';
$extraCss = <<<CSS
<style>
/* No .header rules: the sidebar partial supplies the app bar. Form controls
   inherit app.css — including its :focus-visible ring — so only the layout of
   the picker row is defined here. */
.pick-form { display: flex; align-items: flex-end; gap: 10px; flex-wrap: wrap; }
.pick-form .fld { display: flex; flex-direction: column; gap: 4px; }
.pick-form label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted); }
.pick-form input, .pick-form select { padding: 9px 12px; border: 1px solid var(--border); border-radius: var(--radius-input, 10px); font: inherit; font-size: 13.5px; background: var(--card); color: var(--text); }

/* ---- The statement sheet: A5 portrait, one page ---- */
.sheet { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius-card, 14px); box-shadow: var(--shadow-sm);
         padding: 22px 26px; max-width: 560px; }
.sheet h2 { font-size: 17px; margin: 0 0 2px; font-weight: 800; }
.sheet .sub { font-size: 12px; color: var(--text-muted); margin-bottom: 14px; }
.sheet .doc-line { display: flex; justify-content: space-between; gap: 12px; align-items: baseline;
                   border-bottom: 1px solid var(--border); padding-bottom: 10px; margin-bottom: 12px; }
.sheet .doc-line .who { font-size: 15px; font-weight: 700; }
.sheet .doc-line .terms { font-size: 11.5px; color: var(--text-muted); }

.ltab { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.ltab td { padding: 4px 0; vertical-align: baseline; }
.ltab td.n { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.ltab td.qty { text-align: right; color: var(--text-muted); font-variant-numeric: tabular-nums; width: 52px; }
.ltab tr.sect td { padding-top: 11px; font-weight: 700; font-size: 11px; text-transform: uppercase;
                   letter-spacing: .05em; color: var(--text-muted); }
.ltab tr.rule td { border-bottom: 1px solid var(--border); padding: 0; height: 6px; }
.ltab tr.sum td { font-weight: 700; padding-top: 7px; }
.ltab tr.grand td { font-weight: 800; font-size: 14px; padding-top: 9px; border-top: 2px solid var(--text); }
.ltab .muted { color: var(--text-muted); font-weight: 400; }
.ltab tr.neg td.n { color: var(--danger); }

.sign { display: flex; gap: 26px; margin-top: 26px; }
.sign div { flex: 1; border-top: 1px solid var(--border); padding-top: 5px; font-size: 10.5px; color: var(--text-muted); text-align: center; }

.empty { padding: 40px 20px; text-align: center; color: var(--text-muted); font-size: 13.5px; }

@media print {
    /* A5 portrait, single sheet. Margins tight so the whole statement fits
       without a second page even with every fee-type line present. */
    @page { size: A5 portrait; margin: 9mm 10mm; }
    .sidebar, .mobile-bar, .header, .pick-form, .print-btn, .nav-group, .page-head { display: none !important; }
    .main, .content { margin: 0 !important; padding: 0 !important; }
    .sheet { border: none !important; box-shadow: none !important; border-radius: 0 !important;
             padding: 0 !important; max-width: none !important; }
    body { background: #fff !important; }
    .ltab { font-size: 11px; }
    .sheet h2 { font-size: 15px; }
}
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
                <form class="pick-form" method="GET" action="doctor_share_statement.php">
                    <div class="fld">
                        <label>Doctor</label>
                        <select name="doctor_id" required>
                            <option value="">Select&hellip;</option>
                            <?php foreach ($doctors as $d): ?>
                            <option value="<?= (int) $d['id'] ?>" <?= (int) $d['id'] === $doctorId ? 'selected' : '' ?>>
                                <?= htmlspecialchars($d['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="fld">
                        <label>From</label>
                        <input type="date" name="from" value="<?= htmlspecialchars($from) ?>">
                    </div>
                    <div class="fld">
                        <label>To</label>
                        <input type="date" name="to" value="<?= htmlspecialchars($to) ?>">
                    </div>
                    <button type="submit" class="btn secondary">View</button>
                    <?php if ($doc): ?>
                    <button type="button" class="btn print-btn" onclick="window.print()">Print A5</button>
                    <?php endif; ?>
                </form>
            </div>

            <?php if (!$doc): ?>
            <div class="card"><div class="empty">Pick a doctor and a date range to build the statement.</div></div>
            <?php else: ?>
            <div class="sheet">
                <h2>Doctor Share Statement</h2>
                <div class="sub"><?= htmlspecialchars(date('d/m/Y', strtotime($from))) ?> &ndash; <?= htmlspecialchars(date('d/m/Y', strtotime($to))) ?></div>

                <div class="doc-line">
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

                    <!-- Money -->
                    <tr class="rule"><td colspan="3"></td></tr>
                    <tr class="sum">
                        <td colspan="2">Gross collected</td>
                        <td class="n"><?= $m($grossCollected) ?></td>
                    </tr>
                    <?php if ($refundAmt > 0): ?>
                    <tr class="neg">
                        <td>Refunds issued</td>
                        <td class="qty"><?= number_format($refundCount) ?></td>
                        <td class="n">&minus;<?= $m($refundAmt) ?></td>
                    </tr>
                    <tr class="sum"><td colspan="2">Net collected</td><td class="n"><?= $m($netCollected) ?></td></tr>
                    <?php endif; ?>

                    <tr class="neg">
                        <td colspan="2">Tax withheld <span class="muted"><?= $hasTax ? '(' . number_format($taxPct, 0) . '% of gross)' : '(doctor self-deposits)' ?></span></td>
                        <td class="n"><?= $split['tax'] > 0 ? '&minus;' . $m($split['tax']) : 'Rs 0' ?></td>
                    </tr>
                    <tr class="sum">
                        <td colspan="2">Divisible amount</td>
                        <td class="n"><?= $m($netCollected - $split['tax']) ?></td>
                    </tr>
                    <tr>
                        <td colspan="2" class="muted">Clinic share (<?= number_format(100 - $sharePct, 0) ?>%)</td>
                        <td class="n muted"><?= $m($split['clinic']) ?></td>
                    </tr>
                    <tr class="sum">
                        <td colspan="2">Doctor share (<?= number_format($sharePct, 0) ?>%)</td>
                        <td class="n"><?= $m($split['doctor']) ?></td>
                    </tr>

                    <?php if ($paidOut > 0): ?>
                    <tr class="neg">
                        <td colspan="2">Already disbursed</td>
                        <td class="n">&minus;<?= $m($paidOut) ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr class="grand">
                        <td colspan="2"><?= $paidOut > 0 ? 'Net still payable' : 'Net payable' ?></td>
                        <td class="n"><?= $m($netPayable) ?></td>
                    </tr>
                </table>

                <div class="sign">
                    <div>Prepared by</div>
                    <div>Received by (<?= htmlspecialchars($doc['name']) ?>)</div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
