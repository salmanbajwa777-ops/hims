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

// FALLBACK gap limit, used only for a vehicle with no jump_limit_km of its own.
// A gap larger than the limit is treated as a data error (missed posting or a
// typo) rather than real distance.
//
// One constant cannot fit a fleet: 5,000 km between two fills is generous for an
// ambulance and physically impossible for a 10-litre motorbike, so a bike error
// of 3,000 km would pass unnoticed. veh_limit_for() reads the vehicle's own
// limit and falls back here, so an un-tuned vehicle behaves exactly as before.
if (!defined('VEH_MAX_PLAUSIBLE_GAP')) {
    define('VEH_MAX_PLAUSIBLE_GAP', 5000);
}

/**
 * The plausibility limit to measure ONE vehicle against.
 *
 * Cached per request: every per-fill row, the approval panel and the attention
 * list all ask for the same vehicle's limit, and re-querying per reading would
 * turn one page render into hundreds of round trips.
 *
 * Falls back to the fleet default when the column is absent (migration not run)
 * or the vehicle has no limit set, so behaviour is unchanged until someone
 * deliberately tunes a vehicle.
 */
function veh_limit_for(PDO $pdo, int $vehicleId): int {
    static $cache = [];
    if (array_key_exists($vehicleId, $cache)) { return $cache[$vehicleId]; }

    $limit = VEH_MAX_PLAUSIBLE_GAP;
    try {
        $s = $pdo->prepare('SELECT jump_limit_km FROM vehicles WHERE id = ?');
        $s->execute([$vehicleId]);
        $v = $s->fetchColumn();
        // A zero or negative stored limit would discard every reading and report
        // the vehicle as having driven nowhere. Treat it as "not set".
        if ($v !== false && $v !== null && (int) $v > 0) { $limit = (int) $v; }
    } catch (PDOException $e) {
        // jump_limit_km absent — add_fuel_accountability.sql not run yet.
    }
    return $cache[$vehicleId] = $limit;
}

/**
 * Every usable odometer reading for one vehicle in a date range, in order.
 * Excludes voided and REJECTED rows — a rejected expense returned its cash and
 * its reading is not evidence of a trip.
 */
