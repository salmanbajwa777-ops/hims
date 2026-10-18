<?php
/**
 * ER-direct controls: the paper-register reconciliation at shift close, plus the
 * detectors that feed the exceptions page.
 *
 * ER-direct = a patient arrives with a doctor's PAPER advice (e.g. nebulisation
 * three times a day), is served, and goes home. No admission. EACH SESSION GETS
 * ITS OWN BILL. With the receptionist the only person on a device there is no
 * independent system record of the nurse's work, so the controls are:
 *   - the bill carries who gave the service + "session N of M"  (er_bills.*)
 *   - at close the receptionist declares the nurse's paper-register tally, which
 *     is compared with the ER bills they raised (shift_closings.er_register_*)
 *
 * SCHEMA GUARDS. Ships before OR after sql/2026_10_er_direct_controls.sql. Every
 * touch of a new column goes through adm_has() (config/admission_bill.php), so
 * pre-migration every detector returns [] and the close/raise paths fall back to
 * their old behaviour. Never a fatal, never a false alarm.
 *
 * DETECTORS (all take $from/$to as 'Y-m-d', inclusive; return a list of rows,
 * each with at least: kind, user_id, user_name, date, detail):
 *   erx_register_mismatches(PDO, from, to): array
 *   erx_no_register_closings(PDO, from, to, bool $onlyWithEr = true): array
 *   erx_void_waive_rates(PDO, from, to, int $minCount = 2, float $minRate = 0.25): array
 *   erx_skipped_sessions(PDO, from, to): array
 *   erx_repeat_beyond_advice(PDO, from, to): array
 *   erx_all(PDO, from, to): array   // kind => rows, for the page to loop over
 */

require_once __DIR__ . '/admission_bill.php';   // adm_has(), billing.php

/** True when shift_closings carries the register columns. */
function erx_closing_ready(PDO $pdo): bool {
    return adm_has($pdo, 'shift_closings', 'er_register_count')
        && adm_has($pdo, 'shift_closings', 'er_register_none')
        && adm_has($pdo, 'shift_closings', 'er_register_system_count')
        && adm_has($pdo, 'shift_closings', 'er_register_note');
}

/** True when er_bills carries the nurse + session columns. */
function erx_bill_ready(PDO $pdo): bool {
    foreach (['service_given_by_id', 'service_given_by_manual', 'advice_session_no', 'advice_sessions_total'] as $c) {
        if (!adm_has($pdo, 'er_bills', $c)) { return false; }
    }
    return true;
}

/**
 * Non-voided ER bills a receptionist raised in their business day — the number
 * the nurse's paper register is compared with. Same window and same drawer
 * attribution (paid_by_id) as day_cash_tally().
 */
