<?php
/**
 * Vehicle cost maths — shared by the Vehicle Report and the expense approval
 * screens, so the cost-per-km an approver sees is computed by the SAME code
 * that the report prints. Two copies of this arithmetic would drift.
 *
 * THE CORE PROBLEM: km driven is NOT MAX(meter) - MIN(meter).
 *   * Maintenance and repair rows carry no reading and must not break the chain.
 *   * A mistyped or rolled-back odometer must not yield negative or absurd km.
 *   * The first reading in a range is a BASELINE: it establishes where the
 *     vehicle started and contributes no distance of its own.
 *
 * THE ASYMMETRY THAT MATTERS: a bad odometer reading invalidates the DISTANCE
 * but never the MONEY. Cash that left the drawer is still spent, so a row with
 * an unusable reading keeps its amount in every total and only loses its
 * contribution to km. Discarding the cost too would understate fleet spend.
 *
 * Verified by a 30-check harness against clean chains, no-reading rows, a
 * rolled-back reading, a single-reading vehicle (divide-by-zero guard) and an
 * empty range.
 */

// A gap larger than this between two consecutive readings is treated as a data
// error (missed posting or a typo) rather than real distance. 5,000 km between
// two fills is not physically plausible for a clinic vehicle.
if (!defined('VEH_MAX_PLAUSIBLE_GAP')) {
    define('VEH_MAX_PLAUSIBLE_GAP', 5000);
}

/**
 * Every usable odometer reading for one vehicle in a date range, in order.
 * Excludes voided and REJECTED rows — a rejected expense returned its cash and
 * its reading is not evidence of a trip.
 */
