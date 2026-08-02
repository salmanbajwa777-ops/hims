<?php
/**
 * IPD Digital Treatment Sheet — the shared engine.
 *
 * Everything the treatment sheet needs that must behave identically no matter
 * which page calls it: slot generation, the allergy / duplicate-therapy
 * validators, the doctor-approval gate, and the audit writer.
 *
 * Scope: IPD admissions only. Every function keys off ipd_admissions.id. The ER
 * short-stay admission_* tables are untouched and out of scope by design.
 *
 * ---------------------------------------------------------------------------
 * The rule this file exists to enforce
 * ---------------------------------------------------------------------------
 * A staff member may WRITE a medication order. Only a doctor may APPROVE it.
 * No dose can be administered against an unapproved order. That gate is
 * enforced in THREE independent places on purpose:
 *
 *   1. the permission split (IPD_WRITE_MED_ORDER vs IPD_APPROVE_MED_ORDER),
 *   2. can_administer_order() — re-checked server-side on every give,
 *   3. the slot generator, which refuses to create slots for a PENDING order.
 *
 * Any one of those alone would be a single point of failure for a rule that is
 * the whole clinical point of the feature.
 *
 * ---------------------------------------------------------------------------
 * Why slots are generated at APPROVAL, not at order creation
 * ---------------------------------------------------------------------------
 * The spec says slots are generated "at order creation". That is right for a
 * doctor-written order — which self-approves, so the two moments are the same
 * instant. It is WRONG for a staff-written one: generating slots first would
 * put PENDING doses on the nurse's due list for a drug no doctor has signed,
 * which is exactly the situation this feature is meant to prevent. So slots are
 * generated the moment the order becomes APPROVED, which for a doctor is order
 * creation and for staff is the doctor's signature.
 */

require_once __DIR__ . '/permissions.php';   // audit_log()

// -----------------------------------------------------------------------------
// Fixed enums (spec sections 2 + 3). These are the authority; the formulary's
// default_routes / default_frequencies only pre-filter the dropdowns.
// -----------------------------------------------------------------------------

// The CODE is what gets stored and what every downstream check compares on, so
// it never changes once orders exist — 'PO' stays 'PO' even though the label
// reads "Oral". Relabelling is free; recoding would orphan every historical
// order's route.
const IPD_ROUTES = [
    'PO'  => 'Oral',
    'IV'  => 'IV — intravenous',
    'IM'  => 'IM — intramuscular',
    'PR'  => 'PR — rectal',
    'IO'  => 'IO — intraosseous',
    'PV'  => 'PV — vaginal',
    'SC'  => 'SC — subcutaneous',
    'NEB' => 'Nebulise',
    'TOP' => 'TOP — topical',
    'SL'  => 'SL — sublingual',
    'NG'  => 'NG — nasogastric',
];

/**
 * The routes offered on the FORMULARY screen, in the clinic's preferred order.
 *
 * A subset of IPD_ROUTES by design: the formulary sets a drug's usual routes,
 * while the order form must still be able to express the unusual one. Keys are
 * IPD_ROUTES codes — anything else here would filter the doctor's route picker
 * down to nothing at prescribing time.
 */
const IPD_FORMULARY_ROUTES = ['PO', 'IV', 'IM', 'PR', 'IO', 'PV', 'SC', 'NEB'];

/**
 * The frequencies offered as a one-click default on the formulary screen.
 *
 * The everyday four. Anything else (Q6H, Q8H, STAT, PRN) is reachable through
 * the "Other" box, which validates against the live frequency map.
 */
const IPD_FORMULARY_FREQS = ['OD', 'BD', 'TDS', 'QID'];

const IPD_ORDER_TYPES = ['SCHEDULED', 'PRN', 'STAT'];

const IPD_DOSE_UNITS = ['mg', 'g', 'mcg', 'ml', 'IU', 'mEq', 'tab', 'cap', 'drop', 'puff', 'unit'];

/**
 * Frequency -> clock times, read from the site-configurable table.
 *
 * Falls back to the spec defaults if ipd_frequency_times is missing or empty, so
 * a half-migrated environment degrades to correct behaviour instead of silently
 * generating zero slots (which would look like "the drug has no doses due").
 */