function veh_readings(PDO $pdo, int $vehicleId, string $from, string $to): array {
    // Two optional columns, each added by a migration that may not have run.
    // Both are selected as literal NULL when absent so every consumer sees one
    // stable row shape — a NULL reads as "not recorded", which is exactly right
    // for a pre-migration row and is never confused with a real value.
    $sql = "
        SELECT e.id, e.expense_date, e.meter_reading, e.litres, e.amount,
               %s AS tank_full,
               %s AS refuelled_by_id,
               %s AS refuelled_by_name,
               s.name AS sub_name, s.tracks_fuel
          FROM expenses e
          LEFT JOIN expense_subcategories s ON s.id = e.subcategory_id
          %s
         WHERE e.vehicle_id = ?
           AND e.meter_reading IS NOT NULL
           AND e.voided_at IS NULL
           AND e.approval_status <> 'REJECTED'
           AND e.expense_date BETWEEN ? AND ?
         ORDER BY e.expense_date, e.meter_reading, e.id
    ";
    // Widest shape first, then step down one column at a time. Tried in this
    // order because the two migrations can have been applied independently.
    $shapes = [
        ['e.tank_full', 'e.refuelled_by_id', 'ru.name', 'LEFT JOIN users ru ON ru.id = e.refuelled_by_id'],
        ['e.tank_full', 'NULL',              'NULL',    ''],
        ['NULL',        'e.refuelled_by_id', 'ru.name', 'LEFT JOIN users ru ON ru.id = e.refuelled_by_id'],
        ['NULL',        'NULL',              'NULL',    ''],
    ];
    foreach ($shapes as $i => $sh) {
        try {
            $s = $pdo->prepare(vsprintf($sql, $sh));
            $s->execute([$vehicleId, $from, $to]);
            return $s->fetchAll();
        } catch (PDOException $e) {
            if ($i === count($shapes) - 1) { throw $e; }   // last shape uses no
            // optional column at all, so a failure there is a real error and must
            // not be swallowed into an empty result that reads as "no data".
        }
    }
    return [];
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
                      ?int $maxGap = null): array {
    // Null means "ask the vehicle". An explicit value still wins, so a caller
    // testing a specific limit can pass one.
    $maxGap = $maxGap ?? veh_limit_for($pdo, $vehicleId);
    $rows = veh_readings($pdo, $vehicleId, $from, $to);
    $out = []; $prev = null;
    // Fill state of the PREVIOUS fuel row, for the full-to-full test below.
    // NULL until the first fuel row is seen; a pre-migration row is NULL too,
    // which correctly reads as "cannot prove it was full".
    $prevTankFull = null;

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
            $tankFull = $r['tank_full'] === null ? null : (int) $r['tank_full'];

            // A DISCRETE km/L needs a full-to-full segment: the litres in THIS
            // fill are what covered the distance since the tank was last brimmed.
            // If either end was a partial fill (or its state is unknown), the
            // litres do not correspond to the distance and the figure would be
            // wrong rather than merely imprecise — so it is withheld.
            //
            // Only the EFFICIENCY figure is withheld. trip, per_km, amount and
            // litres are all still reported and still count everywhere else.
            $fullToFull = ($tankFull === 1 && $prevTankFull === 1);
            $kmpl = ($fullToFull && $trip && $litres > 0) ? $trip / $litres : null;

            // Why km/L is blank, so the report can explain rather than show a
            // bare dash: 'partial' is a legitimate measurement gap, not an error.
            $kmplBlockedBy = '';
            if ($kmpl === null && $trip && $litres > 0) {
                $kmplBlockedBy = 'partial';
            }

            $out[] = [
                'id'        => (int) $r['id'],
                'date'      => $r['expense_date'],
                'meter'     => $m,
                'trip'      => $trip,
                'litres'    => $litres,
                'amount'    => $amount,
                'tank_full' => $tankFull,
                // Who took the vehicle to the pump. NULL on every pre-migration
                // row and on any fill posted without a name, which the per-person
                // report reports as "Unassigned" rather than folding into a person.
                'by_id'     => isset($r['refuelled_by_id']) && $r['refuelled_by_id'] !== null
                                   ? (int) $r['refuelled_by_id'] : null,
                'by_name'   => $r['refuelled_by_name'] ?? null,
                'kmpl'      => $kmpl,
                'kmpl_blocked_by' => $kmplBlockedBy,
                'per_km'    => $trip ? $amount / $trip : null,
                'rate'      => ($litres > 0) ? $amount / $litres : null,   // Rs per litre
                'flag'      => $flag,
            ];
            $prevTankFull = $tankFull;
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
    $limit    = veh_limit_for($pdo, $vehicleId);
    $readings = veh_readings($pdo, $vehicleId, $from, $to);
    $k        = veh_km($readings, $limit);
    $spend    = veh_spend($pdo, $vehicleId, $from, $to);
    $km       = $k['km'];

    // Discrete full-to-full segments, and how many were excluded for a partial
    // fill. Counted separately from bad_gaps so a vehicle that simply tops up
    // is never mistaken for one with corrupt odometer data — very different
    // problems, and only one of them needs fixing.
    $fills          = veh_per_fill($pdo, $vehicleId, $from, $to);
    $exactSegments  = 0;
    $partialBlocked = 0;
    $exactKm = 0.0; $exactLitres = 0.0;
    foreach ($fills as $f) {
        if ($f['kmpl'] !== null) {
            $exactSegments++;
            $exactKm     += (float) $f['trip'];
            $exactLitres += (float) $f['litres'];
        } elseif ($f['kmpl_blocked_by'] === 'partial') {
            $partialBlocked++;
        }
    }

    // PRECISE efficiency: only full-to-full segments, litres over their own km.
    $exactKmPerLitre = ($exactLitres > 0 && $exactKm > 0) ? $exactKm / $exactLitres : null;

    // APPROXIMATE efficiency (rolling average): all fuel litres in the window
    // over all distance in the window, regardless of fill state. For a vehicle
    // that structurally never gets a full tank this is the only efficiency
    // figure obtainable — the discrete method cannot work by definition, which
    // is physics rather than a bug. Always labelled "approx" in the UI and never
    // merged with or substituted for the precise figure.
    $approxKmPerLitre = ($km > 0 && $spend['litres'] > 0) ? $km / $spend['litres'] : null;

    return [
        'km'           => $km,
        'bad_gaps'     => $k['bad_gaps'],
        'jump_limit'   => $limit,          // so the UI can name the limit it applied
        'readings'     => count($readings),
        'fuel'         => $spend['fuel'],
        'maintenance'  => $spend['maintenance'],
        'repairs'      => $spend['repairs'],
        'other'        => $spend['other'],
        'total'        => $spend['total'],
        'litres'       => $spend['litres'],
        'fuel_per_km'  => $km > 0 ? $spend['fuel'] / $km : null,
        'total_per_km' => $km > 0 ? $spend['total'] / $km : null,
        // Precise where possible, approximate otherwise. km_per_litre is kept as
        // the precise figure for backward compatibility with existing callers.
        'km_per_litre'        => $exactKmPerLitre,
        'approx_km_per_litre' => $approxKmPerLitre,
        'exact_segments'      => $exactSegments,
        'partial_blocked'     => $partialBlocked,
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
    // This vehicle's own limit, so the approval panel and the report agree on
    // what counts as a believable gap for THIS vehicle rather than for a fleet
    // average that fits neither a bike nor an ambulance.
    $maxGap    = veh_limit_for($pdo, $vehicleId);

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
        if ($delta > 0 && $delta <= $maxGap) {
            $prev = $cand;             // forms a sane gap with its predecessor
            break;
        }
        // else: this reading is part of a bad transition — keep walking back.
    }

    $trip = null; $flag = '';
    if ($prev && $e['meter_reading'] !== null) {
        $delta = (int) $e['meter_reading'] - (int) $prev['meter_reading'];
        if ($delta > 0 && $delta <= $maxGap) { $trip = $delta; }
        else { $flag = $delta <= 0 ? 'rollback' : 'jump'; }
    }

    $amount = (float) $e['amount'];
    $litres = $e['litres'] !== null ? (float) $e['litres'] : null;

    // Who refuelled, and their own running km/L on THIS vehicle. Surfaced at the
    // moment of approval because that is when the approver can still ask the
    // question — a name on a report three weeks later is far weaker. Read in its
    // own guarded query so a pre-migration database renders the rest of the panel.
    $byName = null; $byKmpl = null; $byFills = 0;
    try {
        $bq = $pdo->prepare('SELECT e.refuelled_by_id, u.name
                               FROM expenses e LEFT JOIN users u ON u.id = e.refuelled_by_id
                              WHERE e.id = ?');
        $bq->execute([$expenseId]);
        $br = $bq->fetch() ?: null;
        if ($br && $br['refuelled_by_id'] !== null) {
            $byName = $br['name'];
            // Their trailing history on this vehicle, over a wider window than the
            // 90-day cost baseline: km/L needs full-to-full pairs, which are far
            // sparser than plain readings, so a short window would usually be null.
            $bpFrom = date('Y-m-d', strtotime($date . ' -180 days'));
            $bp = veh_by_person($pdo, $vehicleId, $bpFrom, $date);
            foreach ($bp['rows'] as $row) {
                if ($row['id'] === (int) $br['refuelled_by_id']) {
                    $byKmpl  = $row['kmpl'];
                    $byFills = (int) $row['fills'];
                    break;
                }
            }
        }
    } catch (PDOException $eb) { /* refuelled_by_id absent */ }

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
        'jump_limit'     => $maxGap,
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
        'by_name'        => $byName,
        'by_km_per_l'    => $byKmpl,
        'by_fills'       => $byFills,
    ];
}

