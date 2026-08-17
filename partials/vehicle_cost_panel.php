<?php
/**
 * Vehicle cost panel for an expense awaiting a decision.
 *
 * Rendered on BOTH approval surfaces — the in-app Expenses list and the emailed
 * magic-link page — so an approver sees the cost per km at the moment of
 * deciding, not afterwards in a report. Both call the same
 * veh_approval_context(), so the figure on the email page and the figure in the
 * app can never disagree.
 *
 * Self-contained styles (inline): approve_expense.php is a standalone page that
 * does not load assets/app.css, so this cannot rely on the app stylesheet.
 *
 * Renders nothing at all when the expense has no vehicle, when the migration
 * has not run, or when the helper cannot resolve the row — an approval screen
 * must never break because a reporting extra is unavailable.
 *
 * Usage:  vehicle_cost_panel($pdo, (int) $expenseId);
 */
require_once __DIR__ . '/../config/vehicle_costs.php';

function vehicle_cost_panel(PDO $pdo, int $expenseId): void {
    // Guard the whole thing: a missing column mid-deploy must degrade to
    // silence, never to a fatal on the approval path.
    try {
        $ctx = veh_approval_context($pdo, $expenseId);
    } catch (Throwable $e) {
        return;
    }
    if (!$ctx) { return; }

    // Amber when this fill costs materially more per km than the vehicle's own
    // recent history. 25% is deliberately loose — fuel prices move, and crying
    // wolf on every fill would train approvers to ignore the flag.
    $v      = $ctx['variance_pct'];
    $isHigh = ($v !== null && $v >= 25);
    $isBad  = ($ctx['flag'] === 'rollback');

    $tone = $isBad ? '#B3261E' : ($isHigh ? '#8A6100' : '#2C4A3E');
    $bg   = $isBad ? '#FBEAE8' : ($isHigh ? '#FDF3DC' : '#E7F0EA');
    $bd   = $isBad ? '#E8B6B0' : ($isHigh ? '#E8CE8A' : '#C3CDBF');

    $mono = 'ui-monospace,SFMono-Regular,Menlo,Consolas,monospace';
    ?>
    <div style="border:1px solid <?= $bd ?>;background:<?= $bg ?>;border-radius:11px;
                padding:12px 14px;margin:14px 0;font-size:12.8px;line-height:1.55;color:#16211C;">
        <div style="font-weight:700;color:<?= $tone ?>;margin-bottom:8px;">
            <?= htmlspecialchars($ctx['vehicle']) ?>
            <?php if ($ctx['sub_name']): ?>
                <span style="font-weight:600;opacity:.75;">· <?= htmlspecialchars($ctx['sub_name']) ?></span>
            <?php endif; ?>
        </div>

        <?php if ($isBad): ?>
        <div style="margin-bottom:8px;font-weight:600;color:#B3261E;">
            Meter reading is <b><?= number_format(abs((int) $ctx['meter'] - (int) $ctx['prev_meter'])) ?> km lower</b>
            than this vehicle's last reading
            (<?= number_format((int) $ctx['prev_meter']) ?> on <?= htmlspecialchars(date('d/m', strtotime($ctx['prev_date']))) ?>).
            Cost per km cannot be worked out for this fill.
        </div>
        <?php elseif ($ctx['flag'] === 'jump'): ?>
        <div style="margin-bottom:8px;font-weight:600;color:#8A6100;">
            Jump of <?= number_format((int) $ctx['meter'] - (int) $ctx['prev_meter']) ?> km since the last
            reading — larger than this vehicle's
            <?= number_format($ctx['jump_limit'] ?? VEH_MAX_PLAUSIBLE_GAP) ?> km limit,
            so a posting may have been missed.
        </div>
        <?php endif; ?>

        <table style="width:100%;border-collapse:collapse;font-variant-numeric:tabular-nums;">
            <tr>
                <td style="padding:3px 0;color:#4A574F;">This posting</td>
                <td style="padding:3px 0;text-align:right;font-family:<?= $mono ?>;font-weight:700;">
                    <?= $ctx['trip'] !== null ? number_format($ctx['trip']) . ' km' : '—' ?>
                    <?php if ($ctx['this_km_per_l'] !== null): ?>
                        · <?= number_format($ctx['this_km_per_l'], 2) ?> km/L
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td style="padding:3px 0;color:#4A574F;">Cost per km</td>
                <td style="padding:3px 0;text-align:right;font-family:<?= $mono ?>;font-weight:700;color:<?= $tone ?>;">
                    <?= veh_money_per_km($ctx['this_per_km']) ?>
                </td>
            </tr>
            <?php if ($ctx['this_rate'] !== null): ?>
            <tr>
                <td style="padding:3px 0;color:#4A574F;">Rate paid</td>
                <td style="padding:3px 0;text-align:right;font-family:<?= $mono ?>;">
                    Rs <?= number_format($ctx['this_rate'], 2) ?> / litre
                    <?php if ($ctx['litres'] !== null): ?>
                        <span style="color:#616D65;">· <?= number_format($ctx['litres'], 2) ?> L</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endif; ?>
            <?php /* Who refuelled, and how this vehicle usually performs under
                     them. Shown at the decision point because that is when the
                     approver can still ask — the same name on a report three
                     weeks later carries far less weight. Absent pre-migration. */ ?>
            <?php if (!empty($ctx['by_name'])): ?>
            <tr>
                <td style="padding:3px 0;color:#4A574F;">Refuelled by</td>
                <td style="padding:3px 0;text-align:right;font-weight:700;">
                    <?= htmlspecialchars($ctx['by_name']) ?>
                    <?php if ($ctx['by_km_per_l'] !== null && $ctx['by_fills'] >= VEH_MIN_FILLS_TO_JUDGE): ?>
                    <span style="color:#616D65;font-weight:400;font-size:11.5px;font-family:<?= $mono ?>;">
                        · usually <?= number_format($ctx['by_km_per_l'], 2) ?> km/L
                        over <?= (int) $ctx['by_fills'] ?> fills</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endif; ?>
            <tr><td colspan="2" style="border-top:1px dashed <?= $bd ?>;padding-top:5px;"></td></tr>
            <tr>
                <td style="padding:3px 0;color:#4A574F;">Its usual cost per km</td>
                <td style="padding:3px 0;text-align:right;font-family:<?= $mono ?>;">
                    <?= veh_money_per_km($ctx['base_per_km']) ?>
                    <span style="color:#616D65;font-size:11.5px;">
                        <?= $ctx['base_km'] > 0 ? '· last 90 days' : '· no history yet' ?></span>
                </td>
            </tr>
            <?php if ($v !== null): ?>
            <tr>
                <td style="padding:3px 0;color:#4A574F;">Difference</td>
                <td style="padding:3px 0;text-align:right;font-family:<?= $mono ?>;font-weight:700;color:<?= $tone ?>;">
                    <?= ($v >= 0 ? '+' : '') . number_format($v, 1) ?>%
                    <?= $isHigh ? ' — costlier than usual' : '' ?>
                </td>
            </tr>
            <?php endif; ?>
        </table>
    </div>
    <?php
}
