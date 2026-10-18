<?php
/**
 * Short-stay admission bill: draft creation, seeding, approvals, service void.
 *
 * Extracted from admission_discharge.php so the ADMIT transaction
 * (config/admission_actions.php) can raise the draft bill header in the same
 * transaction as the admission, and so the rules are testable without a page.
 *
 * SCHEMA GUARDS. This code ships before OR after its migrations, unpredictably
 * (sql/2026_10_admission_services_void.sql, sql/2026_10_admission_bill_approvals.sql).
 * Every read/write of a new column or table goes through adm_has() first, so the
 * pre-migration database keeps its old behaviour and never fatals or shows a
 * false alarm. adm_has() asks information_schema once per request per object.
 */

require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/billing.php';

// ---------------------------------------------------------------------------
// Schema guard
// ---------------------------------------------------------------------------

if (!function_exists('adm_schema_probe')) {
    // Defined only if absent so a test harness can substitute a non-MySQL probe.
    function adm_schema_probe(PDO $pdo, string $table, ?string $col): bool {
        try {
            if ($col === null) {
                $q = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
                $q->execute([$table]);
            } else {
                $q = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
                $q->execute([$table, $col]);
            }
            return (bool) $q->fetchColumn();
        } catch (Throwable $e) {
            return false;   // cannot prove it exists -> behave as pre-migration
        }
    }
}

/** True when the table (or table.column) exists. Cached per request. */
function adm_has(PDO $pdo, string $table, ?string $col = null, bool $reset = false): bool {
    static $cache = [];
    if ($reset) { $cache = []; return false; }
    $k = $table . '.' . ($col ?? '*');
    if (!array_key_exists($k, $cache)) {
        $cache[$k] = adm_schema_probe($pdo, $table, $col);
    }
    return $cache[$k];
}

/**
 * ' AND [alias.]voided_at IS NULL' once admission_services has the void columns,
 * else ''. Append to EVERY query that reads admission_services as money or as a
 * live service list.
 */
function adm_svc_live(PDO $pdo, string $alias = ''): string {
    if (!adm_has($pdo, 'admission_services', 'voided_at')) { return ''; }
    return ' AND ' . ($alias !== '' ? $alias . '.' : '') . 'voided_at IS NULL';
}

// ---------------------------------------------------------------------------
// Bill header + seeding
// ---------------------------------------------------------------------------

/** True for a UNIQUE-key violation (MySQL 1062 / SQLSTATE 23000; sqlite "UNIQUE constraint failed"). */
function adm_is_duplicate_key(PDOException $e): bool {
    $info = $e->errorInfo;
    return (is_array($info) && isset($info[1]) && (int) $info[1] === 1062)
        || stripos($e->getMessage(), 'duplicate entry') !== false
        || stripos($e->getMessage(), 'UNIQUE constraint failed') !== false;
}

/**
 * Raise the DRAFT admission bill header (no lines) for a fresh admission.
 * Call INSIDE the admit transaction (no transaction of its own). A no-op on a
 * pre-migration schema (items_seeded_at absent) so the old lazy-create on the
 * discharge page keeps working unchanged. Returns the bill id, or null if skipped.
 *
 * The header carries no lines on purpose: the stay line is priced from the
 * nurse's discharged_at, so lines are seeded later by ensure_admission_bill().
 */
