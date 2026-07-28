<?php
/**
 * IPD advance payments — money taken before the stay is billed.
 *
 * A patient may pay several advances across a long admission, so this is a
 * ledger of receipts, not a single figure. Each receipt carries its own
 * paid_by_id / paid_at so it lands on the shift that physically took the cash,
 * the moment it is taken (see day_cash_tally). That is the lesson from the
 * partial-discharge bug: money that reaches the drawer without a drawer stamp
 * is counted for nobody.
 *
 * At discharge the net advance is deducted from the bill. If it exceeds the
 * final bill the excess goes back out of the drawer, recorded here as a
 * direction='REFUND' row so this ledger stays the single answer to "what has
 * this patient paid us, net".
 *
 * Requires sql/ipd/add_ipd_advances.sql.
 *
 * Every function takes $pdo explicitly — callers load config/db.php themselves,
 * same as billing.php and ipd_billing.php.
 */

/** True once the advance ledger exists. Cached per request. */
function ipd_advances_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $pdo->query('SELECT 1 FROM ipd_advances LIMIT 1');
        $ready = true;
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

/**
 * Next "ADV-YYYY-NNNN" receipt number.
 *
 * Derived from MAX(counter, highest actually in the table) so a counter row that
 * drifts — restored backup, manual edit — can never re-issue a number already
 * printed on a receipt. Same belt-and-braces as generate_refund_number().
 */
function generate_ipd_advance_number(PDO $pdo): string
{
    $yr = (int) date('Y');

    $pdo->prepare('INSERT INTO ipd_advance_counters (yr, last_no) VALUES (?, 0)
                   ON DUPLICATE KEY UPDATE yr = yr')->execute([$yr]);

    $c = $pdo->prepare('SELECT last_no FROM ipd_advance_counters WHERE yr = ? FOR UPDATE');
    $c->execute([$yr]);
    $counter = (int) $c->fetchColumn();

    $m = $pdo->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(receipt_number, '-', -1) AS UNSIGNED)), 0)
                        FROM ipd_advances WHERE receipt_number LIKE ?");
    $m->execute(['ADV-' . $yr . '-%']);
    $highest = (int) $m->fetchColumn();

    $next = max($counter, $highest) + 1;
    $pdo->prepare('UPDATE ipd_advance_counters SET last_no = ? WHERE yr = ?')->execute([$next, $yr]);

    return sprintf('ADV-%d-%04d', $yr, $next);
}

/**
 * Net advance held against an admission: payments in, minus any refunded back.
 * Voided rows are excluded, so voiding a receipt correctly reduces the balance.
 */
function ipd_advance_balance(PDO $pdo, int $admissionId): float
{
    if (!ipd_advances_ready($pdo)) {
        return 0.0;
    }
    try {
        $s = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN direction = 'REFUND' THEN -amount ELSE amount END), 0)
                            FROM ipd_advances WHERE admission_id = ? AND voided_at IS NULL");
        $s->execute([$admissionId]);
        return round((float) $s->fetchColumn(), 2);
    } catch (Throwable $e) {
        return 0.0;
    }
}

