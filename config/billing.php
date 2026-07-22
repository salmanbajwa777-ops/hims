<?php
// Shared billing helpers, used by checkout.php (manual checkout) and patients.php
// (auto-create a bill the moment a visit is registered).

// Invoice number: sequence + YY + MM as one continuous run of digits, e.g. 1202607
// is the 12th invoice of July 2026. Same encoding as the MRN (see patients.php).
// The sequence restarts at 1 each month; year and month are carried in the number
// itself, so the pair (yr, mo) is what makes it unique, not the sequence alone.
//
// Race-safe under concurrent registrations via the atomic-upsert + LAST_INSERT_ID()
// pattern used for MRNs and queue tokens. rowCount() distinguishes the two branches:
// MySQL reports 1 affected row for a fresh INSERT and 2 for the ON DUPLICATE KEY
// update path, so the first invoice of a month correctly gets sequence 1.
function generate_invoice_number(PDO $pdo): string {
    $year = (int) date('Y');
    $month = (int) date('n');

    $stmt = $pdo->prepare('
        INSERT INTO invoice_counters (yr, mo, next_seq)
        VALUES (?, ?, 2)
        ON DUPLICATE KEY UPDATE next_seq = LAST_INSERT_ID(next_seq) + 1
    ');
    $stmt->execute([$year, $month]);
    $seq = $stmt->rowCount() === 1 ? 1 : (int) $pdo->lastInsertId();

    return $seq
        . substr((string) $year, 2, 2)
        . str_pad((string) $month, 2, '0', STR_PAD_LEFT);
}

// Invoices carry no tax: the net total is simply the sum of the line items. The
// sales_tax_* / consolidation_* columns are retained (written as zero) so historical
// bills keep their shape and the schema needn't change.
function recalc_bill_totals(PDO $pdo, int $billId): void {
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) AS subtotal FROM bill_items WHERE bill_id = ?');
    $stmt->execute([$billId]);
    $subtotal = (float) $stmt->fetch()['subtotal'];

    $pdo->prepare('
        UPDATE bills
        SET subtotal = ?, sales_tax_percent = 0, sales_tax_amount = 0,
            consolidation_rate_percent = 0, consolidation_amount = 0, grand_total = ?
        WHERE id = ?
    ')->execute([$subtotal, $subtotal, $billId]);
}

// Yearly refund voucher number, e.g. "RF-2026-0021". Separate series from invoice
// numbers (confirmed), race-safe via the same atomic-upsert + LAST_INSERT_ID()
// pattern used for invoice numbers and queue tokens.
function generate_refund_number(PDO $pdo): string {
    $year = (int) date('Y');

    $pdo->prepare('
        INSERT INTO refund_sequences (sequence_year, last_sequence)
        VALUES (?, 1)
        ON DUPLICATE KEY UPDATE last_sequence = LAST_INSERT_ID(last_sequence) + 1
    ')->execute([$year]);
    $lastId = (int) $pdo->lastInsertId();
    $seq = $lastId > 0 ? $lastId : 1;

    return 'RF-' . $year . '-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
}

// How much of a bill has already been refunded.
function refunded_total(PDO $pdo, int $billId): float {
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) AS t FROM refunds WHERE bill_id = ?');
    $stmt->execute([$billId]);
    return (float) $stmt->fetch()['t'];
}

// Creates the bill for a freshly registered visit, seeded with the consultation
// fee line. Must be called inside an open transaction; returns the new bill id.
function create_bill_for_visit(
    PDO $pdo,
    int $visitId,
    string $consultLabel,
    float $fee,
    float $discountPct,
    int $userId,
    ?string $visitPaymentMode = null
): int {
    $invoiceNumber = generate_invoice_number($pdo);

    // Reception picks CASH/DIGITAL at registration; carry it onto the bill so the printed
    // invoice shows a real payment mode instead of "Pending". Actual settlement still
    // happens via record_payment, which overwrites this with the confirmed method.
    $paymentMethod = $visitPaymentMode === 'CASH' ? 'cash' : ($visitPaymentMode === 'DIGITAL' ? 'card' : null);

    $pdo->prepare('
        INSERT INTO bills (invoice_number, visit_id, sales_tax_percent, consolidation_rate_percent, payment_method, created_by_id)
        VALUES (?, ?, 0, 0, ?, ?)
    ')->execute([$invoiceNumber, $visitId, $paymentMethod, $userId]);
    $billId = (int) $pdo->lastInsertId();

    $consultFee = round($fee * (1 - ($discountPct / 100)), 2);
    $pdo->prepare('
        INSERT INTO bill_items (bill_id, description, quantity, unit_rate, amount)
        VALUES (?, ?, 1, ?, ?)
    ')->execute([$billId, $consultLabel, $consultFee, $consultFee]);

    recalc_bill_totals($pdo, $billId);

    return $billId;
}

// ============================================================================
// Admission billing (Phase 1). The admission invoice is a SEPARATE document
// from the consultation bill — its own tables (admission_bills /
// admission_bill_items) and its own number series ("A" prefix). A doctor
// advising admission after the paid OPD consultation raises a new, distinct
// bill; the consultation `bills` table is never touched here.
// ============================================================================

// Admission invoice number: "A" + sequence + YY + MM, e.g. A1202607 is the 12th
// admission invoice of July 2026. Same monthly-reset + atomic-upsert pattern as
// generate_invoice_number(), but a separate counter table so the two series
// never collide.
function generate_admission_invoice_number(PDO $pdo): string {
    $year = (int) date('Y');
    $month = (int) date('n');

    $stmt = $pdo->prepare('
        INSERT INTO admission_invoice_counters (yr, mo, next_seq)
        VALUES (?, ?, 2)
        ON DUPLICATE KEY UPDATE next_seq = LAST_INSERT_ID(next_seq) + 1
    ');
    $stmt->execute([$year, $month]);
    $seq = $stmt->rowCount() === 1 ? 1 : (int) $pdo->lastInsertId();

    return 'A' . $seq
        . substr((string) $year, 2, 2)
        . str_pad((string) $month, 2, '0', STR_PAD_LEFT);
}

// Billed stay-hours from a raw minute count.
//   0–44 completed minutes  -> 0.5 hour (flat half hour)
//   45 minutes and above    -> round DOWN to the previous quarter-hour
// e.g. 44->0.5, 45->0.75, 60->1.0, 100->1.5, 106->1.75.
function admission_billed_hours(int $minutes): float {
    if ($minutes < 45) {
        return 0.5;
    }
    return floor($minutes / 15) / 4;
}

// Recompute an admission bill's subtotal/grand_total from its line items.
// No tax (same policy as consultation invoices).
function recalc_admission_bill_totals(PDO $pdo, int $admissionBillId): void {
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) AS subtotal FROM admission_bill_items WHERE admission_bill_id = ?');
    $stmt->execute([$admissionBillId]);
    $subtotal = (float) $stmt->fetch()['subtotal'];

    $pdo->prepare('
        UPDATE admission_bills SET subtotal = ?, grand_total = ? WHERE id = ?
    ')->execute([$subtotal, $subtotal, $admissionBillId]);
}

// The billed charge for one logged service, from its charge type.
//   FLAT / PER_UNIT -> unit_charge * quantity
//   HOURLY          -> unit_charge * (duration_minutes / 60)   [bed rounding is
//                      NOT applied to services — only to stay-hours]
function admission_service_charge(string $chargeType, float $unitCharge, int $quantity, ?int $durationMinutes): float {
    if ($chargeType === 'HOURLY') {
        $hours = ($durationMinutes ?? 0) / 60;
        return round($unitCharge * $hours, 2);
    }
    return round($unitCharge * max(1, $quantity), 2);
}

// ============================================================================
// Patient discount categories (Family & Friends / Charity / Loyalty).
// Admin assigns a category to a patient; every future invoice auto-discounts
// at the category's rates — separate percentages for consultation, ER services
// and procedures. Rates are snapshotted at billing time; the printed slip stays
// generic ("Discount"), the category is internal-only for month-end reporting.
// ============================================================================

// The patient's active discount category, or null. Inactive categories are
// treated as unassigned (admin can pause a category without touching patients).
function patient_discount_category(PDO $pdo, int $patientId): ?array {
    $stmt = $pdo->prepare('
        SELECT dc.id, dc.name, dc.consultation_pct, dc.er_services_pct, dc.procedures_pct
        FROM patients p
        JOIN discount_categories dc ON dc.id = p.discount_category_id AND dc.is_active = 1
        WHERE p.id = ?
    ');
    $stmt->execute([$patientId]);
    $cat = $stmt->fetch();
    return $cat ?: null;
}

// Stack the category percentage ON TOP of an already-discounted price
// (confirmed rule: revisit engine first, category second — they compound).
// e.g. 50% revisit + 20% category → 100 − 50×0.80 paid = 60% total discount.
// Capped at 100 so a free follow-up stays exactly free.
function stack_discount_pct(float $basePct, float $categoryPct): float {
    $paid = (100 - $basePct) * (100 - $categoryPct) / 100;
    return round(min(100, max(0, 100 - $paid)), 2);
}

// ============================================================================
// Revisit billing (Phase 2). OPD consultation follow-ups only. Window is per
// patient + doctor + consultation type, measured from the last FULL-paid
// consultation. Only FULL payments move the window; free/50%/75% visits don't.
// Applies only to consult-types the admin flagged is_revisit_eligible.
// ============================================================================

// Returns the proposed fee for a (possibly returning) consultation:
//   ['fee', 'discount_pct', 'fee_type', 'reason', 'anchor_visit_id', 'days_since', 'label']
// $fullFee is the consult-type's list fee (the current visit's own fee — the
// discount base). $consultTypeId identifies the type; ineligible types always
// bill FULL with no revisit fields.
function revisit_consultation_fee(PDO $pdo, int $patientId, int $doctorId, int $consultTypeId, float $fullFee): array {
    $full = [
        'fee' => round($fullFee, 2), 'discount_pct' => 0.0, 'fee_type' => 'FULL',
        'reason' => 'First/standard consultation', 'anchor_visit_id' => null,
        'days_since' => null, 'label' => 'Full consultation fee',
    ];

    // Only eligible consultation types take revisit pricing.
    $elig = $pdo->prepare('SELECT is_revisit_eligible FROM doctor_consult_types WHERE id = ? AND doctor_id = ?');
    $elig->execute([$consultTypeId, $doctorId]);
    if (!(int) ($elig->fetchColumn() ?: 0)) {
        return $full;
    }

    // Latest FULL-paid consultation of this exact trio = the window anchor.
    $anchorStmt = $pdo->prepare("
        SELECT id, visit_date
        FROM visits
        WHERE patient_id = ? AND doctor_id = ? AND doctor_consult_type_id = ?
          AND consultation_fee_type = 'FULL'
        ORDER BY visit_date DESC, id DESC
        LIMIT 1
    ");
    $anchorStmt->execute([$patientId, $doctorId, $consultTypeId]);
    $anchor = $anchorStmt->fetch();
    if (!$anchor) {
        return $full;   // never had a full consultation of this type with this doctor
    }

    $anchorDate = new DateTime($anchor['visit_date']);
    $today = new DateTime(date('Y-m-d'));
    $days = (int) $anchorDate->diff($today)->days;

    if ($days > 15) {
        return $full;   // window expired — this visit is a fresh full anchor
    }

    if ($days <= 5) {
        // Count paid follow-ups already taken in this window (after the anchor).
        $cnt = $pdo->prepare("
            SELECT COUNT(*) FROM visits
            WHERE patient_id = ? AND doctor_id = ? AND doctor_consult_type_id = ?
              AND id <> ? AND visit_date >= ? AND visit_date <= CURDATE()
              AND consultation_fee_type IN ('FREE_FOLLOWUP','HALF_FOLLOWUP','THREE_QUARTER_FOLLOWUP')
        ");
        $cnt->execute([$patientId, $doctorId, $consultTypeId, (int) $anchor['id'], $anchor['visit_date']]);
        $priorRevisits = (int) $cnt->fetchColumn();

        if ($priorRevisits === 0) {
            return [
                'fee' => 0.0, 'discount_pct' => 100.0, 'fee_type' => 'FREE_FOLLOWUP',
                'reason' => 'Free follow-up (1st within 5 days)', 'anchor_visit_id' => (int) $anchor['id'],
                'days_since' => $days, 'label' => 'Free follow-up',
            ];
        }
        return [
            'fee' => round($fullFee * 0.5, 2), 'discount_pct' => 50.0, 'fee_type' => 'HALF_FOLLOWUP',
            'reason' => 'Follow-up (2nd+ within 5 days, 50%)', 'anchor_visit_id' => (int) $anchor['id'],
            'days_since' => $days, 'label' => '50% follow-up',
        ];
    }

    // 6–15 days → 75% of fee (25% discount).
    return [
        'fee' => round($fullFee * 0.75, 2), 'discount_pct' => 25.0, 'fee_type' => 'THREE_QUARTER_FOLLOWUP',
        'reason' => 'Follow-up (day 6–15, 75%)', 'anchor_visit_id' => (int) $anchor['id'],
        'days_since' => $days, 'label' => '75% follow-up',
    ];
}

// ============================================================================
// Day closing / cash tally / handover (approved 2026-07-23). Reception counts
// the drawer against the system's expected-cash figure, declares the handover
// to admin, and the day's payments lock. See sql/add_shift_closings.sql.
//
// "Cash vs online": patients only ever pay Cash or Online. Historically the
// bills enum spelled online as card/bank_transfer/cheque (DIGITAL mapped to
// 'card' at registration), so the tally treats every non-cash method as
// online rather than migrating old rows.
// ============================================================================

// Yearly closing-slip number, e.g. "DC-2026-0187". Same atomic-upsert pattern
// as generate_refund_number().
function generate_closing_number(PDO $pdo): string {
    $year = (int) date('Y');

    $pdo->prepare('
        INSERT INTO closing_sequences (sequence_year, last_sequence)
        VALUES (?, 1)
        ON DUPLICATE KEY UPDATE last_sequence = LAST_INSERT_ID(last_sequence) + 1
    ')->execute([$year]);
    $lastId = (int) $pdo->lastInsertId();
    $seq = $lastId > 0 ? $lastId : 1;

    return 'DC-' . $year . '-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
}

// The drawer's opening float (admin-configurable via clinic_settings).
function opening_float(PDO $pdo): float {
    $stmt = $pdo->prepare("SELECT setting_value FROM clinic_settings WHERE setting_key = 'opening_float'");
    $stmt->execute();
    return (float) ($stmt->fetchColumn() ?: 0);
}

// The full system-side tally for one date: cash/online collections split by
// document series, cash refunds out, and the expected-cash figure the physical
// count is checked against. Everything keys off when money actually moved —
// DATE(paid_at) for bills, DATE(created_at) for refunds.
function day_cash_tally(PDO $pdo, string $date): array {
    $t = [
        'cash_consult_total' => 0.0, 'cash_consult_count' => 0,
        'cash_admission_total' => 0.0, 'cash_admission_count' => 0,
        'online_consult_total' => 0.0, 'online_consult_count' => 0,
        'online_admission_total' => 0.0, 'online_admission_count' => 0,
        'cash_refund_total' => 0.0, 'cash_refund_count' => 0,
    ];

    $stmt = $pdo->prepare("
        SELECT (payment_method = 'cash') AS is_cash,
               COUNT(*) AS n, COALESCE(SUM(paid_amount), 0) AS total
        FROM bills
        WHERE status = 'paid' AND DATE(paid_at) = ?
        GROUP BY is_cash
    ");
    $stmt->execute([$date]);
    foreach ($stmt->fetchAll() as $r) {
        $k = ((int) $r['is_cash']) ? 'cash_consult' : 'online_consult';
        $t[$k . '_total'] = (float) $r['total'];
        $t[$k . '_count'] = (int) $r['n'];
    }

    $stmt = $pdo->prepare("
        SELECT (payment_method = 'cash') AS is_cash,
               COUNT(*) AS n, COALESCE(SUM(paid_amount), 0) AS total
        FROM admission_bills
        WHERE status = 'paid' AND DATE(paid_at) = ?
        GROUP BY is_cash
    ");
    $stmt->execute([$date]);
    foreach ($stmt->fetchAll() as $r) {
        $k = ((int) $r['is_cash']) ? 'cash_admission' : 'online_admission';
        $t[$k . '_total'] = (float) $r['total'];
        $t[$k . '_count'] = (int) $r['n'];
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS total
        FROM refunds
        WHERE refund_mode = 'cash' AND DATE(created_at) = ?
    ");
    $stmt->execute([$date]);
    $r = $stmt->fetch();
    $t['cash_refund_total'] = (float) $r['total'];
    $t['cash_refund_count'] = (int) $r['n'];

    $t['cash_total']   = round($t['cash_consult_total'] + $t['cash_admission_total'], 2);
    $t['cash_count']   = $t['cash_consult_count'] + $t['cash_admission_count'];
    $t['online_total'] = round($t['online_consult_total'] + $t['online_admission_total'], 2);
    $t['online_count'] = $t['online_consult_count'] + $t['online_admission_count'];
    $t['net_collected'] = round($t['cash_total'] + $t['online_total'] - $t['cash_refund_total'], 2);

    $t['opening_float'] = opening_float($pdo);
    $t['expected_cash'] = round($t['opening_float'] + $t['cash_total'] - $t['cash_refund_total'], 2);

    return $t;
}

// The closing row for a date, or null while the day is still open.
function day_closing(PDO $pdo, string $date): ?array {
    $stmt = $pdo->prepare('SELECT * FROM shift_closings WHERE closing_date = ?');
    $stmt->execute([$date]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// Guard for every money-moving action: once a date is closed, payments and
// refunds for that date are refused so the signed tally can never drift.
// Returns an error string to surface, or null when the day is open. Tolerates
// the migration not being run yet (table missing → treat as open).
function require_day_open(PDO $pdo, ?string $date = null): ?string {
    $date = $date ?: date('Y-m-d');
    try {
        $closing = day_closing($pdo, $date);
    } catch (PDOException $e) {
        return null;
    }
    if ($closing) {
        return 'Day ' . date('d M Y', strtotime($date)) . ' is closed (slip '
             . $closing['closing_number'] . ') — payments and refunds are locked '
             . 'so the signed tally cannot drift. Record it tomorrow.';
    }
    return null;
}
