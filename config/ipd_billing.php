<?php
/**
 * IPD billing helpers — kept OUT of config/billing.php (which is ER/consultation
 * only and must not be modified for IPD). Own "I" invoice series.
 *
 * Billing model (locked): per-day bed/ward charge x days + logged services +
 * daily consultant visit fee (sum of PAID ward-round notes). Day count =
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

// Find or create the IPD bill (draft), seeded once from stay-days + paid
// consultant visits + logged billable services.
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

        // --- STAY line: per-day ward rate x calendar days ---
        $wr = $pdo->prepare('SELECT per_day_rate FROM ipd_ward_rates WHERE ward = ?');
        $wr->execute([$adm['ward']]);
        $perDay = (float) ($wr->fetchColumn() ?: 0);
        $days = ipd_stay_days($adm['admitted_at'], $adm['discharged_at'] ?? null);
        $stayAmt = round($perDay * $days, 2);
        $pdo->prepare('INSERT INTO ipd_bill_items (ipd_bill_id, description, quantity, unit_rate, amount, item_kind) VALUES (?, ?, ?, ?, ?, \'STAY\')')
            ->execute([$billId, $adm['ward'] . ' ward — ' . $days . ' day' . ($days > 1 ? 's' : ''), $days, $perDay, $stayAmt]);

        // --- CONSULT_VISIT line: sum of PAID ward-round note fees ---
        $cv = $pdo->prepare('SELECT COUNT(*) AS n, COALESCE(SUM(visit_charge),0) AS total FROM ipd_doctor_visits WHERE admission_id = ? AND is_paid = 1');
        $cv->execute([$adm['id']]);
        $cvRow = $cv->fetch();
        $cvCount = (int) $cvRow['n'];
        $cvTotal = (float) $cvRow['total'];
        if ($cvCount > 0) {
            $pdo->prepare('INSERT INTO ipd_bill_items (ipd_bill_id, description, quantity, unit_rate, amount, item_kind) VALUES (?, ?, ?, ?, ?, \'CONSULT_VISIT\')')
                ->execute([$billId, 'Consultant visits (' . $cvCount . ' paid)', $cvCount, $cvCount ? round($cvTotal / $cvCount, 2) : 0, round($cvTotal, 2)]);
        }

        // --- SERVICE lines: billable logged services ---
        $svc = $pdo->prepare('SELECT * FROM ipd_services WHERE admission_id = ? AND is_billable = 1 ORDER BY logged_at');
        $svc->execute([$adm['id']]);
        foreach ($svc->fetchAll() as $s) {
            $qtyLabel = $s['charge_type'] === 'HOURLY' ? ((int) $s['duration_minutes']) . ' min' : (int) $s['quantity'];
            $pdo->prepare('INSERT INTO ipd_bill_items (ipd_bill_id, description, quantity, unit_rate, amount, item_kind) VALUES (?, ?, ?, ?, ?, \'SERVICE\')')
                ->execute([$billId, $s['service_name'] . ' (' . $qtyLabel . ')', max(1, (int) $s['quantity']), $s['unit_charge'], (float) $s['calculated_charge']]);
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
