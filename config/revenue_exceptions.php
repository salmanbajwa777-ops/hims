<?php
/**
 * Revenue Exceptions — read-only detectors for SHORT-STAY ER admissions.
 *
 * Shared by exceptions_report.php (the page) and cron/daily_summary.php (the
 * email section) so the two can never disagree about what counts as an
 * exception. Nothing here writes. Nothing here proves wrongdoing: every list is
 * "a record that needs a look".
 *
 * SCHEMA SAFETY
 *   Every detector checks information_schema for the tables/columns it reads
 *   and, if one is missing, returns an empty list plus a note instead of
 *   throwing — the page must never 500 on a database that is a migration
 *   behind. Later-phase columns (manual discount, approvals, a "left before
 *   treatment" outcome) are simply not read until they exist.
 *
 * DATES
 *   Ranges are YYYY-MM-DD, PKT. config/permissions.php pins the MySQL session
 *   to +05:00, so DATE(col) is the PKT calendar day for TIMESTAMP columns (MySQL
 *   converts on read) and for DATETIME columns (literal wall clock) alike. No
 *   interval arithmetic or CONVERT_TZ here — adding one would double-correct
 *   the TIMESTAMP columns.
 *
 * UNKNOWN IS NOT A PERSON
 *   A row whose actor column is NULL (pre-migration data, deleted user) is
 *   attributed to RX_UNKNOWN, never to whoever happened to be nearby.
 */

const RX_UNKNOWN = 'Unknown (not recorded)';
/** Evidence of care/payment this many minutes before admitted_at makes a late-admission candidate. */
const RX_LATE_GAP_MIN = 30;
/** ER-bill payments up to this many hours before admission are linked to it. */
const RX_ER_LOOKBACK_H = 24;

/** Does every named column exist on the table? (cached per table) */
function rx_has_cols(PDO $pdo, string $table, array $cols = []): bool
{
    static $store = null;
    $store = $store ?? new SplObjectStorage();
    if (!isset($store[$pdo])) { $store[$pdo] = []; }
    $cache = $store[$pdo];
    if (!isset($cache[$table])) {
        $cache[$table] = [];
        try {
            $st = $pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?');
            $st->execute([$table]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) {
                $cache[$table][strtolower((string) $c)] = true;
            }
        } catch (Throwable $e) {
            $cache[$table] = [];
        }
        $store[$pdo] = $cache;
    }
    if (!$cache[$table]) { return false; }          // table absent
    foreach ($cols as $c) {
        if (!isset($cache[$table][strtolower($c)])) { return false; }
    }
    return true;
}

function rx_upper($s): string
{
    $s = (string) $s;
    return function_exists('mb_strtoupper') ? mb_strtoupper($s) : strtoupper($s);
}

/** Display label for a possibly-NULL user name. */
function rx_user($name): string
{
    return ($name === null || $name === '') ? RX_UNKNOWN : (string) $name;
}

/**
 * Run every detector for [$from, $to] (inclusive, YYYY-MM-DD).
 * $now is injectable (unix time) so tests are deterministic.
 */
function rx_collect(PDO $pdo, string $from, string $to, ?int $now = null): array
{
    $now = $now ?? time();
    $out = [
        'short' => [], 'zero' => [], 'unbilled_closed' => [], 'unbilled_active' => [],
        'voids' => [], 'admit' => [], 'removed' => [], 'notes' => [],
        'approvals_pending' => [], 'reception' => [], 'ho_unaccepted' => [], 'ho_orphans' => [],
    ];
    $steps = [
        'short'    => 'rx_short_payments',
        'zero'     => 'rx_zero_or_discounted',
        'voids'    => 'rx_voids_and_waivers',
        'removed'  => 'rx_removed_services',
    ];
    foreach ($steps as $k => $fn) {
        try { $out[$k] = $fn($pdo, $from, $to, $out['notes']); }
        catch (Throwable $e) { $out['notes'][] = "$k check skipped (query failed)."; }
    }
    try {
        $u = rx_unbilled($pdo, $from, $to, $now, $out['notes']);
        $out['unbilled_closed'] = $u['closed'];
        $out['unbilled_active'] = $u['active'];
    } catch (Throwable $e) { $out['notes'][] = 'unbilled check skipped (query failed).'; }
    try { $out['admit'] = rx_admit_timing($pdo, $from, $to, $out['notes']); }
    catch (Throwable $e) { $out['notes'][] = 'admission timing check skipped (query failed).'; }
    try { $out['approvals_pending'] = rx_pending_approvals($pdo, $now, $out['notes']); }
    catch (Throwable $e) { $out['notes'][] = 'pending-approvals check skipped (query failed).'; }
    try { $out['reception'] = rx_reception_admits($pdo, $from, $to, $out['notes']); }
    catch (Throwable $e) { $out['notes'][] = 'reception-admit check skipped (query failed).'; }
    try {
        $h = rx_handover_cards($pdo, $out['notes']);
        $out['ho_unaccepted'] = $h['unaccepted'];
        $out['ho_orphans'] = $h['orphans'];
    } catch (Throwable $e) { $out['notes'][] = 'hand-over check skipped (query failed).'; }
    return $out;
}