function ipd_frequency_map(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) { return $cache; }

    $fallback = [
        'OD'   => ['label' => 'Once daily',        'times' => ['08:00']],
        'BD'   => ['label' => 'Twice daily',       'times' => ['08:00', '20:00']],
        'TDS'  => ['label' => 'Three times daily', 'times' => ['08:00', '14:00', '20:00']],
        'QID'  => ['label' => 'Four times daily',  'times' => ['08:00', '13:00', '19:00', '23:00']],
        'Q6H'  => ['label' => 'Every 6 hours',     'times' => ['00:00', '06:00', '12:00', '18:00']],
        'Q8H'  => ['label' => 'Every 8 hours',     'times' => ['06:00', '14:00', '22:00']],
        'STAT' => ['label' => 'Once, immediately', 'times' => []],
        'PRN'  => ['label' => 'As needed',         'times' => []],
    ];

    try {
        $rows = $pdo->query('SELECT code, label, times_csv FROM ipd_frequency_times ORDER BY sort_order, code')->fetchAll();
        if (!$rows) { return $cache = $fallback; }
        $map = [];
        foreach ($rows as $r) {
            $times = array_values(array_filter(array_map('trim', explode(',', (string) $r['times_csv']))));
            $map[$r['code']] = ['label' => $r['label'], 'times' => $times];
        }
        // Keep any code the table somehow lost, so the enum never outruns the map.
        return $cache = $map + $fallback;
    } catch (Throwable $e) {
        return $cache = $fallback;
    }
}

function ipd_frequency_label(PDO $pdo, string $code): string {
    $map = ipd_frequency_map($pdo);
    return $map[$code]['label'] ?? $code;
}

/**
 * How many doses a frequency gives per day. PRN and STAT are 0 — neither is
 * a per-day schedule (STAT is a single dose, PRN is on demand).
 */
function ipd_slots_per_day(PDO $pdo, string $frequency): int {
    $map = ipd_frequency_map($pdo);
    return count($map[$frequency]['times'] ?? []);
}

// -----------------------------------------------------------------------------
// Audit
// -----------------------------------------------------------------------------

/**
 * Write the feature-local audit row AND the global audit_logs row.
 *
 * Both, deliberately. audit_logs is the hospital-wide trail an admin already
 * reads; ipd_medication_audit is keyed by entity so "everything that happened to
 * this order" is answerable. Neither replaces the other.
 *
 * Never throws — an audit table that is missing must not block a nurse from
 * recording that a drug was given. The global audit_log() still fires.
 */
