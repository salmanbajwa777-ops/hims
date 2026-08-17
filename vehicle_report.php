<?php
/**
 * Vehicle Report — per-vehicle running cost.   (2026-08-11)
 *
 * Answers the two questions the expense list cannot:
 *   * FUEL cost per km   — fuel spend / km driven
 *   * TOTAL cost per km  — (fuel + maintenance + repairs) / km driven
 * both per vehicle, plus a date-wise per-fill table showing each fill's own
 * trip, economy and cost per km.
 *
 * KM IS DERIVED, NOT STORED. Distance comes from consecutive odometer readings
 * (config/vehicle_costs.php), never MAX-MIN: maintenance rows carry no reading,
 * and a mistyped reading must not produce negative or absurd km. A reading that
 * cannot be trusted loses its DISTANCE but keeps its MONEY — the cash left the
 * drawer regardless — and the count of discarded gaps is shown so a shrunken
 * denominator is never mistaken for good data.
 *
 * Gated on FINANCIAL_VIEW_CLINIC_REPORTS, the same key as the Income and
 * Expense reports, which reaches ADMIN and the manager preset. NOT guard_admin:
 * the live base_role ENUM is only ADMIN/DOCTOR/STAFF, so a manager is a
 * permission holder, not a base role, and an admin-only guard would lock them
 * out of a report they are explicitly meant to see.
 *
 * Voided rows and REJECTED postings are excluded everywhere.
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
require_once __DIR__ . '/config/vehicle_costs.php';
refresh_session_permissions($pdo);
require_permission('FINANCIAL_VIEW_CLINIC_REPORTS');

// The whole page depends on a migration that may not have run yet. Detect it
// once and degrade to an explanatory panel rather than a 500 — the same
// pattern the rest of the app uses for mid-deploy schema gaps.
$schemaReady = false;
try {
    $chk = $pdo->query("
        SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND ((table_name = 'expenses' AND column_name IN ('vehicle_id','meter_reading','litres','subcategory_id'))
             OR (table_name = 'vehicles' AND column_name = 'registration'))
    ");
    $schemaReady = ((int) $chk->fetchColumn()) >= 5;
} catch (PDOException $e) { $schemaReady = false; }

// ---- Filters: a month by default, since running cost is a monthly question ----
$today = date('Y-m-d');
$from  = $_GET['from'] ?? date('Y-m-01');
$to    = $_GET['to']   ?? $today;
$reDate = '/^\d{4}-\d{2}-\d{2}$/';
if (!preg_match($reDate, $from)) { $from = date('Y-m-01'); }
if (!preg_match($reDate, $to))   { $to   = $today; }
if ($from > $to) { [$from, $to] = [$to, $from]; }   // swap rather than return nothing
$focusVehicle = (int) ($_GET['vehicle'] ?? 0);

$vehicles = [];
$rowsOut  = [];
$fleet    = ['km' => 0, 'fuel' => 0.0, 'maintenance' => 0.0, 'repairs' => 0.0,
             'other' => 0.0, 'total' => 0.0, 'litres' => 0.0, 'bad_gaps' => 0,
             'partial_blocked' => 0, 'exact_segments' => 0];

if ($schemaReady) {
    // Inactive vehicles still appear when they have spend in the range — a van
    // sold in March must still show March's costs.
    $vehicles = $pdo->query("SELECT id, name, registration, vehicle_type, is_active
                               FROM vehicles ORDER BY is_active DESC, name")->fetchAll();

    foreach ($vehicles as $v) {
        $vid = (int) $v['id'];
        $m = veh_metrics($pdo, $vid, $from, $to);
        if ($m['total'] == 0.0 && $m['km'] === 0 && !$v['is_active']) {
            continue;   // retired vehicle with nothing in range — omit entirely
        }
        $m['vehicle'] = $v;
        $rowsOut[] = $m;

        $fleet['km']          += $m['km'];
        $fleet['fuel']        += $m['fuel'];
        $fleet['maintenance'] += $m['maintenance'];
        $fleet['repairs']     += $m['repairs'];
        $fleet['other']       += $m['other'];
        $fleet['total']       += $m['total'];
        $fleet['litres']      += $m['litres'];
        $fleet['bad_gaps']    += $m['bad_gaps'];
        $fleet['partial_blocked'] += $m['partial_blocked'];
        $fleet['exact_segments']  += $m['exact_segments'];
    }
}

// Fleet-level per-km uses summed spend over summed km, NOT an average of the
// per-vehicle rates: a bike doing 700 km must not weigh the same as an
// ambulance doing 1,900 km.
$fleetFuelPerKm  = $fleet['km'] > 0 ? $fleet['fuel']  / $fleet['km'] : null;
$fleetTotalPerKm = $fleet['km'] > 0 ? $fleet['total'] / $fleet['km'] : null;
// Fleet APPROXIMATE efficiency — all litres over all distance. The precise
// full-to-full figure is per-vehicle only: summing exact segments across
// different vehicles would average a bike against an ambulance and mean nothing.
$fleetKmPerL     = ($fleet['km'] > 0 && $fleet['litres'] > 0) ? $fleet['km'] / $fleet['litres'] : null;

// Per-fill detail for the focused vehicle (or the first one with data).
$fills = [];
$focusRow = null;
if ($schemaReady && $rowsOut) {
    if ($focusVehicle > 0) {
        foreach ($rowsOut as $r) { if ((int) $r['vehicle']['id'] === $focusVehicle) { $focusRow = $r; break; } }
    }
    if (!$focusRow) { $focusRow = $rowsOut[0]; $focusVehicle = (int) $focusRow['vehicle']['id']; }
    $fills = veh_per_fill($pdo, $focusVehicle, $from, $to);
}

$qs = function (array $over = []) use ($from, $to, $focusVehicle) {
    return '?' . http_build_query(array_merge(
        ['from' => $from, 'to' => $to, 'vehicle' => $focusVehicle], $over));
};

$pageTitle = 'Vehicle Report';
$headExtra = <<<CSS
<style>
.filters { display: flex; gap: 10px; align-items: end; flex-wrap: wrap; margin-bottom: 16px; }
.filters label { font-size: 11.5px; font-weight: 600; color: var(--text-secondary); display: block; margin-bottom: 5px; }
.filters input, .filters select { padding: 9px 11px; border: 1px solid var(--border); border-radius: 10px;
    font: inherit; font-size: 13.5px; background: var(--bg); }
.filters input:focus, .filters select:focus { outline: none; border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(63,122,99,.18); background: #fff; }

.kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-bottom: 18px; }
.kpi { background: var(--card, #fff); border: 1px solid var(--border); border-radius: 12px; padding: 13px 15px; }
.kpi .k { font-size: 10.5px; text-transform: uppercase; letter-spacing: .06em; color: var(--text-muted); font-weight: 700; }
.kpi .v { font-size: 21px; font-weight: 700; font-variant-numeric: tabular-nums; margin-top: 3px; letter-spacing: -.02em; }
.kpi .d { font-size: 11.5px; color: var(--text-muted); margin-top: 1px; }
.kpi.hero { background: var(--primary-light); border-color: var(--border-strong); }
.kpi.hero .v { color: var(--primary); }

.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.veh-name { font-weight: 600; }
.plate { display: block; font-size: 11.5px; color: var(--text-muted); font-variant-numeric: tabular-nums; }
.chip { display: inline-block; font-size: 10.5px; font-weight: 700; border-radius: 20px; padding: 2px 9px;
        white-space: nowrap; border: 1px solid transparent; }
.c-fuel  { background: var(--primary-light); color: var(--primary); border-color: var(--border-strong); }
.c-warn  { background: var(--amber-bg); color: var(--amber-text); border-color: rgba(245,158,11,.34); }
.c-bad   { background: var(--red-bg); color: var(--red-text); border-color: rgba(225,29,72,.24); }
.row-focus td { background: var(--primary-light); }
.row-inactive td { opacity: .55; }
.muted-note { font-size: 12px; color: var(--text-muted); margin-top: 8px; }
.warnbox { background: var(--amber-bg); border: 1px solid rgba(245,158,11,.34); border-radius: 11px;
           padding: 12px 15px; font-size: 12.8px; line-height: 1.6; margin-bottom: 16px; }
.notebox { background: var(--primary-light); border: 1px solid var(--border); border-radius: 11px;
           padding: 12px 16px; font-size: 12.8px; line-height: 1.6; color: var(--text-secondary); margin-bottom: 16px; }
.vlink { color: var(--primary); font-weight: 600; text-decoration: none; }
.vlink:hover { text-decoration: underline; }
</style>
CSS;
require __DIR__ . '/partials/head.php';
$navActive = 'vehicle_report';
require __DIR__ . '/partials/sidebar.php';
?>
        <div class="content">
            <div class="page-head">
                <div>
                    <div class="page-title">Vehicle Report</div>
                    <div class="page-sub">Fuel and total running cost per kilometre, per vehicle</div>
                </div>
                <a class="btn" href="expenses.php" style="text-decoration:none;">Expenses</a>
            </div>

            <?php if (!$schemaReady): ?>
            <div class="alert error">
                The vehicle columns are not in the database yet. Run
                <b>sql/add_vehicle_expenses.sql</b> in phpMyAdmin, then reload this page.
            </div>
            <?php else: ?>

            <form method="GET" action="vehicle_report.php" class="filters">
                <div><label>From</label><input type="date" name="from" value="<?= htmlspecialchars($from) ?>"></div>
                <div><label>To</label><input type="date" name="to" value="<?= htmlspecialchars($to) ?>"></div>
                <input type="hidden" name="vehicle" value="<?= $focusVehicle ?>">
                <button type="submit" class="btn small" style="padding:9px 16px;font-size:12.5px;">Apply</button>
            </form>

            <div class="kpis">
                <div class="kpi hero">
                    <div class="k">Fuel cost / km</div>
                    <div class="v"><?= veh_money_per_km($fleetFuelPerKm) ?></div>
                    <div class="d">fleet · fuel only</div>
                </div>
                <div class="kpi hero">
                    <div class="k">Total cost / km</div>
                    <div class="v"><?= veh_money_per_km($fleetTotalPerKm) ?></div>
                    <div class="d">fuel + maintenance + repairs</div>
                </div>
                <div class="kpi">
                    <div class="k">Distance</div>
                    <div class="v"><?= number_format($fleet['km']) ?></div>
                    <div class="d">km from meter readings</div>
                </div>
                <div class="kpi">
                    <div class="k">Fleet spend</div>
                    <div class="v"><?= number_format($fleet['total']) ?></div>
                    <div class="d">Rs · <?= count($rowsOut) ?> vehicle<?= count($rowsOut) === 1 ? '' : 's' ?></div>
                </div>
                <div class="kpi">
                    <div class="k">Economy</div>
                    <div class="v"><?= veh_kmpl($fleetKmPerL) ?></div>
                    <div class="d">approx km / litre<?= $fleet['partial_blocked'] > 0 ? ' · incl. part fills' : '' ?></div>
                </div>
            </div>

            <?php if ($fleet['bad_gaps'] > 0 || $fleet['partial_blocked'] > 0): ?>
            <div class="warnbox">
                <?php /* Two separate reasons, counted separately. A vehicle that
                         simply tops up must never be mistaken for one with corrupt
                         odometer data — only one of those needs fixing. */ ?>
                <?php if ($fleet['bad_gaps'] > 0): ?>
                <div>
                    <b><?= $fleet['bad_gaps'] ?> reading<?= $fleet['bad_gaps'] === 1 ? '' : 's' ?> discarded (implausible).</b>
                    Lower than the one before it, or more than <?= number_format(VEH_MAX_PLAUSIBLE_GAP) ?> km above it.
                    The money on those postings still counts in full, so cost per km is measured over
                    <em>less</em> distance than the vehicle may really have run — an over-estimate rather
                    than a silent under-estimate. Fix these with <b>Edit meter</b> on the Expenses page.
                </div>
                <?php endif; ?>
                <?php if ($fleet['partial_blocked'] > 0): ?>
                <div style="<?= $fleet['bad_gaps'] > 0 ? 'margin-top:8px;padding-top:8px;border-top:1px dashed rgba(245,158,11,.40);' : '' ?>">
                    <b><?= $fleet['partial_blocked'] ?> segment<?= $fleet['partial_blocked'] === 1 ? '' : 's' ?> excluded from km/L (partial fill).</b>
                    This is not an error — a part fill's litres cannot be matched to a distance. Those
                    fills still count toward cost per km, and the <b>approx km/L</b> column below covers
                    them.
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Per-vehicle -->
            <div class="card">
                <div class="section-title">Per Vehicle</div>
                <div class="section-sub">Click a vehicle to see its fill-by-fill history below.</div>
                <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Vehicle</th>
                            <th class="num">Km</th>
                            <th class="num">Fuel</th>
                            <th class="num">Maint.</th>
                            <th class="num">Repairs</th>
                            <th class="num">Total</th>
                            <th class="num" title="Precise: measured between two full-tank fills only">Km/L</th>
                            <th class="num" title="All litres over all distance in range — includes part fills">Approx km/L</th>
                            <th class="num">Fuel /km</th>
                            <th class="num">Total /km</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$rowsOut): ?>
                        <tr><td colspan="10" class="muted" style="padding:20px 10px;">
                            No vehicle expenses in this range. Add vehicles under
                            <a class="vlink" href="expense_categories.php">Categories &amp; Limits</a>.
                        </td></tr>
                        <?php endif; ?>
                        <?php foreach ($rowsOut as $r): $v = $r['vehicle']; $vid = (int) $v['id']; ?>
                        <tr class="<?= $vid === $focusVehicle ? 'row-focus' : '' ?> <?= $v['is_active'] ? '' : 'row-inactive' ?>">
                            <td>
                                <a class="vlink" href="<?= htmlspecialchars($qs(['vehicle' => $vid])) ?>">
                                    <span class="veh-name"><?= htmlspecialchars($v['name']) ?></span></a>
                                <span class="plate"><?= htmlspecialchars($v['registration']) ?><?php
                                    if (!$v['is_active']) { echo ' · retired'; } ?></span>
                            </td>
                            <td class="num"><?= $r['km'] > 0 ? number_format($r['km']) : '—' ?>
                                <?php if ($r['bad_gaps'] > 0): ?>
                                <br><span class="chip c-warn" title="Readings excluded as implausible">
                                    <?= $r['bad_gaps'] ?> bad</span>
                                <?php endif; ?>
                            </td>
                            <td class="num"><?= number_format($r['fuel']) ?></td>
                            <td class="num"><?= number_format($r['maintenance']) ?></td>
                            <td class="num"><?= number_format($r['repairs']) ?></td>
                            <td class="num"><b><?= number_format($r['total']) ?></b></td>
                            <td class="num">
                                <?= veh_kmpl($r['km_per_litre']) ?>
                                <?php if ($r['km_per_litre'] === null && $r['litres'] > 0): ?>
                                <br><span class="chip c-warn" title="No two consecutive full-tank fills in this range, so a precise figure cannot be measured. Use the approx column.">no full pair</span>
                                <?php elseif ($r['exact_segments'] > 0): ?>
                                <br><span class="muted" style="font-size:10.5px;"><?= (int) $r['exact_segments'] ?> seg</span>
                                <?php endif; ?>
                            </td>
                            <td class="num"><?= veh_kmpl($r['approx_km_per_litre']) ?></td>
                            <td class="num"><?= veh_money_per_km($r['fuel_per_km']) ?></td>
                            <td class="num"><b><?= veh_money_per_km($r['total_per_km']) ?></b></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <?php if ($rowsOut): ?>
                    <tfoot>
                        <tr>
                            <td><b>Fleet</b></td>
                            <td class="num"><b><?= number_format($fleet['km']) ?></b></td>
                            <td class="num"><b><?= number_format($fleet['fuel']) ?></b></td>
                            <td class="num"><b><?= number_format($fleet['maintenance']) ?></b></td>
                            <td class="num"><b><?= number_format($fleet['repairs']) ?></b></td>
                            <td class="num"><b><?= number_format($fleet['total']) ?></b></td>
                            <?php /* No precise fleet km/L: averaging full-to-full segments across a
                                     bike and an ambulance would produce a number describing neither.
                                     The approx column carries the fleet figure instead. */ ?>
                            <td class="num muted">—</td>
                            <td class="num"><b><?= veh_kmpl($fleetKmPerL) ?></b></td>
                            <td class="num"><b><?= veh_money_per_km($fleetFuelPerKm) ?></b></td>
                            <td class="num"><b><?= veh_money_per_km($fleetTotalPerKm) ?></b></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
                </div>
                <div class="muted-note">
                    Fleet rates divide total spend by total distance — not an average of the per-vehicle
                    rates, so a bike's cheap kilometres do not outweigh an ambulance's expensive ones.
                </div>
            </div>

            <!-- Per-fill, date-wise -->
            <?php if ($focusRow): ?>
            <div class="card" style="margin-top:18px;">
                <div class="section-title">
                    Fill history — <?= htmlspecialchars($focusRow['vehicle']['name']) ?>
                    <span style="font-weight:400;color:var(--text-muted);">
                        (<?= htmlspecialchars($focusRow['vehicle']['registration']) ?>)</span>
                </div>
                <div class="section-sub">Each fill measured against the previous good reading.</div>
                <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th class="num">Meter</th>
                            <th class="num">Trip km</th>
                            <th class="num">Litres</th>
                            <th>Tank</th>
                            <th class="num">Rs / litre</th>
                            <th class="num">Km / L</th>
                            <th class="num">Amount</th>
                            <th class="num">Cost / km</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$fills): ?>
                        <tr><td colspan="9" class="muted" style="padding:18px 10px;">No fuel fills for this vehicle in the range.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($fills as $i => $f): ?>
                        <tr>
                            <td style="white-space:nowrap;"><?= htmlspecialchars(date('d/m/Y', strtotime($f['date']))) ?></td>
                            <td class="num"><?= number_format($f['meter']) ?></td>
                            <td class="num">
                                <?php if ($f['trip'] !== null): ?>
                                    <?= number_format($f['trip']) ?>
                                <?php elseif ($f['flag'] === 'rollback'): ?>
                                    <span class="chip c-bad" title="Reading is lower than the previous one">rollback</span>
                                <?php elseif ($f['flag'] === 'jump'): ?>
                                    <span class="chip c-warn" title="Jump larger than <?= number_format(VEH_MAX_PLAUSIBLE_GAP) ?> km — likely a missed posting">jump</span>
                                <?php else: ?>
                                    <span class="muted" title="First fill in this range — nothing to measure from">baseline</span>
                                <?php endif; ?>
                            </td>
                            <td class="num"><?= $f['litres'] !== null ? number_format($f['litres'], 2) : '—' ?></td>
                            <td>
                                <?php if (($f['tank_full'] ?? null) === 1): ?>
                                    <span class="chip c-fuel">Full</span>
                                <?php elseif (($f['tank_full'] ?? null) === 0): ?>
                                    <span class="chip c-warn" title="Part fill — litres cannot be matched to a distance, so no precise km/L">Part</span>
                                <?php else: ?>
                                    <span class="muted" title="Recorded before the full-tank flag existed">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="num"><?= $f['rate'] !== null ? number_format($f['rate'], 2) : '—' ?></td>
                            <td class="num">
                                <?= veh_kmpl($f['kmpl']) ?>
                                <?php if ($f['kmpl'] === null && ($f['kmpl_blocked_by'] ?? '') === 'partial'): ?>
                                <br><span class="chip c-warn" title="Needs a full tank at BOTH ends of the segment">part fill</span>
                                <?php endif; ?>
                            </td>
                            <td class="num"><?= number_format($f['amount']) ?></td>
                            <td class="num"><b><?= veh_money_per_km($f['per_km']) ?></b></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <div class="muted-note">
                    <b>km/L reflects fuel used to cover the distance since the last full-tank fill.</b>
                    Partial fills are excluded from km/L but still count toward cost.
                    The first fill in the range is a <b>baseline</b> — it fixes the starting odometer, so it
                    has no trip of its own. Widen the date range to measure it against an earlier fill.
                </div>
            </div>
            <?php endif; ?>

            <div class="notebox" style="margin-top:18px;">
                <b>How distance is worked out.</b> Km comes from consecutive odometer readings, not from
                the difference between the highest and lowest reading — maintenance and repair postings
                carry no reading, and one mistyped number would otherwise distort the whole range.
                A reading that cannot be trusted loses its distance but keeps its cost, so these rates
                never understate what the fleet is costing.
            </div>

            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