function adm_create_bill_header(PDO $pdo, int $admissionId, int $patientId, int $uid): ?int {
    if (!adm_has($pdo, 'admission_bills', 'items_seeded_at')) { return null; }
    $b = $pdo->prepare('SELECT id FROM admission_bills WHERE admission_id = ?');
    $b->execute([$admissionId]);
    $existing = $b->fetchColumn();
    if ($existing) { return (int) $existing; }

    $cat = patient_discount_category($pdo, $patientId);
    // generate_admission_invoice_number() is not locked, so two simultaneous admits can
    // draw the same number; the UNIQUE key rejects the second. Retry ONCE with a fresh
    // number (the winner's row is committed or visible by then); a second failure throws.
    $insSql = 'INSERT INTO admission_bills (invoice_number, admission_id, created_by_id, discount_category_id) VALUES (?, ?, ?, ?)';
    try {
        $pdo->prepare($insSql)->execute([generate_admission_invoice_number($pdo), $admissionId, $uid, $cat ? (int) $cat['id'] : null]);
    } catch (PDOException $e) {
        if (!adm_is_duplicate_key($e)) { throw $e; }
        $pdo->prepare($insSql)->execute([generate_admission_invoice_number($pdo), $admissionId, $uid, $cat ? (int) $cat['id'] : null]);
    }
    return (int) $pdo->lastInsertId();
}

/** Seed stay + live billable service lines. Caller owns the transaction. */
function adm_seed_bill_items(PDO $pdo, int $billId, array $adm): void {
    // Patient's standing discount category: applies to SERVICE/PROCEDURE lines
    // only (each at its own rate) — the room stay is NEVER discounted. Rates are
    // snapshotted per line so later category edits don't rewrite this bill.
    $cat = patient_discount_category($pdo, (int) $adm['patient_id']);
    $pdo->prepare('UPDATE admission_bills SET discount_category_id = ? WHERE id = ?')
        ->execute([$cat ? (int) $cat['id'] : null, $billId]);

    $rate = $pdo->prepare('SELECT rate_amount, rate_basis FROM admission_rates WHERE admission_type = ?');
    $rate->execute([$adm['admission_type']]);
    $rate = $rate->fetch() ?: ['rate_amount' => 0, 'rate_basis' => 'HOURLY'];
    $endTs = $adm['discharged_at'] ? strtotime($adm['discharged_at']) : time();
    $mins = max(0, (int) round(($endTs - strtotime($adm['admitted_at'])) / 60));
    if ($rate['rate_basis'] === 'DAILY') {
        $units = max(1, (int) ceil($mins / 1440));
        $desc = 'Stay — ' . $adm['admission_type'] . ' (' . $units . ' day' . ($units > 1 ? 's' : '') . ')';
    } else {
        $units = admission_billed_hours($mins);
        $desc = 'Stay — ' . $adm['admission_type'] . ' (' . $units . ' hr)';
    }
    $stayAmt = round((float) $rate['rate_amount'] * $units, 2);
    $totalDiscount = 0.0;
    $stayPct = $cat ? (float) $cat['room_stay_pct'] : 0.0;
    $stayDiscount = round($stayAmt * $stayPct / 100, 2);
    $stayNet = round($stayAmt - $stayDiscount, 2);
    $totalDiscount += $stayDiscount;
    $pdo->prepare('INSERT INTO admission_bill_items (admission_bill_id, description, quantity, unit_rate, amount, item_kind, discount_pct, discount_amount) VALUES (?, ?, ?, ?, ?, \'STAY\', ?, ?)')
        ->execute([$billId, $desc, $units, $rate['rate_amount'], $stayNet, $stayPct, $stayDiscount]);

    // Service lines (billable, NOT voided), net of the category discount if any.
    $svc = $pdo->prepare('SELECT * FROM admission_services WHERE admission_id = ? AND is_billable = 1' . adm_svc_live($pdo) . ' ORDER BY logged_at');
    $svc->execute([$adm['id']]);
    foreach ($svc->fetchAll() as $s) {
        $qtyLabel = $s['charge_type'] === 'HOURLY' ? ((int) $s['duration_minutes']) . ' min' : (int) $s['quantity'];
        $pct = 0.0;
        if ($cat) {
            $pct = $s['service_type'] === 'PROCEDURE'
                ? (float) $cat['procedures_pct'] : (float) $cat['er_services_pct'];
        }
        $gross = (float) $s['calculated_charge'];
        $lineDiscount = round($gross * $pct / 100, 2);
        $net = round($gross - $lineDiscount, 2);
        $totalDiscount += $lineDiscount;
        $pdo->prepare('INSERT INTO admission_bill_items (admission_bill_id, description, quantity, unit_rate, amount, item_kind, discount_pct, discount_amount, service_type) VALUES (?, ?, ?, ?, ?, \'SERVICE\', ?, ?, ?)')
            ->execute([$billId, $s['service_name'] . ' (' . $qtyLabel . ')', max(1, (int) $s['quantity']), $s['unit_charge'], $net, $pct, $lineDiscount, $s['service_type']]);
    }
    if ($totalDiscount > 0) {
        $pdo->prepare('UPDATE admission_bills SET discount_amount = ? WHERE id = ?')
            ->execute([round($totalDiscount, 2), $billId]);
    }
    recalc_admission_bill_totals($pdo, $billId);
}