/** Common patient join for admission-based rows. */
const RX_ADM_PATIENT_JOIN = ' JOIN visits v ON v.id = a.visit_id JOIN patients p ON p.id = v.patient_id ';

// ---- approvals + draft helpers ------------------------------------------------

/**
 * Latest approval row per (admission, kind): [admission_id][kind] => row with
 * status/decided_at/decider name. [] when the approvals table is not migrated,
 * so every caller degrades to "no approval info" instead of failing.
 */
function rx_approval_lookup(PDO $pdo): array
{
    if (!rx_has_cols($pdo, 'admission_bill_approvals', ['id', 'admission_id', 'kind', 'status', 'decided_by_id', 'decided_at'])) {
        return [];
    }
    $rows = $pdo->query("
        SELECT x.id, x.admission_id, x.kind, x.status, x.decided_at, u.name AS decider
        FROM admission_bill_approvals x
        LEFT JOIN users u ON u.id = x.decided_by_id
        WHERE x.id = (SELECT MAX(y.id) FROM admission_bill_approvals y
                      WHERE y.admission_id = x.admission_id AND y.kind = x.kind)")->fetchAll(PDO::FETCH_ASSOC);
    $m = [];
    foreach ($rows as $r) { $m[(int) $r['admission_id']][(string) $r['kind']] = $r; }
    return $m;
}

/** Newest approval of any listed kind for the admission, or null. */
function rx_approval_for(array $lookup, int $admissionId, array $kinds): ?array
{
    $best = null;
    foreach ($kinds as $k) {
        $r = $lookup[$admissionId][$k] ?? null;
        if ($r && ($best === null || (int) $r['id'] > (int) $best['id'])) { $best = $r; }
    }
    return $best;
}

/**
 * SQL predicate (on alias ab): "this bill row is only the empty draft raised at
 * admit" - draft, nothing paid, no totals, no lines, lines never seeded.
 * Pre-migration (no items_seeded_at) it is the constant false, so the detectors
 * keep the old rule: ANY bill row counts as a bill. Rows that existed when the
 * migration ran are backfilled items_seeded_at = created_at, so they stay
 * "billed" too; only drafts raised at admit since then can match.
 */
function rx_empty_draft_sql(PDO $pdo, string $ab = 'ab'): string
{
    if (!rx_has_cols($pdo, 'admission_bills', ['items_seeded_at', 'status', 'grand_total', 'subtotal', 'paid_amount'])) {
        return '(1 = 0)';
    }
    $items = rx_has_cols($pdo, 'admission_bill_items', ['admission_bill_id'])
        ? " AND NOT EXISTS (SELECT 1 FROM admission_bill_items bi WHERE bi.admission_bill_id = $ab.id)" : '';
    return "($ab.status = 'draft' AND $ab.items_seeded_at IS NULL AND COALESCE($ab.paid_amount, 0) = 0"
         . " AND COALESCE($ab.grand_total, 0) = 0 AND COALESCE($ab.subtotal, 0) = 0$items)";
}

/**
 * Earliest earlier clinical/ER event for a patient in the RX_ER_LOOKBACK_H hours
 * before $atTs. Shared by the admit handler (live gap at admit time) and the
 * late-admission detector so both use the same signals.
 *   'er'    - er_bills.paid_at (not voided)
 *   'visit' - a real doctor-consult visit created in the window: started, or
 *             with a live bill. Visit SHELLS (made only to anchor an admit)
 *             carry neither, so they never count as evidence.
 * $excludeVisitId: the visit being admitted right now (it carries a registration bill, so
 * it would otherwise be its own "earlier" evidence); null for the detector.
 * Returns ['first' => datetime, 'source' => label, 'gap_min' => int] or null.
 */
function rx_pre_admit_evidence(PDO $pdo, int $patientId, int $atTs, array $signals = ['er', 'visit'], ?int $excludeVisitId = null): ?array
{
    if ($patientId <= 0 || $atTs <= 0) { return null; }
    $lo = date('Y-m-d H:i:s', $atTs - RX_ER_LOOKBACK_H * 3600);
    $hi = date('Y-m-d H:i:s', $atTs);
    $c = [];
    if (in_array('er', $signals, true) && rx_has_cols($pdo, 'er_bills', ['patient_id', 'paid_at', 'status'])) {
        $st = $pdo->prepare("SELECT MIN(paid_at) FROM er_bills
            WHERE patient_id = ? AND paid_at IS NOT NULL AND status <> 'voided' AND paid_at >= ? AND paid_at < ?");
        $st->execute([$patientId, $lo, $hi]);
        $t = $st->fetchColumn();
        if ($t) { $c['ER BILL PAID'] = $t; }
    }
    if (in_array('visit', $signals, true) && rx_has_cols($pdo, 'visits', ['patient_id', 'created_at'])) {
        $started = rx_has_cols($pdo, 'visits', ['started_at']) ? 'v.started_at IS NOT NULL' : '1 = 0';
        $billed  = rx_has_cols($pdo, 'bills', ['visit_id'])
            ? 'EXISTS (SELECT 1 FROM bills b WHERE b.visit_id = v.id' . (rx_has_cols($pdo, 'bills', ['voided_at']) ? ' AND b.voided_at IS NULL' : '') . ')'
            : '1 = 0';
        $excl = ($excludeVisitId !== null && $excludeVisitId > 0) ? ' AND v.id <> ' . (int) $excludeVisitId : '';   // the visit being admitted is not "earlier" evidence
        $st = $pdo->prepare("SELECT MIN(v.created_at) FROM visits v
            WHERE v.patient_id = ? AND v.created_at >= ? AND v.created_at < ? AND ($started OR $billed)$excl");
        $st->execute([$patientId, $lo, $hi]);
        $t = $st->fetchColumn();
        if ($t) { $c['DOCTOR VISIT'] = $t; }
    }
    $src = null; $first = null;
    foreach ($c as $k => $t) {
        if ($first === null || strtotime((string) $t) < strtotime((string) $first)) { $first = $t; $src = $k; }
    }
    if ($first === null) { return null; }
    return ['first' => $first, 'source' => $src, 'gap_min' => (int) round(($atTs - strtotime((string) $first)) / 60)];
}

// ---- (1) finalised / paid bills where paid < total ---------------------------
function rx_short_payments(PDO $pdo, string $from, string $to, array &$notes): array
{
    $need = ['id', 'admission_id', 'invoice_number', 'grand_total', 'status', 'paid_amount', 'paid_at', 'created_at', 'created_by_id'];
    if (!rx_has_cols($pdo, 'admission_bills', $need) || !rx_has_cols($pdo, 'admissions', ['id', 'visit_id'])) {
        $notes[] = 'Short-payment check skipped: admission billing tables are not set up.';
        return [];
    }
    $wo   = rx_has_cols($pdo, 'admission_bills', ['write_off_amount']) ? 'COALESCE(ab.write_off_amount, 0)' : '0';
    $void = rx_has_cols($pdo, 'admission_bills', ['voided_at']) ? ' AND ab.voided_at IS NULL' : '';
    $fin  = rx_has_cols($pdo, 'admission_bills', ['finalized_by_id']) ? 'COALESCE(ab.finalized_by_id, ab.created_by_id)' : 'ab.created_by_id';
    $st = $pdo->prepare("
        SELECT ab.id AS bill_id, ab.admission_id, ab.invoice_number, ab.status,
               ab.grand_total AS total, COALESCE(ab.paid_amount, 0) AS paid, $wo AS written_off,
               COALESCE(ab.paid_at, ab.created_at) AS at_time,
               p.name AS patient_name, p.mrn, u.id AS user_id, u.name AS user_name
        FROM admission_bills ab
        JOIN admissions a ON a.id = ab.admission_id
        " . RX_ADM_PATIENT_JOIN . "
        LEFT JOIN users u ON u.id = $fin
        WHERE ab.status IN ('finalized','paid') $void
          AND ab.grand_total > 0.009
          AND COALESCE(ab.paid_amount, 0) < ab.grand_total - 0.009
          AND DATE(COALESCE(ab.paid_at, ab.created_at)) BETWEEN ? AND ?
        ORDER BY at_time, ab.id");
    $st->execute([$from, $to]);
    $rows = [];
    $appr = rx_approval_lookup($pdo);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ap = rx_approval_for($appr, (int) $r['admission_id'], ['SHORT_PAYMENT']);
        $r['approval_status'] = $ap ? $ap['status'] : null;
        $r['approved_by'] = $ap ? $ap['decider'] : null;
        $r['short'] = (float) $r['total'] - (float) $r['paid'];
        $r['unresolved'] = max(0.0, $r['short'] - (float) $r['written_off']);
        $r['user_name'] = rx_user($r['user_name']);
        $rows[] = $r;
    }
    return $rows;
}

// ---- (2) finalised at total 0, or carrying any discount ----------------------
function rx_zero_or_discounted(PDO $pdo, string $from, string $to, array &$notes): array
{
    $need = ['id', 'admission_id', 'invoice_number', 'grand_total', 'subtotal', 'status', 'paid_at', 'created_at', 'created_by_id'];
    if (!rx_has_cols($pdo, 'admission_bills', $need) || !rx_has_cols($pdo, 'admissions', ['id', 'visit_id'])) {
        $notes[] = 'Zero-total / discount check skipped: admission billing tables are not set up.';
        return [];
    }
    $void = rx_has_cols($pdo, 'admission_bills', ['voided_at']) ? ' AND ab.voided_at IS NULL' : '';
    $fin  = rx_has_cols($pdo, 'admission_bills', ['finalized_by_id']) ? 'COALESCE(ab.finalized_by_id, ab.created_by_id)' : 'ab.created_by_id';
    $catD = rx_has_cols($pdo, 'admission_bills', ['discount_amount']) ? 'COALESCE(ab.discount_amount, 0)' : '0';
    $manD = rx_has_cols($pdo, 'admission_bills', ['manual_discount_amount']) ? 'COALESCE(ab.manual_discount_amount, 0)' : '0';
    if ($manD === '0') {
        $notes[] = 'Manual-discount column is not migrated yet: manual discounts cannot be detected.';
    }
    if ($catD === '0') {
        $notes[] = 'Category-discount column is not migrated yet: category discounts cannot be detected.';
    }
    $st = $pdo->prepare("
        SELECT ab.id AS bill_id, ab.admission_id, ab.invoice_number, ab.status,
               ab.subtotal, ab.grand_total AS total, $catD AS cat_discount, $manD AS manual_discount,
               COALESCE(ab.paid_at, ab.created_at) AS at_time,
               p.name AS patient_name, p.mrn, u.id AS user_id, u.name AS user_name
        FROM admission_bills ab
        JOIN admissions a ON a.id = ab.admission_id
        " . RX_ADM_PATIENT_JOIN . "
        LEFT JOIN users u ON u.id = $fin
        WHERE ab.status IN ('finalized','paid') $void
          AND (ab.grand_total <= 0.009 OR $catD > 0.009 OR $manD > 0.009)
          AND DATE(COALESCE(ab.paid_at, ab.created_at)) BETWEEN ? AND ?
        ORDER BY at_time, ab.id");
    $st->execute([$from, $to]);
    $rows = [];
    $appr = rx_approval_lookup($pdo);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ap = rx_approval_for($appr, (int) $r['admission_id'], ['ZERO_OR_DISCOUNT', 'LEFT_BEFORE_TREATMENT']);
        $r['approval_status'] = $ap ? $ap['status'] : null;
        $r['approval_kind'] = $ap ? $ap['kind'] : null;
        $r['approved_by'] = $ap ? $ap['decider'] : null;
        $flags = [];
        if ((float) $r['total'] <= 0.009) { $flags[] = 'TOTAL RS 0'; }
        if ((float) $r['manual_discount'] > 0.009) { $flags[] = 'MANUAL DISCOUNT'; }
        if ((float) $r['cat_discount'] > 0.009) { $flags[] = 'CATEGORY DISCOUNT'; }
        $r['flags'] = $flags;
        $r['discount'] = (float) $r['cat_discount'] + (float) $r['manual_discount'];
        $r['user_name'] = rx_user($r['user_name']);
        $rows[] = $r;
    }
    return $rows;
}

// ---- (3)+(4) admissions with no bill row ------------------------------------
function rx_unbilled(PDO $pdo, string $from, string $to, int $now, array &$notes): array
{
    $res = ['closed' => [], 'active' => []];
    if (!rx_has_cols($pdo, 'admissions', ['id', 'visit_id', 'status', 'admitted_at', 'admitted_by_id'])
        || !rx_has_cols($pdo, 'admission_bills', ['id', 'admission_id'])) {
        $notes[] = 'Unbilled-admission check skipped: admission tables are not set up.';
        return $res;
    }
    $disc = rx_has_cols($pdo, 'admissions', ['discharged_at']) ? 'COALESCE(a.discharged_at, a.admitted_at)' : 'a.admitted_at';
    $base = "
        SELECT a.id AS admission_id, a.status, a.admitted_at, $disc AS closed_at,
               p.name AS patient_name, p.mrn, u.id AS user_id, u.name AS user_name
        FROM admissions a
        " . RX_ADM_PATIENT_JOIN . "
        LEFT JOIN users u ON u.id = a.admitted_by_id
        WHERE NOT EXISTS (SELECT 1 FROM admission_bills ab WHERE ab.admission_id = a.id AND NOT " . rx_empty_draft_sql($pdo) . ")";
    $st = $pdo->prepare($base . " AND a.status IN ('DISCHARGE_IN_PROGRESS','DISCHARGED') AND DATE($disc) BETWEEN ? AND ? ORDER BY closed_at");
    $st->execute([$from, $to]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $t0 = strtotime((string) $r['admitted_at']); $t1 = strtotime((string) $r['closed_at']);
        $r['hours_open'] = ($t0 && $t1) ? round(max(0, $t1 - $t0) / 3600, 1) : null;
        $r['user_name'] = rx_user($r['user_name']);
        $res['closed'][] = $r;
    }
    // Open right now — a live snapshot, deliberately NOT range-filtered.
    foreach ($pdo->query($base . " AND a.status = 'ACTIVE' ORDER BY a.admitted_at")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $t = strtotime((string) $r['admitted_at']);
        $r['hours_open'] = $t ? round(max(0, $now - $t) / 3600, 1) : null;
        $r['user_name'] = rx_user($r['user_name']);
        $res['active'][] = $r;
    }
    return $res;
}

// ---- (5) voided / waived bills ----------------------------------------------
function rx_voids_and_waivers(PDO $pdo, string $from, string $to, array &$notes): array
{
    $rows = [];
    // ER bills: voided, and waived (Rs 0).
    if (rx_has_cols($pdo, 'er_bills', ['id', 'invoice_number', 'patient_id', 'status', 'grand_total', 'subtotal', 'created_at', 'created_by_id'])) {
        $hasVoid = rx_has_cols($pdo, 'er_bills', ['voided_at', 'voided_by_id', 'void_reason']);
        $paidAt  = rx_has_cols($pdo, 'er_bills', ['paid_at']) ? 'COALESCE(b.paid_at, b.created_at)' : 'b.created_at';
        if ($hasVoid) {
            $st = $pdo->prepare("
                SELECT 'ER BILL VOIDED' AS kind, b.invoice_number, b.grand_total AS amount, b.void_reason AS reason,
                       b.voided_at AS at_time, p.name AS patient_name, p.mrn, u.id AS user_id, u.name AS user_name
                FROM er_bills b JOIN patients p ON p.id = b.patient_id
                LEFT JOIN users u ON u.id = b.voided_by_id
                WHERE (b.voided_at IS NOT NULL OR b.status = 'voided')
                  AND DATE(COALESCE(b.voided_at, b.created_at)) BETWEEN ? AND ?");
        } else {
            $notes[] = 'ER bill void columns are missing: voided ER bills are listed without who/when.';
            $st = $pdo->prepare("
                SELECT 'ER BILL VOIDED' AS kind, b.invoice_number, b.grand_total AS amount, NULL AS reason,
                       b.created_at AS at_time, p.name AS patient_name, p.mrn, NULL AS user_id, NULL AS user_name
                FROM er_bills b JOIN patients p ON p.id = b.patient_id
                WHERE b.status = 'voided' AND DATE(b.created_at) BETWEEN ? AND ?");
        }
        $st->execute([$from, $to]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $rows[] = $r; }

        $notVoid = $hasVoid ? ' AND b.voided_at IS NULL' : '';
        $st = $pdo->prepare("
            SELECT 'ER BILL WAIVED (RS 0)' AS kind, b.invoice_number, b.subtotal AS amount, NULL AS reason,
                   $paidAt AS at_time, p.name AS patient_name, p.mrn, u.id AS user_id, u.name AS user_name
            FROM er_bills b JOIN patients p ON p.id = b.patient_id
            LEFT JOIN users u ON u.id = b.created_by_id
            WHERE b.status = 'waived' $notVoid AND DATE($paidAt) BETWEEN ? AND ?");
        $st->execute([$from, $to]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $rows[] = $r; }
    } else {
        $notes[] = 'ER bill check skipped: er_bills is not set up.';
    }
    // Admission bills voided.
    if (rx_has_cols($pdo, 'admission_bills', ['id', 'admission_id', 'invoice_number', 'grand_total', 'voided_at', 'voided_by_id', 'void_reason'])
        && rx_has_cols($pdo, 'admissions', ['id', 'visit_id'])) {
        $st = $pdo->prepare("
            SELECT 'ADMISSION BILL VOIDED' AS kind, ab.invoice_number, ab.grand_total AS amount, ab.void_reason AS reason,
                   ab.voided_at AS at_time, p.name AS patient_name, p.mrn, u.id AS user_id, u.name AS user_name
            FROM admission_bills ab JOIN admissions a ON a.id = ab.admission_id " . RX_ADM_PATIENT_JOIN . "
            LEFT JOIN users u ON u.id = ab.voided_by_id
            WHERE ab.voided_at IS NOT NULL AND DATE(ab.voided_at) BETWEEN ? AND ?");
        $st->execute([$from, $to]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $rows[] = $r; }
    } else {
        $notes[] = 'Admission-bill void check skipped: void columns are not migrated.';
    }
    // Other (consultation) bills voided.
    if (rx_has_cols($pdo, 'bills', ['id', 'visit_id', 'invoice_number', 'grand_total', 'voided_at', 'voided_by_id', 'void_reason'])
        && rx_has_cols($pdo, 'visits', ['id', 'patient_id'])) {
        $st = $pdo->prepare("
            SELECT 'CONSULT BILL VOIDED' AS kind, b.invoice_number, b.grand_total AS amount, b.void_reason AS reason,
                   b.voided_at AS at_time, p.name AS patient_name, p.mrn, u.id AS user_id, u.name AS user_name
            FROM bills b JOIN visits v ON v.id = b.visit_id JOIN patients p ON p.id = v.patient_id
            LEFT JOIN users u ON u.id = b.voided_by_id
            WHERE b.voided_at IS NOT NULL AND DATE(b.voided_at) BETWEEN ? AND ?");
        $st->execute([$from, $to]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $rows[] = $r; }
    }
    foreach ($rows as &$r) { $r['user_name'] = rx_user($r['user_name']); }
    unset($r);
    usort($rows, fn($a, $b) => strcmp((string) $a['at_time'], (string) $b['at_time']));
    return $rows;
}

// ---- (6) who admitted, and did care/payment precede the admit clock? --------
function rx_admit_timing(PDO $pdo, string $from, string $to, array &$notes): array
{
    if (!rx_has_cols($pdo, 'admissions', ['id', 'visit_id', 'admitted_at', 'admitted_by_id', 'admitted_by_role'])) {
        $notes[] = 'Admission timing check skipped: admissions columns are missing.';
        return [];
    }
    $hasVit = rx_has_cols($pdo, 'admission_vitals', ['admission_id', 'recorded_at']);
    $hasSvc = rx_has_cols($pdo, 'admission_services', ['admission_id', 'logged_at']);
    $hasEr  = rx_has_cols($pdo, 'er_bills', ['patient_id', 'paid_at', 'status']);
    if (!$hasVit) { $notes[] = 'No vitals table: vitals are not used as admission-time evidence.'; }
    if (!$hasSvc) { $notes[] = 'No services table: services are not used as admission-time evidence.'; }
    if (!$hasEr)  { $notes[] = 'No ER-bill payment times: ER bills are not used as admission-time evidence.'; }

    $vitSql = $hasVit ? '(SELECT MIN(x.recorded_at) FROM admission_vitals x WHERE x.admission_id = a.id)' : 'NULL';
    $svcSql = $hasSvc ? '(SELECT MIN(x.logged_at) FROM admission_services x WHERE x.admission_id = a.id)' : 'NULL';
    $st = $pdo->prepare("
        SELECT a.id AS admission_id, a.admitted_at, a.admitted_by_role AS role, v.patient_id,
               p.name AS patient_name, p.mrn, u.id AS user_id, u.name AS user_name,
               $vitSql AS first_vitals, $svcSql AS first_service
        FROM admissions a " . RX_ADM_PATIENT_JOIN . "
        LEFT JOIN users u ON u.id = a.admitted_by_id
        WHERE DATE(a.admitted_at) BETWEEN ? AND ?
        ORDER BY a.admitted_at, a.id");
    $st->execute([$from, $to]);

    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $adm = strtotime((string) $r['admitted_at']);
        $cands = [];
        if ($r['first_vitals']) { $cands['VITALS'] = $r['first_vitals']; }
        if ($r['first_service']) { $cands['SERVICE'] = $r['first_service']; }
        if ($hasEr && $adm) {
            // Same helper the admit handler uses; 'er' only here so this
            // detector's meaning is unchanged (vitals/services cover the rest).
            $ev = rx_pre_admit_evidence($pdo, (int) $r['patient_id'], $adm, ['er']);
            if ($ev) { $cands['ER BILL PAID'] = $ev['first']; }
        }
        $src = null; $first = null;
        foreach ($cands as $k => $t) {
            if ($first === null || strtotime((string) $t) < strtotime((string) $first)) { $first = $t; $src = $k; }
        }
        // Positive gap = evidence is EARLIER than the admit clock.
        $gap = ($first !== null && $adm) ? (int) round(($adm - strtotime((string) $first)) / 60) : null;
        $r['first_evidence'] = $first;
        $r['evidence_source'] = $src;
        $r['gap_min'] = $gap;
        $r['late_candidate'] = $gap !== null && $gap > RX_LATE_GAP_MIN;
        $r['user_name'] = rx_user($r['user_name']);
        $rows[] = $r;
    }
    return $rows;
}

// ---- (7) hard-deleted services: the audit trail is all that is left ---------
function rx_removed_services(PDO $pdo, string $from, string $to, array &$notes): array
{
    if (!rx_has_cols($pdo, 'audit_logs', ['action', 'details', 'created_at', 'user_id'])) {
        $notes[] = 'Removed-service check skipped: audit_logs is not available.';
        return [];
    }
    // 'admission_service_removed' (pre-migration hard delete) and 'admission_service_voided' (soft void, adm_void_service) are what the short stay writes.
    // IPD writes 'ipd_service_removed' — deliberately not matched here.
    $st = $pdo->prepare("
        SELECT l.id, l.action, l.details, l.created_at AS at_time, u.id AS user_id, u.name AS user_name
        FROM audit_logs l LEFT JOIN users u ON u.id = l.user_id
        WHERE l.action IN ('admission_service_removed', 'admission_service_voided', 'remove_service')
          AND DATE(l.created_at) BETWEEN ? AND ?
        ORDER BY l.created_at, l.id");
    $st->execute([$from, $to]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['user_name'] = rx_user($r['user_name']); }
    unset($r);
    return $rows;
}

// ---- (8) approvals still waiting for a manager/admin -------------------------
// Live snapshot, not range-filtered: a request nobody has decided is open now.
function rx_pending_approvals(PDO $pdo, int $now, array &$notes): array
{
    if (!rx_has_cols($pdo, 'admission_bill_approvals', ['id', 'admission_id', 'kind', 'reason', 'requested_by_id', 'requested_at', 'status'])
        || !rx_has_cols($pdo, 'admissions', ['id', 'visit_id'])) {
        $notes[] = 'Pending-approvals card skipped: the approvals table is not set up yet.';
        return [];
    }
    $st = $pdo->query("
        SELECT x.id AS approval_id, x.admission_id, x.kind, x.reason, x.requested_at,
               p.name AS patient_name, p.mrn, u.id AS user_id, u.name AS user_name
        FROM admission_bill_approvals x
        JOIN admissions a ON a.id = x.admission_id " . RX_ADM_PATIENT_JOIN . "
        LEFT JOIN users u ON u.id = x.requested_by_id
        WHERE x.status = 'PENDING'
        ORDER BY x.requested_at, x.id");
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $t = strtotime((string) $r['requested_at']);
        $r['age_hours'] = $t ? round(max(0, $now - $t) / 3600, 1) : null;
        $r['user_name'] = rx_user($r['user_name']);
        $rows[] = $r;
    }
    return $rows;
}

// ---- (9) admissions made by reception, with the reason they gave --------------
// Only rows that carry a recorded reason are listed. Admissions from before the
// reason was required have none; they are NOT listed, so "no reason recorded" is
// never read as "reception admitted without a reason".
function rx_reception_admits(PDO $pdo, string $from, string $to, array &$notes): array
{
    if (!rx_has_cols($pdo, 'admissions', ['id', 'visit_id', 'admitted_at', 'admitted_by_id', 'reception_admit_reason'])) {
        $notes[] = 'Admitted-by-reception card skipped: the reason column is not migrated yet (sql/2026_10_admission_reception_reason.sql).';
        return [];
    }
    $gap = rx_has_cols($pdo, 'admissions', ['admit_gap_minutes']) ? 'a.admit_gap_minutes' : 'NULL';
    $doc = rx_has_cols($pdo, 'admissions', ['admitting_doctor_id', 'admitting_doctor_manual'])
        ? 'COALESCE(du.name, a.admitting_doctor_manual)' : 'NULL';
    $docJoin = rx_has_cols($pdo, 'admissions', ['admitting_doctor_id']) ? 'LEFT JOIN users du ON du.id = a.admitting_doctor_id' : '';
    if ($docJoin === '') { $doc = 'NULL'; }
    $st = $pdo->prepare("
        SELECT a.id AS admission_id, a.admitted_at, a.reception_admit_reason AS reason, $gap AS gap_min,
               $doc AS doctor_name, p.name AS patient_name, p.mrn, u.id AS user_id, u.name AS user_name
        FROM admissions a " . RX_ADM_PATIENT_JOIN . "
        LEFT JOIN users u ON u.id = a.admitted_by_id
        $docJoin
        WHERE a.reception_admit_reason IS NOT NULL AND a.reception_admit_reason <> ''
          AND DATE(a.admitted_at) BETWEEN ? AND ?
        ORDER BY a.admitted_at, a.id");
    $st->execute([$from, $to]);
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $r['user_name'] = rx_user($r['user_name']);
        $r['gap_min'] = $r['gap_min'] === null ? null : (int) $r['gap_min'];
        $r['late'] = $r['gap_min'] !== null && $r['gap_min'] > RX_LATE_GAP_MIN;
        // Bucket "Other: free text" under Other for the per-person counts.
        $r['reason_key'] = strncmp((string) $r['reason'], 'Other', 5) === 0 ? 'Other' : (string) $r['reason'];
        $rows[] = $r;
    }
    return $rows;
}

/** Per-person reason counts for the reception card: [name => [reason_key => n, '_total' => n, '_late' => n]]. */
function rx_reception_summary(array $rows): array
{
    $m = [];
    foreach ($rows as $r) {
        $n = $r['user_name'];
        if (!isset($m[$n])) { $m[$n] = ['_total' => 0, '_late' => 0]; }
        $m[$n][$r['reason_key']] = ($m[$n][$r['reason_key']] ?? 0) + 1;
        $m[$n]['_total']++;
        if ($r['late']) { $m[$n]['_late']++; }
    }
    uasort($m, fn($a, $b) => $b['_total'] <=> $a['_total']);
    return $m;
}

// ---- (10) hand-over cards (config/handover_helpers.php) -----------------------
// Both lists are empty until sql/add_admission_reception_handovers.sql is run.
function rx_handover_cards(PDO $pdo, array &$notes): array
{
    $res = ['unaccepted' => [], 'orphans' => []];
    if (!function_exists('pending_handovers_unaccepted')) {
        $f = __DIR__ . '/handover_helpers.php';
        if (!is_file($f)) { $notes[] = 'Hand-over cards skipped: helper library missing.'; return $res; }
        require_once $f;
    }
    if (!ho_ready($pdo)) {
        $notes[] = 'Hand-over cards are empty: the hand-over table is not migrated yet.';
        return $res;
    }
    $res['unaccepted'] = pending_handovers_unaccepted($pdo, true);
    // orphan_open_admissions() needs the drawer-holder ids. If they cannot be
    // read, skip: an EMPTY list would make every open admission look orphaned.
    try {
        if (!function_exists('receptionists_with_drawer')) { require_once __DIR__ . '/billing.php'; }
        $ids = array_map(fn($r) => (int) $r['id'], receptionists_with_drawer($pdo));
        if (!$ids) {
            // No drawer holder found means the lookup is suspect (or nobody can close):
            // judging every stay "orphaned" against an empty list would be a false alarm.
            $notes[] = 'Orphaned-admission card skipped: no cash-drawer holders were found, so ownership cannot be judged.';
        } else {
            $res['orphans'] = orphan_open_admissions($pdo, $ids);
        }
    } catch (Throwable $e) {
        $notes[] = 'Orphaned-admission card skipped: the list of cash-drawer holders could not be read.';
    }
    return $res;
}

/**
 * Per-user counts across every detector, for the "patterns" table.
 * Returns [name => [counter => n, ...]] sorted by total desc; RX_UNKNOWN kept
 * as its own row, never merged into a named person.
 */
function rx_user_matrix(array $d): array
{
    $cols = [
        'short'   => 'Short paid',
        'zero'    => 'Zero / discounted',
        'unbilled'=> 'Unbilled admissions',
        'voids'   => 'Voids',
        'waived'  => 'ER waived',
        'removed' => 'Services removed',
        'late'    => 'Late-admit candidates',
        'admits'  => 'Admissions made',
    ];
    $m = [];
    $bump = function (string $name, string $k) use (&$m, $cols) {
        if (!isset($m[$name])) { $m[$name] = array_fill_keys(array_keys($cols), 0); }
        $m[$name][$k]++;
    };
    foreach ($d['short'] as $r)   { $bump($r['user_name'], 'short'); }
    foreach ($d['zero'] as $r)    { $bump($r['user_name'], 'zero'); }
    foreach (array_merge($d['unbilled_closed'], $d['unbilled_active']) as $r) { $bump($r['user_name'], 'unbilled'); }
    foreach ($d['voids'] as $r)   { $bump($r['user_name'], strpos($r['kind'], 'WAIVED') !== false ? 'waived' : 'voids'); }
    foreach ($d['removed'] as $r) { $bump($r['user_name'], 'removed'); }
    foreach ($d['admit'] as $r) {
        $bump($r['user_name'], 'admits');
        if ($r['late_candidate']) { $bump($r['user_name'], 'late'); }
    }
    uasort($m, function ($a, $b) {
        $ta = array_sum($a) - $a['admits']; $tb = array_sum($b) - $b['admits'];
        return $tb <=> $ta;
    });
    return ['cols' => $cols, 'rows' => $m];
}

/** Headline counts used by the page KPIs and the daily email. */
function rx_counts(array $d): array
{
    $waived = 0; $voids = 0;
    foreach ($d['voids'] as $r) { if (strpos($r['kind'], 'WAIVED') !== false) { $waived++; } else { $voids++; } }
    $unres = 0;
    // An APPROVED short-payment request means a manager already decided on the shortfall.
    foreach ($d['short'] as $r) { if ($r['unresolved'] > 0.009 && ($r['approval_status'] ?? null) !== 'APPROVED') { $unres++; } }
    $zeroApproved = 0;
    foreach ($d['zero'] as $r) { if (($r['approval_status'] ?? null) === 'APPROVED') { $zeroApproved++; } }
    $recLate = 0;
    foreach ($d['reception'] ?? [] as $r) { if ($r['late']) { $recLate++; } }
    $late = 0;
    foreach ($d['admit'] as $r) { if ($r['late_candidate']) { $late++; } }
    return [
        'short' => count($d['short']), 'short_unresolved' => $unres,
        'zero' => count($d['zero']),
        'unbilled_closed' => count($d['unbilled_closed']), 'unbilled_active' => count($d['unbilled_active']),
        'voids' => $voids, 'waived' => $waived,
        'removed' => count($d['removed']), 'late' => $late,
        'zero_approved' => $zeroApproved,
        'approvals_pending' => count($d['approvals_pending'] ?? []),
        'ho_unaccepted' => count($d['ho_unaccepted'] ?? []),
        'ho_orphans' => count($d['ho_orphans'] ?? []),
        'reception_admits' => count($d['reception'] ?? []), 'reception_late' => $recLate,
    ];
}