// A person needs at least this many measurable full-to-full fills before any
// comparison is drawn. Below it, one heavy-traffic week or one mistyped odometer
// dominates the average and the "finding" is noise wearing a red badge.
if (!defined('VEH_MIN_FILLS_TO_JUDGE')) { define('VEH_MIN_FILLS_TO_JUDGE', 3); }

// How far below the vehicle's own average a person's km/L must sit before the
// row is flagged for a look. 15% is beyond what route or traffic variation
// explains over several fills, without flagging every ordinary fluctuation.
if (!defined('VEH_PERSON_FLAG_PCT')) { define('VEH_PERSON_FLAG_PCT', 15.0); }

/**
 * Km per litre grouped by the PERSON who refuelled, for one vehicle.
 *
 * THE QUESTION THIS ANSWERS: litres bought is recorded on the receipt; litres
 * BURNED is inferred from the odometer. For one vehicle on one set of routes,
 * those two should track each other regardless of who was driving. When one
 * person's fills persistently return fewer km per litre than everyone else's on
 * the SAME vehicle, fuel that was paid for did not go into that tank.
 *
 * WHY THE COMPARISON IS WITHIN ONE VEHICLE, NEVER ACROSS THE FLEET
 *   A bike returns 40 km/L and an ambulance 9. Ranking people across vehicles
 *   would simply rank them by which vehicle they happen to drive, and the
 *   person on the thirstiest van would be permanently "worst".
 *
 * WHY ONLY FULL-TO-FULL SEGMENTS COUNT
 *   Only there do the litres in a fill correspond to the distance just covered.
 *   Including part fills would attribute someone else's litres to this person's
 *   kilometres, which is precisely the error the report claims to detect —
 *   a false accusation, not merely an imprecise number.
 *
 * WHY UNASSIGNED IS REPORTED SEPARATELY AND NEVER FLAGGED
 *   Rows posted before refuelled_by_id existed have nobody attached. Folding
 *   them into a person would put another person's litres on their name.
 *
 * The 'flag' is a prompt to look, not a verdict: heavy city traffic, long idles
 * on aircon, a loaded route or one bad odometer reading all lower km/L honestly.
 * 'litres_over' and 'rs_over' quantify the gap so the size of the question is
 * visible — they are NOT a claim that this much fuel was stolen.
 */