/**
 * Find or create the admission bill (draft), seeded exactly once from stay +
 * services. Moved verbatim in behaviour from admission_discharge.php, plus:
 * a header created at admit time (no lines, items_seeded_at NULL) is seeded here
 * on first use. The seed is claimed with an atomic UPDATE ... WHERE
 * items_seeded_at IS NULL so two concurrent page loads cannot both seed.
 */
function ensure_admission_bill(PDO $pdo, array $adm, int $uid): array {
    $b = $pdo->prepare('SELECT * FROM admission_bills WHERE admission_id = ?');
    $b->execute([$adm['id']]);
    $bill = $b->fetch();
    $hasMarker = adm_has($pdo, 'admission_bills', 'items_seeded_at');

    if ($bill) {
        if (!$hasMarker || $bill['items_seeded_at'] !== null || $bill['status'] !== 'draft') { return $bill; }
        $pdo->beginTransaction();
        try {
            $claim = $pdo->prepare('UPDATE admission_bills SET items_seeded_at = NOW() WHERE id = ? AND items_seeded_at IS NULL');
            $claim->execute([(int) $bill['id']]);
            if ($claim->rowCount() === 1) {
                // An old-code bill that already has lines (the approvals migration ran
                // before this code was deployed and a backfill missed it) only gets the
                // marker - seeding again would double every line.
                $has = $pdo->prepare('SELECT 1 FROM admission_bill_items WHERE admission_bill_id = ? LIMIT 1');
                $has->execute([(int) $bill['id']]);
                if (!$has->fetchColumn()) {
                    adm_seed_bill_items($pdo, (int) $bill['id'], $adm);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        $b->execute([$adm['id']]);
        return $b->fetch();
    }

    $pdo->beginTransaction();
    try {
        $inv = generate_admission_invoice_number($pdo);
        $cat = patient_discount_category($pdo, (int) $adm['patient_id']);
        $pdo->prepare('INSERT INTO admission_bills (invoice_number, admission_id, created_by_id, discount_category_id) VALUES (?, ?, ?, ?)')
            ->execute([$inv, $adm['id'], $uid, $cat ? (int) $cat['id'] : null]);
        $billId = (int) $pdo->lastInsertId();
        if ($hasMarker) {
            $pdo->prepare('UPDATE admission_bills SET items_seeded_at = NOW() WHERE id = ?')->execute([$billId]);
        }
        adm_seed_bill_items($pdo, $billId, $adm);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    $b->execute([$adm['id']]);
    return $b->fetch();
}

// ---------------------------------------------------------------------------
// Approvals (review sign-off; moves NO money)
// ---------------------------------------------------------------------------

const ADM_APPROVAL_KINDS = ['LEFT_BEFORE_TREATMENT', 'SHORT_PAYMENT', 'ZERO_OR_DISCOUNT'];

function adm_approvals_ready(PDO $pdo): bool {
    return adm_has($pdo, 'admission_bill_approvals');
}

/** Number of live (not voided) billable services on the stay. */
function adm_billable_service_count(PDO $pdo, int $admissionId): int {
    $q = $pdo->prepare('SELECT COUNT(*) FROM admission_services WHERE admission_id = ? AND is_billable = 1' . adm_svc_live($pdo));
    $q->execute([$admissionId]);
    return (int) $q->fetchColumn();
}

/**
 * Raise a PENDING approval. Idempotent per (bill, kind): if a PENDING row exists
 * the new reason is appended to it; if an APPROVED one exists nothing is added
 * (already signed off); a REJECTED one does not suppress a fresh row.
 * Returns the row id, or null when the table is absent or nothing was added.
 * Joins the caller's transaction.
 */
function adm_open_approval(PDO $pdo, int $admissionId, ?int $billId, string $kind, string $reason, int $uid): ?int {
    if (!adm_approvals_ready($pdo) || !in_array($kind, ADM_APPROVAL_KINDS, true)) { return null; }
    $reason = mb_substr(trim($reason), 0, 500);
    $q = $pdo->prepare('SELECT id, status, reason FROM admission_bill_approvals WHERE admission_id = ? AND kind = ? AND status IN (\'PENDING\',\'APPROVED\') ORDER BY id DESC LIMIT 1');
    $q->execute([$admissionId, $kind]);
    $row = $q->fetch();
    if ($row) {
        if ($row['status'] === 'PENDING' && $reason !== '' && strpos((string) $row['reason'], $reason) === false) {
            $pdo->prepare('UPDATE admission_bill_approvals SET reason = ? WHERE id = ?')
                ->execute([mb_substr($row['reason'] . ' | ' . $reason, 0, 500), (int) $row['id']]);
        }
        return null;
    }
    $pdo->prepare('INSERT INTO admission_bill_approvals (admission_id, bill_id, kind, reason, requested_by_id, requested_at, status) VALUES (?, ?, ?, ?, ?, NOW(), \'PENDING\')')
        ->execute([$admissionId, $billId, $kind, $reason, $uid]);
    return (int) $pdo->lastInsertId();
}

/**
 * Kinds a finalise must flag when NO outcome was chosen. Allowed, never blocked:
 * the row exists so an admin/manager reviews it.
 */
function adm_finalize_flags(array $bill, float $total, float $paid): array {
    $kinds = [];
    if ($total <= 0.001 || (float) ($bill['manual_discount_amount'] ?? 0) > 0) { $kinds[] = 'ZERO_OR_DISCOUNT'; }
    if (round($total - $paid, 2) > 0.001) { $kinds[] = 'SHORT_PAYMENT'; }
    return $kinds;
}

/** Null when LEFT_BEFORE_TREATMENT is allowed, else the refusal message. */
function adm_check_left_before_treatment(PDO $pdo, int $admissionId, string $reason): ?string {
    if (!adm_approvals_ready($pdo)) {
        return 'This outcome needs the approvals migration (sql/2026_10_admission_bill_approvals.sql) — ask the administrator.';
    }
    if (trim($reason) === '') { return 'Give the reason the patient left before treatment.'; }
    if (adm_billable_service_count($pdo, $admissionId) > 0) {
        return 'Services were logged for this stay, so it cannot be closed as "left before treatment". Finalise it normally; any discount or short payment is flagged for review.';
    }
    return null;
}

/**
 * Admin OR manager decision. Both hold ADMISSION_APPROVE_WRITEOFF (no new
 * permission). A requester cannot decide their own row unless base_role ADMIN.
 * The status guard in the WHERE makes a double-submit match zero rows.
 */
function adm_decide_approval(PDO $pdo, int $id, string $decision, string $note, int $uid, string $baseRole): array {
    if (!has_permission('ADMISSION_APPROVE_WRITEOFF')) { return ['ok' => false, 'error' => 'You are not allowed to decide approvals.']; }
    if (!adm_approvals_ready($pdo)) { return ['ok' => false, 'error' => 'Approvals are not set up yet.']; }
    if (!in_array($decision, ['APPROVED', 'REJECTED'], true)) { return ['ok' => false, 'error' => 'Choose approve or reject.']; }
    $note = mb_substr(trim($note), 0, 255);
    if ($decision === 'REJECTED' && $note === '') { return ['ok' => false, 'error' => 'Say why you are rejecting.']; }

    $q = $pdo->prepare('SELECT * FROM admission_bill_approvals WHERE id = ?');
    $q->execute([$id]);
    $row = $q->fetch();
    if (!$row) { return ['ok' => false, 'error' => 'Approval not found.']; }
    if ((int) $row['requested_by_id'] === $uid && $baseRole !== 'ADMIN') {
        return ['ok' => false, 'error' => 'You raised this request — another admin or manager must decide it.'];
    }
    $own = !$pdo->inTransaction();
    if ($own) { $pdo->beginTransaction(); }
    try {
        $u = $pdo->prepare('UPDATE admission_bill_approvals SET status = ?, decided_by_id = ?, decided_at = NOW(), decision_note = ? WHERE id = ? AND status = \'PENDING\'');
        $u->execute([$decision, $uid, $note !== '' ? $note : null, $id]);
        if ($u->rowCount() !== 1) {
            if ($own) { $pdo->rollBack(); }
            return ['ok' => false, 'error' => 'Already decided.'];
        }
        audit_log($pdo, 'admission_bill_approval_' . strtolower($decision),
            json_encode(['approval' => $id, 'admission' => (int) $row['admission_id'], 'kind' => $row['kind'], 'note' => $note]), $uid);
        if ($own) { $pdo->commit(); }
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
        return ['ok' => false, 'error' => 'Could not record the decision.'];
    }
    return ['ok' => true, 'error' => ''];
}

// ---------------------------------------------------------------------------
// Service void (replaces the hard DELETE in admission.php)
// ---------------------------------------------------------------------------

/**
 * Soft-void one service line. Keeps the original amount. Needs a reason.
 * Pre-migration (no voided_at column) falls back to the old hard DELETE so the
 * nurse is never blocked by a deploy gap. Returns ['ok','error','mode'=>'void'|'delete'].
 */
function adm_void_service(PDO $pdo, int $serviceId, int $admissionId, string $reason, int $uid): array {
    $reason = mb_substr(trim($reason), 0, 255);
    if (!adm_has($pdo, 'admission_services', 'voided_at')) {
        $pdo->prepare('DELETE FROM admission_services WHERE id = ? AND admission_id = ?')->execute([$serviceId, $admissionId]);
        audit_log($pdo, 'admission_service_removed', "Removed service #$serviceId from admission #$admissionId", $uid);
        return ['ok' => true, 'error' => '', 'mode' => 'delete'];
    }
    if ($reason === '') { return ['ok' => false, 'error' => 'Give a reason to void this service.', 'mode' => 'void']; }
    $own = !$pdo->inTransaction();
    if ($own) { $pdo->beginTransaction(); }
    try {
        $q = $pdo->prepare('SELECT service_name, quantity, duration_minutes, unit_charge, calculated_charge FROM admission_services WHERE id = ? AND admission_id = ? AND voided_at IS NULL');
        $q->execute([$serviceId, $admissionId]);
        $old = $q->fetch();
        if (!$old) {
            if ($own) { $pdo->rollBack(); }
            return ['ok' => false, 'error' => 'That service is already voided or does not exist.', 'mode' => 'void'];
        }
        $pdo->prepare('UPDATE admission_services SET voided_at = NOW(), voided_by_id = ?, void_reason = ? WHERE id = ? AND admission_id = ? AND voided_at IS NULL')
            ->execute([$uid, $reason, $serviceId, $admissionId]);
        audit_log($pdo, 'admission_service_voided',
            json_encode(['service' => $serviceId, 'admission' => $admissionId, 'before' => $old, 'reason' => $reason]), $uid);
        if ($own) { $pdo->commit(); }
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
        return ['ok' => false, 'error' => 'Could not void the service.', 'mode' => 'void'];
    }
    return ['ok' => true, 'error' => '', 'mode' => 'void'];
}
