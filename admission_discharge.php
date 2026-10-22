<?php
/**
 * Admission discharge & billing.
 *
 * Raises the SEPARATE admission bill (its own "A" invoice series), seeded from
 * the stay hours + logged services. Receptionist reviews/edits the lines, takes
 * payment (full or partial), and on a shortfall routes to the admin/manager
 * write-off approval. The consultation bill is untouched.
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
require_once __DIR__ . '/config/billing.php';
require_once __DIR__ . '/config/notify.php';
require_once __DIR__ . '/config/sheets.php';
require_once __DIR__ . '/config/payment_methods.php';
require_once __DIR__ . '/config/admission_bill.php';   // ensure_admission_bill(), approvals, schema guards
refresh_session_permissions($pdo);

$baseRole = $_SESSION['base_role'] ?? '';
$uid = (int) $_SESSION['user_id'];
$admissionId = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT a.*, v.token_no, p.id AS patient_id, p.mrn, p.name AS patient_name, p.phone, p.dob,
           COALESCE(du.name, a.admitting_doctor_manual) AS doctor_name
    FROM admissions a
    JOIN visits v ON v.id = a.visit_id
    JOIN patients p ON p.id = v.patient_id
    LEFT JOIN users du ON du.id = a.admitting_doctor_id
    WHERE a.id = ?
");
$stmt->execute([$admissionId]);
$adm = $stmt->fetch();
if (!$adm) { http_response_code(404); exit('Admission not found.'); }

// Admission settlement is its own capability now (ADMISSION_FINALIZE_BILL), split
// from the OPD till (RECEPTION_PROCESS_PAYMENTS) so an in-door biller can settle
// discharges without touching front-desk payments. ADMIN/MANAGER hold both keys
// via role_permissions (see sql/rbac_overhaul_2_grants.sql), so no role hardcode.
$canFinalize = has_permission('ADMISSION_FINALIZE_BILL');
$canApproveWriteoff = has_permission('ADMISSION_APPROVE_WRITEOFF');
if (!$canFinalize && !$canApproveWriteoff) { http_response_code(403); exit('Forbidden.'); }

$err = '';

// ensure_admission_bill() now lives in config/admission_bill.php (shared with the admit
// transaction, which raises the draft header). Behaviour unchanged for existing bills.
$bill = ensure_admission_bill($pdo, $adm, $uid);
$locked = $bill['status'] === 'paid' || $bill['printed_at'];

// Keep the bill's discount rollup honest after any line edit/removal: it's
// simply the sum of the surviving lines' snapshots.
function resync_admission_bill_discount(PDO $pdo, int $billId): void {
    $pdo->prepare('
        UPDATE admission_bills SET discount_amount =
            (SELECT COALESCE(SUM(discount_amount), 0) FROM admission_bill_items WHERE admission_bill_id = ?)
        WHERE id = ?
    ')->execute([$billId, $billId]);
}

// ---- Edit a line item (before finalize) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_item' && !$locked && $canFinalize) {
    $itemId = (int) ($_POST['item_id'] ?? 0);
    $desc = trim($_POST['description'] ?? '');
    $qty = (float) ($_POST['quantity'] ?? 1);
    $rate = (float) ($_POST['unit_rate'] ?? 0);
    $editReason = mb_substr(trim((string) ($_POST['reason'] ?? '')), 0, 255);
    $amount = round($qty * $rate, 2);

    $oldQ = $pdo->prepare('SELECT description, quantity, unit_rate, amount, item_kind FROM admission_bill_items WHERE id = ? AND admission_bill_id = ?');
    $oldQ->execute([$itemId, $bill['id']]);
    $old = $oldQ->fetch();

    if (!$old || $desc === '') {
        $err = 'That line could not be found, or its description is empty.';
    } elseif ($qty <= 0 || $rate < 0) {
        // Negative money / zero quantity is never a valid line.
        $err = 'Quantity must be above zero and the rate cannot be negative.';
    } else {
        // Below the previous rate, or a smaller line total than before, is a
        // discount by another name: it needs a reason and is flagged for review.
        $lowered = $rate < (float) $old['unit_rate'] - 0.001 || $amount < (float) $old['amount'] - 0.001;
        if ($lowered && $editReason === '') {
            $err = 'Give a reason when lowering a rate or a line total.';
        } else {
            $pdo->beginTransaction();
            try {
                // A manual edit replaces the auto-priced line entirely — clear its
                // category-discount snapshot so reporting doesn't count a discount
                // that is no longer embedded in the amount.
                $pdo->prepare('UPDATE admission_bill_items SET description = ?, quantity = ?, unit_rate = ?, amount = ?, discount_pct = 0, discount_amount = 0 WHERE id = ? AND admission_bill_id = ?')
                    ->execute([$desc, $qty, $rate, $amount, $itemId, $bill['id']]);
                resync_admission_bill_discount($pdo, (int) $bill['id']);
                recalc_admission_bill_totals($pdo, (int) $bill['id']);
                audit_log($pdo, 'admission_bill_item_edited', json_encode([
                    'invoice' => $bill['invoice_number'], 'item' => $itemId,
                    'before' => ['desc' => $old['description'], 'qty' => (float) $old['quantity'], 'rate' => (float) $old['unit_rate'], 'amount' => (float) $old['amount']],
                    'after'  => ['desc' => $desc, 'qty' => $qty, 'rate' => $rate, 'amount' => $amount],
                    'reason' => $editReason,
                ]), $uid);
                if ($lowered) {
                    adm_open_approval($pdo, $admissionId, (int) $bill['id'], 'ZERO_OR_DISCOUNT',
                        'Line lowered: ' . $old['description'] . ' Rs ' . number_format((float) $old['amount']) . ' -> Rs ' . number_format($amount) . ' (' . $editReason . ')', $uid);
                }
                $pdo->commit();
                header('Location: admission_discharge.php?id=' . $admissionId); exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $err = 'Could not save the line.';
            }
        }
    }
}

// ---- Remove a line item ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_item' && !$locked && $canFinalize) {
    $itemId = (int) ($_POST['item_id'] ?? 0);
    $rmReason = mb_substr(trim((string) ($_POST['reason'] ?? '')), 0, 255);
    $oldQ = $pdo->prepare('SELECT description, quantity, unit_rate, amount, item_kind FROM admission_bill_items WHERE id = ? AND admission_bill_id = ?');
    $oldQ->execute([$itemId, $bill['id']]);
    $old = $oldQ->fetch();
    if (!$old) {
        $err = 'That line could not be found.';
    } elseif ((float) $old['amount'] > 0 && $rmReason === '') {
        $err = 'Give a reason for removing a charged line.';
    } else {
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM admission_bill_items WHERE id = ? AND admission_bill_id = ?')->execute([$itemId, $bill['id']]);
            resync_admission_bill_discount($pdo, (int) $bill['id']);
            recalc_admission_bill_totals($pdo, (int) $bill['id']);
            audit_log($pdo, 'admission_bill_item_removed', json_encode([
                'invoice' => $bill['invoice_number'], 'item' => $itemId,
                'before' => ['desc' => $old['description'], 'qty' => (float) $old['quantity'], 'rate' => (float) $old['unit_rate'], 'amount' => (float) $old['amount'], 'kind' => $old['item_kind']],
                'after'  => null,
                'reason' => $rmReason,
            ]), $uid);
            // Dropping a SERVICE line lowers the bill below what the nurse logged.
            if ($old['item_kind'] === 'SERVICE' && (float) $old['amount'] > 0) {
                adm_open_approval($pdo, $admissionId, (int) $bill['id'], 'ZERO_OR_DISCOUNT',
                    'Line removed: ' . $old['description'] . ' Rs ' . number_format((float) $old['amount']) . ' (' . $rmReason . ')', $uid);
            }
            $pdo->commit();
            header('Location: admission_discharge.php?id=' . $admissionId); exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $err = 'Could not remove the line.';
        }
    }
}

// ---- Apply / update the manual discharge discount ----
// A lump-sum rupee amount OR a percentage of the current total. The % is a
// convenience: it's converted to a rupee amount here (off the current grand
// total) and stored as the authoritative amount, so later line edits don't
// silently re-scale it. This manual discount REPLACES the category discount for
// the grand total (see recalc_admission_bill_totals).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply_discount' && !$locked && $canFinalize) {
    $mode = $_POST['discount_mode'] ?? 'amount';
    // Gross = current lines (net of category) + the category discount we're
    // about to replace. The discount can never exceed the gross.
    $subtotalNow = (float) $bill['subtotal'];
    $categoryDiscount = (float) ($bill['discount_amount'] ?? 0);
    $gross = round($subtotalNow + $categoryDiscount, 2);
    // % base is the "current total" the biller sees = grand_total on screen.
    $currentTotal = (float) $bill['grand_total'];

    $pct = 0.0;
    $discErr = '';
    $discReason = mb_substr(trim((string) ($_POST['discount_reason'] ?? '')), 0, 255);
    if ($mode === 'percent') {
        $rawPct = (float) ($_POST['discount_percent'] ?? 0);
        if ($rawPct < 0 || $rawPct > 100) { $discErr = 'Discount percent must be between 0 and 100.'; }
        $pct = max(0, min(100, $rawPct));
        $amount = round($currentTotal * $pct / 100, 2);
    } else {
        $rawAmt = (float) ($_POST['discount_amount'] ?? 0);
        if ($rawAmt < 0) { $discErr = 'Discount cannot be negative.'; }
        $amount = max(0, $rawAmt);
    }
    // Clamp: never discount below zero.
    $amount = min($amount, $gross);

    // The user's own discount ceiling (users.max_discount_pct, the same cap OPD
    // registration enforces) applies when one is set (> 0). 0 means "unset" here,
    // because treating it as "no discount allowed" would lock every receptionist
    // out of this box; those discounts are capped at gross and flagged instead.
    if ($discErr === '' && $amount > 0 && $gross > 0 && adm_has($pdo, 'users', 'max_discount_pct')) {
        $capQ = $pdo->prepare('SELECT max_discount_pct FROM users WHERE id = ?');
        $capQ->execute([$uid]);
        $cap = (float) $capQ->fetchColumn();
        if ($cap > 0 && ($amount / $gross * 100) > $cap + 0.001) {
            $discErr = 'Discount exceeds your permitted limit of ' . rtrim(rtrim(number_format($cap, 2), '0'), '.') . '% of the bill.';
        }
    }
    if ($discErr === '' && $amount > 0 && $discReason === '') {
        $discErr = 'Give a reason for the discount.';
    }

    if ($discErr !== '') {
        $err = $discErr;
    } else {
        // Bill update + ledger row + approval row share ONE transaction.
        $priorManual = (float) ($bill['manual_discount_amount'] ?? 0);
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE admission_bills SET manual_discount_amount = ?, manual_discount_pct = ?, manual_discount_by_id = ? WHERE id = ?')
                ->execute([$amount, $pct, $uid, $bill['id']]);
            recalc_admission_bill_totals($pdo, (int) $bill['id']);
            audit_log($pdo, 'admission_bill_discount', json_encode([
                'invoice' => $bill['invoice_number'],
                'before' => ['manual_discount' => $priorManual, 'grand_total' => (float) $bill['grand_total']],
                'after'  => ['manual_discount' => $amount, 'pct' => $pct, 'gross' => $gross],
                'reason' => $discReason,
            ]), $uid);
            if ($amount > 0) {
                log_shift_event($pdo, 'DISCOUNT', 'IPD', (int) $adm['patient_id'], (int) ($adm['admitting_doctor_id'] ?: 0) ?: null,
                    -$amount, 'admission_bill', (int) $bill['id'], $bill['invoice_number'],
                    'paid', "Discharge lump-sum discount" . ($pct > 0 ? " ({$pct}%)" : ''));
                adm_open_approval($pdo, $admissionId, (int) $bill['id'], 'ZERO_OR_DISCOUNT',
                    'Manual discount Rs ' . number_format($amount) . ' of Rs ' . number_format($gross) . ': ' . $discReason, $uid);
            }
            $pdo->commit();
            header('Location: admission_discharge.php?id=' . $admissionId); exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $err = 'Could not apply the discount.';
        }
    }
}

// ---- Clear the manual discharge discount ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_discount' && !$locked && $canFinalize) {
    $pdo->prepare('UPDATE admission_bills SET manual_discount_amount = 0, manual_discount_pct = 0, manual_discount_by_id = NULL WHERE id = ?')
        ->execute([$bill['id']]);
    recalc_admission_bill_totals($pdo, (int) $bill['id']);
    header('Location: admission_discharge.php?id=' . $admissionId); exit;
}

// ---- Finalize + payment ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'finalize' && !$locked && $canFinalize) {
    // Whitelisted, not trusted: an out-of-ENUM value would be coerced to '' by
    // MySQL and then read as NOT cash in every `<> 'cash'` tally, quietly moving
    // drawer cash into the online column where it can never be reconciled.
    $method = pay_method_in($_POST['payment_method'] ?? null);
    $paid = (float) ($_POST['paid_amount'] ?? 0);
    $total = (float) $bill['grand_total'];
    $paid = max(0, min($paid, $total));
    $short = round($total - $paid, 2);

    // A closed day accepts no more payments — the cash tally has been signed.
    $dayLock = require_day_open($pdo);
    $outcome = (($_POST['outcome'] ?? '') === 'LEFT_BEFORE_TREATMENT') ? 'LEFT_BEFORE_TREATMENT' : '';
    $outReason = mb_substr(trim((string) ($_POST['outcome_reason'] ?? '')), 0, 255);
    $finFlagReason = $outReason !== '' ? $outReason
        : 'Flagged at finalise: total Rs ' . number_format($total) . ', collected Rs ' . number_format($paid);
    if ($dayLock) {
        $err = $dayLock;
    } elseif ($outcome === 'LEFT_BEFORE_TREATMENT') {
        // Patient was admitted, then the parents declined treatment. Allowed ONLY
        // when no live billable service exists; reason mandatory; recorded as a
        // PENDING approval for an admin/manager to review (moves no money itself).
        $lbtErr = adm_check_left_before_treatment($pdo, $admissionId, $outReason);
        if ($lbtErr !== null) {
            $err = $lbtErr;
        } else {
            // The stay line is waived down to whatever was actually collected: the
            // gap is booked as the manual discharge discount (replacing any earlier
            // one), so the bill still ties out and the discount ledger row exists.
            $gross = round((float) $bill['subtotal'] + (float) ($bill['discount_amount'] ?? 0), 2);
            $collect = max(0, min((float) ($_POST['paid_amount'] ?? 0), $gross));
            $waived = round($gross - $collect, 2);
            $priorManual = (float) ($bill['manual_discount_amount'] ?? 0);
            $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE admission_bills SET manual_discount_amount = ?, manual_discount_pct = 0, manual_discount_by_id = ? WHERE id = ?')
                    ->execute([$waived, $uid, $bill['id']]);
                recalc_admission_bill_totals($pdo, (int) $bill['id']);
                try {
                    $pdo->prepare('UPDATE admission_bills SET status = \'paid\', payment_method = ?, paid_amount = ?, paid_at = NOW(), finalized_by_id = ?, paid_by_id = ? WHERE id = ?')
                        ->execute([$method, $collect, $uid, $uid, $bill['id']]);
                } catch (PDOException $e) {
                    $pdo->prepare('UPDATE admission_bills SET status = \'paid\', payment_method = ?, paid_amount = ?, paid_at = NOW(), finalized_by_id = ? WHERE id = ?')
                        ->execute([$method, $collect, $uid, $bill['id']]);
                }
                $pdo->prepare('UPDATE admissions SET status = \'DISCHARGED\', discharge_finalized_by_id = ?, discharge_finalized_at = NOW() WHERE id = ?')
                    ->execute([$uid, $admissionId]);
                adm_open_approval($pdo, $admissionId, (int) $bill['id'], 'LEFT_BEFORE_TREATMENT', $outReason, $uid);
                audit_log($pdo, 'admission_left_before_treatment', json_encode([
                    'invoice' => $bill['invoice_number'], 'gross' => $gross, 'collected' => $collect, 'waived' => $waived, 'reason' => $outReason,
                ]), $uid);
                $docId = (int) ($adm['admitting_doctor_id'] ?: 0) ?: null;
                log_shift_event($pdo, 'INVOICE', 'IPD', (int) $adm['patient_id'], $docId,
                    $collect, 'admission_bill', (int) $bill['id'], $bill['invoice_number']);
                if ($waived - $priorManual > 0.001) {
                    log_shift_event($pdo, 'DISCOUNT', 'IPD', (int) $adm['patient_id'], $docId,
                        -round($waived - $priorManual, 2), 'admission_bill', (int) $bill['id'], $bill['invoice_number'],
                        'paid', 'Left before treatment: ' . $outReason);
                }
                log_shift_event($pdo, 'DISCHARGE', 'IPD', (int) $adm['patient_id'], $docId,
                    $collect, 'admission_bill', (int) $bill['id'], $bill['invoice_number']);
                $pdo->commit();
                notify_patient_discharged($pdo, $admissionId);
                sheet_push($pdo, 'DISCHARGE', (int) $bill['id'], $uid);
                header('Location: admission_discharge.php?id=' . $admissionId . '&paid=1'); exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $err = 'Could not close the stay as left before treatment.';
            }
        }
    } elseif ($short > 0.001) {
        // Partial → needs write-off approval before the stay closes. Record the
        // paid amount + finalize, but leave the admission DISCHARGE_IN_PROGRESS
        // and the bill 'finalized' (not yet 'paid') until approved.
        //
        // paid_at + paid_by_id ARE stamped here even though the status stays
        // 'finalized': the cash is physically in the drawer now, so it has to
        // land on THIS shift's tally. Without them the money was invisible to
        // day_cash_tally() until an admin approved the write-off — which could
        // be days later — leaving the collector over at every close in between
        // with no line explaining it. See day_cash_tally()'s 'finalized' branch.
        // The money write, its ledger row and the review flag share ONE transaction.
        $pdo->beginTransaction();
        try {
            try {
                $pdo->prepare('UPDATE admission_bills SET status = \'finalized\', payment_method = ?, paid_amount = ?, paid_at = NOW(), finalized_by_id = ?, paid_by_id = ? WHERE id = ?')
                    ->execute([$method, $paid, $uid, $uid, $bill['id']]);
            } catch (PDOException $e) {
                // paid_by_id absent (pre-migration) — same fallback as the full-payment
                // path below.
                $pdo->prepare('UPDATE admission_bills SET status = \'finalized\', payment_method = ?, paid_amount = ?, paid_at = NOW(), finalized_by_id = ? WHERE id = ?')
                    ->execute([$method, $paid, $uid, $bill['id']]);
            }
            // 'partial' — the stay hasn't closed yet (awaiting write-off approval), so
            // no DISCHARGE event here; that lands when approve_writeoff (or a future
            // full top-up) actually finalizes the admission below.
            log_shift_event($pdo, 'INVOICE', 'IPD', (int) $adm['patient_id'], (int) ($adm['admitting_doctor_id'] ?: 0) ?: null,
                (float) $total, 'admission_bill', (int) $bill['id'], $bill['invoice_number'], 'partial');
            // Allowed, not blocked — but a short payment / zero total / discount is
            // flagged for an admin or manager to review. (Write-off flow below is unchanged.)
            foreach (adm_finalize_flags($bill, $total, $paid) as $flagKind) {
                adm_open_approval($pdo, $admissionId, (int) $bill['id'], $flagKind, $finFlagReason, $uid);
            }
            $pdo->commit();
            header('Location: admission_discharge.php?id=' . $admissionId . '&pending_writeoff=1'); exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $err = 'Could not record the partial payment.';
        }
    }

    // Full payment → close it. Skipped when a guard above already set $err (the
    // closed-day lock used to fall through to here and book the payment anyway).
    if ($err === '') {
    $pdo->beginTransaction();
    try {
        // paid_by_id fallback pattern: see checkout.php record_payment.
        try {
            $pdo->prepare('UPDATE admission_bills SET status = \'paid\', payment_method = ?, paid_amount = ?, paid_at = NOW(), finalized_by_id = ?, paid_by_id = ? WHERE id = ?')
                ->execute([$method, $paid, $uid, $uid, $bill['id']]);
        } catch (PDOException $e) {
            $pdo->prepare('UPDATE admission_bills SET status = \'paid\', payment_method = ?, paid_amount = ?, paid_at = NOW(), finalized_by_id = ? WHERE id = ?')
                ->execute([$method, $paid, $uid, $bill['id']]);
        }
        $pdo->prepare('UPDATE admissions SET status = \'DISCHARGED\', discharge_finalized_by_id = ?, discharge_finalized_at = NOW() WHERE id = ?')
            ->execute([$uid, $admissionId]);
        audit_log($pdo, 'admission_bill_paid', "Admission bill {$bill['invoice_number']} paid Rs " . number_format($paid, 2) . " ($method)", $uid);
        log_shift_event($pdo, 'INVOICE', 'IPD', (int) $adm['patient_id'], (int) ($adm['admitting_doctor_id'] ?: 0) ?: null,
            (float) $total, 'admission_bill', (int) $bill['id'], $bill['invoice_number']);
        log_shift_event($pdo, 'DISCHARGE', 'IPD', (int) $adm['patient_id'], (int) ($adm['admitting_doctor_id'] ?: 0) ?: null,
            (float) $paid, 'admission_bill', (int) $bill['id'], $bill['invoice_number']);
        // Zero total / manual discount: allowed, flagged for review (same transaction).
        foreach (adm_finalize_flags($bill, $total, $paid) as $flagKind) {
            adm_open_approval($pdo, $admissionId, (int) $bill['id'], $flagKind, $finFlagReason, $uid);
        }
        $pdo->commit();

        // Alert admin: discharge complete, bill paid in full (best-effort, after commit).
        notify_patient_discharged($pdo, $admissionId);
        // Log the discharge bill to the yearly Google Sheet (best-effort, after commit).
        sheet_push($pdo, 'DISCHARGE', (int) $bill['id'], $uid);

        header('Location: admission_discharge.php?id=' . $admissionId . '&paid=1'); exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $err = 'Could not record payment.';
    }
    }
}

// ---- Approve the write-off (admin/manager) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'approve_writeoff' && $canApproveWriteoff) {
    $reason = trim($_POST['reason'] ?? '');
    $total = (float) $bill['grand_total'];
    $paid = (float) ($bill['paid_amount'] ?? 0);
    $short = round($total - $paid, 2);
    // DAY LOCK, BUT ONLY WHEN THIS APPROVAL ACTUALLY BOOKS CASH.
    //
    // The old comment here said the approval "flips the bill to 'paid' with
    // today's paid_at", and blocked whenever the collector's day was signed.
    // That was wrong on both halves, and it made the feature impossible to use:
    //
    //   - The UPDATE below is paid_at = COALESCE(paid_at, NOW()). When the
    //     partial payment already stamped paid_at, the approval moves NO money
    //     onto a signed day; it only changes status and the unpaid_* counters.
    //   - A partial payment stamps paid_at at discharge and the write-off is
    //     approved "possibly days later" — by which time the collector's day is
    //     signed BY DEFINITION. So the gate refused every approval it was ever
    //     asked about, the bill never reached 'paid', and the patient's
    //     unpaid_* counters never rolled. Permanently stranded.
    //
    // So gate on whether this approval is the first thing to book the cash. If
    // paid_at is NULL the money lands now, on the collector's day, and the lock
    // is the correct control. If it is already stamped there is nothing to lock.
    $dayLock = empty($bill['paid_at'])
        ? require_day_open($pdo, null, (int) ($bill['finalized_by_id'] ?? 0) ?: null)
        : null;
    if ($dayLock) {
        $err = $dayLock;
    } elseif ($short <= 0) {
        $err = 'Nothing to write off.';
    } else {
        $pdo->beginTransaction();
        try {
            // Collector = whoever took the partial payment at discharge, not the
            // approving admin — their shift owns the cash. Fallback pattern: see
            // checkout.php record_payment.
            //
            // COALESCE on paid_at, NOT NOW(): the partial payment already stamped
            // the moment the cash was taken, and the tally has very likely counted
            // it on that shift. Overwriting with the approval time would move the
            // money to a second day — short on the day it was collected, over on
            // the day it was approved. NOW() only applies to a legacy row that
            // never got a stamp.
            try {
                $pdo->prepare('UPDATE admission_bills SET status = \'paid\', write_off_amount = ?, paid_at = COALESCE(paid_at, NOW()), paid_by_id = COALESCE(paid_by_id, finalized_by_id, ?) WHERE id = ?')
                    ->execute([$short, $uid, $bill['id']]);
            } catch (PDOException $e) {
                $pdo->prepare('UPDATE admission_bills SET status = \'paid\', write_off_amount = ?, paid_at = COALESCE(paid_at, NOW()) WHERE id = ?')
                    ->execute([$short, $bill['id']]);
            }
            $pdo->prepare('INSERT INTO admission_writeoffs (admission_id, admission_bill_id, patient_id, amount_written_off, approved_by_id, approved_by_role, reason, shift_tally_date) VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE())')
                ->execute([$admissionId, $bill['id'], (int) $adm['patient_id'], $short, $uid, (in_array($baseRole, ['ADMIN','STAFF'], true) ? $baseRole : 'STAFF'), $reason]);
            $writeoffId = (int) $pdo->lastInsertId();
            // Patient rollup for the alert badge.
            $pdo->prepare('UPDATE patients SET unpaid_flag = 1, unpaid_total = unpaid_total + ?, unpaid_count = unpaid_count + 1 WHERE id = ?')
                ->execute([$short, (int) $adm['patient_id']]);
            $pdo->prepare('UPDATE admissions SET status = \'DISCHARGED\', discharge_finalized_by_id = ?, discharge_finalized_at = NOW() WHERE id = ?')
                ->execute([$uid, $admissionId]);
            audit_log($pdo, 'admission_writeoff_approved', "Wrote off Rs " . number_format($short, 2) . " on {$bill['invoice_number']}: $reason", $uid);
            // The earlier partial-payment INVOICE row (logged at finalize time)
            // settles to 'paid' now that the shortfall is written off.
            $pdo->prepare("UPDATE shift_events SET status = 'paid' WHERE reference_type = 'admission_bill' AND reference_id = ? AND event_type = 'INVOICE'")
                ->execute([(int) $bill['id']]);
            log_shift_event($pdo, 'WRITEOFF', 'IPD', (int) $adm['patient_id'], (int) ($adm['admitting_doctor_id'] ?: 0) ?: null,
                -$short, 'admission_writeoff', $writeoffId, $bill['invoice_number'], 'paid', $reason ?: null);
            log_shift_event($pdo, 'DISCHARGE', 'IPD', (int) $adm['patient_id'], (int) ($adm['admitting_doctor_id'] ?: 0) ?: null,
                (float) $bill['grand_total'], 'admission_bill', (int) $bill['id'], $bill['invoice_number']);
            $pdo->commit();

            // Alert admin: discharge with a write-off (best-effort, after commit).
            notify_patient_discharged($pdo, $admissionId, $short);
            // Log the discharge bill to the yearly Google Sheet — the Payment Method
            // cell carries the written-off shortfall (best-effort, after commit).
            sheet_push($pdo, 'DISCHARGE', (int) $bill['id'], $uid);

            header('Location: admission_discharge.php?id=' . $admissionId . '&wroteoff=1'); exit;
        } catch (Throwable $e) {
            $pdo->rollBack();
            $err = 'Could not approve write-off.';
        }
    }
}

// Reload bill + items for the view.
$b = $pdo->prepare('SELECT * FROM admission_bills WHERE admission_id = ?');
$b->execute([$admissionId]);
$bill = $b->fetch();
$items = $pdo->prepare('SELECT * FROM admission_bill_items WHERE admission_bill_id = ? ORDER BY item_kind DESC, id');
$items->execute([$bill['id']]);
$items = $items->fetchAll();
$locked = $bill['status'] === 'paid' || $bill['printed_at'];
$total = (float) $bill['grand_total'];
$paid = (float) ($bill['paid_amount'] ?? 0);
$short = round($total - $paid, 2);
$pendingWriteoff = $bill['status'] === 'finalized' && $short > 0.001;

// Review flags raised on this stay (all statuses — rejected stays visible).
// Guarded: the table only exists after sql/2026_10_admission_bill_approvals.sql.
$approvalRows = [];
if (adm_approvals_ready($pdo)) {
    $apQ = $pdo->prepare('
        SELECT ap.*, ru.name AS requester_name, du.name AS decider_name
        FROM admission_bill_approvals ap
        LEFT JOIN users ru ON ru.id = ap.requested_by_id
        LEFT JOIN users du ON du.id = ap.decided_by_id
        WHERE ap.admission_id = ? ORDER BY ap.id');
    $apQ->execute([$admissionId]);
    $approvalRows = $apQ->fetchAll();
}

// Admission → discharge timing for the header strip.
$dischargeTs = $adm['discharged_at'] ? strtotime($adm['discharged_at']) : time();
$stayMins = max(0, (int) round(($dischargeTs - strtotime($adm['admitted_at'])) / 60));
$stayLabel = (intdiv($stayMins, 60) ? intdiv($stayMins, 60) . 'h ' : '') . ($stayMins % 60) . 'm';

$pageTitle = 'Discharge — ' . $adm['patient_name'];
$headExtra = <<<CSS
<style>
.bill-head { display:flex; justify-content:space-between; flex-wrap:wrap; gap:12px; align-items:flex-start; }
.bill-meta { font-size:12.5px; color:var(--text-muted); }
.iedit { display:grid; grid-template-columns: 1fr 70px 90px auto auto; gap:8px; align-items:center; }
.iedit input { padding:7px 9px; border:1px solid var(--border); border-radius:8px; font:inherit; font-size:12.5px; background:#CCD0CF; width:100%; }
.iedit input:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 3px rgba(74,92,106,.15); }
.link-btn { background:none; border:none; color:var(--red-text); font:inherit; font-size:12px; font-weight:600; cursor:pointer; }
.pay-grid { display:grid; grid-template-columns: 1fr 1fr; gap:14px; }
.pay-grid label { font-size:12px; font-weight:600; color:var(--text-secondary); display:block; margin-bottom:5px; }
.pay-grid select, .pay-grid input { width:100%; padding:10px 12px; border:1px solid var(--border); border-radius:var(--radius-input); font:inherit; font-size:14px; background:var(--bg); }
.pay-grid select:focus, .pay-grid input:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 3px rgba(74,92,106,.15); background:#CCD0CF; }
.totbox { display:flex; flex-direction:column; gap:8px; margin:14px 0; }
.totbox .r { display:flex; justify-content:space-between; font-size:14px; }
.totbox .r.grand { border-top:1px solid var(--border); padding-top:8px; font-weight:700; font-size:16px; }
.short-note { background:var(--amber-bg); color:var(--amber-text); border-radius:12px; padding:12px 14px; font-size:13px; font-weight:600; }
.wo-note { background:#E8DCF6; color:#6B3A9E; border-radius:12px; padding:12px 14px; font-size:13px; }
.locked-tag { background:var(--green-bg); color:var(--green-text); font-size:12px; font-weight:700; padding:4px 12px; border-radius:20px; }
.time-strip { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:2px; border:1px solid var(--border); border-radius:12px; overflow:hidden; margin-top:14px; }
.time-strip > div { padding:10px 14px; border-right:1px solid var(--border); }
.time-strip > div:last-child { border-right:none; }
.time-strip .k { font-size:10.5px; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted); font-weight:700; }
.time-strip .v { font-size:14px; font-weight:650; margin-top:2px; }
/* Manual discount box */
.disc-box { border:1px dashed var(--border-strong,var(--border)); border-radius:12px; padding:14px 16px; margin-top:14px; background:var(--bg); }
.disc-head { display:flex; justify-content:space-between; align-items:center; gap:10px; margin-bottom:10px; }
.disc-head .t { font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--text-secondary); }
.disc-row { display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; }
.disc-seg { display:inline-flex; border:1px solid var(--border); border-radius:10px; overflow:hidden; background:#CCD0CF; }
.disc-seg button { appearance:none; border:none; background:transparent; font:inherit; font-size:12.5px; font-weight:600; color:var(--text-secondary); padding:9px 14px; cursor:pointer; }
.disc-seg button[aria-pressed="true"] { background:var(--primary); color:#CCD0CF; }
.disc-field { flex:1; min-width:140px; }
.disc-field label { font-size:11.5px; font-weight:600; color:var(--text-secondary); display:block; margin-bottom:4px; }
.disc-field .in-wrap { position:relative; }
.disc-field input { width:100%; padding:9px 12px; border:1px solid var(--border); border-radius:10px; font:inherit; font-size:14px; background:#CCD0CF; }
.disc-field input:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 3px rgba(74,92,106,.15); }
.disc-applied { display:flex; justify-content:space-between; align-items:center; gap:10px; background:#D2F0D9; border:1px solid #CCD0CF; color:#17602A; border-radius:12px; padding:11px 14px; margin-top:14px; font-size:13px; font-weight:600; }
</style>
CSS;
require __DIR__ . '/partials/head.php';
$navActive = 'admissions';
require __DIR__ . '/partials/sidebar.php';
?>
        <div class="content">
            <div class="page-head">
                <div>
                    <div class="page-title">Discharge &amp; Billing</div>
                    <div class="page-sub"><a href="admission.php?id=<?= $admissionId ?>" style="color:var(--primary);font-weight:600;">&larr; Back to stay</a></div>
                </div>
                <?php if ($bill['status'] === 'paid'): ?><span class="locked-tag">Paid &amp; closed</span><?php endif; ?>
            </div>

            <?php if ($err): ?><div class="alert error"><?= htmlspecialchars($err) ?></div><?php endif; ?>
            <?php if (isset($_GET['paid'])): ?><div class="alert success">Payment recorded — patient discharged. The invoice is opening in a new tab.</div><?php endif; ?>
            <?php if (isset($_GET['wroteoff'])): ?><div class="alert success">Write-off approved — patient discharged. The invoice is opening in a new tab.</div><?php endif; ?>
            <?php if (isset($_GET['pending_writeoff'])): ?><div class="alert error">Partial payment recorded. Admin/manager must approve the write-off below before this closes.</div><?php endif; ?>

            <div class="card">
                <div class="bill-head">
                    <div>
                        <div class="section-title"><?= htmlspecialchars($adm['patient_name']) ?></div>
                        <div class="bill-meta"><span class="mono"><?= htmlspecialchars($adm['mrn']) ?></span> &middot; <?= htmlspecialchars($adm['doctor_name'] ?: '—') ?> &middot; Admission invoice <b><?= htmlspecialchars($bill['invoice_number']) ?></b></div>
                    </div>
                    <?php if ($bill['status'] === 'paid'): ?>
                    <a class="btn secondary" href="admission_invoice.php?id=<?= $admissionId ?>" target="_blank" rel="noopener">Print invoice</a>
                    <?php else: ?>
                    <!-- The invoice PDF only exists AFTER payment: reception first reviews
                         the lines and records the payment mode; opening the print view also
                         locks the bill, so exposing it early would freeze editable bills. -->
                    <span class="bill-meta" style="align-self:center;">Invoice prints after payment is recorded</span>
                    <?php endif; ?>
                </div>

                <div class="time-strip">
                    <div><div class="k">Admitted</div><div class="v"><?= date('d/m, H:i', strtotime($adm['admitted_at'])) ?></div></div>
                    <div><div class="k">Discharged</div><div class="v"><?= $adm['discharged_at'] ? date('d/m, H:i', strtotime($adm['discharged_at'])) : '—' ?></div></div>
                    <div><div class="k">Total stay</div><div class="v"><?= $stayLabel ?></div></div>
                </div>

                <div style="overflow-x:auto;margin-top:14px;">
                <table>
                    <thead><tr><th>Description</th><th>Qty</th><th>Rate</th><th>Amount</th><?php if (!$locked && $canFinalize): ?><th></th><?php endif; ?></tr></thead>
                    <tbody>
                        <?php foreach ($items as $it): ?>
                        <tr>
                            <?php if (!$locked && $canFinalize): ?>
                            <td colspan="4" style="padding:8px 10px;">
                                <form method="POST" action="admission_discharge.php?id=<?= $admissionId ?>" class="iedit">
                                    <input type="hidden" name="action" value="edit_item">
                                    <input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>">
                                    <input type="text" name="description" value="<?= htmlspecialchars($it['description']) ?>">
                                    <input type="number" step="0.01" name="quantity" value="<?= htmlspecialchars((string) $it['quantity']) ?>">
                                    <input type="number" step="0.01" min="0" name="unit_rate" value="<?= htmlspecialchars((string) $it['unit_rate']) ?>">
                                    <span class="mono" style="white-space:nowrap;">Rs <?= number_format((float) $it['amount']) ?></span>
                                    <button type="submit" class="link-btn" style="color:var(--primary);">Save</button>
                                    <input type="text" name="reason" maxlength="255" placeholder="Reason (required if the rate or line total goes down)" style="grid-column:1 / -1;" autocomplete="off">
                                </form>
                            </td>
                            <td>
                                <form method="POST" action="admission_discharge.php?id=<?= $admissionId ?>" onsubmit="var r = prompt('Reason for removing this line (required):'); if (!r || !r.trim()) { return false; } this.reason.value = r.trim(); return true;">
                                    <input type="hidden" name="action" value="remove_item">
                                    <input type="hidden" name="reason" value="">
                                    <input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>">
                                    <button type="submit" class="link-btn">×</button>
                                </form>
                            </td>
                            <?php else: ?>
                            <td style="font-weight:600;"><?= htmlspecialchars($it['description']) ?></td>
                            <td><?= htmlspecialchars((string) (float) $it['quantity']) ?></td>
                            <td class="mono">Rs <?= number_format((float) $it['unit_rate']) ?></td>
                            <td class="mono">Rs <?= number_format((float) $it['amount']) ?></td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>

                <?php
                $catDiscount = (float) ($bill['discount_amount'] ?? 0);
                $manualDiscount = (float) ($bill['manual_discount_amount'] ?? 0);
                $manualPct = (float) ($bill['manual_discount_pct'] ?? 0);
                // Gross = what the lines would total before ANY discount.
                $gross = round((float) $bill['subtotal'] + $catDiscount, 2);
                ?>
                <div class="totbox">
                    <?php if ($manualDiscount > 0): ?>
                    <!-- Manual discount REPLACES the category discount in the total. -->
                    <div class="r"><span>Subtotal</span><span class="mono">Rs <?= number_format($gross) ?></span></div>
                    <div class="r" style="color:var(--green-text);"><span>Discount<?= $manualPct > 0 ? ' (' . rtrim(rtrim(number_format($manualPct, 2), '0'), '.') . '%)' : '' ?></span><span class="mono">− Rs <?= number_format($manualDiscount) ?></span></div>
                    <?php elseif ($catDiscount > 0): ?>
                    <!-- Generic wording by design — the category name never shows to reception/patient here. -->
                    <div class="r"><span>Before discount</span><span class="mono">Rs <?= number_format($total + $catDiscount) ?></span></div>
                    <div class="r" style="color:var(--green-text);"><span>Discount</span><span class="mono">− Rs <?= number_format($catDiscount) ?></span></div>
                    <?php endif; ?>
                    <div class="r grand"><span>Total</span><span class="mono">Rs <?= number_format($total) ?></span></div>
                    <?php if ($bill['status'] !== 'draft'): ?>
                    <div class="r"><span>Paid</span><span class="mono">Rs <?= number_format($paid) ?></span></div>
                    <?php if ($bill['write_off_amount'] > 0): ?><div class="r"><span>Written off</span><span class="mono">Rs <?= number_format((float) $bill['write_off_amount']) ?></span></div><?php endif; ?>
                    <?php if ($short > 0.001 && $bill['status'] !== 'paid'): ?><div class="r" style="color:var(--red-text);font-weight:700;"><span>Balance</span><span class="mono">Rs <?= number_format($short) ?></span></div><?php endif; ?>
                    <?php endif; ?>
                </div>

                <?php if (!$locked && $canFinalize && !$pendingWriteoff): ?>

                <!-- Manual discount: lump-sum amount OR % of the current total, applied
                     BEFORE payment. Replaces the auto category discount in the total. -->
                <?php if ($manualDiscount > 0): ?>
                <div class="disc-applied">
                    <span>Discount applied: Rs <?= number_format($manualDiscount) ?><?= $manualPct > 0 ? ' (' . rtrim(rtrim(number_format($manualPct, 2), '0'), '.') . '% of total)' : '' ?></span>
                    <form method="POST" action="admission_discharge.php?id=<?= $admissionId ?>">
                        <input type="hidden" name="action" value="clear_discount">
                        <button type="submit" class="link-btn" style="color:var(--red-text);">Remove discount</button>
                    </form>
                </div>
                <?php else: ?>
                <div class="disc-box">
                    <div class="disc-head"><span class="t">Add discount</span></div>
                    <form method="POST" action="admission_discharge.php?id=<?= $admissionId ?>" class="disc-row" id="discForm">
                        <input type="hidden" name="action" value="apply_discount">
                        <input type="hidden" name="discount_mode" id="discMode" value="amount">
                        <div class="disc-seg" role="group" aria-label="Discount type">
                            <button type="button" data-mode="amount" aria-pressed="true" onclick="discSetMode('amount')">Amount (Rs)</button>
                            <button type="button" data-mode="percent" aria-pressed="false" onclick="discSetMode('percent')">Percent (%)</button>
                        </div>
                        <div class="disc-field" id="discAmountField">
                            <label>Lump-sum discount (Rs)</label>
                            <div class="in-wrap"><input type="number" step="0.01" min="0" max="<?= number_format($gross, 2, '.', '') ?>" name="discount_amount" placeholder="0"></div>
                        </div>
                        <div class="disc-field" id="discPercentField" style="display:none;">
                            <label>Discount (% of Rs <?= number_format($total) ?>)</label>
                            <div class="in-wrap"><input type="number" step="0.01" min="0" max="100" name="discount_percent" placeholder="0"></div>
                        </div>
                        <div class="disc-field" style="flex-basis:100%;">
                            <label>Reason for the discount (required)</label>
                            <div class="in-wrap"><input type="text" name="discount_reason" maxlength="255" placeholder="e.g. family of staff, approved by owner" autocomplete="off"></div>
                        </div>
                        <button type="submit" class="btn secondary" style="white-space:nowrap;">Apply discount</button>
                    </form>
                </div>
                <?php endif; ?>

                <form method="POST" action="admission_discharge.php?id=<?= $admissionId ?>">
                    <input type="hidden" name="action" value="finalize">
                    <div class="pay-grid">
                        <div>
                            <label>Payment method</label>
                            <?= pay_method_toggle('payment_method', 'cash', 'adm') ?>
                        </div>
                        <div>
                            <label>Amount collected (Rs)</label>
                            <input type="number" step="0.01" min="0" name="paid_amount" value="<?= number_format($total, 2, '.', '') ?>">
                        </div>
                    </div>
                    <?php
                    // "Left before treatment" is offered only when it can be recorded (the
                    // approvals table exists) and no live billable service is on the stay.
                    $canLbt = adm_approvals_ready($pdo) && adm_billable_service_count($pdo, $admissionId) === 0;
                    ?>
                    <div class="pay-grid" style="margin-top:14px;">
                        <?php if ($canLbt): ?>
                        <div>
                            <label>Outcome</label>
                            <select name="outcome">
                                <option value="">Normal discharge</option>
                                <option value="LEFT_BEFORE_TREATMENT">Left before treatment</option>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div<?= $canLbt ? '' : ' style="grid-column:1 / -1;"' ?>>
                            <label><?= $canLbt ? 'Reason (required for left before treatment)' : 'Note for reviewer (optional)' ?></label>
                            <input type="text" name="outcome_reason" maxlength="255" autocomplete="off" placeholder="e.g. parents declined treatment after 10 minutes">
                        </div>
                    </div>
                    <div style="display:flex;justify-content:flex-end;margin-top:16px;">
                        <button type="submit" class="btn">Record payment &amp; discharge</button>
                    </div>
                    <div class="est-note" style="font-size:11.5px;color:var(--text-muted);margin-top:8px;">Collect less than the total to record a partial payment — the shortfall then needs admin/manager write-off approval. A zero total, short payment or discount is allowed but is flagged for admin/manager review.</div>
                </form>
                <?php endif; ?>

                <?php if ($pendingWriteoff): ?>
                <div class="short-note" style="margin-top:12px;">Partial payment of Rs <?= number_format($paid) ?> recorded. Balance Rs <?= number_format($short) ?> requires a write-off approval to close.</div>
                <?php if ($canApproveWriteoff): ?>
                <form method="POST" action="admission_discharge.php?id=<?= $admissionId ?>" style="margin-top:12px;" onsubmit="return confirm('Write off Rs <?= number_format($short) ?>? This is gone forever and flags the patient.');">
                    <input type="hidden" name="action" value="approve_writeoff">
                    <label style="font-size:12px;font-weight:600;color:var(--text-secondary);display:block;margin-bottom:5px;">Reason for allowing the unpaid balance</label>
                    <input type="text" name="reason" placeholder="e.g. patient unable to pay, approved by owner" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:var(--radius-input);font:inherit;">
                    <div style="display:flex;justify-content:flex-end;margin-top:12px;">
                        <button type="submit" class="btn danger">Approve write-off &amp; close</button>
                    </div>
                </form>
                <?php else: ?>
                <div class="wo-note" style="margin-top:12px;">Waiting for an admin or manager to approve the write-off.</div>
                <?php endif; ?>
                <?php endif; ?>

                <?php if ($approvalRows): ?>
                <div class="disc-box" style="margin-top:14px;">
                    <div class="disc-head"><span class="t">Review flags on this stay</span><?php if ($canApproveWriteoff): ?><a href="admission_approvals.php" style="font-size:12px;color:var(--primary);font-weight:600;">All approvals</a><?php endif; ?></div>
                    <?php foreach ($approvalRows as $ap): ?>
                    <div style="font-size:13px;padding:6px 0;border-top:1px solid var(--border);">
                        <b><?= htmlspecialchars(str_replace('_', ' ', $ap['kind'])) ?></b>
                        &middot; <?= htmlspecialchars($ap['status']) ?>
                        &middot; <?= htmlspecialchars((string) $ap['requester_name']) ?>, <?= date('d/m/Y H:i', strtotime($ap['requested_at'])) ?>
                        <div class="muted" style="font-size:12px;"><?= htmlspecialchars((string) $ap['reason']) ?><?= $ap['decided_at'] ? ' &mdash; ' . htmlspecialchars((string) $ap['decider_name']) . ': ' . htmlspecialchars((string) $ap['decision_note']) : '' ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if ($bill['status'] === 'paid'): ?>
                <div class="wo-note" style="margin-top:12px;background:var(--green-bg);color:var(--green-text);">
                    Closed. Collected Rs <?= number_format($paid) ?><?= $bill['write_off_amount'] > 0 ? ', wrote off Rs ' . number_format((float) $bill['write_off_amount']) : '' ?>.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<script src="assets/js/date-picker.js?v=<?= @filemtime(__DIR__ . "/assets/js/date-picker.js") ?: 1 ?>"></script>
<script>
// Double-submit guard: a second submit of the same form is swallowed and the
// buttons lock once the first is on its way (the server also guards each write).
document.querySelectorAll('form[method="POST"]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
        if (e.defaultPrevented) return;
        if (f.dataset.busy) { e.preventDefault(); return; }
        f.dataset.busy = '1';
        setTimeout(function () { f.querySelectorAll('button[type="submit"]').forEach(function (b) { b.disabled = true; }); }, 0);
    });
});
// Discount type toggle — switch the visible field and the hidden mode flag.
function discSetMode(mode) {
    var m = document.getElementById('discMode');
    if (m) m.value = mode;
    var amt = document.getElementById('discAmountField');
    var pct = document.getElementById('discPercentField');
    if (amt) amt.style.display = mode === 'amount' ? '' : 'none';
    if (pct) pct.style.display = mode === 'percent' ? '' : 'none';
    document.querySelectorAll('.disc-seg button[data-mode]').forEach(function (b) {
        b.setAttribute('aria-pressed', b.getAttribute('data-mode') === mode ? 'true' : 'false');
    });
    // Clear the now-hidden field so a stale value isn't submitted.
    var hide = mode === 'amount' ? pct : amt;
    if (hide) { var i = hide.querySelector('input'); if (i) i.value = ''; }
}
</script>
<?php if (isset($_GET['paid']) || isset($_GET['wroteoff'])): ?>
<script>
// Payment just landed: open the invoice PDF automatically, mirroring how
// registration auto-opens the consultation slip. Popup blockers fall back to
// the Print invoice button that is now visible in the header.
window.addEventListener('load', function () {
    window.open('admission_invoice.php?id=<?= (int) $admissionId ?>', '_blank', 'noopener');
});
</script>
<?php endif; ?>
</body>
</html>