/** Every receipt for an admission, oldest first, for the ledger display. */
function ipd_advance_rows(PDO $pdo, int $admissionId): array
{
    if (!ipd_advances_ready($pdo)) {
        return [];
    }
    try {
        $s = $pdo->prepare('SELECT a.*, u.name AS received_by_name
                            FROM ipd_advances a
                            LEFT JOIN users u ON u.id = a.received_by_id
                            WHERE a.admission_id = ?
                            ORDER BY a.id');
        $s->execute([$admissionId]);
        return $s->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Record one advance payment. Returns [ok, receiptNumberOrError].
 *
 * $uid is both the receiver and the drawer: whoever is signed in took the cash.
 */
function ipd_take_advance(PDO $pdo, int $admissionId, float $amount, string $method, ?string $note, int $uid): array
{
    if (!ipd_advances_ready($pdo)) {
        return [false, 'Advance payments are not available yet — run sql/ipd/add_ipd_advances.sql.'];
    }
    $amount = round($amount, 2);
    if ($amount <= 0) {
        return [false, 'Enter an amount greater than zero.'];
    }
    if (!in_array($method, ['cash', 'card', 'bank_transfer', 'cheque'], true)) {
        return [false, 'Pick a valid payment method.'];
    }

    // Refuse to take money against a stay that is already closed — the discharge
    // bill has settled and this receipt would never be applied to anything.
    $a = $pdo->prepare('SELECT status FROM ipd_admissions WHERE id = ?');
    $a->execute([$admissionId]);
    $status = $a->fetchColumn();
    if ($status === false) {
        return [false, 'Admission not found.'];
    }
    if ($status === 'DISCHARGED') {
        return [false, 'This patient is already discharged — the stay has been settled.'];
    }

    $pdo->beginTransaction();
    try {
        $receipt = generate_ipd_advance_number($pdo);
        // paid_at + paid_by_id stamped NOW: the cash is in the drawer now, so it
        // belongs on this shift's tally now.
        $pdo->prepare("INSERT INTO ipd_advances
                (receipt_number, admission_id, direction, amount, payment_method, note,
                 paid_at, paid_by_id, received_by_id)
                VALUES (?, ?, 'PAYMENT', ?, ?, ?, NOW(), ?, ?)")
            ->execute([$receipt, $admissionId, $amount, $method, $note ?: null, $uid, $uid]);

        $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
            ->execute([$uid, 'ipd_advance_taken',
                "Advance $receipt — Rs " . number_format($amount, 2) . " ($method) on admission #$admissionId"]);
        $pdo->commit();
        return [true, $receipt];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        return [false, 'Could not record the advance. Please try again.'];
    }
}

/**
 * Void an advance receipt (admin correction — wrong amount, wrong patient).
 *
 * Kept forever with its number; the balance excludes it. Refusing once the stay
 * is discharged is deliberate: the bill has already been settled against this
 * ledger, so voiding underneath it would silently unbalance a closed account.
 */
function ipd_void_advance(PDO $pdo, int $advanceId, string $reason, int $uid): array
{
    if (!ipd_advances_ready($pdo)) {
        return [false, 'Advance payments are not available yet.'];
    }
    if (trim($reason) === '') {
        return [false, 'A void needs a reason.'];
    }
    try {
        $s = $pdo->prepare('SELECT a.*, adm.status AS adm_status
                            FROM ipd_advances a
                            JOIN ipd_admissions adm ON adm.id = a.admission_id
                            WHERE a.id = ?');
        $s->execute([$advanceId]);
        $row = $s->fetch();
        if (!$row) {
            return [false, 'Receipt not found.'];
        }
        if ($row['voided_at'] !== null) {
            return [false, 'This receipt is already voided.'];
        }
        if ($row['adm_status'] === 'DISCHARGED') {
            return [false, 'The stay is already discharged and settled — void the bill instead.'];
        }

        $pdo->prepare('UPDATE ipd_advances SET voided_at = NOW(), voided_by_id = ?, void_reason = ?
                       WHERE id = ? AND voided_at IS NULL')
            ->execute([$uid, mb_substr(trim($reason), 0, 255), $advanceId]);

        $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
            ->execute([$uid, 'ipd_advance_voided',
                "Voided advance {$row['receipt_number']} (Rs " . number_format((float) $row['amount'], 2) . "): $reason"]);
        return [true, $row['receipt_number']];
    } catch (Throwable $e) {
        return [false, 'Could not void the receipt.'];
    }
}

/**
 * Settle a discharge bill against the advances held.
 *
 * Returns [applied, refundDue]:
 *   applied   — advance money consumed by the bill (never more than the bill)
 *   refundDue — the excess to hand back from the drawer, 0 when the bill covers
 *               everything
 *
 * Pure arithmetic; the caller decides what to do with the numbers.
 */
function ipd_settle_advance(float $grandTotal, float $advanceBalance): array
{
    $grandTotal = max(0, round($grandTotal, 2));
    $advanceBalance = max(0, round($advanceBalance, 2));
    $applied = min($advanceBalance, $grandTotal);
    return [round($applied, 2), round($advanceBalance - $applied, 2)];
}

/**
 * Hand the excess advance back at discharge, as a REFUND row in this ledger.
 *
 * Stamped with paid_at/paid_by_id like a payment, because the tally treats it as
 * cash LEAVING this shift's drawer — the mirror of taking it.
 */
function ipd_refund_excess_advance(PDO $pdo, int $admissionId, float $amount, string $method, int $uid): array
{
    if (!ipd_advances_ready($pdo)) {
        return [false, 'Advance payments are not available yet.'];
    }
    $amount = round($amount, 2);
    if ($amount <= 0) {
        return [false, 'Nothing to refund.'];
    }
    // Never hand back more than is actually held.
    $balance = ipd_advance_balance($pdo, $admissionId);
    if ($amount > $balance + 0.001) {
        return [false, 'Refund exceeds the advance held (Rs ' . number_format($balance, 2) . ').'];
    }
    try {
        $receipt = generate_ipd_advance_number($pdo);
        $pdo->prepare("INSERT INTO ipd_advances
                (receipt_number, admission_id, direction, amount, payment_method, note,
                 paid_at, paid_by_id, received_by_id)
                VALUES (?, ?, 'REFUND', ?, ?, 'Excess advance returned at discharge', NOW(), ?, ?)")
            ->execute([$receipt, $admissionId, $amount, $method, $uid, $uid]);

        $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
            ->execute([$uid, 'ipd_advance_refunded',
                "Returned excess advance $receipt — Rs " . number_format($amount, 2) . " ($method) on admission #$admissionId"]);
        return [true, $receipt];
    } catch (Throwable $e) {
        return [false, 'Could not record the refund.'];
    }
}
