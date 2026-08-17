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
$byPerson = ['rows' => [], 'vehicle_kmpl' => null, 'measured_fills' => 0];
$trend    = ['months' => [], 'mean' => null, 'peak' => null];
if ($schemaReady && $rowsOut) {
    if ($focusVehicle > 0) {
        foreach ($rowsOut as $r) { if ((int) $r['vehicle']['id'] === $focusVehicle) { $focusRow = $r; break; } }
    }
    if (!$focusRow) { $focusRow = $rowsOut[0]; $focusVehicle = (int) $focusRow['vehicle']['id']; }
    $fills = veh_per_fill($pdo, $focusVehicle, $from, $to);

    // Per-person efficiency for the focused vehicle. Within ONE vehicle only —
    // a bike returns 40 km/L and an ambulance 9, so a fleet-wide ranking of
    // people would just rank them by which vehicle they happen to drive.
    $byPerson = veh_by_person($pdo, $focusVehicle, $from, $to);

    // Six months regardless of the date filter above: a trend needs a fixed
    // window to be a trend, and re-scoping it to a one-week filter would leave
    // a single bar that says nothing.
    $trend = veh_trend($pdo, $focusVehicle, 6, $to);
}

// Readings that need a human to fix them, fleet-wide. Deliberately NOT limited
// to the focused vehicle: a corrupt reading on a van nobody is looking at is
// exactly the one that stays broken.
$attention = $schemaReady ? veh_attention($pdo, $from, $to) : [];

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

/* ---- Per-person efficiency: an outlier must read without reading numbers ---- */
.who { font-weight: 600; }
.who .role { display: block; font-size: 11.5px; color: var(--text-muted); font-weight: 400; }
tr.suspect td { background: var(--red-bg); }
tr.suspect td:first-child { box-shadow: inset 3px 0 0 var(--red-text); }
.dev { display: flex; align-items: center; gap: 7px; justify-content: flex-end; }
.dev .track { width: 68px; height: 7px; border-radius: 4px; background: var(--border);
              position: relative; flex: none; overflow: hidden; }
.dev .track i { position: absolute; top: 0; bottom: 0; display: block; }
.dev .track i.lo { background: var(--red-text); right: 50%; }
.dev .track i.hi { background: var(--primary-accent); left: 50%; }
.dev .v { font-variant-numeric: tabular-nums; font-size: 12px; font-weight: 700; }
.dev .v.lo { color: var(--red-text); } .dev .v.hi { color: var(--primary-accent); }

/* ---- Attention list ---- */
.att { display: flex; flex-direction: column; gap: 9px; }
.att .arow { display: flex; gap: 12px; align-items: center; flex-wrap: wrap;
    border: 1px solid var(--border); border-left-width: 3px; border-radius: 0 10px 10px 0;
    padding: 11px 14px; background: var(--bg); }
