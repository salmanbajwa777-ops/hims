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

    // Deterministic next number: take the greater of (a) the stored counter and
    // (b) the highest RF-YYYY-#### actually in `refunds`, then +1. Deriving from
    // the real max makes this immune to sequence drift — a re-import/reset that
    // leaves refund_sequences behind the rows on record can no longer cause a
    // duplicate refund_number (the failure seen live 2026-07-24). We do NOT rely
    // on LAST_INSERT_ID() here: on an ON DUPLICATE KEY *update* its return value
    // is unreliable across setups, which was re-issuing RF-2026-0001. Callers run
    // this inside the refund transaction under the bill-row FOR UPDATE lock, so
    // two concurrent refunds are already serialized on that lock.
    $stmt = $pdo->prepare("
        SELECT GREATEST(
            COALESCE((SELECT last_sequence FROM refund_sequences WHERE sequence_year = :y), 0),
            COALESCE((SELECT MAX(CAST(SUBSTRING_INDEX(refund_number, '-', -1) AS UNSIGNED))
                      FROM refunds WHERE refund_number LIKE :pfx), 0)
        ) + 1
    ");
    $stmt->execute([':y' => $year, ':pfx' => 'RF-' . $year . '-%']);
    $seq = (int) $stmt->fetchColumn();
    if ($seq < 1) {
        $seq = 1;
    }

    // Persist the new high-water mark so the counter tracks reality.
    $pdo->prepare('
        INSERT INTO refund_sequences (sequence_year, last_sequence)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE last_sequence = GREATEST(last_sequence, VALUES(last_sequence))
    ')->execute([$year, $seq]);

    return 'RF-' . $year . '-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
}

// How much of a bill has already been refunded (voided refunds don't count).
function refunded_total(PDO $pdo, int $billId): float {
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) AS t FROM refunds WHERE bill_id = ? AND voided_at IS NULL');
    $stmt->execute([$billId]);
    return (float) $stmt->fetch()['t'];
}