function veh_by_person(PDO $pdo, int $vehicleId, string $from, string $to): array {
    $fills = veh_per_fill($pdo, $vehicleId, $from, $to);

    $people = [];
    foreach ($fills as $f) {
        // Only measurable segments. A fill with no km/L carries no evidence
        // about efficiency, so it must not dilute anyone's average — but its
        // spend is still real, so it is counted separately as 'unmeasured'.
        $key  = $f['by_id'] ?? 0;                       // 0 = Unassigned
        $name = $f['by_id'] ? ($f['by_name'] ?: 'User #' . $f['by_id']) : 'Unassigned';
        if (!isset($people[$key])) {
            $people[$key] = ['id' => $f['by_id'], 'name' => $name, 'fills' => 0,
                             'km' => 0.0, 'litres' => 0.0, 'amount' => 0.0,
                             'unmeasured' => 0, 'unmeasured_amount' => 0.0];
        }
        $people[$key]['amount'] += $f['amount'];
        if ($f['kmpl'] !== null) {
            $people[$key]['fills']++;
            $people[$key]['km']     += (float) $f['trip'];
            $people[$key]['litres'] += (float) $f['litres'];
        } else {
            $people[$key]['unmeasured']++;
            $people[$key]['unmeasured_amount'] += $f['amount'];
        }
    }
    if (!$people) { return ['rows' => [], 'vehicle_kmpl' => null, 'measured_fills' => 0]; }

    // The vehicle's own baseline: total measured km over total measured litres.
    // Not a mean of the per-person rates — someone with two fills must not weigh
    // the same as someone with twelve.
    $totKm = 0.0; $totLit = 0.0; $measured = 0;
    foreach ($people as $p) { $totKm += $p['km']; $totLit += $p['litres']; $measured += $p['fills']; }
    $vehicleKmpl = ($totKm > 0 && $totLit > 0) ? $totKm / $totLit : null;

    $rows = [];
    foreach ($people as $p) {
        $kmpl = ($p['km'] > 0 && $p['litres'] > 0) ? $p['km'] / $p['litres'] : null;
        $dev  = ($kmpl !== null && $vehicleKmpl > 0)
                    ? (($kmpl - $vehicleKmpl) / $vehicleKmpl) * 100 : null;

        // Litres this person's distance SHOULD have needed at the vehicle's own
        // rate, and what the surplus cost. Only for a person running BELOW the
        // baseline with enough fills to judge — computing it for someone above
        // average would produce a meaningless negative "loss".
        $litresOver = null; $rsOver = null;
        $flag = false;
        if ($kmpl !== null && $dev !== null && $p['fills'] >= VEH_MIN_FILLS_TO_JUDGE
            && $dev <= -VEH_PERSON_FLAG_PCT) {
            $flag = true;
            $expected   = $p['km'] / $vehicleKmpl;              // litres at baseline
            $litresOver = $p['litres'] - $expected;
            $rate       = $p['litres'] > 0 ? $p['amount'] / $p['litres'] : 0.0;
            $rsOver     = $litresOver > 0 ? $litresOver * $rate : null;
        }
        // Unassigned is a data gap, never a suspect. Its rows may well belong to
        // the very person being examined.
        if ($p['id'] === null) { $flag = false; $litresOver = null; $rsOver = null; }

        $rows[] = $p + [
            'kmpl'        => $kmpl,
            'dev_pct'     => $dev,
            'flag'        => $flag,
            'litres_over' => $litresOver,
            'rs_over'     => $rsOver,
            // Why no judgement was drawn, so the UI explains rather than showing
            // a bare blank that reads as "nothing wrong here".
            'too_few'     => ($kmpl !== null && $p['fills'] < VEH_MIN_FILLS_TO_JUDGE),
        ];
    }

    // Worst first: a flagged row leads, then by deviation ascending. The person
    // who needs looking at is at the top without anyone having to sort.
    usort($rows, function ($a, $b) {
        if ($a['flag'] !== $b['flag']) { return $a['flag'] ? -1 : 1; }
        $ad = $a['dev_pct'] ?? 999; $bd = $b['dev_pct'] ?? 999;
        return $ad <=> $bd;
    });

    return ['rows' => $rows, 'vehicle_kmpl' => $vehicleKmpl, 'measured_fills' => $measured];
}