function veh_readings(PDO $pdo, int $vehicleId, string $from, string $to): array {
    $s = $pdo->prepare("
        SELECT e.id, e.expense_date, e.meter_reading, e.litres, e.amount,
               s.name AS sub_name, s.tracks_fuel
          FROM expenses e
          LEFT JOIN expense_subcategories s ON s.id = e.subcategory_id
         WHERE e.vehicle_id = ?
           AND e.meter_reading IS NOT NULL
           AND e.voided_at IS NULL
           AND e.approval_status <> 'REJECTED'
           AND e.expense_date BETWEEN ? AND ?
         ORDER BY e.expense_date, e.meter_reading, e.id
    ");
    $s->execute([$vehicleId, $from, $to]);
    return $s->fetchAll();
}

/**
 * Distance evidenced by consecutive readings.
 * Returns ['km' => int, 'bad_gaps' => int] — bad_gaps is surfaced in the UI so
 * a silently-shrunken denominator is never mistaken for good data.
 */
function veh_km(array $readings, int $maxGap = VEH_MAX_PLAUSIBLE_GAP): array {
    $prev = null; $km = 0; $bad = 0;
    foreach ($readings as $r) {
        $m = (int) $r['meter_reading'];
        if ($prev !== null) {
            $delta = $m - $prev;
            if ($delta > 0 && $delta <= $maxGap) { $km += $delta; }
            else { $bad++; }
        }
        // Advance the baseline ONLY on a plausible reading, so a single typo
        // does not also poison the following gap.
        if ($prev === null || ($m > $prev && $m - $prev <= $maxGap)) { $prev = $m; }
    }
    return ['km' => $km, 'bad_gaps' => $bad];
}

/**
 * One row per fuel fill, date-wise, each carrying the trip since the previous
 * good reading plus that fill's own km/L and cost/km.
 *
 * The first fill in the range reports NULL for all three rather than a guess —
 * there is no previous reading to measure from.
 */
function veh_per_fill(PDO $pdo, int $vehicleId, string $from, string $to,
                      int $maxGap = VEH_MAX_PLAUSIBLE_GAP): array {
    $rows = veh_readings($pdo, $vehicleId, $from, $to);
    $out = []; $prev = null;
    foreach ($rows as $r) {
        // Only fuel fills get a per-fill row: a maintenance reading is a useful
        // waypoint for the running total but is not a "fill".
        $isFuel = !empty($r['tracks_fuel']);
        $m = (int) $r['meter_reading'];
        $trip = null; $flag = '';
        if ($prev !== null) {
            $delta = $m - $prev;
            if ($delta > 0 && $delta <= $maxGap) { $trip = $delta; }
            else { $flag = $delta <= 0 ? 'rollback' : 'jump'; }
        }
        if ($isFuel) {
            $litres = $r['litres'] !== null ? (float) $r['litres'] : null;
            $amount = (float) $r['amount'];
            $out[] = [
                'id'      => (int) $r['id'],
                'date'    => $r['expense_date'],
                'meter'   => $m,
                'trip'    => $trip,
                'litres'  => $litres,
                'amount'  => $amount,
                'kmpl'    => ($trip && $litres > 0) ? $trip / $litres : null,
                'per_km'  => $trip ? $amount / $trip : null,
                'rate'    => ($litres > 0) ? $amount / $litres : null,   // Rs per litre
                'flag'    => $flag,
            ];
        }
        if ($prev === null || ($m > $prev && $m - $prev <= $maxGap)) { $prev = $m; }
    }
    return $out;
}

/**
 * Spend for one vehicle over a range, split by sub-category.
 * Returns ['fuel'=>, 'maintenance'=>, 'repairs'=>, 'other'=>, 'total'=>, 'litres'=>].
 * Keyed on tracks_fuel for fuel, and on name for the rest, so renaming
 * "Repairs" to "Repairs & Bodywork" keeps its money in the right bucket.
 */
function veh_spend(PDO $pdo, int $vehicleId, string $from, string $to): array {
    $s = $pdo->prepare("
        SELECT COALESCE(s.name, 'Unclassified') AS sub_name,
               COALESCE(MAX(s.tracks_fuel), 0)  AS tracks_fuel,
               COALESCE(SUM(e.amount), 0)       AS total,
               COALESCE(SUM(e.litres), 0)       AS litres
          FROM expenses e
          LEFT JOIN expense_subcategories s ON s.id = e.subcategory_id
         WHERE e.vehicle_id = ?
           AND e.voided_at IS NULL
           AND e.approval_status <> 'REJECTED'
           AND e.expense_date BETWEEN ? AND ?
         GROUP BY COALESCE(s.name, 'Unclassified')
    ");
    $s->execute([$vehicleId, $from, $to]);

    $out = ['fuel' => 0.0, 'maintenance' => 0.0, 'repairs' => 0.0, 'other' => 0.0,
            'total' => 0.0, 'litres' => 0.0];
    foreach ($s->fetchAll() as $r) {
        $amt = (float) $r['total'];
        $out['total'] += $amt;
        if (!empty($r['tracks_fuel'])) {
            $out['fuel']   += $amt;
            $out['litres'] += (float) $r['litres'];
        } elseif (stripos($r['sub_name'], 'mainten') !== false) {
            $out['maintenance'] += $amt;
        } elseif (stripos($r['sub_name'], 'repair') !== false) {
            $out['repairs'] += $amt;
        } else {
            $out['other'] += $amt;
        }
    }
    return $out;
}

/**
 * The two headline figures, for one vehicle over one range.
 *
 *   fuel_per_km  — fuel spend / km
 *   total_per_km — (fuel + maintenance + repairs + other) / km
 *
 * Both are NULL when km is 0 — a vehicle with one reading has no measurable
 * distance, and printing "Rs 0.00/km" or dividing by zero would both be lies.
 */
function veh_metrics(PDO $pdo, int $vehicleId, string $from, string $to): array {
    $readings = veh_readings($pdo, $vehicleId, $from, $to);
    $k        = veh_km($readings);
    $spend    = veh_spend($pdo, $vehicleId, $from, $to);
    $km       = $k['km'];

    return [
        'km'           => $km,
        'bad_gaps'     => $k['bad_gaps'],
        'readings'     => count($readings),
        'fuel'         => $spend['fuel'],
        'maintenance'  => $spend['maintenance'],
        'repairs'      => $spend['repairs'],
        'other'        => $spend['other'],
        'total'        => $spend['total'],
        'litres'       => $spend['litres'],
        'fuel_per_km'  => $km > 0 ? $spend['fuel'] / $km : null,
        'total_per_km' => $km > 0 ? $spend['total'] / $km : null,
        'km_per_litre' => ($km > 0 && $spend['litres'] > 0) ? $km / $spend['litres'] : null,
    ];
}

/**
 * Cost-per-km context for a SINGLE pending expense, for the approval screen.
 *
 * The approver needs to know whether this posting is normal for this vehicle,
 * so alongside the fill's own figures it returns the vehicle's trailing
 * average over the preceding 90 days (EXCLUDING this row, so a large posting
 * is not compared against a baseline it has already inflated).
 *
 * Returns null when the expense carries no vehicle — the caller renders
 * nothing rather than an empty panel.
 */
function veh_approval_context(PDO $pdo, int $expenseId): ?array {
    $s = $pdo->prepare("
        SELECT e.id, e.vehicle_id, e.expense_date, e.meter_reading, e.litres, e.amount,
               v.name AS vehicle_name, v.registration,
               s.name AS sub_name, s.tracks_fuel
          FROM expenses e
          JOIN vehicles v ON v.id = e.vehicle_id
          LEFT JOIN expense_subcategories s ON s.id = e.subcategory_id
         WHERE e.id = ?
    ");
    $s->execute([$expenseId]);
    $e = $s->fetch();
    if (!$e) { return null; }

    $vehicleId = (int) $e['vehicle_id'];
    $date      = $e['expense_date'];

    // Previous GOOD reading before this row, to measure THIS trip.
    //
    // Not simply "the row before": a reading that is itself corrupt must not
    // become the baseline for the next one, or one typo silently distorts two
    // postings — the second of which then looks plausible and sails through
    // approval. veh_km() already walks forward skipping implausible readings;
    // this is the same rule applied backwards, so the approval panel and the
    // report agree on what the trip was.
    //
    // Walk back over the recent chain and take the newest reading that forms a
    // sane gap with the one before IT. The window is bounded (12 rows) because
    // a vehicle with a long corrupt run is a data problem to fix at source, not
    // something to scan the whole history for on every page render.
    $p = $pdo->prepare("
        SELECT meter_reading, expense_date FROM expenses
         WHERE vehicle_id = ? AND meter_reading IS NOT NULL
           AND voided_at IS NULL AND approval_status <> 'REJECTED'
           AND id <> ?
           AND (expense_date < ? OR (expense_date = ? AND id < ?))
         ORDER BY expense_date DESC, id DESC LIMIT 12
    ");
    $p->execute([$vehicleId, $expenseId, $date, $date, $expenseId]);
    $recent = $p->fetchAll();          // newest first

    $prev = null;
    foreach ($recent as $i => $cand) {
        $m = (int) $cand['meter_reading'];
        $older = $recent[$i + 1] ?? null;
        if ($older === null) {
            // Nothing older to validate against — the oldest reading we can see
            // is taken at face value, exactly as veh_km() treats its baseline.
            $prev = $cand;
            break;
        }
        $delta = $m - (int) $older['meter_reading'];
        if ($delta > 0 && $delta <= VEH_MAX_PLAUSIBLE_GAP) {
            $prev = $cand;             // forms a sane gap with its predecessor
            break;
        }
        // else: this reading is part of a bad transition — keep walking back.
    }

    $trip = null; $flag = '';
    if ($prev && $e['meter_reading'] !== null) {
        $delta = (int) $e['meter_reading'] - (int) $prev['meter_reading'];
        if ($delta > 0 && $delta <= VEH_MAX_PLAUSIBLE_GAP) { $trip = $delta; }
        else { $flag = $delta <= 0 ? 'rollback' : 'jump'; }
    }

    $amount = (float) $e['amount'];
    $litres = $e['litres'] !== null ? (float) $e['litres'] : null;

    // Trailing 90-day baseline, excluding this expense.
    $from = date('Y-m-d', strtotime($date . ' -90 days'));
    $to   = date('Y-m-d', strtotime($date . ' -1 day'));
    $base = veh_metrics($pdo, $vehicleId, $from, $to);

    $thisPerKm = $trip ? $amount / $trip : null;

    // How far off the trailing average is this fill? Drives the amber flag.
    $variance = null;
    if ($thisPerKm !== null && !empty($base['total_per_km']) && $base['total_per_km'] > 0) {
        $variance = (($thisPerKm - $base['total_per_km']) / $base['total_per_km']) * 100;
    }

    return [
        'vehicle'        => $e['vehicle_name'] . ' — ' . $e['registration'],
        'sub_name'       => $e['sub_name'],
        'is_fuel'        => !empty($e['tracks_fuel']),
        'meter'          => $e['meter_reading'] !== null ? (int) $e['meter_reading'] : null,
        'prev_meter'     => $prev ? (int) $prev['meter_reading'] : null,
        'prev_date'      => $prev ? $prev['expense_date'] : null,
        'trip'           => $trip,
        'flag'           => $flag,
        'amount'         => $amount,
        'litres'         => $litres,
        'this_per_km'    => $thisPerKm,
        'this_km_per_l'  => ($trip && $litres > 0) ? $trip / $litres : null,
        'this_rate'      => ($litres > 0) ? $amount / $litres : null,
        'base_per_km'    => $base['total_per_km'],
        'base_fuel_km'   => $base['fuel_per_km'],
        'base_km_per_l'  => $base['km_per_litre'],
        'base_km'        => $base['km'],
        'variance_pct'   => $variance,
    ];
}

/** Rs 24.13 — two decimals, or an em dash when the figure is unknowable. */
function veh_money_per_km(?float $v): string {
    return $v === null ? '—' : 'Rs ' . number_format($v, 2);
}

/** 9.63 km/L, or an em dash. */
function veh_kmpl(?float $v): string {
    return $v === null ? '—' : number_format($v, 2);
}