.att .arow.s3 { border-left-color: var(--red-text); }
.att .arow.s2 { border-left-color: var(--amber-text); }
.att .arow.s1 { border-left-color: var(--amber-text); }
.att .what { flex: 1 1 220px; min-width: 0; }
.att .what b { font-size: 13.5px; }
.att .what span { display: block; font-size: 12.5px; color: var(--text-secondary); }
.att .fix { font-size: 12.5px; font-weight: 700; color: var(--primary); text-decoration: none;
    border: 1px solid var(--border-strong); background: var(--card, #fff); border-radius: 8px;
    padding: 0 13px; min-height: 40px; display: inline-flex; align-items: center; white-space: nowrap; }
.att .fix:hover { background: var(--primary-light); }

/* ---- Trend ---- */
.trend { display: flex; flex-direction: column; gap: 5px; }
.trend .t { display: grid; grid-template-columns: 64px 1fr 78px; gap: 12px; align-items: center; }
.trend .t .m { font-size: 12.5px; color: var(--text-secondary); font-weight: 600; }
.trend .t .g { height: 22px; background: var(--border); border-radius: 5px; overflow: hidden; }
.trend .t .g i { display: block; height: 100%; background: var(--primary-accent); border-radius: 5px; }
.trend .t .g i.up { background: var(--red-text); }
.trend .t .val { font-variant-numeric: tabular-nums; font-size: 12.5px; text-align: right; }

@media (max-width: 640px) {
    .trend .t { grid-template-columns: 52px 1fr 70px; }
}
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
                    Lower than the one before it, or further above it than that vehicle's own jump limit
                    allows. The money on those postings still counts in full, so cost per km is measured
                    over <em>less</em> distance than the vehicle may really have run — an over-estimate
                    rather than a silent under-estimate.
                    <b>They are listed individually at the bottom of this page</b>, each with a link to fix it.
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
                                    if (!$v['is_active']) { echo ' · retired'; } ?>
                                    <?php /* Only shown when tuned away from the fleet default, so the
                                             column does not repeat "5,000 km" on every row. */ ?>
                                    <?php if ((int) $r['jump_limit'] !== (int) VEH_MAX_PLAUSIBLE_GAP): ?>
                                    · <span title="Gaps larger than this are discarded for this vehicle">max <?= number_format($r['jump_limit']) ?> km</span>
                                    <?php endif; ?>
                                </span>
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

            <!-- Per-person efficiency, within this one vehicle -->
            <?php if ($focusRow): ?>
            <div class="card" style="margin-top:18px;">
                <div class="section-title">
                    Km per litre by person — <?= htmlspecialchars($focusRow['vehicle']['name']) ?>
                </div>
                <div class="section-sub">
                    Same vehicle, same routes. Litres bought is on the receipt; litres burned comes from
                    the odometer. When one person's fills keep returning fewer kilometres per litre than
                    everyone else's on this vehicle, the two do not agree.
                </div>
                <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Refuelled by</th>
                            <th class="num" title="Full-tank-to-full-tank fills only — the ones where litres match a distance">Fills</th>
                            <th class="num">Km</th>
                            <th class="num">Litres</th>
                            <th class="num">Km / L</th>
                            <th class="num">vs vehicle average</th>
                            <th class="num" title="Litres above what this distance needed at the vehicle's own rate">Extra litres</th>
                            <th class="num">Rs</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$byPerson['rows']): ?>
                        <tr><td colspan="8" class="muted" style="padding:18px 10px;">
                            No fuel fills for this vehicle in the range.
                        </td></tr>
                        <?php endif; ?>
                        <?php foreach ($byPerson['rows'] as $p): ?>
                        <tr class="<?= $p['flag'] ? 'suspect' : '' ?>">
                            <td>
                                <span class="who"><?= htmlspecialchars($p['name']) ?></span>
                                <?php if ($p['id'] === null): ?>
                                <span class="role">no name recorded on these fills</span>
                                <?php elseif ($p['flag']): ?>
                                <span class="chip c-bad" style="margin-top:4px;">Look at this</span>
                                <?php elseif ($p['too_few']): ?>
                                <span class="role">too few fills to judge</span>
                                <?php endif; ?>
                            </td>
                            <td class="num"><?= (int) $p['fills'] ?>
                                <?php if ($p['unmeasured'] > 0): ?>
                                <br><span class="muted" style="font-size:10.5px;"
                                          title="Part fills and baseline fills — real spend, but no distance can be matched to their litres">
                                    +<?= (int) $p['unmeasured'] ?> n/m</span>
                                <?php endif; ?>
                            </td>
                            <td class="num"><?= $p['km'] > 0 ? number_format($p['km']) : '—' ?></td>
                            <td class="num"><?= $p['litres'] > 0 ? number_format($p['litres'], 2) : '—' ?></td>
                            <td class="num"><b><?= veh_kmpl($p['kmpl']) ?></b></td>
                            <td class="num">
                                <?php if ($p['dev_pct'] === null): ?>
                                    <span class="muted">—</span>
                                <?php else: $lo = $p['dev_pct'] < 0;
                                      // Bar width caps at 50% of the half-track so a wild
                                      // outlier cannot overflow its own cell.
                                      $w = min(50, abs($p['dev_pct'])); ?>
                                    <div class="dev">
                                        <span class="track"><i class="<?= $lo ? 'lo' : 'hi' ?>" style="width:<?= $w ?>%;"></i></span>
                                        <span class="v <?= $lo ? 'lo' : 'hi' ?>"><?= ($lo ? '' : '+') . number_format($p['dev_pct'], 1) ?>%</span>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="num"><?= $p['litres_over'] !== null ? number_format($p['litres_over'], 1) : '—' ?></td>
                            <td class="num"><b><?= $p['rs_over'] !== null ? number_format($p['rs_over']) : '—' ?></b></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <?php if ($byPerson['vehicle_kmpl'] !== null): ?>
                    <tfoot>
                        <tr>
                            <td><b>This vehicle</b></td>
                            <td class="num"><b><?= (int) $byPerson['measured_fills'] ?></b></td>
                            <td class="num" colspan="2" style="text-align:right;color:var(--text-muted);">baseline</td>
                            <td class="num"><b><?= veh_kmpl($byPerson['vehicle_kmpl']) ?></b></td>
                            <td colspan="3"></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
                </div>
                <div class="warnbox" style="margin:14px 0 0;">
                    <b>This is a question, not a verdict.</b> Heavy city traffic, long idles on aircon, a
                    loaded route or one mistyped odometer all lower km/L honestly. A flag means
                    <em>look at these fills</em> — never that fuel was taken. Nobody is flagged on fewer
                    than <?= (int) VEH_MIN_FILLS_TO_JUDGE ?> measurable fills, and
                    <b>Unassigned</b> is never flagged at all: those fills may well be the same person's.
                </div>
                <div class="muted-note">
                    Only full-tank-to-full-tank fills count here, so litres genuinely match the distance.
                    Part fills still count toward cost per km and appear as <b>n/m</b> (not measurable).
                    <b>Extra litres</b> is what this person's distance needed at this vehicle's own rate,
                    subtracted from what they actually bought.
                </div>
            </div>
            <?php endif; ?>

            <!-- Trend, six months, measured against this vehicle's own mean -->
            <?php if ($focusRow && $trend['months']): ?>
            <div class="card" style="margin-top:18px;">
                <div class="section-title">
                    Six-month trend — <?= htmlspecialchars($focusRow['vehicle']['name']) ?>
                </div>
                <div class="section-sub">
                    Fuel cost per km, month by month. Compared with this vehicle's own average, not the
                    fleet's — a fuel price rise lifts every vehicle together, so one van climbing alone
                    is the signal worth chasing.
                </div>
                <div class="trend">
                    <?php
                    // Bars scale to the peak month so the shape is visible; a
                    // fixed maximum would flatten every bar on a cheap vehicle.
                    $peak = $trend['peak'] ?: 1;
                    foreach ($trend['months'] as $mo):
                        $v = $mo['fuel_per_km'];
                        $w = $v !== null ? max(2, min(100, ($v / $peak) * 100)) : 0;
                    ?>
                    <div class="t">
                        <span class="m"><?= htmlspecialchars($mo['label']) ?>
                            <span class="muted" style="font-size:10.5px;"><?= htmlspecialchars(substr($mo['year'], 2)) ?></span></span>
                        <span class="g">
                            <?php if ($v !== null): ?>
                            <i class="<?= $mo['high'] ? 'up' : '' ?>" style="width:<?= $w ?>%;"
                               title="<?= number_format($mo['km']) ?> km · Rs <?= number_format($mo['total']) ?> spent"></i>
                            <?php endif; ?>
                        </span>
                        <span class="val"><?= $v !== null ? number_format($v, 2) : '<span class="muted">no km</span>' ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="muted-note">
                    A bar turns red once that month sits more than 20% above this vehicle's six-month
                    average<?= $trend['mean'] !== null ? ' of Rs ' . number_format($trend['mean'], 2) . '/km' : '' ?>.
                    Each month is measured on its own readings, so distance across a month boundary
                    belongs to neither — which makes these figures a slight over-estimate, never a
                    flattering one. A month showing <b>no km</b> had no usable pair of readings.
                </div>
            </div>
            <?php endif; ?>

            <!-- Readings that need fixing, fleet-wide, worst first -->
            <div class="card" style="margin-top:18px;">
                <div class="section-title">
                    Readings needing attention
                    <?php if ($attention): ?>
                    <span class="chip c-bad" style="vertical-align:2px;"><?= count($attention) ?></span>
                    <?php endif; ?>
                </div>
                <div class="section-sub">
                    Every reading in this range that a figure above had to discard or could not use —
                    across the whole fleet, not just the vehicle selected, because a bad reading on a
                    van nobody is looking at is the one that stays broken.
                </div>
                <?php if (!$attention): ?>
                <div class="notebox" style="margin:0;">
                    Nothing to fix — every reading in this range forms a believable chain.
                </div>
                <?php else: ?>
                <div class="att">
                    <?php foreach ($attention as $a): ?>
                    <div class="arow s<?= (int) $a['sev'] ?>">
                        <div class="what">
                            <b><?= htmlspecialchars($a['vehicle']) ?> ·
                               <?= htmlspecialchars(date('d/m/Y', strtotime($a['date']))) ?></b>
                            <span>
                                <?php if ($a['kind'] === 'rollback'): ?>
                                    Reading <?= number_format($a['meter']) ?> is <b>lower</b> than
                                    <?= number_format($a['prev']) ?> before it — a digit was probably dropped.
                                <?php elseif ($a['kind'] === 'jump'): ?>
                                    Jumped <?= number_format($a['delta']) ?> km, over this vehicle's
                                    <?= number_format($a['limit']) ?> km limit — a typo, or postings were missed.
                                <?php elseif ($a['kind'] === 'missing'): ?>
                                    A fuel fill with <b>no meter reading</b>, so it measures no distance at all.
                                <?php else: ?>
                                    Rs <?= number_format($a['rate'], 2) ?> per litre is
                                    <?= number_format(abs($a['off_pct']), 0) ?>%
                                    <?= $a['off_pct'] > 0 ? 'above' : 'below' ?>
                                    the fleet rate of Rs <?= number_format($a['fleet_rate'], 2) ?> — check the receipt.
                                <?php endif; ?>
                            </span>
                        </div>
                        <span class="chip <?= $a['sev'] >= 3 ? 'c-bad' : 'c-warn' ?>">
                            <?= htmlspecialchars(ucfirst($a['kind'])) ?></span>
                        <?php /* Straight to the Expenses page filtered to that day, where the
                                 admin's Edit meter link sits on the row. */ ?>
                        <a class="fix" href="expenses.php?<?= http_build_query(['from' => $a['date'], 'to' => $a['date']]) ?>">
                            <?= $a['kind'] === 'missing' ? 'Add reading' : 'Fix reading' ?></a>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="muted-note">
                    Fixing one uses <b>Edit meter</b> on the Expenses page — admin only, logged with the
                    old and new value, and it never touches the amount, the date or who paid. A closed
                    day is no obstacle: no money moves.
                </div>
                <?php endif; ?>
            </div>

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