// Creates the bill for a freshly registered visit, seeded with the consultation
// fee line, and SETTLES it in the same transaction. Must be called inside an open
// transaction; returns the new bill id.
//
// Registration IS the point of sale: reception collects the fee (or the visit is a
// free follow-up) before the slip prints, so the bill is born settled rather than
// left 'draft' for a separate checkout step. Two settled outcomes:
//   - non-zero net fee  -> status 'paid',   paid_amount = net fee, cash tally counts it.
//   - zero net fee      -> status 'waived',  paid_amount = 0, EXCLUDED from cash counts
//                          (free follow-up / 100% discount — still tokened & queued).
// The caller must already have run require_day_open(): once a shift is closed no new
// paid bill may land on the signed tally.
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

    // Reception picks CASH/DIGITAL at registration; this IS the confirmed method — the
    // bill is settled here, not later. A free visit keeps a mode for the record even
    // though no money moved.
    $paymentMethod = $visitPaymentMode === 'CASH' ? 'cash' : ($visitPaymentMode === 'DIGITAL' ? 'card' : null);

    $consultFee = round($fee * (1 - ($discountPct / 100)), 2);
    // A zero net fee is a waived (free) visit — settled but off the cash tally.
    $status = $consultFee > 0 ? 'paid' : 'waived';

    // paid_by_id owns the money on the collector's shift tally. Fall back to the
    // pre-per-user-closings insert if that column isn't present yet, so registration
    // never breaks mid-deploy (same pattern as checkout.php record_payment).
    try {
        $pdo->prepare('
            INSERT INTO bills (invoice_number, visit_id, sales_tax_percent, consolidation_rate_percent, payment_method, status, paid_amount, paid_at, paid_by_id, created_by_id)
            VALUES (?, ?, 0, 0, ?, ?, ?, NOW(), ?, ?)
        ')->execute([$invoiceNumber, $visitId, $paymentMethod, $status, $consultFee, $userId, $userId]);
    } catch (PDOException $e) {
        $pdo->prepare('
            INSERT INTO bills (invoice_number, visit_id, sales_tax_percent, consolidation_rate_percent, payment_method, status, paid_amount, paid_at, created_by_id)
            VALUES (?, ?, 0, 0, ?, ?, ?, NOW(), ?)
        ')->execute([$invoiceNumber, $visitId, $paymentMethod, $status, $consultFee, $userId]);
    }
    $billId = (int) $pdo->lastInsertId();

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
    $yymm = substr((string) $year, 2, 2) . str_pad((string) $month, 2, '0', STR_PAD_LEFT);

    // Don't trust the counter alone: LAST_INSERT_ID() on an ON DUPLICATE KEY
    // *update* is unreliable across setups (this re-issued duplicate numbers for
    // EXP-/RF- series after a restore or re-run migration). Take GREATEST of the
    // stored counter and the real max already in admission_bills for this month,
    // then persist. The stored number is "A{seq}{YY}{MM}" so the sequence is the
    // middle: strip the leading "A" and the trailing 4 chars (YYMM).
    $stmt = $pdo->prepare("
        SELECT GREATEST(
            COALESCE((SELECT next_seq - 1 FROM admission_invoice_counters WHERE yr = :y AND mo = :m), 0),
            COALESCE((SELECT MAX(CAST(SUBSTRING(invoice_number, 2, CHAR_LENGTH(invoice_number) - 5) AS UNSIGNED))
                      FROM admission_bills WHERE invoice_number LIKE :pfx), 0)
        ) + 1
    ");
    $stmt->execute([':y' => $year, ':m' => $month, ':pfx' => 'A%' . $yymm]);
    $seq = (int) $stmt->fetchColumn();
    if ($seq < 1) {
        $seq = 1;
    }

    // Persist the high-water mark. next_seq is "the next number to hand out", so
    // store seq + 1.
    $pdo->prepare('
        INSERT INTO admission_invoice_counters (yr, mo, next_seq)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE next_seq = GREATEST(next_seq, VALUES(next_seq))
    ')->execute([$year, $month, $seq + 1]);

    return 'A' . $seq . $yymm;
}

// ER walk-in service invoice number: "E" + sequence + YY + MM (e.g. E1202607 is
// the 12th ER bill of July 2026). Same GREATEST-of-(counter, real max) pattern as
// the admission series so it survives DB re-imports, with its own counter table so
// the "E" series never collides with the consult / "A" / IPD numbers.
function generate_er_invoice_number(PDO $pdo): string {
    $year = (int) date('Y');
    $month = (int) date('n');
    $yymm = substr((string) $year, 2, 2) . str_pad((string) $month, 2, '0', STR_PAD_LEFT);

    $stmt = $pdo->prepare("
        SELECT GREATEST(
            COALESCE((SELECT next_seq - 1 FROM er_invoice_counters WHERE yr = :y AND mo = :m), 0),
            COALESCE((SELECT MAX(CAST(SUBSTRING(invoice_number, 2, CHAR_LENGTH(invoice_number) - 5) AS UNSIGNED))
                      FROM er_bills WHERE invoice_number LIKE :pfx), 0)
        ) + 1
    ");
    $stmt->execute([':y' => $year, ':m' => $month, ':pfx' => 'E%' . $yymm]);
    $seq = (int) $stmt->fetchColumn();
    if ($seq < 1) {
        $seq = 1;
    }

    $pdo->prepare('
        INSERT INTO er_invoice_counters (yr, mo, next_seq)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE next_seq = GREATEST(next_seq, VALUES(next_seq))
    ')->execute([$year, $month, $seq + 1]);

    return 'E' . $seq . $yymm;
}

// "P" series for procedure bills (sql/add_procedure_bills.sql). Same
// GREATEST(stored counter, real max) guard as the A/E series — see the comment
// on generate_admission_invoice_number() for why the counter alone isn't
// trusted. Stored as "P{seq}{YY}{MM}", so the sequence is the middle: strip the
// leading "P" and the trailing 4 chars.
function generate_procedure_invoice_number(PDO $pdo): string {
    $year = (int) date('Y');
    $month = (int) date('n');
    $yymm = substr((string) $year, 2, 2) . str_pad((string) $month, 2, '0', STR_PAD_LEFT);

    $stmt = $pdo->prepare("
        SELECT GREATEST(
            COALESCE((SELECT next_seq - 1 FROM procedure_invoice_counters WHERE yr = :y AND mo = :m), 0),
            COALESCE((SELECT MAX(CAST(SUBSTRING(invoice_number, 2, CHAR_LENGTH(invoice_number) - 5) AS UNSIGNED))
                      FROM procedure_bills WHERE invoice_number LIKE :pfx), 0)
        ) + 1
    ");
    $stmt->execute([':y' => $year, ':m' => $month, ':pfx' => 'P%' . $yymm]);
    $seq = (int) $stmt->fetchColumn();
    if ($seq < 1) {
        $seq = 1;
    }

    $pdo->prepare('
        INSERT INTO procedure_invoice_counters (yr, mo, next_seq)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE next_seq = GREATEST(next_seq, VALUES(next_seq))
    ')->execute([$year, $month, $seq + 1]);

    return 'P' . $seq . $yymm;
}

// What a doctor earned from PROCEDURES in a date range, split tax-first.
//
// Procedures differ from consultations: the share is per-PROCEDURE, not one
// rate per doctor, so this cannot go through doctor_split() on an aggregate.
// Each line carries its own snapshot (doctor_share_pct / has_tax /
// tax_percent), so the split is summed line-by-line in SQL via
// doctor_split_sql() over the ITEM columns.
//
// Cash basis keyed off pb.paid_at, matching every other money figure. Voided
// bills are excluded. $from / $toExcl are 'Y-m-d' bounds, upper exclusive.
//
// Returns ['gross','tax','doctor','clinic','bills','live'] — 'live' is false
// when the tables don't exist yet (migration not run), which lets callers show
// "not billed yet" instead of a misleading zero.
function doctor_procedure_earnings(PDO $pdo, int $doctorId, string $from, string $toExcl): array {
    $zero = ['gross' => 0.0, 'tax' => 0.0, 'doctor' => 0.0, 'clinic' => 0.0, 'bills' => 0, 'live' => false];
    if ($doctorId <= 0) {
        return $zero;
    }

    $doc    = doctor_split_sql('i.amount', 'i.doctor_share_pct', 'i.has_tax', 'i.tax_percent', 'doctor');
    $tax    = doctor_split_sql('i.amount', 'i.doctor_share_pct', 'i.has_tax', 'i.tax_percent', 'tax');
    $clinic = doctor_split_sql('i.amount', 'i.doctor_share_pct', 'i.has_tax', 'i.tax_percent', 'clinic');

    try {
        $q = $pdo->prepare("
            SELECT COUNT(DISTINCT pb.id)         AS bills,
                   COALESCE(SUM(i.amount), 0)    AS gross,
                   COALESCE(SUM($tax), 0)        AS tax,
                   COALESCE(SUM($doc), 0)        AS doctor,
                   COALESCE(SUM($clinic), 0)     AS clinic
            FROM procedure_bill_items i
            JOIN procedure_bills pb ON pb.id = i.procedure_bill_id
            WHERE pb.doctor_id = ?
              AND pb.voided_at IS NULL
              AND pb.paid_at >= ? AND pb.paid_at < ?
        ");
        $q->execute([$doctorId, $from, $toExcl]);
        $r = $q->fetch();
    } catch (PDOException $e) {
        // Tables absent = migration not run. Not an error worth failing a whole
        // statement page over.
        return $zero;
    }

    return [
        'gross'  => (float) ($r['gross'] ?? 0),
        'tax'    => (float) ($r['tax'] ?? 0),
        'doctor' => (float) ($r['doctor'] ?? 0),
        'clinic' => (float) ($r['clinic'] ?? 0),
        'bills'  => (int) ($r['bills'] ?? 0),
        'live'   => true,
    ];
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
    // subtotal = sum of line amounts. Lines are stored NET of the automatic
    // category discount, so subtotal is already the category-discounted total.
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) AS subtotal FROM admission_bill_items WHERE admission_bill_id = ?');
    $stmt->execute([$admissionBillId]);
    $subtotal = (float) $stmt->fetch()['subtotal'];

    // A manual discharge discount REPLACES the category discount for the grand
    // total: restore the gross (subtotal + the category discount already baked
    // into the lines) and subtract the manual amount instead. With no manual
    // discount, grand_total = subtotal (category discount stays applied) — the
    // original behaviour, untouched.
    $b = $pdo->prepare('SELECT discount_amount, manual_discount_amount FROM admission_bills WHERE id = ?');
    $b->execute([$admissionBillId]);
    $row = $b->fetch() ?: ['discount_amount' => 0, 'manual_discount_amount' => 0];
    $categoryDiscount = (float) ($row['discount_amount'] ?? 0);
    $manual = (float) ($row['manual_discount_amount'] ?? 0);

    if ($manual > 0) {
        $gross = round($subtotal + $categoryDiscount, 2);      // undo the category discount
        $grand = max(0, round($gross - $manual, 2));
    } else {
        $grand = $subtotal;
    }

    $pdo->prepare('
        UPDATE admission_bills SET subtotal = ?, grand_total = ? WHERE id = ?
    ')->execute([$subtotal, $grand, $admissionBillId]);
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
        SELECT dc.id, dc.name, dc.consultation_pct, dc.er_services_pct, dc.room_stay_pct, dc.procedures_pct
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
// Shift closing / cash tally / handover (approved 2026-07-23; reworked to
// PER-RECEPTIONIST the same day — see sql/add_per_user_closings.sql).
//
// Each receptionist closes THEIR OWN shift: expected cash = the cash payments
// THEY recorded (bills/admission_bills.paid_by_id) − the cash refunds THEY
// generated − the expenses THEY posted. No float in personal tallies (the
// physical drawer float is admin's concern). Closing locks only that user's
// money actions; colleagues keep working. The cashier may edit their closing
// while PENDING_RECEIPT/EDITED — changes apply immediately, log per-field in
// shift_closing_edits, and email admin for approval at mark-received time.
//
// "Cash vs online": patients only ever pay Cash or Online. Historically the
// bills enum spelled online as card/bank_transfer/cheque (DIGITAL mapped to
// 'card' at registration), so the tally treats every non-cash method as
// online rather than migrating old rows.
// ============================================================================

// Yearly closing-slip number, e.g. "DC-2026-0187". Same GREATEST-of-counter-and-
// actual-max pattern as generate_refund_number(): don't trust LAST_INSERT_ID() on
// an ON DUPLICATE KEY update — it re-issues DC-2026-0001 when the counter falls
// behind the real max in shift_closings (restore / re-run migration).
function generate_closing_number(PDO $pdo): string {
    $year = (int) date('Y');

    $stmt = $pdo->prepare("
        SELECT GREATEST(
            COALESCE((SELECT last_sequence FROM closing_sequences WHERE sequence_year = :y), 0),
            COALESCE((SELECT MAX(CAST(SUBSTRING_INDEX(closing_number, '-', -1) AS UNSIGNED))
                      FROM shift_closings WHERE closing_number LIKE :pfx), 0)
        ) + 1
    ");
    $stmt->execute([':y' => $year, ':pfx' => 'DC-' . $year . '-%']);
    $seq = (int) $stmt->fetchColumn();
    if ($seq < 1) {
        $seq = 1;
    }

    $pdo->prepare('
        INSERT INTO closing_sequences (sequence_year, last_sequence)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE last_sequence = GREATEST(last_sequence, VALUES(last_sequence))
    ')->execute([$year, $seq]);

    return 'DC-' . $year . '-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
}

// The drawer's opening float (admin-configurable via clinic_settings).
function opening_float(PDO $pdo): float {
    $stmt = $pdo->prepare("SELECT setting_value FROM clinic_settings WHERE setting_key = 'opening_float'");
    $stmt->execute();
    return (float) ($stmt->fetchColumn() ?: 0);
}

// The hour (0–23, PKT) at which one business day rolls into the next. A late
// shift that runs past midnight but before this hour still belongs to the day
// it started, so the receptionist closes ONE shift for the whole night and the
// evening's takings don't split across two calendar dates. Default 04:00.
// Admin-configurable via clinic_settings ('day_cutoff_hour'). Cached per request.
function day_cutoff_hour(PDO $pdo): int {
    static $hour = null;
    if ($hour !== null) {
        return $hour;
    }
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM clinic_settings WHERE setting_key = 'day_cutoff_hour'");
        $stmt->execute();
        $raw = $stmt->fetchColumn();
    } catch (PDOException $e) {
        $raw = false;   // clinic_settings missing — fall back to default
    }
    $h = ($raw === false || $raw === null || $raw === '') ? 4 : (int) $raw;
    return $hour = max(0, min(23, $h));
}

// The current business day (Y-m-d) given the cutoff hour: before the cutoff we
// are still on the previous calendar day. Pass a specific wall-clock time to
// resolve which business day a payment/refund belongs to.
function business_day(PDO $pdo, ?string $atDateTime = null): string {
    $cutoff = day_cutoff_hour($pdo);
    $ts = $atDateTime ? strtotime($atDateTime) : time();
    if ($ts === false) {
        $ts = time();
    }
    if ($cutoff > 0 && (int) date('G', $ts) < $cutoff) {
        $ts -= 86400;   // still yesterday's business day
    }
    return date('Y-m-d', $ts);
}

// The [start, end) wall-clock window that a business day covers, so tally
// queries can range over paid_at/created_at datetimes instead of DATE()=.
// A cutoff of 4 means business day D runs D 04:00:00 → (D+1) 04:00:00.
function business_day_window(PDO $pdo, string $businessDate): array {
    $cutoff = day_cutoff_hour($pdo);
    $start = sprintf('%s %02d:00:00', $businessDate, $cutoff);
    $end   = date('Y-m-d H:i:s', strtotime($start) + 86400);
    return [$start, $end];
}

// One receptionist's system-side tally for one date: the cash/online payments
// THEY recorded (paid_by_id), the cash refunds THEY generated, the expenses
// THEY posted, and the expected-cash figure their physical count is checked
// against. No float — expected = own cash − own cash refunds − own expenses.
// Keys off when money actually moved, over the BUSINESS-DAY window (cutoff-hour
// aware) rather than the calendar date, so a shift running past midnight tallies
// the whole night as one day. paid_at/created_at are ranged [start, end);
// expenses key off the expense_date DATE column (posted for a business day).
function day_cash_tally(PDO $pdo, string $date, int $userId): array {
    [$winStart, $winEnd] = business_day_window($pdo, $date);

    $t = [
        'cash_consult_total' => 0.0, 'cash_consult_count' => 0,
        'cash_admission_total' => 0.0, 'cash_admission_count' => 0,
        'online_consult_total' => 0.0, 'online_consult_count' => 0,
        'online_admission_total' => 0.0, 'online_admission_count' => 0,
        'cash_er_total' => 0.0, 'cash_er_count' => 0,
        'online_er_total' => 0.0, 'online_er_count' => 0,
        'cash_refund_total' => 0.0, 'cash_refund_count' => 0,
        'expense_total' => 0.0, 'expense_count' => 0,
    ];

    $stmt = $pdo->prepare("
        SELECT (payment_method = 'cash') AS is_cash,
               COUNT(*) AS n, COALESCE(SUM(paid_amount), 0) AS total
        FROM bills
        WHERE status = 'paid' AND voided_at IS NULL AND paid_at >= ? AND paid_at < ? AND paid_by_id = ?
        GROUP BY is_cash
    ");
    $stmt->execute([$winStart, $winEnd, $userId]);
    foreach ($stmt->fetchAll() as $r) {
        $k = ((int) $r['is_cash']) ? 'cash_consult' : 'online_consult';
        $t[$k . '_total'] = (float) $r['total'];
        $t[$k . '_count'] = (int) $r['n'];
    }

    $stmt = $pdo->prepare("
        SELECT (payment_method = 'cash') AS is_cash,
               COUNT(*) AS n, COALESCE(SUM(paid_amount), 0) AS total
        FROM admission_bills
        WHERE status = 'paid' AND voided_at IS NULL AND paid_at >= ? AND paid_at < ? AND paid_by_id = ?
        GROUP BY is_cash
    ");
    $stmt->execute([$winStart, $winEnd, $userId]);
    foreach ($stmt->fetchAll() as $r) {
        $k = ((int) $r['is_cash']) ? 'cash_admission' : 'online_admission';
        $t[$k . '_total'] = (float) $r['total'];
        $t[$k . '_count'] = (int) $r['n'];
    }

    // IPD (In-Door) bills fold into the SAME admission buckets so a receptionist's
    // signed shift close covers IPD cash without a new UI field. Additive and
    // guarded — an unmigrated ipd_bills table degrades to "no IPD cash", never a
    // fatal. (This is the one deliberate IPD touch of billing.php.)
    try {
        $stmt = $pdo->prepare("
            SELECT (payment_method = 'cash') AS is_cash,
                   COUNT(*) AS n, COALESCE(SUM(paid_amount), 0) AS total
            FROM ipd_bills
            WHERE status = 'paid' AND voided_at IS NULL AND paid_at >= ? AND paid_at < ? AND paid_by_id = ?
            GROUP BY is_cash
        ");
        $stmt->execute([$winStart, $winEnd, $userId]);
        foreach ($stmt->fetchAll() as $r) {
            $k = ((int) $r['is_cash']) ? 'cash_admission' : 'online_admission';
            $t[$k . '_total'] += (float) $r['total'];
            $t[$k . '_count'] += (int) $r['n'];
        }
    } catch (Throwable $e) {
        // ipd_bills not migrated yet — leave admission buckets as-is.
    }

    // ER walk-in service bills (their own "E" series, no admission). Kept in their
    // OWN cash_er_/online_er_ keys for the live Day Closing page to show ER on its
    // own line, AND folded into the admission buckets (like IPD) so the STORED
    // closing columns + the printed A5 slip capture ER cash without a schema change.
    // Guarded — an unmigrated er_bills table degrades to "no ER cash", never a fatal.
    try {
        $stmt = $pdo->prepare("
            SELECT (payment_method = 'cash') AS is_cash,
                   COUNT(*) AS n, COALESCE(SUM(paid_amount), 0) AS total
            FROM er_bills
            WHERE status = 'paid' AND voided_at IS NULL AND paid_at >= ? AND paid_at < ? AND paid_by_id = ?
            GROUP BY is_cash
        ");
        $stmt->execute([$winStart, $winEnd, $userId]);
        foreach ($stmt->fetchAll() as $r) {
            $isCash = (int) $r['is_cash'];
            $erKey = $isCash ? 'cash_er' : 'online_er';
            $t[$erKey . '_total'] = (float) $r['total'];
            $t[$erKey . '_count'] = (int) $r['n'];
            // Also fold into the admission buckets so stored totals + slip include it.
            $admKey = $isCash ? 'cash_admission' : 'online_admission';
            $t[$admKey . '_total'] += (float) $r['total'];
            $t[$admKey . '_count'] += (int) $r['n'];
        }
    } catch (Throwable $e) {
        // er_bills not migrated yet — leave ER buckets at zero.
    }

    // Cash refunds are attributed to the DRAWER they came out of (paid_out_by_id)
    // — normally the receptionist who took the original payment — not whoever
    // clicked "Refund" (generated_by_id could be a doctor/admin with no drawer).
    // COALESCE keeps any legacy row with a NULL drawer behaving as it did before.
    // paid_out_by_id only exists after add_refund_paid_out_by.sql; fall back to
    // the pre-migration behaviour (attribute to the clicker) when it is absent.
    $drawerExpr = column_exists($pdo, 'refunds', 'paid_out_by_id')
        ? 'COALESCE(paid_out_by_id, generated_by_id)'
        : 'generated_by_id';
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS total
        FROM refunds
        WHERE refund_mode = 'cash' AND voided_at IS NULL AND created_at >= ? AND created_at < ?
          AND $drawerExpr = ?
    ");
    $stmt->execute([$winStart, $winEnd, $userId]);
    $r = $stmt->fetch();
    $t['cash_refund_total'] = (float) $r['total'];
    $t['cash_refund_count'] = (int) $r['n'];

    // Counter expenses (EXP- vouchers) this user paid out of their takings.
    // Voided rows are excluded; tolerate the expenses table missing.
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS total
            FROM expenses
            WHERE source = 'CASH_COUNTER' AND expense_date = ? AND posted_by_id = ?
              AND voided_at IS NULL
        ");
        $stmt->execute([$date, $userId]);
        $r = $stmt->fetch();
        $t['expense_total'] = (float) $r['total'];
        $t['expense_count'] = (int) $r['n'];
    } catch (PDOException $e) {
        // expenses module not migrated — treat as zero
    }

    // ER is already folded into the admission buckets above, so it is NOT added
    // again here (that would double-count). cash_er_/online_er_ remain available
    // for the live page to show ER split out from admissions.
    $t['cash_total']   = round($t['cash_consult_total'] + $t['cash_admission_total'], 2);
    $t['cash_count']   = $t['cash_consult_count'] + $t['cash_admission_count'];
    $t['online_total'] = round($t['online_consult_total'] + $t['online_admission_total'], 2);
    $t['online_count'] = $t['online_consult_count'] + $t['online_admission_count'];
    $t['net_collected'] = round($t['cash_total'] + $t['online_total'] - $t['cash_refund_total'], 2);

    // Personal accountability only — no drawer float here.
    $t['expected_cash'] = round(
        $t['cash_total'] - $t['cash_refund_total'] - $t['expense_total'], 2
    );

    return $t;
}

// Cheap schema probe so code tolerates a migration not being run yet. Cached per
// (table, column) for the request. Returns false on any error (missing table etc).
function column_exists(PDO $pdo, string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    try {
        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.columns
                               WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1');
        $stmt->execute([$table, $column]);
        return $cache[$key] = (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

// Drawer-holding receptionists: every ACTIVE user whose EFFECTIVE permissions
// include RECEPTION_CLOSE_DAY (role default ∪ per-user grant, minus per-user
// revoke). This is the permission-driven "who runs a cash drawer" signal — the
// base_role enum was collapsed to ADMIN/DOCTOR/STAFF, so role name no longer
// identifies receptionists. Returns [['id'=>.., 'name'=>..], ...] ordered by name.
function receptionists_with_drawer(PDO $pdo): array {
    $sql = "
        SELECT u.id, u.name
        FROM users u
        WHERE u.is_active = 1
          AND (
            EXISTS (
                SELECT 1 FROM role_permissions rp
                JOIN permissions p ON p.id = rp.permission_id
                WHERE rp.base_role = u.base_role AND p.`key` = 'RECEPTION_CLOSE_DAY'
            )
            OR EXISTS (
                SELECT 1 FROM user_permission_overrides o
                JOIN permissions p ON p.id = o.permission_id
                WHERE o.user_id = u.id AND p.`key` = 'RECEPTION_CLOSE_DAY' AND o.granted = 1
            )
          )
          AND NOT EXISTS (
                SELECT 1 FROM user_permission_overrides o
                JOIN permissions p ON p.id = o.permission_id
                WHERE o.user_id = u.id AND p.`key` = 'RECEPTION_CLOSE_DAY' AND o.granted = 0
          )
        ORDER BY u.name
    ";
    try {
        return $pdo->query($sql)->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

// True if this user currently holds a cash drawer (effective RECEPTION_CLOSE_DAY).
function user_holds_drawer(PDO $pdo, int $userId): bool {
    if ($userId <= 0) {
        return false;
    }
    foreach (receptionists_with_drawer($pdo) as $r) {
        if ((int) $r['id'] === $userId) {
            return true;
        }
    }
    return false;
}

// Which drawer a CASH refund should be charged to. Normally the receptionist who
// took the ORIGINAL payment (bills.paid_by_id), collected minutes earlier — even
// when a doctor/admin clicks Refund. Returns that user id when it is a valid
// drawer holder, else null to signal "the clicker must pick a receptionist".
function resolve_refund_drawer(PDO $pdo, array $bill, int $clickerId): ?int {
    $originalPayer = (int) ($bill['paid_by_id'] ?? 0);
    if ($originalPayer > 0 && user_holds_drawer($pdo, $originalPayer)) {
        return $originalPayer;
    }
    // Fall back to the clicker only when THEY hold a drawer (the receptionist is
    // refunding at their own counter). Otherwise null → the form prompts for a payer.
    if (user_holds_drawer($pdo, $clickerId)) {
        return $clickerId;
    }
    return null;
}

// A receptionist's unclosed BUSINESS days within the last $lookback days that had
// money movement (so we don't nag about days they weren't working). Newest first.
// Each entry: ['date'=>Y-m-d, 'expected_cash'=>float, 'age_days'=>int]. Excludes
// the live business day only when $includeToday is false.
function unclosed_business_days(PDO $pdo, int $userId, int $lookback = 5, bool $includeToday = true): array {
    $todayBiz = business_day($pdo);
    $out = [];
    for ($i = ($includeToday ? 0 : 1); $i <= $lookback; $i++) {
        $date = date('Y-m-d', strtotime($todayBiz . " -{$i} day"));
        if (day_closing($pdo, $date, $userId)) {
            continue;   // already closed
        }
        $tally = day_cash_tally($pdo, $date, $userId);
        $hadMoney = ($tally['cash_count'] + $tally['online_count'] + $tally['cash_refund_count'] + $tally['expense_count']) > 0;
        if (!$hadMoney) {
            continue;   // nothing to close on that day
        }
        $out[] = [
            'date'          => $date,
            'expected_cash' => (float) $tally['expected_cash'],
            'age_days'      => $i,
        ];
    }
    return $out;   // already newest-first (i grows into the past)
}

// One user's closing row for a date, or null while their shift is still open.
function day_closing(PDO $pdo, string $date, int $userId): ?array {
    $stmt = $pdo->prepare('SELECT * FROM shift_closings WHERE closing_date = ? AND cashier_id = ?');
    $stmt->execute([$date, $userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// Guard for money-moving actions: once THIS USER has closed their shift for a
// date, their own payments/refunds/expenses on that date are refused so the
// signed tally can never drift. Other receptionists are unaffected. Returns an
// error string to surface, or null when the user's shift is open. Tolerates
// the migration not being run yet (table missing → treat as open).
function require_day_open(PDO $pdo, ?string $date = null, ?int $userId = null): ?string {
    // Default to the BUSINESS day (cutoff-aware) so a payment taken at 00:30
    // maps to the shift that is still open, not a fresh calendar day.
    $date = $date ?: business_day($pdo);
    $userId = $userId ?: (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        return null;
    }
    try {
        $closing = day_closing($pdo, $date, $userId);
    } catch (PDOException $e) {
        return null;
    }
    if ($closing) {
        return 'Your shift for ' . date('d/m/Y', strtotime($date)) . ' is closed (slip '
             . $closing['closing_number'] . ') — your payments and refunds for that day are locked. '
             . 'To correct the count, edit the closing itself on the Day Closing page.';
    }
    return null;
}

// ============================================================================
// Admin VOID for billable actions (soft-void, mirrors the expenses void).
// A voided row keeps its number for audit but drops out of every money
// calculation. Each void:
//   * refuses if the payment's shift is already closed (signed tally) — the
//     void would change that day's counted cash;
//   * stamps voided_at / voided_by_id / void_reason;
//   * writes an audit_logs entry;
//   * reverses any side effects on other tables (write-off patient rollup).
//
// Returns [ok(bool), message(string)]. Callers gate on FINANCIAL_VOID_BILL.
// Requires the add_void_actions.sql migration; tolerates it being unrun by
// reporting a clean error rather than throwing.
// ============================================================================

// Void a consultation bill (`bills`). The whole visit's money reverses because
// every cash/revenue read filters status='paid' or the new voided_at IS NULL.
function void_bill(PDO $pdo, int $billId, int $userId, string $reason): array {
    $reason = trim($reason);
    if ($reason === '') {
        return [false, 'A void needs a reason.'];
    }
    $stmt = $pdo->prepare('SELECT b.id, b.invoice_number, b.status, b.paid_at, b.paid_by_id, b.created_by_id, b.voided_at
                           FROM bills b WHERE b.id = ?');
    $stmt->execute([$billId]);
    $bill = $stmt->fetch();
    if (!$bill) {
        return [false, 'Invoice not found.'];
    }
    if (!array_key_exists('voided_at', $bill)) {
        return [false, 'Void is not available yet — run the add_void_actions.sql migration first.'];
    }
    if ($bill['voided_at'] !== null) {
        return [false, 'This invoice is already voided.'];
    }
    // Any refund already raised against this bill must be voided first, else the
    // net-collected math (paid − refunded) would go inconsistent.
    $rf = $pdo->prepare('SELECT COUNT(*) FROM refunds WHERE bill_id = ? AND voided_at IS NULL');
    $rf->execute([$billId]);
    if ((int) $rf->fetchColumn() > 0) {
        return [false, 'Void the refund(s) on this invoice first, then void the invoice.'];
    }
    // Shift lock: attribute to the collector's shift on the paid date.
    if ($bill['status'] === 'paid' && $bill['paid_at']) {
        $lockDate = date('Y-m-d', strtotime($bill['paid_at']));
        $lockUser = (int) ($bill['paid_by_id'] ?: $bill['created_by_id']);
        $dayLock = require_day_open($pdo, $lockDate, $lockUser);
        if ($dayLock) {
            return [false, $dayLock . ' Voiding this invoice would change that day\'s signed tally.'];
        }
    }
    $upd = $pdo->prepare('UPDATE bills SET voided_at = NOW(), voided_by_id = ?, void_reason = ? WHERE id = ? AND voided_at IS NULL');
    $upd->execute([$userId, $reason, $billId]);
    if ($upd->rowCount() !== 1) {
        return [false, 'Could not void the invoice (already voided?).'];
    }
    $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
        ->execute([$userId, 'bill_voided', "Voided invoice {$bill['invoice_number']} (bill #$billId) — $reason"]);
    return [true, "Invoice {$bill['invoice_number']} voided. The number is kept for the record."];
}

// Void a refund (`refunds`). Reverses the refund so the invoice's refundable
// balance is restored and cash-refund tallies exclude it.
function void_refund(PDO $pdo, int $refundId, int $userId, string $reason): array {
    $reason = trim($reason);
    if ($reason === '') {
        return [false, 'A void needs a reason.'];
    }
    // paid_out_by_id may be absent if the add_refund_paid_out_by.sql migration
    // hasn't run yet — select it defensively so an unmigrated DB still voids.
    $hasDrawerCol = column_exists($pdo, 'refunds', 'paid_out_by_id');
    $drawerSelect = $hasDrawerCol ? 'paid_out_by_id, ' : '';
    $stmt = $pdo->prepare("SELECT id, refund_number, refund_mode, created_at, generated_by_id, {$drawerSelect}voided_at
                           FROM refunds WHERE id = ?");
    $stmt->execute([$refundId]);
    $r = $stmt->fetch();
    if (!$r) {
        return [false, 'Refund not found.'];
    }
    if (!array_key_exists('voided_at', $r)) {
        return [false, 'Void is not available yet — run the add_void_actions.sql migration first.'];
    }
    if ($r['voided_at'] !== null) {
        return [false, 'This refund is already voided.'];
    }
    // A cash refund left a specific drawer — refuse if THAT receptionist's shift
    // is closed (their signed tally already accounts for the refund). The drawer
    // is paid_out_by_id, falling back to the generator for legacy/NULL rows.
    if ($r['refund_mode'] === 'cash' && $r['created_at']) {
        $lockDate = date('Y-m-d', strtotime($r['created_at']));
        $drawerId = (int) (($r['paid_out_by_id'] ?? null) ?: $r['generated_by_id']);
        $dayLock = require_day_open($pdo, $lockDate, $drawerId);
        if ($dayLock) {
            return [false, $dayLock . ' Voiding this refund would change that day\'s signed tally.'];
        }
    }
    $upd = $pdo->prepare('UPDATE refunds SET voided_at = NOW(), voided_by_id = ?, void_reason = ? WHERE id = ? AND voided_at IS NULL');
    $upd->execute([$userId, $reason, $refundId]);
    if ($upd->rowCount() !== 1) {
        return [false, 'Could not void the refund (already voided?).'];
    }
    $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
        ->execute([$userId, 'refund_voided', "Voided refund {$r['refund_number']} (#$refundId) — $reason"]);
    return [true, "Refund {$r['refund_number']} voided. The number is kept for the record."];
}

// Void an admission bill (`admission_bills`). Reverses the admission invoice and,
// if it carried an approved write-off, backs the patient unpaid rollup out too.
function void_admission_bill(PDO $pdo, int $admBillId, int $userId, string $reason): array {
    $reason = trim($reason);
    if ($reason === '') {
        return [false, 'A void needs a reason.'];
    }
    $stmt = $pdo->prepare('SELECT ab.id, ab.invoice_number, ab.status, ab.paid_at, ab.paid_by_id,
                                  ab.finalized_by_id, ab.write_off_amount, ab.admission_id, ab.voided_at,
                                  a.patient_id
                           FROM admission_bills ab
                           JOIN admissions a ON a.id = ab.admission_id
                           WHERE ab.id = ?');
    $stmt->execute([$admBillId]);
    $ab = $stmt->fetch();
    if (!$ab) {
        return [false, 'Admission bill not found.'];
    }
    if (!array_key_exists('voided_at', $ab)) {
        return [false, 'Void is not available yet — run the add_void_actions.sql migration first.'];
    }
    if ($ab['voided_at'] !== null) {
        return [false, 'This admission bill is already voided.'];
    }
    if ($ab['status'] === 'paid' && $ab['paid_at']) {
        $lockDate = date('Y-m-d', strtotime($ab['paid_at']));
        $lockUser = (int) ($ab['paid_by_id'] ?: $ab['finalized_by_id']);
        $dayLock = require_day_open($pdo, $lockDate, $lockUser);
        if ($dayLock) {
            return [false, $dayLock . ' Voiding this admission bill would change that day\'s signed tally.'];
        }
    }
    $pdo->beginTransaction();
    try {
        $upd = $pdo->prepare('UPDATE admission_bills SET voided_at = NOW(), voided_by_id = ?, void_reason = ? WHERE id = ? AND voided_at IS NULL');
        $upd->execute([$userId, $reason, $admBillId]);
        if ($upd->rowCount() !== 1) {
            $pdo->rollBack();
            return [false, 'Could not void the admission bill (already voided?).'];
        }
        // Reverse the patient unpaid rollup for any write-off this bill drove.
        $woAmt = (float) ($ab['write_off_amount'] ?? 0);
        if ($woAmt > 0) {
            $pdo->prepare('UPDATE patients
                              SET unpaid_total = GREATEST(0, unpaid_total - ?),
                                  unpaid_count = GREATEST(0, unpaid_count - 1)
                              WHERE id = ?')
                ->execute([$woAmt, (int) $ab['patient_id']]);
            // Clear the flag if nothing unpaid remains.
            $pdo->prepare('UPDATE patients SET unpaid_flag = 0 WHERE id = ? AND unpaid_total <= 0')
                ->execute([(int) $ab['patient_id']]);
        }
        $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
            ->execute([$userId, 'admission_bill_voided', "Voided admission bill {$ab['invoice_number']} (#$admBillId) — $reason"]);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('[void_admission_bill] ' . $e->getMessage());
        return [false, 'Could not void the admission bill. Please try again.'];
    }
    return [true, "Admission bill {$ab['invoice_number']} voided. The number is kept for the record."];
}

// Void an ER walk-in bill (`er_bills`). Reverses the bill so its cash drops out
// of the tally. Refused if the payer's shift is already closed (their signed
// tally already counted it). Mirrors void_refund / void_admission_bill.
function void_er_bill(PDO $pdo, int $erBillId, int $userId, string $reason): array {
    $reason = trim($reason);
    if ($reason === '') {
        return [false, 'A void needs a reason.'];
    }
    $stmt = $pdo->prepare('SELECT id, invoice_number, status, paid_at, paid_by_id, created_by_id, voided_at
                           FROM er_bills WHERE id = ?');
    $stmt->execute([$erBillId]);
    $eb = $stmt->fetch();
    if (!$eb) {
        return [false, 'ER bill not found.'];
    }
    if ($eb['voided_at'] !== null) {
        return [false, 'This ER bill is already voided.'];
    }
    if ($eb['status'] === 'paid' && $eb['paid_at']) {
        $lockDate = date('Y-m-d', strtotime($eb['paid_at']));
        $lockUser = (int) ($eb['paid_by_id'] ?: $eb['created_by_id']);
        $dayLock = require_day_open($pdo, $lockDate, $lockUser);
        if ($dayLock) {
            return [false, $dayLock . ' Voiding this ER bill would change that day\'s signed tally.'];
        }
    }
    $pdo->beginTransaction();
    try {
        $upd = $pdo->prepare("UPDATE er_bills SET status = 'voided', voided_at = NOW(), voided_by_id = ?, void_reason = ? WHERE id = ? AND voided_at IS NULL");
        $upd->execute([$userId, $reason, $erBillId]);
        if ($upd->rowCount() !== 1) {
            $pdo->rollBack();
            return [false, 'Could not void the ER bill (already voided?).'];
        }
        $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
            ->execute([$userId, 'er_bill_voided', "Voided ER bill {$eb['invoice_number']} (#$erBillId) — $reason"]);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('[void_er_bill] ' . $e->getMessage());
        return [false, 'Could not void the ER bill. Please try again.'];
    }
    return [true, "ER bill {$eb['invoice_number']} voided. The number is kept for the record."];
}

// Void a procedure bill — same contract and day-lock rule as void_er_bill().
// The invoice number is retained (never reissued) so the series stays auditable.
function void_procedure_bill(PDO $pdo, int $procBillId, int $userId, string $reason): array {
    $reason = trim($reason);
    if ($reason === '') {
        return [false, 'A void needs a reason.'];
    }
    $stmt = $pdo->prepare('SELECT id, invoice_number, status, paid_at, paid_by_id, created_by_id, voided_at
                           FROM procedure_bills WHERE id = ?');
    $stmt->execute([$procBillId]);
    $pb = $stmt->fetch();
    if (!$pb) {
        return [false, 'Procedure bill not found.'];
    }
    if ($pb['voided_at'] !== null) {
        return [false, 'This procedure bill is already voided.'];
    }
    // Voiding a settled bill changes a day's cash position — blocked once that
    // cashier's day is closed and signed.
    if ($pb['status'] === 'paid' && $pb['paid_at']) {
        $lockDate = date('Y-m-d', strtotime($pb['paid_at']));
        $lockUser = (int) ($pb['paid_by_id'] ?: $pb['created_by_id']);
        $dayLock = require_day_open($pdo, $lockDate, $lockUser);
        if ($dayLock) {
            return [false, $dayLock . ' Voiding this procedure bill would change that day\'s signed tally.'];
        }
    }
    $pdo->beginTransaction();
    try {
        $upd = $pdo->prepare("UPDATE procedure_bills SET status = 'voided', voided_at = NOW(), voided_by_id = ?, void_reason = ? WHERE id = ? AND voided_at IS NULL");
        $upd->execute([$userId, $reason, $procBillId]);
        if ($upd->rowCount() !== 1) {
            $pdo->rollBack();
            return [false, 'Could not void the procedure bill (already voided?).'];
        }
        $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
            ->execute([$userId, 'procedure_bill_voided', "Voided procedure bill {$pb['invoice_number']} (#$procBillId) — $reason"]);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('[void_procedure_bill] ' . $e->getMessage());
        return [false, 'Could not void the procedure bill. Please try again.'];
    }
    return [true, "Procedure bill {$pb['invoice_number']} voided. The number is kept for the record."];
}

// ============================================================================
// DOCTOR / CLINIC REVENUE SPLIT  (single source of truth — added 2026-07-26)
//
// THE RULE (confirmed 2026-07-26): tax comes off the FULL amount FIRST, and
// whatever remains is split by the doctor's share %.
//
//     Rs 100 fee, 10% tax, 70/30 split
//       -> tax     10   (deposited by the clinic)
//       -> remainder 90
//       -> doctor  63   (70% of 90)
//       -> clinic  27   (30% of 90)
//
// This UNIFIES two rules that used to disagree. Consultations always worked
// this way (add_consult_revenue_share.sql). doctor_procedures documented the
// OPPOSITE (split first, then withhold tax from the doctor's share only),
// which quietly left the clinic paying tax on the doctor's behalf out of its
// own share. Procedures have never been billed — zero transactions — so the
// rules were unified at the only moment it was free to do so.
//
// Use this function EVERYWHERE a share is computed. It replaces the SQL
// expression that was copy-pasted into dashboard.php (x2), doctor.php and
// doctor_analytics.php — four places that had to be edited in lockstep or a
// doctor's dashboard would disagree with their payout statement.
//
// Returns floats, unrounded. Round at the point of DISPLAY, not here, so a
// month's worth of lines does not accumulate rounding drift. The payout engine
// (Phase 6) snapshots these figures per line at disbursement time.
// ============================================================================
function doctor_split(float $amount, float $sharePct, bool $hasTax, float $taxPct): array {
    // Guard rails: a negative amount, or percentages outside 0-100, are data
    // errors rather than arithmetic ones. Clamp instead of throwing so a single
    // bad row cannot take down a whole monthly report.
    $amount   = max(0.0, $amount);
    $sharePct = min(100.0, max(0.0, $sharePct));
    $taxPct   = $hasTax ? min(100.0, max(0.0, $taxPct)) : 0.0;

    $tax       = $amount * $taxPct / 100;
    $remainder = $amount - $tax;
    $doctor    = $remainder * $sharePct / 100;
    $clinic    = $remainder - $doctor;   // subtraction, not a second %, so the
                                         // three parts always re-sum to $amount

    return [
        'gross'  => $amount,
        'tax'    => $tax,
        'doctor' => $doctor,
        'clinic' => $clinic,
    ];
}

// The same rule expressed as SQL, for set-based aggregation where looping in
// PHP would be wasteful (monthly reports over thousands of bills).
//
// $amt/$share/$hasTax/$tax are COLUMN EXPRESSIONS, not values — e.g.
//   doctor_split_sql('b.paid_amount', 'dr.consult_share_pct', 'dr.consult_has_tax', 'dr.consult_tax_pct')
// Never pass user input here; these are hardcoded column names at every call
// site and must stay that way.
//
// Keep this in lockstep with doctor_split() above. If the rule ever changes,
// both must change together — that is the price of having a SQL form at all.
function doctor_split_sql(string $amt, string $share, string $hasTax, string $tax, string $part = 'doctor'): string {
    // Tax first: the remainder that actually gets split.
    $remainder = "($amt - CASE WHEN $hasTax = 1 THEN $amt * $tax / 100 ELSE 0 END)";
    switch ($part) {
        case 'tax':
            return "(CASE WHEN $hasTax = 1 THEN $amt * $tax / 100 ELSE 0 END)";
        case 'clinic':
            return "($remainder - $remainder * $share / 100)";
        case 'doctor':
        default:
            return "($remainder * $share / 100)";
    }
}

// ============================================================================
// DOCTOR EARNED SHARE FOR A MONTH  (added 2026-07-26)
//
// What a doctor EARNED in a calendar month, so the Doctor Shares disbursement
// can be pre-filled instead of typed from a spreadsheet. Cash basis: keyed off
// when the bill was PAID (b.paid_at), matching how every other money figure in
// this app is recognised.
//
// TWO streams count today:
//   1. OPD consultations — bills joined to visits.doctor_id.
//   2. IPD ward rounds   — ipd_doctor_visits rows flagged is_paid = 1 (the
//      first note of each calendar day), whose visit_charge snapshot is billed
//      to the patient as the CONSULT_VISIT line on the admission's ipd_bill.
//      An in-door patient's consultations ARE the consultant's income and carry
//      the same tax-first split as an OPD consult.
//
// IPD money is recognised when the IPD BILL is paid, not when the round was
// written — a ward round on the 28th that settles on the 2nd belongs to the
// month the money arrived, matching the cash basis used everywhere else.
// Because one bill covers the whole stay, a doctor's rounds are attributed via
// their own visit_charge rows rather than by splitting the bill total.
//
// Still NOT payable: procedures are configured but have never been billed
// (doctor_procedures holds rates, no transaction table writes to them);
// admission_bills.admitting_doctor_id carries no share %; er_bills has no
// doctor at all. When any of those becomes payable, this function is the one
// place to extend.
//
// Returns gross / tax / doctor / clinic plus the bill count. The tax figure is
// what the clinic withholds and deposits — for a doctor who self-deposits
// (consult_has_tax = 0, e.g. DR SALMAN A BAJWA) it is correctly zero, which is
// NOT missing data.
//
// Amounts are unrounded; round at display. Returns zeros (never throws) if the
// consult_share_pct columns or the bills/visits tables are absent.
// ============================================================================
function doctor_earned_for_month(PDO $pdo, int $doctorId, string $month): array {
    $zero = ['gross' => 0.0, 'tax' => 0.0, 'doctor' => 0.0, 'clinic' => 0.0, 'bills' => 0];
    if ($doctorId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $month)) {
        return $zero;
    }
    $start = $month . '-01';
    // Exclusive upper bound: [1st of month, 1st of next month). Avoids both the
    // 23:59:59 gap and any leap/short-month arithmetic.
    $end = date('Y-m-01', strtotime($start . ' +1 month'));

    $doc    = doctor_split_sql('b.paid_amount', 'dr.consult_share_pct', 'dr.consult_has_tax', 'dr.consult_tax_pct', 'doctor');
    $tax    = doctor_split_sql('b.paid_amount', 'dr.consult_share_pct', 'dr.consult_has_tax', 'dr.consult_tax_pct', 'tax');
    $clinic = doctor_split_sql('b.paid_amount', 'dr.consult_share_pct', 'dr.consult_has_tax', 'dr.consult_tax_pct', 'clinic');

    try {
        $q = $pdo->prepare("
            SELECT COUNT(*)                            AS bills,
                   COALESCE(SUM(b.paid_amount), 0)     AS gross,
                   COALESCE(SUM($tax), 0)              AS tax,
                   COALESCE(SUM($doc), 0)              AS doctor,
                   COALESCE(SUM($clinic), 0)           AS clinic
            FROM bills b
            JOIN visits v ON v.id = b.visit_id
            JOIN users dr ON dr.id = v.doctor_id
            WHERE dr.id = ?
              AND b.status = 'paid'
              AND b.voided_at IS NULL
              AND b.paid_at >= ? AND b.paid_at < ?
        ");
        $q->execute([$doctorId, $start, $end]);
        $r = $q->fetch();
    } catch (PDOException $e) {
        error_log('[doctor_earned_for_month] ' . $e->getMessage());
        return $zero;
    }
    if (!$r) {
        return $zero;
    }

    // Refunds issued against this doctor's bills claw back earned share. Keyed
    // off the refund's own date, so a refund in a later month reduces THAT
    // month — the same rule the dashboard uses, and it keeps a settled month
    // settled once Phase 5 freezes it.
    $refund = 0.0;
    try {
        $rq = $pdo->prepare("
            SELECT COALESCE(SUM(r.amount), 0)
            FROM refunds r
            JOIN bills b  ON b.id = r.bill_id
            JOIN visits v ON v.id = b.visit_id
            WHERE v.doctor_id = ? AND r.voided_at IS NULL
              AND r.created_at >= ? AND r.created_at < ?
        ");
        $rq->execute([$doctorId, $start, $end]);
        $refund = (float) $rq->fetchColumn();
    } catch (PDOException $e) { /* no refunds table, or no bill_id column */ }

    $gross = max(0.0, (float) $r['gross'] - $refund);
    // Re-split the refund-adjusted gross rather than subtracting the raw refund
    // from the doctor's cut, so tax/doctor/clinic still re-sum to gross.
    $ratio = ((float) $r['gross']) > 0 ? $gross / (float) $r['gross'] : 0.0;

    $out = [
        'gross'  => $gross,
        'tax'    => (float) $r['tax']    * $ratio,
        'doctor' => (float) $r['doctor'] * $ratio,
        'clinic' => (float) $r['clinic'] * $ratio,
        'bills'  => (int) $r['bills'],
        // Stream breakdown, so a statement can show WHERE the money came from.
        'opd_doctor' => (float) $r['doctor'] * $ratio,
        'opd_bills'  => (int) $r['bills'],
        'ipd_doctor' => 0.0,
        'ipd_visits' => 0,
    ];

    // ---- IPD ward rounds -------------------------------------------------
    // The consultant's chargeable notes (is_paid = 1) on admissions whose IPD
    // bill was PAID inside this month. Attribution is per-round via each row's
    // own visit_charge snapshot, so two consultants sharing a stay each earn
    // exactly their own rounds rather than a split of the bill total.
    //
    // Same tax-first rule and the same per-doctor percentages as a consultation
    // — an in-door consult is consultation income.
    //
    // The whole block is optional: on a database without the IPD module these
    // tables simply do not exist and the OPD figure stands alone.
    try {
        $ipdDoc    = doctor_split_sql('dv.visit_charge', 'dr.consult_share_pct', 'dr.consult_has_tax', 'dr.consult_tax_pct', 'doctor');
        $ipdTax    = doctor_split_sql('dv.visit_charge', 'dr.consult_share_pct', 'dr.consult_has_tax', 'dr.consult_tax_pct', 'tax');
        $ipdClinic = doctor_split_sql('dv.visit_charge', 'dr.consult_share_pct', 'dr.consult_has_tax', 'dr.consult_tax_pct', 'clinic');

        $iq = $pdo->prepare("
            SELECT COUNT(*)                            AS visits,
                   COALESCE(SUM(dv.visit_charge), 0)   AS gross,
                   COALESCE(SUM($ipdTax), 0)           AS tax,
                   COALESCE(SUM($ipdDoc), 0)           AS doctor,
                   COALESCE(SUM($ipdClinic), 0)        AS clinic
            FROM ipd_doctor_visits dv
            JOIN ipd_bills ib ON ib.admission_id = dv.admission_id
            JOIN users dr     ON dr.id = dv.doctor_id
            WHERE dv.doctor_id = ?
              AND dv.is_paid = 1
              AND dv.visit_charge > 0
              AND ib.status = 'paid'
              AND ib.voided_at IS NULL
              AND ib.paid_at >= ? AND ib.paid_at < ?
        ");
        $iq->execute([$doctorId, $start, $end]);
        $i = $iq->fetch();
        if ($i && (int) $i['visits'] > 0) {
            $out['gross']  += (float) $i['gross'];
            $out['tax']    += (float) $i['tax'];
            $out['doctor'] += (float) $i['doctor'];
            $out['clinic'] += (float) $i['clinic'];
            $out['bills']  += (int) $i['visits'];
            $out['ipd_doctor'] = (float) $i['doctor'];
            $out['ipd_visits'] = (int) $i['visits'];
        }
    } catch (PDOException $e) {
        // IPD module not installed on this database — OPD figure stands.
    }

    return $out;
}

// ============================================================================
// UNIFIED CLINIC REVENUE  (Accounts Phase 3, added 2026-07-26)
//
// Revenue lives in FOUR tables that nothing ever joined: bills (OPD),
// admission_bills (ER/short-stay admissions), er_bills (walk-in ER services)
// and ipd_bills (in-door). Every report before this read `bills` alone, and
// cron/daily_summary.php summed only bills + admission_bills — so the daily
// income email has been UNDERSTATING revenue by the ER and IPD streams.
//
// Cash basis throughout: a stream counts when its bill was PAID (paid_at),
// never when it was raised. Voided bills are excluded everywhere.
//
// Refunds are returned SEPARATELY rather than pre-subtracted, because a caller
// showing "where the money came from" wants gross per stream, while a caller
// computing net profit wants the deduction. Netting here would have hidden
// that choice.
//
// Each stream is independently wrapped: a database missing the IPD or ER
// module returns zeros for it instead of failing the whole report.
// ============================================================================
function clinic_revenue(PDO $pdo, string $start, string $end): array {
    // Half-open window [start 00:00:00, end+1day 00:00:00) so a bill paid at
    // 23:30 on the last day is included without any 23:59:59 edge case.
    $from = $start . ' 00:00:00';
    $to   = date('Y-m-d', strtotime($end . ' +1 day')) . ' 00:00:00';

    $streams = [
        'opd'       => ['label' => 'OPD Consultations', 'table' => 'bills'],
        'admission' => ['label' => 'Admissions',        'table' => 'admission_bills'],
        'er'        => ['label' => 'ER Services',       'table' => 'er_bills'],
        'ipd'       => ['label' => 'In-Door (IPD)',     'table' => 'ipd_bills'],
    ];

    $out = ['streams' => [], 'gross' => 0.0, 'refunds' => 0.0, 'net' => 0.0,
            'cash' => 0.0, 'online' => 0.0, 'bills' => 0];

    foreach ($streams as $key => $s) {
        $row = ['label' => $s['label'], 'gross' => 0.0, 'bills' => 0, 'cash' => 0.0, 'online' => 0.0];
        try {
            // Table name comes from the hardcoded map above, never user input.
            $q = $pdo->prepare("
                SELECT COUNT(*)                        AS n,
                       COALESCE(SUM(paid_amount), 0)   AS gross,
                       COALESCE(SUM(CASE WHEN payment_method = 'cash' THEN paid_amount ELSE 0 END), 0) AS cash,
                       COALESCE(SUM(CASE WHEN payment_method <> 'cash' THEN paid_amount ELSE 0 END), 0) AS online
                FROM {$s['table']}
                WHERE status = 'paid' AND voided_at IS NULL
                  AND paid_at >= ? AND paid_at < ?
            ");
            $q->execute([$from, $to]);
            if ($r = $q->fetch()) {
                $row['gross']  = (float) $r['gross'];
                $row['bills']  = (int) $r['n'];
                $row['cash']   = (float) $r['cash'];
                $row['online'] = (float) $r['online'];
            }
        } catch (PDOException $e) {
            // Stream's table absent on this database — leave the row at zero.
        }
        $out['streams'][$key] = $row;
        $out['gross']  += $row['gross'];
        $out['cash']   += $row['cash'];
        $out['online'] += $row['online'];
        $out['bills']  += $row['bills'];
    }

    // Refunds are OPD-only today (refunds.bill_id -> bills). Keyed off the
    // refund's own date, so one issued in a later month reduces THAT month.
    try {
        $rq = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) FROM refunds
            WHERE voided_at IS NULL AND created_at >= ? AND created_at < ?
        ");
        $rq->execute([$from, $to]);
        $out['refunds'] = (float) $rq->fetchColumn();
    } catch (PDOException $e) { /* no refunds table */ }

    $out['net'] = max(0.0, $out['gross'] - $out['refunds']);
    return $out;
}

// What the DOCTORS earned across the same window — the figure that must come
// off revenue before anything is called clinic income. Mirrors
// doctor_earned_for_month() but for every doctor at once and over an arbitrary
// range, so the P&L and a single doctor's statement can never disagree.
//
// Returns per-doctor rows plus totals. 'tax' is what the clinic withholds and
// deposits; a doctor who self-deposits (consult_has_tax = 0) contributes zero
// to it, which is correct and not missing data.
function clinic_doctor_shares(PDO $pdo, string $start, string $end): array {
    $from = $start . ' 00:00:00';
    $to   = date('Y-m-d', strtotime($end . ' +1 day')) . ' 00:00:00';
    $out  = ['rows' => [], 'doctor' => 0.0, 'tax' => 0.0];

    // ---- OPD consultations ----
    try {
        $d = doctor_split_sql('b.paid_amount', 'dr.consult_share_pct', 'dr.consult_has_tax', 'dr.consult_tax_pct', 'doctor');
        $t = doctor_split_sql('b.paid_amount', 'dr.consult_share_pct', 'dr.consult_has_tax', 'dr.consult_tax_pct', 'tax');
        $q = $pdo->prepare("
            SELECT dr.id, dr.name, COALESCE(SUM($d), 0) AS doc, COALESCE(SUM($t), 0) AS tax
            FROM bills b
            JOIN visits v ON v.id = b.visit_id
            JOIN users dr ON dr.id = v.doctor_id
            WHERE b.status = 'paid' AND b.voided_at IS NULL
              AND b.paid_at >= ? AND b.paid_at < ?
            GROUP BY dr.id, dr.name
        ");
        $q->execute([$from, $to]);
        foreach ($q->fetchAll() as $r) {
            $id = (int) $r['id'];
            if (!isset($out['rows'][$id])) {
                $out['rows'][$id] = ['name' => $r['name'], 'doctor' => 0.0, 'tax' => 0.0];
            }
            $out['rows'][$id]['doctor'] += (float) $r['doc'];
            $out['rows'][$id]['tax']    += (float) $r['tax'];
            $out['doctor'] += (float) $r['doc'];
            $out['tax']    += (float) $r['tax'];
        }
    } catch (PDOException $e) { /* consult share columns or tables absent */ }

    // ---- IPD ward rounds (an in-door consult is consultation income) ----
    try {
        $d = doctor_split_sql('dv.visit_charge', 'dr.consult_share_pct', 'dr.consult_has_tax', 'dr.consult_tax_pct', 'doctor');
        $t = doctor_split_sql('dv.visit_charge', 'dr.consult_share_pct', 'dr.consult_has_tax', 'dr.consult_tax_pct', 'tax');
        $q = $pdo->prepare("
            SELECT dr.id, dr.name, COALESCE(SUM($d), 0) AS doc, COALESCE(SUM($t), 0) AS tax
            FROM ipd_doctor_visits dv
            JOIN ipd_bills ib ON ib.admission_id = dv.admission_id
            JOIN users dr     ON dr.id = dv.doctor_id
            WHERE dv.is_paid = 1 AND dv.visit_charge > 0
              AND ib.status = 'paid' AND ib.voided_at IS NULL
              AND ib.paid_at >= ? AND ib.paid_at < ?
            GROUP BY dr.id, dr.name
        ");
        $q->execute([$from, $to]);
        foreach ($q->fetchAll() as $r) {
            $id = (int) $r['id'];
            if (!isset($out['rows'][$id])) {
                $out['rows'][$id] = ['name' => $r['name'], 'doctor' => 0.0, 'tax' => 0.0];
            }
            $out['rows'][$id]['doctor'] += (float) $r['doc'];
            $out['rows'][$id]['tax']    += (float) $r['tax'];
            $out['doctor'] += (float) $r['doc'];
            $out['tax']    += (float) $r['tax'];
        }
    } catch (PDOException $e) { /* IPD module not installed */ }

    uasort($out['rows'], function ($a, $b) { return $b['doctor'] <=> $a['doctor']; });
    return $out;
}

// ============================================================================
// CLINIC INCOME, BUCKETED  (period chart, added 2026-07-27)
//
// clinic_revenue()/clinic_doctor_shares() collapse a whole range into ONE
// total, which is all the Income Report needed until the Day/Month/Year/Total
// chart arrived. This returns the SAME figure sliced into time buckets so the
// chart's bars sum to the page's headline instead of being computed a second,
// subtly different way.
//
//   $bucket = 'hour' | 'day' | 'month' | 'year'
//
// Returns [bucketKey => clinicIncome], where clinic income is
// gross − refunds − tax withheld − doctor shares, floored at zero per bucket
// exactly as the page floors the period total. Buckets with no money are
// simply absent; the caller renders them as zero.
//
// Every stream is independently try-wrapped, so a database without the ER or
// IPD module returns a chart missing those streams rather than a 500.
// ============================================================================
function clinic_income_buckets(PDO $pdo, string $start, string $end, string $bucket = 'day'): array {
    $expr = [
        'hour'  => 'HOUR(%s)',
        'day'   => 'DAY(%s)',
        'month' => 'MONTH(%s)',
        'year'  => 'YEAR(%s)',
    ][$bucket] ?? 'DAY(%s)';

    $from = $start . ' 00:00:00';
    $to   = date('Y-m-d', strtotime($end . ' +1 day')) . ' 00:00:00';

    $gross = [];   // bucket => money in
    $minus = [];   // bucket => refunds + tax + doctor share

    $add = function (array &$acc, $k, $v) { $acc[(int) $k] = ($acc[(int) $k] ?? 0.0) + (float) $v; };

    // ---- Gross, per revenue stream (same four tables as clinic_revenue) ----
    foreach (['bills', 'admission_bills', 'er_bills', 'ipd_bills'] as $tbl) {
        try {
            // Table name is from the hardcoded list above, never user input.
            $q = $pdo->prepare("
                SELECT " . sprintf($expr, 'paid_at') . " AS b, COALESCE(SUM(paid_amount), 0) AS amt
                FROM {$tbl}
                WHERE status = 'paid' AND voided_at IS NULL AND paid_at >= ? AND paid_at < ?
                GROUP BY b
            ");
            $q->execute([$from, $to]);
            foreach ($q->fetchAll() as $r) { $add($gross, $r['b'], $r['amt']); }
        } catch (PDOException $e) { /* stream not installed on this database */ }
    }

    // ---- Refunds (OPD-only today), keyed off the refund's own date ----
    try {
        $q = $pdo->prepare("
            SELECT " . sprintf($expr, 'created_at') . " AS b, COALESCE(SUM(amount), 0) AS amt
            FROM refunds WHERE voided_at IS NULL AND created_at >= ? AND created_at < ?
            GROUP BY b
        ");
        $q->execute([$from, $to]);
        foreach ($q->fetchAll() as $r) { $add($minus, $r['b'], $r['amt']); }
    } catch (PDOException $e) { /* no refunds table */ }

    // ---- Doctor share + withheld tax: OPD consultations ----
    try {
        $d = doctor_split_sql('b.paid_amount', 'dr.consult_share_pct', 'dr.consult_has_tax', 'dr.consult_tax_pct', 'doctor');
        $t = doctor_split_sql('b.paid_amount', 'dr.consult_share_pct', 'dr.consult_has_tax', 'dr.consult_tax_pct', 'tax');
        $q = $pdo->prepare("
            SELECT " . sprintf($expr, 'b.paid_at') . " AS b, COALESCE(SUM($d), 0) + COALESCE(SUM($t), 0) AS amt
            FROM bills b
            JOIN visits v ON v.id = b.visit_id
            JOIN users dr ON dr.id = v.doctor_id
            WHERE b.status = 'paid' AND b.voided_at IS NULL AND b.paid_at >= ? AND b.paid_at < ?
            GROUP BY b
        ");
        $q->execute([$from, $to]);
        foreach ($q->fetchAll() as $r) { $add($minus, $r['b'], $r['amt']); }
    } catch (PDOException $e) { /* consult share columns not migrated */ }

    // ---- Doctor share + withheld tax: IPD ward rounds ----
    try {
        $d = doctor_split_sql('dv.visit_charge', 'dr.consult_share_pct', 'dr.consult_has_tax', 'dr.consult_tax_pct', 'doctor');
        $t = doctor_split_sql('dv.visit_charge', 'dr.consult_share_pct', 'dr.consult_has_tax', 'dr.consult_tax_pct', 'tax');
        $q = $pdo->prepare("
            SELECT " . sprintf($expr, 'ib.paid_at') . " AS b, COALESCE(SUM($d), 0) + COALESCE(SUM($t), 0) AS amt
            FROM ipd_doctor_visits dv
            JOIN ipd_bills ib ON ib.admission_id = dv.admission_id
            JOIN users dr     ON dr.id = dv.doctor_id
            WHERE dv.is_paid = 1 AND dv.visit_charge > 0
              AND ib.status = 'paid' AND ib.voided_at IS NULL
              AND ib.paid_at >= ? AND ib.paid_at < ?
            GROUP BY b
        ");
        $q->execute([$from, $to]);
        foreach ($q->fetchAll() as $r) { $add($minus, $r['b'], $r['amt']); }
    } catch (PDOException $e) { /* IPD module not installed */ }

    $out = [];
    foreach ($gross as $k => $g) {
        $out[$k] = max(0.0, $g - ($minus[$k] ?? 0.0));
    }
    ksort($out);
    return $out;
}

// ============================================================================
// OPERATING EXPENSES FOR A PERIOD  (Accounts Phase 5, added 2026-07-26)
//
// The expense half of the P&L, using the SAME rules as expense_report.php so
// the two pages can never disagree:
//
//   * grouped on COALESCE(period_month, expense_date) — the month a cost
//     BELONGS to, so July's salary paid in August counts in July;
//   * voided and REJECTED postings excluded;
//   * DISBURSEMENTS EXCLUDED. Doctor Shares is money passing through the
//     clinic, and the P&L already deducts the EARNED share from revenue.
//     Counting the posted row as an operating cost as well would understate
//     profit by exactly the doctor share. This is the single most important
//     invariant on the P&L page.
//
// Returns per-category rows plus the operating total, and the disbursement
// total separately so a caller can reconcile earned-vs-paid without ever
// mixing it into costs.
// ============================================================================
function clinic_operating_expenses(PDO $pdo, string $start, string $end): array {
    $out = ['rows' => [], 'operating' => 0.0, 'disbursed' => 0.0, 'count' => 0];

    // period_month / is_disbursement arrive with add_accounts_phase1.sql. Fall
    // back to expense_date grouping with nothing flagged as a disbursement, so
    // a database mid-migration still renders a (less correct) figure instead of
    // failing the whole page.
    $hasPhase1 = false;
    try {
        $hasPhase1 = (int) $pdo->query("
            SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND ((table_name = 'expenses'           AND column_name = 'period_month')
                OR (table_name = 'expense_categories' AND column_name = 'is_disbursement'))
        ")->fetchColumn() === 2;
    } catch (Throwable $e) { /* leave false */ }

    $periodExpr = $hasPhase1 ? 'COALESCE(e.period_month, e.expense_date)' : 'e.expense_date';
    $disbExpr   = $hasPhase1 ? 'ec.is_disbursement' : '0';

    try {
        $q = $pdo->prepare("
            SELECT ec.id, ec.name, $disbExpr AS is_disb,
                   COUNT(*) AS n, COALESCE(SUM(e.amount), 0) AS amt
            FROM expenses e
            JOIN expense_categories ec ON ec.id = e.category_id
            WHERE $periodExpr BETWEEN ? AND ?
              AND e.voided_at IS NULL AND e.approval_status <> 'REJECTED'
            GROUP BY ec.id, ec.name, is_disb
            ORDER BY amt DESC
        ");
        $q->execute([$start, $end]);
        foreach ($q->fetchAll() as $r) {
            if ((int) $r['is_disb'] === 1) {
                $out['disbursed'] += (float) $r['amt'];
                continue;                       // never an operating cost
            }
            $out['rows'][(int) $r['id']] = [
                'name' => $r['name'],
                'amt'  => (float) $r['amt'],
                'n'    => (int) $r['n'],
            ];
            $out['operating'] += (float) $r['amt'];
            $out['count']     += (int) $r['n'];
        }
    } catch (PDOException $e) {
        error_log('[clinic_operating_expenses] ' . $e->getMessage());
    }

    return $out;
}

// ---------------------------------------------------------------------------
// MONTH-END CLOSE
//
// A closed month renders from STORED NUMBERS, never by recomputing. Without
// that, a bill voided in August silently rewrites a July that has already been
// reported on and paid out against. Closing also blocks back-dated postings
// into the month, since expenses.expense_date is admin-editable and a closed
// month would otherwise be trivially rewritable.
// ---------------------------------------------------------------------------

/** The closing row for a month ('YYYY-MM'), or null if the month is open. */
function month_closing(PDO $pdo, string $month): ?array {
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        return null;
    }
    try {
        $q = $pdo->prepare('SELECT * FROM monthly_closings WHERE period_month = ? AND reopened_at IS NULL');
        $q->execute([$month . '-01']);
        return $q->fetch() ?: null;
    } catch (PDOException $e) {
        return null;   // table not created yet — every month is open
    }
}

/**
 * Guard for anything that would write into a closed month. Returns an error
 * message, or null when the write may proceed. Mirrors require_day_open()'s
 * shape so callers read the same way.
 */
function require_month_open(PDO $pdo, ?string $date = null): ?string {
    $date = $date ?: date('Y-m-d');
    $closing = month_closing($pdo, date('Y-m', strtotime($date)));
    if (!$closing) {
        return null;
    }
    return 'The books for ' . date('F Y', strtotime($date)) . ' are closed (closed on '
         . date('d/m/Y', strtotime($closing['closed_at'])) . '). Post this to the current month, '
         . 'or ask an admin to reopen that month first.';
}
