<?php
// Shared billing helpers, used by checkout.php (manual checkout) and patients.php
// (auto-create a bill the moment a visit is registered).

// Daily sequential invoice number: "94345 - 2026-07-17 14:03:00" — matches the
// original spec's format. Race-safe under concurrent checkouts via the same
// atomic-upsert + LAST_INSERT_ID() pattern used for visit queue tokens in patients.php.
function generate_invoice_number(PDO $pdo): string {
    $today = date('Y-m-d');
    $now = date('H:i:s');

    $pdo->prepare('
        INSERT INTO invoice_sequences (sequence_date, last_sequence)
        VALUES (?, 94345)
        ON DUPLICATE KEY UPDATE last_sequence = LAST_INSERT_ID(last_sequence) + 1
    ')->execute([$today]);
    $lastId = (int) $pdo->lastInsertId();
    $seq = $lastId > 0 ? $lastId : 94345;

    return $seq . ' - ' . $today . ' ' . $now;
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