/**
 * Readings that need a human to fix them, worst first, across the whole fleet.
 *
 * The report already counts discarded readings, but a count sends you hunting
 * through a list. This returns the actual rows with the reason, so the fix is
 * one click from the finding.
 *
 * Four kinds, in descending order of how badly they corrupt a figure:
 *   rollback — reading lower than the one before it; a dropped or transposed digit
 *   jump     — gap above THIS vehicle's limit; usually a missed month of postings
 *   missing  — a fuel fill with no reading at all; measures no distance
 *   rate     — Rs/litre more than 30% off the fleet rate; a wrong amount or litres
 */
function veh_attention(PDO $pdo, string $from, string $to, int $limitRows = 40): array {
    $vehicles = $pdo->query('SELECT id, name, registration FROM vehicles')->fetchAll();
    $fleetRate = veh_fleet_rate_per_litre($pdo, 90, $to);
    $out = [];

    foreach ($vehicles as $v) {
        $vid    = (int) $v['id'];
        $label  = $v['name'] . ' · ' . $v['registration'];
        $maxGap = veh_limit_for($pdo, $vid);
        $rows   = veh_readings($pdo, $vid, $from, $to);

        // Same forward walk as veh_km(), but recording WHICH reading broke the
        // chain instead of only counting. Kept in step with veh_km deliberately:
        // if these two disagreed, the report would count a problem the list
        // could not show, or show one the totals did not reflect.
        $prev = null;
        foreach ($rows as $r) {
            $m = (int) $r['meter_reading'];
            if ($prev !== null) {
                $delta = $m - $prev;
                if ($delta <= 0) {
                    $out[] = ['sev' => 3, 'kind' => 'rollback', 'id' => (int) $r['id'],
                              'vehicle' => $label, 'date' => $r['expense_date'],
                              'meter' => $m, 'prev' => $prev, 'delta' => $delta,
                              'limit' => $maxGap];
                } elseif ($delta > $maxGap) {
                    $out[] = ['sev' => 3, 'kind' => 'jump', 'id' => (int) $r['id'],
                              'vehicle' => $label, 'date' => $r['expense_date'],
                              'meter' => $m, 'prev' => $prev, 'delta' => $delta,
                              'limit' => $maxGap];
                }
            }
            if ($prev === null || ($m > $prev && $m - $prev <= $maxGap)) { $prev = $m; }
        }

        // Rs/litre outliers among this vehicle's fills.
        if ($fleetRate !== null && $fleetRate > 0) {
            foreach (veh_per_fill($pdo, $vid, $from, $to) as $f) {
                if ($f['rate'] === null) { continue; }
                $off = (($f['rate'] - $fleetRate) / $fleetRate) * 100;
                if (abs($off) >= 30) {
                    $out[] = ['sev' => 1, 'kind' => 'rate', 'id' => $f['id'],
                              'vehicle' => $label, 'date' => $f['date'],
                              'rate' => $f['rate'], 'fleet_rate' => $fleetRate,
                              'off_pct' => $off];
                }
            }
        }
    }

    // Fuel fills with no reading at all. These never appear in veh_readings()
    // (which requires meter_reading IS NOT NULL), so they are invisible to every
    // other figure on the page — the one class of problem a count cannot surface.
    try {
        $s = $pdo->prepare("
            SELECT e.id, e.expense_date, v.name, v.registration
              FROM expenses e
              JOIN vehicles v ON v.id = e.vehicle_id
              JOIN expense_subcategories s ON s.id = e.subcategory_id
             WHERE s.tracks_fuel = 1
               AND e.meter_reading IS NULL
               AND e.voided_at IS NULL
               AND e.approval_status <> 'REJECTED'
               AND e.expense_date BETWEEN ? AND ?
             ORDER BY e.expense_date DESC
        ");
        $s->execute([$from, $to]);
        foreach ($s->fetchAll() as $r) {
            $out[] = ['sev' => 2, 'kind' => 'missing', 'id' => (int) $r['id'],
                      'vehicle' => $r['name'] . ' · ' . $r['registration'],
                      'date' => $r['expense_date']];
        }
    } catch (PDOException $e) {
        // Vehicle columns absent — the caller already gates on $schemaReady.
    }

    // Severity first, then newest: a recent error is still correctable from
    // memory, whereas one from four months ago probably is not.
    usort($out, function ($a, $b) {
        if ($a['sev'] !== $b['sev']) { return $b['sev'] <=> $a['sev']; }
        return strcmp($b['date'], $a['date']);
    });
    return array_slice($out, 0, $limitRows);
}

/**
 * Monthly cost-per-km series for one vehicle, for the trend view.
 *
 * Each month is measured INDEPENDENTLY, so the first reading of each month is
 * that month's baseline and the distance between the last fill of one month and
 * the first of the next belongs to neither. That understates distance slightly
 * and therefore overstates cost per km — the same deliberate direction of error
 * used everywhere else here, so the trend never flatters.
 *
 * 'high' marks a month sitting more than 20% above the vehicle's own mean for
 * the window. Compared against itself, not the fleet: a fuel price rise lifts
 * every vehicle together, whereas one van climbing alone is the real signal.
 */
function veh_trend(PDO $pdo, int $vehicleId, int $months = 6, ?string $asOf = null): array {
    $end  = $asOf ?: date('Y-m-d');
    $out  = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $mStart = date('Y-m-01', strtotime($end . ' -' . $i . ' months'));
        $mEnd   = date('Y-m-t',  strtotime($mStart));
        $m = veh_metrics($pdo, $vehicleId, $mStart, $mEnd);
        $out[] = [
            'label'        => date('M', strtotime($mStart)),
            'year'         => date('Y', strtotime($mStart)),
            'from'         => $mStart,
            'to'           => $mEnd,
            'km'           => $m['km'],
            'total'        => $m['total'],
            'fuel_per_km'  => $m['fuel_per_km'],
            'total_per_km' => $m['total_per_km'],
            'high'         => false,
        ];
    }
    // The vehicle's own mean over the months that actually have a figure.
    $vals = array_values(array_filter(array_column($out, 'fuel_per_km'), fn($v) => $v !== null));
    $mean = $vals ? array_sum($vals) / count($vals) : null;
    if ($mean !== null && $mean > 0) {
        foreach ($out as $i => $row) {
            if ($row['fuel_per_km'] !== null && $row['fuel_per_km'] > $mean * 1.20) {
                $out[$i]['high'] = true;
            }
        }
    }
    return ['months' => $out, 'mean' => $mean,
            'peak'   => $vals ? max($vals) : null];
}