function ipd_med_audit(PDO $pdo, string $entityType, int $entityId, string $action, string $detail, ?int $admissionId = null): void {
    $uid = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    try {
        $pdo->prepare('
            INSERT INTO ipd_medication_audit
                (entity_type, entity_id, admission_id, action, detail, performed_by_id, performed_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ')->execute([$entityType, $entityId, $admissionId, $action, mb_substr($detail, 0, 1000), $uid]);
    } catch (Throwable $e) {
        // Fall through to the global log below.
    }
    try {
        audit_log($pdo, 'ipd_med_' . strtolower($action), $detail);
    } catch (Throwable $e) {
        // Never let logging break care delivery.
    }
}

// -----------------------------------------------------------------------------
// Allergies
// -----------------------------------------------------------------------------

// -----------------------------------------------------------------------------
// Generic vs brand
//
// The formulary keeps the molecule (generic_name), the primary trade name
// (brand_name) and any alternates (brand_names) in three separate columns, and
// an order snapshots the generic and the brand separately too. They are kept
// apart because they answer different questions: the generic is what the drug
// IS — it is what the allergy and duplicate-therapy checks reason about — while
// the brand is what the nurse actually picks off the shelf. Merging them into
// one string would force the sheet to lie about one of the two.
// -----------------------------------------------------------------------------

/** Every trade name for a formulary row, uppercased: primary first, then alternates. */
function ipd_all_brand_names(array $drug): array {
    $out = [];
    $primary = mb_strtoupper(trim((string) ($drug['brand_name'] ?? '')), 'UTF-8');
    if ($primary !== '') { $out[] = $primary; }
    foreach (explode(',', (string) ($drug['brand_names'] ?? '')) as $b) {
        $b = mb_strtoupper(trim($b), 'UTF-8');
        if ($b !== '' && !in_array($b, $out, true)) { $out[] = $b; }
    }
    return $out;
}

/**
 * The brand PICK LIST for a formulary row, in original case: the primary first,
 * then alternates, capped at IPD_MAX_BRANDS.
 *
 * Separate from ipd_all_brand_names() on purpose. That one uppercases and is for
 * allergy MATCHING, where case is noise. This one preserves what the admin
 * typed, because it populates the brand dropdown the doctor reads and what gets
 * printed on the chart.
 */
const IPD_MAX_BRANDS = 6;

function ipd_brand_options(array $drug): array {
    $out = [];
    $primary = trim((string) ($drug['brand_name'] ?? ''));
    if ($primary !== '') { $out[] = $primary; }
    foreach (explode(',', (string) ($drug['brand_names'] ?? '')) as $b) {
        $b = trim($b);
        // Case-insensitive de-dupe so "Rocephin" listed in both columns
        // does not appear twice in the dropdown.
        if ($b !== '' && !in_array(mb_strtolower($b, 'UTF-8'), array_map(fn($x) => mb_strtolower($x, 'UTF-8'), $out), true)) {
            $out[] = $b;
        }
    }
    return array_slice($out, 0, IPD_MAX_BRANDS);
}

/**
 * Formulary rows grouped by generic name, for the prescribing flow.
 *
 * The doctor picks the GENERIC first, so the order form needs to answer "how
 * many forms does this molecule have?" before it can decide whether to
 * auto-load a form/route or ask. Returns [GENERIC => [row, row, ...]].
 */
function ipd_formulary_by_generic(array $formulary): array {
    $out = [];
    foreach ($formulary as $d) {
        $out[mb_strtoupper($d['generic_name'], 'UTF-8')][] = $d;
    }
    return $out;
}

/**
 * The dose forms a clinic is likely to stock. Free text in the DB (a clinic may
 * type anything), so this only seeds the datalist on the formulary screen.
 */
const IPD_DOSE_FORMS = [
    'Tablet', 'Capsule', 'Syrup', 'Suspension', 'Drops',
    'Injection', 'Infusion', 'Suppository', 'Pessary',
    'Nebuliser solution', 'Inhaler', 'Cream', 'Ointment', 'Gel',
    'Eye drops', 'Ear drops', 'Patch', 'Sachet', 'Topical',
];

/**
 * The printable name for an order: "GENERIC (BRAND)", or just the generic when
 * no brand is known. Used to build drug_name_snapshot at order time and to
 * re-derive a label for legacy rows.
 */
function ipd_display_drug_name(string $generic, ?string $brand): string {
    $generic = trim($generic);
    $brand   = trim((string) $brand);
    return ($brand !== '' && mb_strtoupper($brand, 'UTF-8') !== mb_strtoupper($generic, 'UTF-8'))
        ? $generic . ' (' . $brand . ')'
        : $generic;
}

/** Active documented allergies for a patient. */
function ipd_patient_allergies(PDO $pdo, int $patientId): array {
    try {
        $st = $pdo->prepare('
            SELECT a.*, u.name AS recorded_by_name
            FROM patient_allergies a
            LEFT JOIN users u ON u.id = a.recorded_by_id
            WHERE a.patient_id = ? AND a.is_active = 1
            ORDER BY FIELD(a.severity, "SEVERE", "MODERATE", "MILD", "UNKNOWN"), a.substance
        ');
        $st->execute([$patientId]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Does this drug conflict with a documented allergy?
 *
 * Matches on three widening signals, best first:
 *   1. allergy_group == allergy_group   — the real cross-reactivity check, only
 *      possible when the drug came from the formulary AND the allergy was
 *      recorded against a group.
 *   2. substance matches the generic or a brand name (either direction) — the
 *      substring test both ways, because a patient's allergy may be recorded as
 *      "AUGMENTIN" against an order for "AMOXICILLIN+CLAVULANATE" or vice versa.
 *   3. substance matches the drug class text.
 *
 * Returns the matching allergy rows (empty = clear). This is intentionally
 * over-sensitive rather than under-sensitive: a false warning costs a doctor one
 * override click, a missed one costs a patient.
 */
function ipd_check_allergy(PDO $pdo, int $patientId, ?array $drug, string $drugName): array {
    $allergies = ipd_patient_allergies($pdo, $patientId);
    if (!$allergies) { return []; }

    $needleName  = mb_strtoupper(trim($drugName), 'UTF-8');
    $drugGroup   = $drug ? mb_strtoupper(trim((string) ($drug['allergy_group'] ?? '')), 'UTF-8') : '';
    $drugClass   = $drug ? mb_strtoupper(trim((string) ($drug['drug_class'] ?? '')), 'UTF-8') : '';
    // The primary brand and the alternates are separate columns, but for allergy
    // matching every trade name counts equally — a patient who reacted to a drug
    // does not care which of its names the pharmacy happened to stock.
    $brandNames  = $drug ? ipd_all_brand_names($drug) : [];

    $hits = [];
    foreach ($allergies as $a) {
        $sub      = mb_strtoupper(trim((string) $a['substance']), 'UTF-8');
        $subGroup = mb_strtoupper(trim((string) ($a['allergy_group'] ?? '')), 'UTF-8');
        if ($sub === '') { continue; }

        $matched = false;
        $why     = '';

        // 1. Cross-reactivity group — the strongest signal.
        if ($drugGroup !== '' && $subGroup !== '' && $drugGroup === $subGroup) {
            $matched = true;
            $why = 'same cross-reactivity group (' . $drugGroup . ')';
        }
        // 2. Name / brand match, tested both directions.
        if (!$matched && $needleName !== '') {
            if (mb_strpos($needleName, $sub) !== false || mb_strpos($sub, $needleName) !== false) {
                $matched = true;
                $why = 'drug name matches the recorded allergy';
            }
        }
        if (!$matched) {
            foreach ($brandNames as $b) {
                if (mb_strpos($b, $sub) !== false || mb_strpos($sub, $b) !== false) {
                    $matched = true;
                    $why = 'brand name "' . $b . '" matches the recorded allergy';
                    break;
                }
            }
        }
        // 3. Class text match — weakest, catches "allergic to NSAIDs".
        if (!$matched && $drugClass !== '' && (mb_strpos($drugClass, $sub) !== false || mb_strpos($sub, $drugClass) !== false)) {
            $matched = true;
            $why = 'drug class "' . $drugClass . '" matches the recorded allergy';
        }

        if ($matched) {
            $a['match_reason'] = $why;
            $hits[] = $a;
        }
    }
    return $hits;
}

/**
 * Active orders that duplicate this drug or its class (spec 4.2 — a WARNING,
 * never a block; genuine dual therapy exists).
 *
 * Compares against the SNAPSHOT columns, not a join to the formulary, so a drug
 * later renamed or removed from the formulary still reports its duplicate.
 */
function ipd_check_duplicate(PDO $pdo, int $admissionId, ?int $drugId, string $drugName, ?string $drugClass, int $excludeOrderId = 0): array {
    try {
        $sql = "
            SELECT id, drug_name_snapshot, drug_class_snapshot, dose_value, dose_unit, route, frequency,
                   approval_status, prescribed_at
            FROM ipd_medication_orders
            WHERE admission_id = :aid
              AND status = 'ACTIVE'
              AND approval_status <> 'REJECTED'
              AND id <> :xid
              AND (
                    (:did IS NOT NULL AND drug_id = :did2)
                 OR UPPER(drug_name_snapshot) = UPPER(:dname)
                 OR (:dclass <> '' AND UPPER(COALESCE(drug_class_snapshot,'')) = UPPER(:dclass2))
              )
            ORDER BY prescribed_at DESC
        ";
        $st = $pdo->prepare($sql);
        $st->execute([
            ':aid'    => $admissionId,
            ':xid'    => $excludeOrderId,
            ':did'    => $drugId,
            ':did2'   => $drugId,
            ':dname'  => $drugName,
            ':dclass' => (string) $drugClass,
            ':dclass2'=> (string) $drugClass,
        ]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

// -----------------------------------------------------------------------------
// The doctor-approval gate
// -----------------------------------------------------------------------------

/**
 * May a dose be given against this order right now?
 *
 * Re-checked server-side on every administration POST. A row that is PENDING,
 * REJECTED or DISCONTINUED is never administrable, regardless of what the page
 * happened to render a moment ago.
 */
function can_administer_order(array $order): bool {
    return $order['approval_status'] === 'APPROVED'
        && $order['status'] === 'ACTIVE';
}

/**
 * Is this admission's treatment sheet cleared to start?
 *
 * "Cleared" = at least one doctor-APPROVED active order exists. Used to drive
 * the persistent warning banner and the ward-list flag, so an admission whose
 * sheet was never written cannot go quietly unnoticed.
 *
 * Returns ['has_orders','approved','pending','rejected','cleared'].
 */
function ipd_sheet_state(PDO $pdo, int $admissionId): array {
    $out = [
        'has_orders' => false, 'approved' => 0, 'pending' => 0, 'rejected' => 0,
        'cleared' => false,
        // False when the feature's tables do not exist yet. Callers MUST check
        // this before raising a "no treatment sheet" alarm: code deploys before
        // the migration is run, and during that window every in-patient would
        // otherwise light up with a red warning about a chart nobody could
        // possibly have written. A false alarm on a live ward is not a safe
        // default — it teaches people to ignore the banner that matters.
        'available' => true,
    ];
    try {
        $st = $pdo->prepare("
            SELECT approval_status, COUNT(*) AS n
            FROM ipd_medication_orders
            WHERE admission_id = ? AND status <> 'DISCONTINUED'
            GROUP BY approval_status
        ");
        $st->execute([$admissionId]);
        foreach ($st->fetchAll() as $r) {
            $n = (int) $r['n'];
            if ($r['approval_status'] === 'APPROVED') { $out['approved'] = $n; }
            elseif ($r['approval_status'] === 'PENDING') { $out['pending'] = $n; }
            elseif ($r['approval_status'] === 'REJECTED') { $out['rejected'] = $n; }
        }
        $out['has_orders'] = ($out['approved'] + $out['pending'] + $out['rejected']) > 0;
        $out['cleared'] = $out['approved'] > 0;
    } catch (Throwable $e) {
        // Tables absent (pre-migration). Report "not available" rather than a
        // false all-clear OR a false alarm.
        $out['available'] = false;
    }
    return $out;
}

// -----------------------------------------------------------------------------
// Slot generation
// -----------------------------------------------------------------------------

/**
 * Generate the administration slots for an APPROVED order.
 *
 * Rules:
 *   - PENDING / REJECTED orders generate NOTHING. The gate, restated in code.
 *   - PRN generates nothing — doses are logged on demand against prn_max_per_24h.
 *   - STAT generates exactly one slot at start_datetime.
 *   - SCHEDULED generates duration_days x slots-per-day, from start_datetime.
 *   - duration_days NULL ("ongoing") generates IPD_ONGOING_HORIZON_DAYS forward.
 *     An ongoing order genuinely has no end, but a table cannot hold infinite
 *     rows, so it rolls forward — ipd_extend_ongoing_slots() tops it up.
 *
 * Two behaviours worth being explicit about:
 *
 *   1. Slots strictly BEFORE start_datetime are skipped. Ordering a TDS drug at
 *      15:00 must not back-fill the 08:00 and 14:00 doses as already due — those
 *      moments have passed and no one can give them.
 *
 *   2. On LATE approval, generation starts from approval time, not order time.
 *      A sheet written at 22:00 and signed at 09:00 next morning must not open
 *      with a backlog of overnight doses that were never given and never could
 *      have been. History is preserved by the audit trail, not by fake slots.
 *
 * INSERT IGNORE + uq_slot makes this idempotent: re-running it (a double-submit,
 * a re-approval) can never duplicate a dose row.
 *
 * Returns the number of slots created.
 */
const IPD_ONGOING_HORIZON_DAYS = 14;

function ipd_generate_slots(PDO $pdo, array $order, ?string $fromDatetime = null): int {
    if (!can_administer_order($order)) { return 0; }

    $orderId     = (int) $order['id'];
    $admissionId = (int) $order['admission_id'];
    $orderType   = $order['order_type'];
    $frequency   = $order['frequency'];

    // PRN has no schedule at all.
    if ($orderType === 'PRN' || $frequency === 'PRN') { return 0; }

    $startTs = strtotime((string) $order['start_datetime']);
    if (!$startTs) { return 0; }

    // Never create a dose in the past: the floor is the later of the order's
    // start and (on a late approval) the moment approval happened.
    $floorTs = $startTs;
    if ($fromDatetime !== null) {
        $ft = strtotime($fromDatetime);
        if ($ft && $ft > $floorTs) { $floorTs = $ft; }
    }

    $rows = [];

    if ($orderType === 'STAT' || $frequency === 'STAT') {
        // A STAT dose is "now" — one slot, at the floor, never back-dated.
        $rows[] = [date('Y-m-d H:i:s', $floorTs), 'STAT'];
    } else {
        $map   = ipd_frequency_map($pdo);
        $times = $map[$frequency]['times'] ?? [];
        if (!$times) { return 0; }

        $days = $order['duration_days'] !== null ? max(1, (int) $order['duration_days']) : IPD_ONGOING_HORIZON_DAYS;

        // Walk calendar days from the order's start date, not from the floor —
        // day 1 of a 5-day course is the start date even if approval came later.
        $dayCursor = strtotime(date('Y-m-d', $startTs));
        for ($d = 0; $d < $days; $d++) {
            $dayStr = date('Y-m-d', strtotime("+$d day", $dayCursor));
            foreach ($times as $t) {
                $slotTs = strtotime($dayStr . ' ' . $t . ':00');
                if (!$slotTs || $slotTs < $floorTs) { continue; }   // rule 1 + 2
                $rows[] = [date('Y-m-d H:i:s', $slotTs), 'SCHEDULED'];
            }
        }
    }

    if (!$rows) { return 0; }

    // INSERT IGNORE leans on uq_slot(order_id, scheduled_datetime, slot_kind).
    $ins = $pdo->prepare('
        INSERT IGNORE INTO ipd_medication_admins
            (order_id, admission_id, scheduled_datetime, slot_kind, status)
        VALUES (?, ?, ?, ?, \'PENDING\')
    ');
    $made = 0;
    foreach ($rows as [$when, $kind]) {
        $ins->execute([$orderId, $admissionId, $when, $kind]);
        $made += $ins->rowCount();
    }
    return $made;
}

/**
 * Top up the rolling horizon for ongoing (duration_days IS NULL) orders.
 *
 * Called cheaply whenever the treatment sheet is viewed. Without it, an ongoing
 * order silently runs out of slots after the horizon and simply stops appearing
 * on the nurse's due list — the drug would look finished when nobody stopped it.
 *
 * Idempotent via the same unique key, so calling it on every page view is safe.
 */
function ipd_extend_ongoing_slots(PDO $pdo, int $admissionId): void {
    try {
        $st = $pdo->prepare("
            SELECT * FROM ipd_medication_orders
            WHERE admission_id = ? AND status = 'ACTIVE' AND approval_status = 'APPROVED'
              AND duration_days IS NULL AND order_type = 'SCHEDULED'
        ");
        $st->execute([$admissionId]);
        foreach ($st->fetchAll() as $o) {
            // How far ahead is this order already covered?
            $last = $pdo->prepare('SELECT MAX(scheduled_datetime) FROM ipd_medication_admins WHERE order_id = ?');
            $last->execute([(int) $o['id']]);
            $lastTs = strtotime((string) ($last->fetchColumn() ?: '')) ?: 0;

            // Only regenerate when the tail is within 3 days of running dry.
            if ($lastTs > strtotime('+3 days')) { continue; }

            // Re-anchor the walk at the existing tail so the horizon rolls
            // forward instead of re-walking from the original start date.
            $o2 = $o;
            $o2['start_datetime'] = $lastTs ? date('Y-m-d H:i:s', $lastTs) : $o['start_datetime'];
            ipd_generate_slots($pdo, $o2, date('Y-m-d H:i:s'));
        }
    } catch (Throwable $e) {
        // A missing table must not 500 the sheet.
    }
}

/**
 * Cancel every FUTURE pending slot for an order (discontinuation, spec 5).
 *
 * Past GIVEN slots are untouched — that is the non-negotiable part. Pending
 * slots already in the past are left alone too: they are the real record that a
 * dose was due and not given, and rewriting them would erase a genuine gap in
 * care. Only doses that have not yet come due are cancelled.
 */
function ipd_cancel_future_slots(PDO $pdo, int $orderId, string $reason = ''): int {
    $st = $pdo->prepare("
        UPDATE ipd_medication_admins
        SET status = 'CANCELLED',
            hold_reason = ?
        WHERE order_id = ?
          AND status = 'PENDING'
          AND scheduled_datetime > NOW()
    ");
    $st->execute([mb_substr($reason, 0, 300) ?: 'Order discontinued', $orderId]);
    return $st->rowCount();
}

/**
 * PRN doses given in the trailing 24h — what prn_max_per_24h is checked against.
 */
function ipd_prn_given_24h(PDO $pdo, int $orderId): int {
    try {
        $st = $pdo->prepare("
            SELECT COUNT(*) FROM ipd_medication_admins
            WHERE order_id = ? AND status = 'GIVEN' AND given_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $st->execute([$orderId]);
        return (int) $st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

// -----------------------------------------------------------------------------
// Reads for the sheet
// -----------------------------------------------------------------------------

/**
 * Every order on an admission, newest first, with author / approver / stopper
 * names resolved.
 *
 * Discontinued orders are INCLUDED — the sheet shows them struck through with
 * the reason inline (spec 5). Filtering them out here would quietly hide the
 * history the whole feature is built to keep.
 */
function ipd_load_orders(PDO $pdo, int $admissionId): array {
    try {
        $st = $pdo->prepare('
            SELECT o.*,
                   pu.name AS prescribed_by_name,
                   au.name AS approved_by_name,
                   du.name AS discontinued_by_name
            FROM ipd_medication_orders o
            LEFT JOIN users pu ON pu.id = o.prescribed_by_id
            LEFT JOIN users au ON au.id = o.approved_by_id
            LEFT JOIN users du ON du.id = o.discontinued_by_id
            WHERE o.admission_id = ?
            ORDER BY FIELD(o.status, "ACTIVE", "COMPLETED", "DISCONTINUED"),
                     FIELD(o.approval_status, "PENDING", "APPROVED", "REJECTED"),
                     o.prescribed_at DESC, o.id DESC
        ');
        $st->execute([$admissionId]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Today's slots for an admission, indexed by order id.
 *
 * "Today" is the calendar day in PKT — the sheet is a day-at-a-time document and
 * a nurse reads it against the wall clock, not a rolling 24h window.
 */
function ipd_load_today_slots(PDO $pdo, int $admissionId, ?string $day = null): array {
    $day = $day ?: date('Y-m-d');
    $out = [];
    try {
        $st = $pdo->prepare('
            SELECT s.*, u.name AS given_by_name
            FROM ipd_medication_admins s
            LEFT JOIN users u ON u.id = s.given_by_id
            WHERE s.admission_id = ? AND DATE(s.scheduled_datetime) = ?
            ORDER BY s.scheduled_datetime, s.id
        ');
        $st->execute([$admissionId, $day]);
        foreach ($st->fetchAll() as $r) {
            $out[(int) $r['order_id']][] = $r;
        }
    } catch (Throwable $e) {
        return [];
    }
    return $out;
}

/**
 * PRN doses logged on a given day, indexed by order id. Kept separate from the
 * scheduled grid because PRN rows have no pre-booked time to render against.
 */
function ipd_load_prn_log(PDO $pdo, int $admissionId, ?string $day = null): array {
    $day = $day ?: date('Y-m-d');
    $out = [];
    try {
        $st = $pdo->prepare("
            SELECT s.*, u.name AS given_by_name
            FROM ipd_medication_admins s
            LEFT JOIN users u ON u.id = s.given_by_id
            WHERE s.admission_id = ? AND s.slot_kind = 'PRN' AND DATE(s.given_at) = ?
            ORDER BY s.given_at DESC
        ");
        $st->execute([$admissionId, $day]);
        foreach ($st->fetchAll() as $r) {
            $out[(int) $r['order_id']][] = $r;
        }
    } catch (Throwable $e) {
        return [];
    }
    return $out;
}

/** Formulary rows for the typeahead. */
function ipd_formulary(PDO $pdo): array {
    try {
        // dose_form comes from sql/ipd/add_formulary_dose_forms.sql. The strength
        // column still exists but is no longer captured or displayed — the dose is
        // typed at prescribing time, which is the only moment it is actually known.
        return $pdo->query('
            SELECT id, generic_name, dose_form, brand_name, brand_names,
                   drug_class, allergy_group, is_high_alert,
                   default_routes, default_frequencies, default_dose_unit
            FROM ipd_drug_formulary
            WHERE is_enabled = 1
            ORDER BY generic_name, dose_form
        ')->fetchAll();
    } catch (Throwable $e) {
        // dose_form missing -> the dose-form migration has not run yet.
        // Fall back to the pre-migration columns rather than returning [], which
        // would empty the drug picker entirely and read as "the formulary is
        // broken" instead of "one migration is outstanding".
        try {
            $rows = $pdo->query('
                SELECT id, generic_name, brand_name, brand_names, drug_class, allergy_group,
                       is_high_alert, default_routes, default_frequencies, default_dose_unit
                FROM ipd_drug_formulary
                WHERE is_enabled = 1
                ORDER BY generic_name
            ')->fetchAll();
            foreach ($rows as &$r) { $r['dose_form'] = ''; }
            return $rows;
        } catch (Throwable $e2) {
            return [];
        }
    }
}

/** One formulary row by id, or null. */
function ipd_formulary_drug(PDO $pdo, int $id): ?array {
    if ($id <= 0) { return null; }
    try {
        $st = $pdo->prepare('SELECT * FROM ipd_drug_formulary WHERE id = ? AND is_enabled = 1');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Full audit trail for one order, oldest first. */
function ipd_order_audit(PDO $pdo, int $orderId): array {
    try {
        $st = $pdo->prepare('
            SELECT a.*, u.name AS performed_by_name
            FROM ipd_medication_audit a
            LEFT JOIN users u ON u.id = a.performed_by_id
            WHERE a.entity_type = "ORDER" AND a.entity_id = ?
            ORDER BY a.performed_at, a.id
        ');
        $st->execute([$orderId]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

// -----------------------------------------------------------------------------
// Display helpers
// -----------------------------------------------------------------------------

/** "500 mg PO TDS" — dose/route/frequency without the drug name. */
function ipd_dose_line(array $o): string {
    $dose = rtrim(rtrim(number_format((float) $o['dose_value'], 3, '.', ''), '0'), '.');
    return $dose . ' ' . $o['dose_unit'] . ' ' . $o['route'] . ' ' . $o['frequency'];
}

/**
 * "AMOXICILLIN (Amoxil) 500 mg PO TDS" — the one-line signature used in
 * notifications, the audit trail and the discharge script.
 *
 * Prefers the split generic/brand columns and falls back to drug_name_snapshot,
 * so a row written before the split still renders.
 */
function ipd_order_line(array $o): string {
    $generic = trim((string) ($o['generic_name_snapshot'] ?? ''));
    $name = $generic !== ''
        ? ipd_display_drug_name($generic, $o['brand_name_snapshot'] ?? null)
        : (string) ($o['drug_name_snapshot'] ?? '');
    // The dose form disambiguates the product where the route alone cannot:
    // paracetamol 1 g IV and paracetamol 1 g PR are different things a nurse
    // physically picks up. Omitted when unknown (pre-migration rows).
    $form = trim((string) ($o['dose_form_snapshot'] ?? ''));
    if ($form !== '') { $name .= ' ' . $form; }
    return $name . ' ' . ipd_dose_line($o);
}

/** Slot status -> [label, css class]. */
function ipd_slot_badge(string $status): array {
    return [
        'PENDING'   => ['Due',       'ts-slot-pending'],
        'GIVEN'     => ['Given',     'ts-slot-given'],
        'HELD'      => ['Held',      'ts-slot-held'],
        'MISSED'    => ['Missed',    'ts-slot-missed'],
        'CANCELLED' => ['Cancelled', 'ts-slot-cancelled'],
    ][$status] ?? [$status, 'ts-slot-pending'];
}
