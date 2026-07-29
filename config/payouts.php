<?php
/**
 * Doctor payout engine. (Accounts Phase 6, 2026-07-26)
 *
 * Turns the live share statement into a FROZEN record. Everything else in this
 * app reads live rows — right for a view, wrong for a record: a bill voided in
 * August silently rewrites a July already paid out against, and because
 * consult_share_pct is read live, moving a doctor 70% -> 75% retroactively
 * claims every past month underpaid them.
 *
 * So a payout snapshots its figures INCLUDING the rates in force at the time,
 * one line per source bill / daily round.
 *
 * Requires config/billing.php (doctor_split) and sql/add_doctor_payouts.sql.
 */

/** DP-YYYY-NNNN. Same race-safe GREATEST pattern as expense/refund numbers:
 *  never trust a stored counter alone, take the max of it and what is actually
 *  in the table, so a restore or manual insert cannot re-issue a number. */

require_once __DIR__ . '/permissions.php';   // audit_log(), has_permission()
function generate_payout_number(PDO $pdo): string {
    $year = (int) date('Y');
    $stmt = $pdo->prepare("
        SELECT GREATEST(
            COALESCE((SELECT last_sequence FROM payout_sequences WHERE sequence_year = :y), 0),
            COALESCE((SELECT MAX(CAST(SUBSTRING_INDEX(payout_number, '-', -1) AS UNSIGNED))
                      FROM doctor_payouts WHERE payout_number LIKE :pfx), 0)
        ) + 1
    ");
    $stmt->execute([':y' => $year, ':pfx' => 'DP-' . $year . '-%']);
    $seq = max(1, (int) $stmt->fetchColumn());
    $pdo->prepare('
        INSERT INTO payout_sequences (sequence_year, last_sequence) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE last_sequence = GREATEST(last_sequence, VALUES(last_sequence))
    ')->execute([$year, $seq]);
    return 'DP-' . $year . '-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
}

/** The doctor's last SETTLED payout. Draft rows are deliberately ignored:
 *  reading carried_in from a draft would let an unsettled row consume a
 *  balance without any cash having moved. */
function last_settled_payout(PDO $pdo, int $doctorId): ?array {
    try {
        $q = $pdo->prepare("
            SELECT * FROM doctor_payouts
            WHERE doctor_id = ? AND status = 'paid'
            ORDER BY period_end DESC, id DESC LIMIT 1
        ");
        $q->execute([$doctorId]);
        return $q->fetch() ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * EARNING lines for a period: one row per paid OPD bill and per chargeable IPD
 * daily round, split at the doctor's CURRENT rates (which are then frozen onto
 * the payout).
 *
 * Cash basis — a line belongs to the period the MONEY ARRIVED, matching every
 * other money figure in the app.
 *
 * Rows already carrying an EARNING line on any payout are skipped, so
 * overlapping periods or a re-run cannot pay for the same bill twice. The
 * UNIQUE key is the real guard; this just avoids the error.
 */
function payout_earning_lines(PDO $pdo, int $doctorId, string $start, string $end, array $rates): array {
    $from = $start . ' 00:00:00';
    $to   = date('Y-m-d', strtotime($end . ' +1 day')) . ' 00:00:00';
    $lines = [];

    // ---- OPD consultations ----
    try {
        $q = $pdo->prepare("
            SELECT b.id, b.paid_amount, DATE(b.paid_at) AS on_date, p.name AS patient
            FROM bills b
            JOIN visits v   ON v.id = b.visit_id
            LEFT JOIN patients p ON p.id = v.patient_id
            WHERE v.doctor_id = ? AND b.status = 'paid' AND b.voided_at IS NULL
              AND b.paid_at >= ? AND b.paid_at < ?
              AND b.paid_amount > 0
              AND NOT EXISTS (
                  SELECT 1 FROM doctor_payout_lines l
                  WHERE l.source_type = 'OPD' AND l.source_id = b.id AND l.line_kind = 'EARNING'
              )
            ORDER BY b.paid_at
        ");
        $q->execute([$doctorId, $from, $to]);
        foreach ($q->fetchAll() as $r) {
            $s = doctor_split((float) $r['paid_amount'], $rates['share_pct'], (bool) $rates['has_tax'], $rates['tax_pct']);
            $lines[] = [
                'source_type' => 'OPD', 'source_id' => (int) $r['id'],
                'occurred_on' => $r['on_date'], 'patient_name' => $r['patient'],
                'gross' => $s['gross'], 'tax' => $s['tax'], 'doctor_share' => $s['doctor'],
            ];
        }
    } catch (PDOException $e) { error_log('[payout opd] ' . $e->getMessage()); }

    // ---- IPD daily rounds: chargeable notes on admissions whose bill was paid
    //      in the window. An in-door consult is consultation income. ----
    try {
        $q = $pdo->prepare("
            SELECT dv.id, dv.visit_charge, DATE(dv.visited_at) AS on_date, p.name AS patient
            FROM ipd_doctor_visits dv
            JOIN ipd_bills ib      ON ib.admission_id = dv.admission_id
            JOIN ipd_admissions ia ON ia.id = dv.admission_id
            LEFT JOIN patients p   ON p.id = ia.patient_id
            WHERE dv.doctor_id = ? AND dv.is_paid = 1 AND dv.visit_charge > 0
              AND ib.status = 'paid' AND ib.voided_at IS NULL
              AND ib.paid_at >= ? AND ib.paid_at < ?
              AND NOT EXISTS (
                  SELECT 1 FROM doctor_payout_lines l
                  WHERE l.source_type = 'IPD' AND l.source_id = dv.id AND l.line_kind = 'EARNING'
              )
            ORDER BY dv.visited_at
        ");
        $q->execute([$doctorId, $from, $to]);
        foreach ($q->fetchAll() as $r) {
            $s = doctor_split((float) $r['visit_charge'], $rates['share_pct'], (bool) $rates['has_tax'], $rates['tax_pct']);
            $lines[] = [
                'source_type' => 'IPD', 'source_id' => (int) $r['id'],
                'occurred_on' => $r['on_date'], 'patient_name' => $r['patient'],
                'gross' => $s['gross'], 'tax' => $s['tax'], 'doctor_share' => $s['doctor'],
            ];
        }
    } catch (PDOException $e) { /* IPD module absent */ }

    return $lines;
}

/**
 * CLAWBACK lines: source rows already PAID on an earlier payout that have since
 * been voided or refunded.
 *
 * Reversed at the ORIGINALLY PAID figures, negated — never recomputed at
 * today's rate, which would over-claw if the doctor's percentage has risen
 * since. Partial refunds claw back proportionally.
 *
 * Rows that already carry a CLAWBACK line are excluded, so the same voided bill
 * cannot be deducted on two consecutive payouts.
 */
function payout_clawback_lines(PDO $pdo, int $doctorId): array {
    $lines = [];

    // ---- OPD: voided bills ----
    try {
        $q = $pdo->prepare("
            SELECT l.source_id, l.gross, l.tax, l.doctor_share, l.occurred_on,
                   l.patient_name, l.payout_id, pay.payout_number
            FROM doctor_payout_lines l
            JOIN doctor_payouts pay ON pay.id = l.payout_id
            JOIN bills b            ON b.id = l.source_id
            WHERE pay.doctor_id = ? AND pay.status = 'paid'
              AND l.source_type = 'OPD' AND l.line_kind = 'EARNING'
              AND b.voided_at IS NOT NULL
              AND NOT EXISTS (
                  SELECT 1 FROM doctor_payout_lines c
                  WHERE c.source_type = 'OPD' AND c.source_id = l.source_id AND c.line_kind = 'CLAWBACK'
              )
        ");
        $q->execute([$doctorId]);
        foreach ($q->fetchAll() as $r) {
            $lines[] = [
                'source_type' => 'OPD', 'source_id' => (int) $r['source_id'],
                'occurred_on' => $r['occurred_on'], 'patient_name' => $r['patient_name'],
                'gross' => -(float) $r['gross'], 'tax' => -(float) $r['tax'],
                'doctor_share' => -(float) $r['doctor_share'],
                'clawback_of_payout_id' => (int) $r['payout_id'],
                'clawback_reason' => 'Bill voided after payout ' . $r['payout_number'],
            ];
        }
    } catch (PDOException $e) { error_log('[clawback void] ' . $e->getMessage()); }

    // ---- OPD: refunded bills, clawed back PROPORTIONALLY ----
    try {
        $q = $pdo->prepare("
            SELECT l.source_id, l.gross, l.tax, l.doctor_share, l.occurred_on,
                   l.patient_name, l.payout_id, pay.payout_number,
                   COALESCE(SUM(r.amount), 0) AS refunded
            FROM doctor_payout_lines l
            JOIN doctor_payouts pay ON pay.id = l.payout_id
            JOIN bills b            ON b.id = l.source_id
            JOIN refunds r          ON r.bill_id = b.id AND r.voided_at IS NULL
            WHERE pay.doctor_id = ? AND pay.status = 'paid'
              AND l.source_type = 'OPD' AND l.line_kind = 'EARNING'
              AND b.voided_at IS NULL
              AND NOT EXISTS (
                  SELECT 1 FROM doctor_payout_lines c
                  WHERE c.source_type = 'OPD' AND c.source_id = l.source_id AND c.line_kind = 'CLAWBACK'
              )
            GROUP BY l.id
            HAVING refunded > 0
        ");
        $q->execute([$doctorId]);
        foreach ($q->fetchAll() as $r) {
            $paidGross = (float) $r['gross'];
            if ($paidGross <= 0) { continue; }
            // Proportion of the ORIGINAL gross that came back. Capped at 1 so an
            // over-refund cannot claw back more than was ever paid.
            $ratio = min(1.0, (float) $r['refunded'] / $paidGross);
            $lines[] = [
                'source_type' => 'OPD', 'source_id' => (int) $r['source_id'],
                'occurred_on' => $r['occurred_on'], 'patient_name' => $r['patient_name'],
                'gross' => -$paidGross * $ratio,
                'tax' => -(float) $r['tax'] * $ratio,
                'doctor_share' => -(float) $r['doctor_share'] * $ratio,
                'clawback_of_payout_id' => (int) $r['payout_id'],
                'clawback_reason' => 'Refund of ' . number_format($ratio * 100, 0) . '% after payout ' . $r['payout_number'],
            ];
        }
    } catch (PDOException $e) { /* refunds table absent */ }

    // ---- IPD: the admission's bill voided after payout ----
    try {
        $q = $pdo->prepare("
            SELECT l.source_id, l.gross, l.tax, l.doctor_share, l.occurred_on,
                   l.patient_name, l.payout_id, pay.payout_number
            FROM doctor_payout_lines l
            JOIN doctor_payouts pay      ON pay.id = l.payout_id
            JOIN ipd_doctor_visits dv    ON dv.id = l.source_id
            JOIN ipd_bills ib            ON ib.admission_id = dv.admission_id
            WHERE pay.doctor_id = ? AND pay.status = 'paid'
              AND l.source_type = 'IPD' AND l.line_kind = 'EARNING'
              AND ib.voided_at IS NOT NULL
              AND NOT EXISTS (
                  SELECT 1 FROM doctor_payout_lines c
                  WHERE c.source_type = 'IPD' AND c.source_id = l.source_id AND c.line_kind = 'CLAWBACK'
              )
        ");
        $q->execute([$doctorId]);
        foreach ($q->fetchAll() as $r) {
            $lines[] = [
                'source_type' => 'IPD', 'source_id' => (int) $r['source_id'],
                'occurred_on' => $r['occurred_on'], 'patient_name' => $r['patient_name'],
                'gross' => -(float) $r['gross'], 'tax' => -(float) $r['tax'],
                'doctor_share' => -(float) $r['doctor_share'],
                'clawback_of_payout_id' => (int) $r['payout_id'],
                'clawback_reason' => 'In-door bill voided after payout ' . $r['payout_number'],
            ];
        }
    } catch (PDOException $e) { /* IPD module absent */ }

    return $lines;
}

/**
 * Build a DRAFT payout: earnings for the period, clawbacks from earlier
 * periods, and any balance carried forward. Writes nothing until every line is
 * assembled, and the whole thing is one transaction.
 *
 * Returns [payoutId, null] or [null, 'error message'].
 */
function create_payout(PDO $pdo, int $doctorId, string $start, string $end, int $userId): array {
    if ($doctorId <= 0 || $start > $end) {
        return [null, 'Invalid doctor or period.'];
    }

    // Rates are read ONCE here and frozen onto the payout. Every line in this
    // payout is split at these, so a later rate change cannot re-price it.
    try {
        $d = $pdo->prepare("SELECT id, name, consult_share_pct, consult_has_tax, consult_tax_pct
                            FROM users WHERE id = ? AND base_role = 'DOCTOR'");
        $d->execute([$doctorId]);
        $doc = $d->fetch();
    } catch (PDOException $e) {
        return [null, 'Could not read the doctor.'];
    }
    if (!$doc) {
        return [null, 'That doctor does not exist.'];
    }
    $rates = [
        'share_pct' => (float) ($doc['consult_share_pct'] ?? 0),
        'has_tax'   => (int) ($doc['consult_has_tax'] ?? 0),
        'tax_pct'   => (float) ($doc['consult_tax_pct'] ?? 0),
    ];
    if ($rates['share_pct'] <= 0) {
        return [null, 'That doctor has no share percentage set. Set it on Staff & Doctors first.'];
    }

    $earnings  = payout_earning_lines($pdo, $doctorId, $start, $end, $rates);
    $clawbacks = payout_clawback_lines($pdo, $doctorId);

    $last = last_settled_payout($pdo, $doctorId);
    $carriedIn = $last ? (float) $last['carried_out'] : 0.0;

    if (!$earnings && !$clawbacks && $carriedIn <= 0) {
        return [null, 'Nothing to pay for that period — no new earnings, no adjustments, no balance brought forward.'];
    }

    $gross = $tax = $earned = 0.0;
    foreach ($earnings as $l) { $gross += $l['gross']; $tax += $l['tax']; $earned += $l['doctor_share']; }
    $clawTotal = 0.0;
    foreach ($clawbacks as $l) { $clawTotal += -$l['doctor_share']; }   // positive

    // Carry-forward: never pay a negative. What cannot be recovered now goes to
    // the next payout, so the debt stays visible without anyone handing money back.
    $balance    = $earned - $clawTotal - $carriedIn;
    $netPaid    = max(0.0, $balance);
    $carriedOut = $balance < 0 ? -$balance : 0.0;

    try {
        $pdo->beginTransaction();
        $number = generate_payout_number($pdo);
        $pdo->prepare('
            INSERT INTO doctor_payouts
                (payout_number, doctor_id, period_start, period_end,
                 gross_amount, tax_withheld, earned_amount, clawback_amount,
                 carried_in, carried_out, net_paid,
                 share_pct, has_tax, tax_pct, status, created_by_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ')->execute([
            $number, $doctorId, $start, $end,
            $gross, $tax, $earned, $clawTotal,
            $carriedIn, $carriedOut, $netPaid,
            $rates['share_pct'], $rates['has_tax'], $rates['tax_pct'], 'draft', $userId,
        ]);
        $payoutId = (int) $pdo->lastInsertId();

        $ins = $pdo->prepare('
            INSERT INTO doctor_payout_lines
                (payout_id, source_type, source_id, occurred_on, patient_name,
                 gross, tax, doctor_share, line_kind, clawback_of_payout_id, clawback_reason)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        foreach ($earnings as $l) {
            $ins->execute([$payoutId, $l['source_type'], $l['source_id'], $l['occurred_on'],
                           $l['patient_name'], $l['gross'], $l['tax'], $l['doctor_share'],
                           'EARNING', null, null]);
        }
        foreach ($clawbacks as $l) {
            $ins->execute([$payoutId, $l['source_type'], $l['source_id'], $l['occurred_on'],
                           $l['patient_name'], $l['gross'], $l['tax'], $l['doctor_share'],
                           'CLAWBACK', $l['clawback_of_payout_id'], $l['clawback_reason']]);
        }

        audit_log($pdo, 'payout_created', "Draft payout $number for {$doc['name']} ($start to $end) — net Rs " . number_format($netPaid, 2), $userId);
        $pdo->commit();
        return [$payoutId, null];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('[create_payout] ' . $e->getMessage());
        // A duplicate-key here means a source row was already paid on another
        // payout — the UNIQUE guard doing its job.
        if ((int) $e->getCode() === 23000) {
            return [null, 'Some of those bills are already on another payout. Refresh and try again.'];
        }
        return [null, 'Could not create the payout — has sql/add_doctor_payouts.sql been run?'];
    }
}

/**
 * Settle a draft: post the Doctor Shares expense that moves the cash, and mark
 * the payout paid.
 *
 * A fully-carried-forward payout (net 0) is settled WITHOUT an expense — there
 * is no cash to move, but the clawback and the carried balance must still be
 * recorded, or the next payout would recompute them from scratch.
 */
function settle_payout(PDO $pdo, int $payoutId, int $userId, string $source = 'BANK'): array {
    try {
        $q = $pdo->prepare('
            SELECT p.*, u.name AS doctor_name
            FROM doctor_payouts p JOIN users u ON u.id = p.doctor_id
            WHERE p.id = ?
        ');
        $q->execute([$payoutId]);
        $p = $q->fetch();
    } catch (PDOException $e) {
        return [false, 'Could not read the payout.'];
    }
    if (!$p)                        { return [false, 'Payout not found.']; }
    if ($p['status'] === 'paid')    { return [false, 'That payout is already settled.']; }
    if ($p['status'] === 'voided')  { return [false, 'That payout was voided.']; }

    $net = (float) $p['net_paid'];
    // The period the money BELONGS to, so the expense report and P&L put it in
    // the right month regardless of when it is actually disbursed.
    $periodMonth = date('Y-m-01', strtotime($p['period_end']));

    try {
        $pdo->beginTransaction();
        $expenseId = null;

        if ($net > 0) {
            $cat = $pdo->query("SELECT id FROM expense_categories WHERE name = 'Doctor Shares' LIMIT 1")->fetchColumn();
            if (!$cat) {
                $pdo->rollBack();
                return [false, 'The "Doctor Shares" expense category is missing — run sql/add_accounts_phase1.sql.'];
            }
            // Voucher number. generate_expense_number() lives inside
            // expenses.php (a page, not includable from here), so the same
            // race-safe GREATEST logic is repeated rather than duplicating the
            // page's include chain: take the max of the stored counter and what
            // is actually in the table, so a restore cannot re-issue a number.
            $year = (int) date('Y');
            $sq = $pdo->prepare("
                SELECT GREATEST(
                    COALESCE((SELECT last_sequence FROM expense_sequences WHERE sequence_year = :y), 0),
                    COALESCE((SELECT MAX(CAST(SUBSTRING_INDEX(expense_number, '-', -1) AS UNSIGNED))
                              FROM expenses WHERE expense_number LIKE :pfx), 0)
                ) + 1
            ");
            $sq->execute([':y' => $year, ':pfx' => 'EXP-' . $year . '-%']);
            $seq = max(1, (int) $sq->fetchColumn());
            $pdo->prepare('
                INSERT INTO expense_sequences (sequence_year, last_sequence) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE last_sequence = GREATEST(last_sequence, VALUES(last_sequence))
            ')->execute([$year, $seq]);
            $number = 'EXP-' . $year . '-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);

            $pdo->prepare("
                INSERT INTO expenses
                    (expense_number, category_id, amount, description, paid_to, paid_to_doctor_id,
                     expense_date, period_month, source, posted_by_id, approval_status,
                     approved_by_id, approved_at)
                VALUES (?, ?, ?, ?, ?, ?, CURDATE(), ?, ?, ?, 'APPROVED', ?, NOW())
            ")->execute([
                $number, (int) $cat, $net,
                'Doctor share payout ' . $p['payout_number'],
                $p['doctor_name'], (int) $p['doctor_id'],
                $periodMonth, $source, $userId, $userId,
            ]);
            $expenseId = (int) $pdo->lastInsertId();
        }

        $pdo->prepare("UPDATE doctor_payouts SET status = 'paid', paid_at = NOW(), expense_id = ? WHERE id = ? AND status = 'draft'")
            ->execute([$expenseId, $payoutId]);

        audit_log($pdo, 'payout_settled', "Settled {$p['payout_number']} for {$p['doctor_name']} — Rs " . number_format($net, 2) . ((float) $p['carried_out'] > 0 ? ' (Rs ' . number_format((float) $p['carried_out'], 2) . ' carried forward)' : ''), $userId);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('[settle_payout] ' . $e->getMessage());
        return [false, 'Could not settle the payout. Please try again.'];
    }

    $msg = $net > 0
        ? "Payout {$p['payout_number']} settled — Rs " . number_format($net) . ' recorded as a Doctor Shares expense.'
        : "Payout {$p['payout_number']} settled with no cash movement.";
    if ((float) $p['carried_out'] > 0) {
        $msg .= ' Rs ' . number_format((float) $p['carried_out'])
              . ' carried forward to the next payout.';
    }
    return [true, $msg];
}
