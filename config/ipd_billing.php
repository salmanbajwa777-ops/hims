<?php
/**
 * IPD billing helpers — kept OUT of config/billing.php (which is ER/consultation
 * only and must not be modified for IPD). Own "I" invoice series.
 *
 * Billing model (locked): per-day room charge x days + logged services +
 * daily consultant visit fee (sum of PAID daily-round notes). Day count =
 * DATEDIFF(discharge, admit) + 1 (admit day = day 1, calendar days).
 */

// "I{seq}{YY}{MM}" — own counter, GREATEST-of-counter-and-real-max (mirrors
// generate_admission_invoice_number so a restore/re-run can't reissue numbers).
function generate_ipd_invoice_number(PDO $pdo): string {
    $year = (int) date('Y');
    $month = (int) date('n');
    $yymm = substr((string) $year, 2, 2) . str_pad((string) $month, 2, '0', STR_PAD_LEFT);

    $stmt = $pdo->prepare("
        SELECT GREATEST(
            COALESCE((SELECT next_seq - 1 FROM ipd_invoice_counters WHERE yr = :y AND mo = :m), 0),
            COALESCE((SELECT MAX(CAST(SUBSTRING(invoice_number, 2, CHAR_LENGTH(invoice_number) - 5) AS UNSIGNED))
                      FROM ipd_bills WHERE invoice_number LIKE :pfx), 0)
        ) + 1
    ");
    $stmt->execute([':y' => $year, ':m' => $month, ':pfx' => 'I%' . $yymm]);
    $seq = max(1, (int) $stmt->fetchColumn());

    $pdo->prepare('
        INSERT INTO ipd_invoice_counters (yr, mo, next_seq)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE next_seq = GREATEST(next_seq, VALUES(next_seq))
    ')->execute([$year, $month, $seq + 1]);

    return 'I' . $seq . $yymm;
}

// Calendar days of stay, admit day = day 1. discharge NULL -> up to now.
function ipd_stay_days(string $admittedAt, ?string $dischargedAt): int {
    $a = new DateTime(date('Y-m-d', strtotime($admittedAt)));
    $d = new DateTime(date('Y-m-d', strtotime($dischargedAt ?: 'now')));
    return max(1, (int) $a->diff($d)->days + 1);
}

// Recompute subtotal/grand_total from the line items + manual discount.
// No tax (same policy as every other invoice in this system).
function recalc_ipd_bill_totals(PDO $pdo, int $billId): void {
    $s = $pdo->prepare('SELECT COALESCE(SUM(amount),0) AS subtotal FROM ipd_bill_items WHERE ipd_bill_id = ?');
    $s->execute([$billId]);
    $subtotal = (float) $s->fetchColumn();

    $b = $pdo->prepare('SELECT manual_discount_amount, manual_discount_pct FROM ipd_bills WHERE id = ?');
    $b->execute([$billId]);
    $row = $b->fetch() ?: ['manual_discount_amount' => 0, 'manual_discount_pct' => 0];
    $pct = (float) ($row['manual_discount_pct'] ?? 0);
    $amt = (float) ($row['manual_discount_amount'] ?? 0);

    // A % discount is computed off the subtotal; a flat amount is used as-is.
    // Percent takes precedence if both somehow set.
    $discount = $pct > 0 ? round($subtotal * $pct / 100, 2) : $amt;
    $grand = max(0, round($subtotal - $discount, 2));

    $pdo->prepare('UPDATE ipd_bills SET subtotal = ?, grand_total = ? WHERE id = ?')
        ->execute([round($subtotal, 2), $grand, $billId]);
}

// The billed charge for one logged service, from its charge type.
function ipd_service_charge(string $chargeType, float $unitCharge, int $quantity, ?int $durationMinutes): float {
    if ($chargeType === 'HOURLY') {
        return round($unitCharge * (($durationMinutes ?? 0) / 60), 2);
    }
    return round($unitCharge * max(1, $quantity), 2);
}

// The bill-line description for one logged ipd_services row. Single source of
// truth so a line APPENDED later is indistinguishable from one seeded by
// ensure_ipd_bill() — both call this.
function ipd_service_line_description(array $svc): string {
    $qtyLabel = $svc['charge_type'] === 'HOURLY'
        ? ((int) $svc['duration_minutes']) . ' min'
        : (int) $svc['quantity'];
    return $svc['service_name'] . ' (' . $qtyLabel . ')';
}

// Insert the SERVICE bill line for one logged service and re-total.
// Caller owns the transaction.
function ipd_insert_service_line(PDO $pdo, int $billId, array $svc): void {
    $pdo->prepare('INSERT INTO ipd_bill_items (ipd_bill_id, description, quantity, unit_rate, amount, item_kind) VALUES (?, ?, ?, ?, ?, \'SERVICE\')')
        ->execute([
            $billId,
            ipd_service_line_description($svc),
            max(1, (int) $svc['quantity']),
            $svc['unit_charge'],
            (float) $svc['calculated_charge'],
        ]);
}

/**
 * Put a just-logged service onto an ALREADY-EXISTING bill.
 *
 * ensure_ipd_bill() early-returns when a bill exists (ipd_bills.admission_id is
 * UNIQUE), so it seeds the service lines exactly ONCE. Before this function, a
 * service logged after discharge-submit was silently dropped from the bill —
 * which is why service logging used to be fenced to status = 'ACTIVE'. Nurses
 * give care right up to the moment the patient walks out, so instead of that
 * fence we append.
 *
 * Returns one of:
 *   'none'    — no bill yet; ensure_ipd_bill() will pick this service up when
 *               it seeds. Nothing to do, and NOT an error.
 *   'added'   — appended to the draft bill and totals recalculated.
 *   'settled' — bill is finalized/paid, so it must not move. The service stays
 *               logged clinically; the caller warns the user to tell reception.
 */
function append_ipd_service_to_bill(PDO $pdo, int $admissionId, int $serviceId): string {
    $b = $pdo->prepare('SELECT id, status FROM ipd_bills WHERE admission_id = ?');
    $b->execute([$admissionId]);
    $bill = $b->fetch();
    if (!$bill) { return 'none'; }
    if ($bill['status'] !== 'draft') { return 'settled'; }

    $s = $pdo->prepare('SELECT * FROM ipd_services WHERE id = ? AND is_billable = 1');
    $s->execute([$serviceId]);
    $svc = $s->fetch();
    if (!$svc) { return 'none'; }

    $ownTxn = !$pdo->inTransaction();
    if ($ownTxn) { $pdo->beginTransaction(); }
    try {
        ipd_insert_service_line($pdo, (int) $bill['id'], $svc);
        recalc_ipd_bill_totals($pdo, (int) $bill['id']);
        if ($ownTxn) { $pdo->commit(); }
    } catch (Throwable $e) {
        if ($ownTxn && $pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    return 'added';
}

/**
 * Take a logged service off the bill (or put it back), keeping ipd_services and
 * ipd_bill_items in step.
 *
 * The row is NEVER deleted — is_billable flips, so the clinical record of what
 * was done to the patient always survives an admin removing the charge.
 * ensure_ipd_bill() already filters on is_billable = 1, so a service removed
 * BEFORE the bill is seeded simply never appears on it.
 *
 * Returns true when something actually changed.
 */
function set_ipd_service_billable(PDO $pdo, int $admissionId, int $serviceId, bool $billable): bool {
    $s = $pdo->prepare('SELECT * FROM ipd_services WHERE id = ? AND admission_id = ?');
    $s->execute([$serviceId, $admissionId]);
    $svc = $s->fetch();
    if (!$svc) { return false; }
    if ((int) $svc['is_billable'] === ($billable ? 1 : 0)) { return false; }

    $b = $pdo->prepare('SELECT id, status FROM ipd_bills WHERE admission_id = ?');
    $b->execute([$admissionId]);
    $bill = $b->fetch();
    // A settled bill is immutable — refuse rather than silently desync the
    // line items from the money already taken.
    if ($bill && $bill['status'] !== 'draft') { return false; }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE ipd_services SET is_billable = ? WHERE id = ?')
            ->execute([$billable ? 1 : 0, $serviceId]);

        if ($bill) {
            $billId = (int) $bill['id'];
            if ($billable) {
                $svc['is_billable'] = 1;
                ipd_insert_service_line($pdo, $billId, $svc);
            } else {
                // Bill items carry no FK back to ipd_services, so the line is
                // matched on its rendered description. The same service can
                // legitimately be logged twice with identical text, so delete
                // by the id of ONE matching row rather than by the predicate —
                // a bare DELETE ... WHERE description = ? would wipe both.
                $find = $pdo->prepare('SELECT id FROM ipd_bill_items WHERE ipd_bill_id = ? AND item_kind = \'SERVICE\' AND description = ? ORDER BY id LIMIT 1');
                $find->execute([$billId, ipd_service_line_description($svc)]);
                $lineId = $find->fetchColumn();
                if ($lineId !== false) {
                    $pdo->prepare('DELETE FROM ipd_bill_items WHERE id = ?')->execute([(int) $lineId]);
                }
            }
            recalc_ipd_bill_totals($pdo, $billId);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    return true;
}

// The four per-day / per-round rates for a room category, or all-zero when the
// category no longer resolves.
//
// The lookup matches on the category NAME, not an FK — so a category renamed or
// deleted out from under an in-flight admission returns nothing and the whole
// stay silently prices at Rs 0. ipd_room_rates.php cascades renames into
// ipd_admissions to stop that; this function is the reason it must.
function ipd_room_rates(PDO $pdo, ?string $category): array {
    $zero = ['per_day_rate' => 0.0, 'nursing_per_day_rate' => 0.0, 'mo_per_day_rate' => 0.0, 'consultant_visit_fee' => 0.0];
    if ($category === null || $category === '') { return $zero; }
    try {
        $r = $pdo->prepare('SELECT per_day_rate, nursing_per_day_rate, mo_per_day_rate, consultant_visit_fee FROM ipd_room_rates WHERE room_category = ?');
        $r->execute([$category]);
        $row = $r->fetch();
    } catch (Throwable $e) {
        return $zero;   // migration not run yet — degrade instead of fataling
    }
    if (!$row) { return $zero; }
    return [
        'per_day_rate'         => (float) $row['per_day_rate'],
        'nursing_per_day_rate' => (float) $row['nursing_per_day_rate'],
        'mo_per_day_rate'      => (float) $row['mo_per_day_rate'],
        'consultant_visit_fee' => (float) $row['consultant_visit_fee'],
    ];
}

/**
 * Write the non-SERVICE lines of a bill: room, nursing, MO, consultant rounds.
 *
 * Shared by ensure_ipd_bill() (first seed) and reprice_ipd_bill() (rate fixed
 * after the fact) so the two can never disagree about what a bill contains.
 * Caller owns the transaction.
 *
 * A rate of 0 writes NO line — a bill listing "Nursing charges ... Rs 0" reads
 * as though nursing were free, when it means nobody set the rate.
 */
function ipd_seed_charge_lines(PDO $pdo, int $billId, array $adm): void {
    $cat = $adm['room_category'] ?? '';
    $rates = ipd_room_rates($pdo, $cat);
    $days = ipd_stay_days($adm['admitted_at'], $adm['discharged_at'] ?? null);
    $dayLabel = $days . ' day' . ($days > 1 ? 's' : '');

    $ins = $pdo->prepare('INSERT INTO ipd_bill_items (ipd_bill_id, description, quantity, unit_rate, amount, item_kind) VALUES (?, ?, ?, ?, ?, ?)');

    // --- Room, nursing and MO: each a per-day rate x calendar days ---
    $perDay = [
        ['STAY',    $cat !== '' ? $cat : 'Room', $rates['per_day_rate']],
        ['NURSING', 'Nursing charges',           $rates['nursing_per_day_rate']],
        ['MO',      'Medical Officer charges',   $rates['mo_per_day_rate']],
    ];
    foreach ($perDay as [$kind, $label, $rate]) {
        if ($rate <= 0) { continue; }
        $ins->execute([$billId, $label . ' — ' . $dayLabel, $days, $rate, round($rate * $days, 2), $kind]);
    }

    // --- Consultant rounds: sum of the PAID daily-round fees ---
    // Read from the notes, not the rate table: each note froze the fee that
    // applied on the day it was written, and those notes are immutable.
    $cv = $pdo->prepare('SELECT COUNT(*) AS n, COALESCE(SUM(visit_charge),0) AS total FROM ipd_doctor_visits WHERE admission_id = ? AND is_paid = 1');
    $cv->execute([$adm['id']]);
    $cvRow = $cv->fetch();
    $cvCount = (int) $cvRow['n'];
    $cvTotal = (float) $cvRow['total'];
    if ($cvCount > 0 && $cvTotal > 0) {
        $ins->execute([
            $billId, 'Consultant rounds (' . $cvCount . ' paid)', $cvCount,
            round($cvTotal / $cvCount, 2), round($cvTotal, 2), 'CONSULT_VISIT',
        ]);
    }
}

// Find or create the IPD bill (draft), seeded once from the room category's
// per-day rates + paid consultant rounds + logged billable services.
function ensure_ipd_bill(PDO $pdo, array $adm, int $uid): array {
    $b = $pdo->prepare('SELECT * FROM ipd_bills WHERE admission_id = ?');
    $b->execute([$adm['id']]);
    $bill = $b->fetch();
    if ($bill) { return $bill; }

    $pdo->beginTransaction();
    try {
        $inv = generate_ipd_invoice_number($pdo);
        $pdo->prepare('INSERT INTO ipd_bills (invoice_number, admission_id, created_by_id) VALUES (?, ?, ?)')
            ->execute([$inv, $adm['id'], $uid]);
        $billId = (int) $pdo->lastInsertId();

        ipd_seed_charge_lines($pdo, $billId, $adm);

        // --- SERVICE lines: billable logged services ---
        $svc = $pdo->prepare('SELECT * FROM ipd_services WHERE admission_id = ? AND is_billable = 1 ORDER BY logged_at');
        $svc->execute([$adm['id']]);
        foreach ($svc->fetchAll() as $s) {
            ipd_insert_service_line($pdo, $billId, $s);
        }

        recalc_ipd_bill_totals($pdo, $billId);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }

    $b->execute([$adm['id']]);
    return $b->fetch();
}

/**
 * Rebuild a DRAFT bill's room/nursing/MO/consultant lines from current rates.
 *
 * Every rate shipped seeded at 0.00, so bills raised before anyone filled the
 * rate table charged nothing. This re-prices those that are still open.
 *
 * SERVICE lines are left alone — they carry their own frozen per-line charge and
 * an admin may already have removed some. A settled bill is refused outright:
 * money has changed hands and the invoice may be printed.
 *
 * Returns true when the bill was repriced.
 */
function reprice_ipd_bill(PDO $pdo, array $adm): bool {
    $b = $pdo->prepare('SELECT id, status FROM ipd_bills WHERE admission_id = ?');
    $b->execute([$adm['id']]);
    $bill = $b->fetch();
    if (!$bill || $bill['status'] !== 'draft') { return false; }
    $billId = (int) $bill['id'];

    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM ipd_bill_items WHERE ipd_bill_id = ? AND item_kind IN ('STAY','NURSING','MO','CONSULT_VISIT')")
            ->execute([$billId]);
        ipd_seed_charge_lines($pdo, $billId, $adm);
        recalc_ipd_bill_totals($pdo, $billId);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    return true;
}