/**
 * Fleet-wide average Rs/litre over the trailing N days, for the posting-time
 * plausibility check. Fleet-wide rather than per-vehicle because fuel price is a
 * property of the pump, not the vehicle, and a per-vehicle average would have
 * too few data points to be a stable reference.
 *
 * Returns null when there is not enough history to compare against — the caller
 * must then skip the warning rather than compare against a fabricated baseline.
 */
function veh_fleet_rate_per_litre(PDO $pdo, int $days = 30, ?string $asOf = null): ?float {
    $to   = $asOf ?: date('Y-m-d');
    $from = date('Y-m-d', strtotime($to . ' -' . $days . ' days'));
    try {
        $s = $pdo->prepare("
            SELECT COALESCE(SUM(e.amount), 0) AS amt, COALESCE(SUM(e.litres), 0) AS lit
              FROM expenses e
              JOIN expense_subcategories s ON s.id = e.subcategory_id
             WHERE s.tracks_fuel = 1
               AND e.litres > 0
               AND e.voided_at IS NULL
               AND e.approval_status <> 'REJECTED'
               AND e.expense_date BETWEEN ? AND ?
        ");
        $s->execute([$from, $to]);
        $r = $s->fetch();
        $lit = (float) ($r['lit'] ?? 0);
        return $lit > 0 ? ((float) $r['amt']) / $lit : null;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Does this vehicle already have a fuel posting on this date?
 * Feeds a non-blocking duplicate warning — a second genuine fill on a long trip
 * day is legitimate, so this informs rather than refuses.
 */
function veh_has_fuel_on_date(PDO $pdo, int $vehicleId, string $date, int $excludeId = 0): bool {
    try {
        $s = $pdo->prepare("
            SELECT COUNT(*) FROM expenses e
              JOIN expense_subcategories s ON s.id = e.subcategory_id
             WHERE e.vehicle_id = ? AND s.tracks_fuel = 1
               AND e.expense_date = ?
               AND e.id <> ?
               AND e.voided_at IS NULL
               AND e.approval_status <> 'REJECTED'
        ");
        $s->execute([$vehicleId, $date, $excludeId]);
        return ((int) $s->fetchColumn()) > 0;
    } catch (PDOException $e) {
        return false;
    }
}

/** Rs 24.13 — two decimals, or an em dash when the figure is unknowable. */
function veh_money_per_km(?float $v): string {
    return $v === null ? '—' : 'Rs ' . number_format($v, 2);
}

/** 9.63 km/L, or an em dash. */
function veh_kmpl(?float $v): string {
    return $v === null ? '—' : number_format($v, 2);
}