function er_register_system_count(PDO $pdo, string $date, int $userId): int {
    try {
        [$ws, $we] = business_day_window($pdo, $date);
        $q = $pdo->prepare('SELECT COUNT(*) FROM er_bills
                             WHERE voided_at IS NULL AND paid_at >= ? AND paid_at < ?
                               AND (paid_by_id = ? OR (paid_by_id IS NULL AND created_by_id = ?))');
        $q->execute([$ws, $we, $userId, $userId]);
        return (int) $q->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Validate the close form's register answer. Returns
 *   ['error' => string, 'count' => ?int, 'none' => int, 'system' => int, 'note' => ?string, 'mismatch' => bool]
 * A mismatch needs a note but is NEVER refused beyond that. With ER bills on the
 * day the receptionist must either enter a count or tick "no register kept"
 * (so silence is impossible, but skipping is allowed and recorded).
 */
function er_register_resolve(PDO $pdo, string $date, int $userId, array $post): array {
    $out = ['error' => '', 'count' => null, 'none' => 0, 'system' => 0, 'note' => null, 'mismatch' => false];
    if (!erx_closing_ready($pdo)) { return $out; }   // pre-migration: field absent, nothing enforced
    $system = er_register_system_count($pdo, $date, $userId);
    $out['system'] = $system;
    $none = !empty($post['er_register_none']);
    $raw = trim((string) ($post['er_register_count'] ?? ''));
    $note = trim((string) ($post['er_register_note'] ?? ''));
    $note = $note === '' ? null : mb_substr($note, 0, 255);

    if ($none) {
        $out['none'] = 1;
        $out['note'] = $note;
        return $out;               // ticked: count (if typed) is ignored
    }
    if ($raw === '') {
        if ($system > 0) {
            $out['error'] = "You raised $system ER-direct bill" . ($system === 1 ? '' : 's')
                . ' today. Enter the nurse\'s paper register count, or tick "No register kept".';
        }
        return $out;               // nothing to reconcile on a zero-ER day
    }
    if (!preg_match('/^\d{1,4}$/', $raw)) {
        $out['error'] = 'The ER-direct register count must be a whole number.';
        return $out;
    }
    $count = (int) $raw;
    $out['count'] = $count;
    $out['note'] = $note;
    if ($count !== $system) {
        $out['mismatch'] = true;
        if ($note === null) {
            $out['error'] = "The nurse's register says $count but you raised $system ER-direct bill"
                . ($system === 1 ? '' : 's') . ' — a short note explaining the difference is required.';
        }
    }
    return $out;
}

/** Persist the register answer onto the just-inserted closing (same transaction). */
function er_register_store(PDO $pdo, int $closingId, array $r): void {
    if (!erx_closing_ready($pdo)) { return; }
    $pdo->prepare('UPDATE shift_closings
                      SET er_register_count = ?, er_register_none = ?,
                          er_register_system_count = ?, er_register_note = ?
                    WHERE id = ?')
        ->execute([$r['count'], $r['none'], $r['system'], $r['note'], $closingId]);
}

/** Run a detector query; any failure means "cannot prove" -> no rows. */
function erx_rows(PDO $pdo, string $sql, array $args): array {
    try {
        $q = $pdo->prepare($sql);
        $q->execute($args);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

/** Closings where the declared register count differs from the bills raised. */
function erx_register_mismatches(PDO $pdo, string $from, string $to): array {
    if (!erx_closing_ready($pdo)) { return []; }
    $rows = erx_rows($pdo, '
        SELECT c.id, c.closing_number, c.closing_date, c.cashier_id, u.name AS user_name,
               c.er_register_count AS declared, c.er_register_system_count AS system, c.er_register_note AS note
          FROM shift_closings c LEFT JOIN users u ON u.id = c.cashier_id
         WHERE c.closing_date BETWEEN ? AND ?
           AND c.er_register_count IS NOT NULL AND COALESCE(c.er_register_none, 0) = 0
           AND c.er_register_system_count IS NOT NULL
           AND c.er_register_count <> c.er_register_system_count
         ORDER BY c.closing_date, c.id', [$from, $to]);
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'kind' => 'er_register_mismatch', 'user_id' => (int) $r['cashier_id'], 'user_name' => $r['user_name'],
            'date' => $r['closing_date'], 'closing_id' => (int) $r['id'], 'closing_number' => $r['closing_number'],
            'declared' => (int) $r['declared'], 'system' => (int) $r['system'], 'note' => $r['note'],
            'detail' => "Register {$r['declared']} vs {$r['system']} ER bill(s) raised",
        ];
    }
    return $out;
}

/** Closings where the receptionist ticked "no register kept" (default: only days that had ER bills). */
function erx_no_register_closings(PDO $pdo, string $from, string $to, bool $onlyWithEr = true): array {
    if (!erx_closing_ready($pdo)) { return []; }
    $rows = erx_rows($pdo, '
        SELECT c.id, c.closing_number, c.closing_date, c.cashier_id, u.name AS user_name,
               c.er_register_system_count AS system, c.er_register_note AS note
          FROM shift_closings c LEFT JOIN users u ON u.id = c.cashier_id
         WHERE c.closing_date BETWEEN ? AND ? AND COALESCE(c.er_register_none, 0) = 1
         ORDER BY c.closing_date, c.id', [$from, $to]);
    $out = [];
    foreach ($rows as $r) {
        $n = (int) $r['system'];
        if ($onlyWithEr && $n < 1) { continue; }
        $out[] = [
            'kind' => 'er_no_register', 'user_id' => (int) $r['cashier_id'], 'user_name' => $r['user_name'],
            'date' => $r['closing_date'], 'closing_id' => (int) $r['id'], 'closing_number' => $r['closing_number'],
            'system' => $n, 'note' => $r['note'],
            'detail' => "No register kept; $n ER bill(s) raised",
        ];
    }
    return $out;
}

/**
 * ER bills voided or waived per receptionist (raiser) with rate = (voided+waived)/all.
 * Only receptionists with at least $minCount voided+waived AND rate >= $minRate.
 * Works pre-migration too (uses original columns only).
 */
function erx_void_waive_rates(PDO $pdo, string $from, string $to, int $minCount = 2, float $minRate = 0.25): array {
    if (!adm_has($pdo, 'er_bills')) { return []; }
    $rows = erx_rows($pdo, "
        SELECT COALESCE(e.paid_by_id, e.created_by_id) AS uid, u.name AS user_name,
               COUNT(*) AS total,
               SUM(CASE WHEN e.voided_at IS NOT NULL THEN 1 ELSE 0 END) AS voided,
               SUM(CASE WHEN e.voided_at IS NULL AND e.status = 'waived' THEN 1 ELSE 0 END) AS waived
          FROM er_bills e LEFT JOIN users u ON u.id = COALESCE(e.paid_by_id, e.created_by_id)
         WHERE DATE(e.created_at) BETWEEN ? AND ?
         GROUP BY COALESCE(e.paid_by_id, e.created_by_id), u.name", [$from, $to]);
    $out = [];
    foreach ($rows as $r) {
        $total = (int) $r['total'];
        $bad = (int) $r['voided'] + (int) $r['waived'];
        if ($total < 1 || $bad < $minCount) { continue; }
        $rate = $bad / $total;
        if ($rate + 1e-9 < $minRate) { continue; }
        $out[] = [
            'kind' => 'er_void_waive_rate', 'user_id' => (int) $r['uid'], 'user_name' => $r['user_name'],
            'date' => $from . '..' . $to, 'total' => $total, 'voided' => (int) $r['voided'],
            'waived' => (int) $r['waived'], 'rate' => round($rate, 4),
            'detail' => (int) $r['voided'] . ' voided + ' . (int) $r['waived'] . " waived of $total ER bills ("
                . round($rate * 100) . '%)',
        ];
    }
    return $out;
}

/** Non-voided bills with session metadata, grouped per patient + day. */
function erx_session_groups(PDO $pdo, string $from, string $to): array {
    if (!erx_bill_ready($pdo)) { return []; }
    $rows = erx_rows($pdo, '
        SELECT e.id, e.invoice_number, e.patient_id, p.name AS patient_name, DATE(e.created_at) AS d,
               COALESCE(e.paid_by_id, e.created_by_id) AS uid, u.name AS user_name,
               e.advice_session_no AS sn, e.advice_sessions_total AS st
          FROM er_bills e
          JOIN patients p ON p.id = e.patient_id
          LEFT JOIN users u ON u.id = COALESCE(e.paid_by_id, e.created_by_id)
         WHERE e.voided_at IS NULL AND DATE(e.created_at) BETWEEN ? AND ?
         ORDER BY e.id', [$from, $to]);
    $g = [];
    foreach ($rows as $r) { $g[$r['patient_id'] . '|' . $r['d']][] = $r; }
    return $g;
}

/** Session N billed with an earlier session (1..N-1) missing for the same patient that day. */
function erx_skipped_sessions(PDO $pdo, string $from, string $to): array {
    $out = [];
    foreach (erx_session_groups($pdo, $from, $to) as $bills) {
        $have = [];
        foreach ($bills as $b) { if ($b['sn'] !== null) { $have[(int) $b['sn']] = true; } }
        foreach ($bills as $b) {
            if ($b['sn'] === null) { continue; }
            $n = (int) $b['sn'];
            $missing = [];
            for ($i = 1; $i < $n; $i++) { if (empty($have[$i])) { $missing[] = $i; } }
            if (!$missing) { continue; }
            $out[] = [
                'kind' => 'er_session_skipped', 'user_id' => (int) $b['uid'], 'user_name' => $b['user_name'],
                'date' => $b['d'], 'invoice_number' => $b['invoice_number'], 'patient_name' => $b['patient_name'],
                'session_no' => $n, 'missing' => $missing,
                'detail' => "{$b['invoice_number']} is session $n for {$b['patient_name']} but session "
                    . implode(', ', $missing) . ' was not billed that day',
            ];
        }
    }
    return $out;
}

/** Same patient, same day: more non-voided bills than the advised sessions per day. */
function erx_repeat_beyond_advice(PDO $pdo, string $from, string $to): array {
    $out = [];
    foreach (erx_session_groups($pdo, $from, $to) as $bills) {
        $max = 0;
        foreach ($bills as $b) { if ($b['st'] !== null) { $max = max($max, (int) $b['st']); } }
        if ($max < 1 || count($bills) <= $max) { continue; }   // no advice stated, or within it
        $last = end($bills);
        $out[] = [
            'kind' => 'er_repeat_beyond_advice', 'user_id' => (int) $last['uid'], 'user_name' => $last['user_name'],
            'date' => $last['d'], 'patient_name' => $last['patient_name'], 'billed' => count($bills), 'advised' => $max,
            'invoices' => array_column($bills, 'invoice_number'),
            'detail' => count($bills) . " ER bills for {$last['patient_name']} in one day; advice was $max session(s)/day",
        ];
    }
    return $out;
}

/** Every ER-direct detector in one call: kind => rows. */
function erx_all(PDO $pdo, string $from, string $to): array {
    return [
        'er_register_mismatch'    => erx_register_mismatches($pdo, $from, $to),
        'er_no_register'          => erx_no_register_closings($pdo, $from, $to),
        'er_void_waive_rate'      => erx_void_waive_rates($pdo, $from, $to),
        'er_session_skipped'      => erx_skipped_sessions($pdo, $from, $to),
        'er_repeat_beyond_advice' => erx_repeat_beyond_advice($pdo, $from, $to),
    ];
}
