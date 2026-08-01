<?php
/**
 * IPD Treatment Sheet — the write handlers.
 *
 * Every mutation to a medication order or an administration slot goes through
 * here, so the doctor-approval rule and the audit trail cannot be bypassed by a
 * page that forgot to re-check something. Pages call these; they never INSERT
 * into ipd_medication_orders directly.
 *
 * Each handler returns ['ok' => bool, 'error' => string, ...] and NEVER throws
 * at the caller — a ward screen must not white-page mid-drug-round.
 */

require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/ipd_treatment.php';
require_once __DIR__ . '/notify.php';

/**
 * Create a medication order.
 *
 * The approval rule, applied at the only point it can be applied honestly:
 *   - a DOCTOR (or ADMIN) writing an order self-approves it at insert, and its
 *     slots are generated immediately;
 *   - anyone else writes a PENDING order that generates NO slots and cannot be
 *     administered until a doctor signs it.
 *
 * Allergy handling follows spec 4.1: a match BLOCKS the save outright unless the
 * caller passes an explicit typed override reason, which is stored on the row
 * and written to the audit trail. Duplicate therapy only WARNS — genuine dual
 * therapy exists and blocking it would be clinically wrong.
 */
function ipd_create_med_order(PDO $pdo, int $admissionId, array $in): array {
    $out = ['ok' => false, 'error' => '', 'order_id' => null, 'warnings' => [], 'allergy_hits' => []];

    if (!has_permission('IPD_WRITE_MED_ORDER')) {
        $out['error'] = 'You do not have permission to write medication orders.';
        return $out;
    }

    $uid = (int) ($_SESSION['user_id'] ?? 0);
    $baseRole = $_SESSION['base_role'] ?? '';
    $role = in_array($baseRole, ['ADMIN','DOCTOR','STAFF'], true) ? $baseRole : 'STAFF';

    // The admission must exist and still be open. A discharged stay does not
    // accept new drugs; that is what the discharge prescription is for.
    $st = $pdo->prepare('SELECT a.*, v.patient_id FROM ipd_admissions a JOIN visits v ON v.id = a.visit_id WHERE a.id = ?');
    $st->execute([$admissionId]);
    $adm = $st->fetch();
    if (!$adm) { $out['error'] = 'Admission not found.'; return $out; }
    if ($adm['status'] === 'DISCHARGED') {
        $out['error'] = 'This patient has been discharged — no new medication orders.';
        return $out;
    }
    $patientId = (int) $adm['patient_id'];

    // ---- Resolve the drug: formulary row, or a free-typed name ----
    $drugId = (int) ($in['drug_id'] ?? 0) ?: null;
    $drug   = $drugId ? ipd_formulary_drug($pdo, $drugId) : null;
    if ($drugId && !$drug) {
        $out['error'] = 'That drug is no longer in the formulary — pick another.';
        return $out;
    }

    $manual = mb_strtoupper(trim((string) ($in['drug_name_manual'] ?? '')), 'UTF-8');

    // Generic and brand stay SEPARATE all the way through. From the formulary
    // they come from their own columns; when free-typed, the operator may give
    // a brand alongside the generic and both are kept.
    if ($drug) {
        $generic     = (string) $drug['generic_name'];
        $doseForm    = trim((string) ($drug['dose_form'] ?? ''));
        $drugClass   = $drug['drug_class'] ?? null;
        $isHighAlert = (int) $drug['is_high_alert'];

        // The brand must be one this formulary row actually offers. A POST can
        // name any string, and a brand that does not belong to the product is a
        // wrong label on a drug chart — so an unrecognised one falls back to the
        // row's primary rather than being written through.
        $wanted  = trim((string) ($in['brand_name'] ?? ''));
        $options = ipd_brand_options($drug);
        $brand   = (string) ($drug['brand_name'] ?? '');
        foreach ($options as $opt) {
            if (mb_strtolower($opt, 'UTF-8') === mb_strtolower($wanted, 'UTF-8')) { $brand = $opt; break; }
        }
    } else {
        if ($manual === '') {
            $out['error'] = 'Pick a drug from the formulary or type a drug name.';
            return $out;
        }
        $generic     = $manual;
        $doseForm    = mb_substr(trim((string) ($in['dose_form'] ?? '')), 0, 40);
        $brand       = mb_strtoupper(trim((string) ($in['brand_name'] ?? '')), 'UTF-8');
        $drugClass   = null;
        $isHighAlert = 0;
    }
    $brand    = $brand !== '' ? mb_substr($brand, 0, 150) : null;
    $doseForm = $doseForm !== '' ? $doseForm : null;
    $printable = ipd_display_drug_name($generic, $brand);

    // ---- Structured dose / route / frequency ----
    $doseValue = (float) ($in['dose_value'] ?? 0);
    $doseUnit  = trim((string) ($in['dose_unit'] ?? ''));
    $route     = strtoupper(trim((string) ($in['route'] ?? '')));
    $frequency = strtoupper(trim((string) ($in['frequency'] ?? '')));
    $orderType = strtoupper(trim((string) ($in['order_type'] ?? 'SCHEDULED')));

    if ($doseValue <= 0) { $out['error'] = 'Enter a dose greater than zero.'; return $out; }
    if (!in_array($doseUnit, IPD_DOSE_UNITS, true)) { $out['error'] = 'Pick a valid dose unit.'; return $out; }
    if (!isset(IPD_ROUTES[$route])) { $out['error'] = 'Pick a valid route.'; return $out; }
    if (!array_key_exists($frequency, ipd_frequency_map($pdo))) { $out['error'] = 'Pick a valid frequency.'; return $out; }
    if (!in_array($orderType, IPD_ORDER_TYPES, true)) { $orderType = 'SCHEDULED'; }

    // Keep order_type and frequency consistent — they are two views of one fact,
    // and letting them disagree would send a PRN drug into the scheduled grid.
    if ($frequency === 'PRN')  { $orderType = 'PRN'; }
    if ($frequency === 'STAT') { $orderType = 'STAT'; }
    if ($orderType === 'PRN'  && $frequency !== 'PRN')  { $frequency = 'PRN'; }
    if ($orderType === 'STAT' && $frequency !== 'STAT') { $frequency = 'STAT'; }

    // ---- PRN needs an indication and a ceiling (spec 4.4) ----
    $prnMax = ($in['prn_max_per_24h'] ?? '') !== '' ? (int) $in['prn_max_per_24h'] : null;
    $prnInd = mb_substr(trim((string) ($in['prn_indication'] ?? '')), 0, 300) ?: null;
    if ($orderType === 'PRN') {
        if (!$prnInd) { $out['error'] = 'A PRN order needs an indication ("what is it for").'; return $out; }
        if (!$prnMax || $prnMax < 1) { $out['error'] = 'A PRN order needs a maximum number of doses per 24 hours.'; return $out; }
    } else {
        $prnMax = null; $prnInd = null;
    }

    // ---- Timing ----
    $startRaw = trim((string) ($in['start_datetime'] ?? ''));
    $startTs  = $startRaw !== '' ? strtotime($startRaw) : time();
    if (!$startTs) { $out['error'] = 'Enter a valid start date and time.'; return $out; }
    // A STAT dose means "now" — a back-dated or future STAT is a contradiction.
    if ($orderType === 'STAT') { $startTs = time(); }
    $startDt = date('Y-m-d H:i:s', $startTs);

    $duration = ($in['duration_days'] ?? '') !== '' ? (int) $in['duration_days'] : null;
    if ($orderType === 'STAT') { $duration = 1; }
    if ($duration !== null && ($duration < 1 || $duration > 365)) {
        $out['error'] = 'Duration must be between 1 and 365 days (leave blank for ongoing).';
        return $out;
    }

    $instructions = mb_substr(trim((string) ($in['special_instructions'] ?? '')), 0, 500) ?: null;
    $continueDc   = !empty($in['continue_at_discharge']) ? 1 : 0;

    // ---- Validation: allergy (blocks) then duplicate therapy (warns) ----
    $allergyHits = ipd_check_allergy($pdo, $patientId, $drug, $generic);
    // A brand-name allergy against a brand-named order is caught here too.
    if (!$allergyHits && $brand) {
        $allergyHits = ipd_check_allergy($pdo, $patientId, $drug, $brand);
    }
    $overrideReason = mb_substr(trim((string) ($in['allergy_override_reason'] ?? '')), 0, 500);
    $override = 0;

    if ($allergyHits) {
        if ($overrideReason === '') {
            // BLOCK. The caller re-renders the form with these hits shown and
            // must collect a typed reason to proceed.
            $out['error'] = 'ALLERGY CONFLICT — this patient has a documented allergy that matches this drug.';
            $out['allergy_hits'] = $allergyHits;
            return $out;
        }
        // Only a prescriber may sign off an allergy override. Letting staff type
        // a reason would turn the hard block into a speed bump.
        if (!has_permission('IPD_APPROVE_MED_ORDER')) {
            $out['error'] = 'Only a doctor can override a documented allergy. Ask the consultant to write this order.';
            $out['allergy_hits'] = $allergyHits;
            return $out;
        }
        $override = 1;
    }

    $dupes = ipd_check_duplicate($pdo, $admissionId, $drugId, $generic, $drugClass);
    foreach ($dupes as $d) {
        $out['warnings'][] = 'Already on the sheet: ' . ipd_order_line($d);
    }

    // ---- The approval gate ----
    // A prescriber's own order is signed the moment they write it. Everyone
    // else's waits for a doctor.
    $selfApproves  = has_permission('IPD_APPROVE_MED_ORDER');
    $approvalStatus = $selfApproves ? 'APPROVED' : 'PENDING';

    try {
        $pdo->beginTransaction();

        $pdo->prepare('
            INSERT INTO ipd_medication_orders
                (admission_id, drug_id, drug_name_manual,
                 generic_name_snapshot, brand_name_snapshot, dose_form_snapshot, drug_name_snapshot,
                 drug_class_snapshot, is_high_alert,
                 dose_value, dose_unit, route, frequency, order_type,
                 start_datetime, duration_days, prn_max_per_24h, prn_indication,
                 special_instructions, continue_at_discharge,
                 prescribed_by_id, prescribed_by_role, prescribed_at,
                 approval_status, approved_by_id, approved_at,
                 allergy_override, allergy_override_reason, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ' .
                 ($selfApproves ? 'NOW()' : 'NULL') . ', ?, ?, \'ACTIVE\')
        ')->execute([
            $admissionId, $drugId, $drug ? null : $manual,
            $generic, $brand, $doseForm, $printable,
            $drugClass, $isHighAlert,
            $doseValue, $doseUnit, $route, $frequency, $orderType,
            $startDt, $duration, $prnMax, $prnInd,
            $instructions, $continueDc,
            $uid, $role,
            $approvalStatus, $selfApproves ? $uid : null,
            $override, $override ? $overrideReason : null,
        ]);
        $orderId = (int) $pdo->lastInsertId();

        // Slots are generated only for an approved order — the gate, restated.
        $slots = 0;
        if ($selfApproves) {
            $o = $pdo->prepare('SELECT * FROM ipd_medication_orders WHERE id = ?');
            $o->execute([$orderId]);
            $slots = ipd_generate_slots($pdo, $o->fetch());
        }

        $pdo->commit();

        $detail = $printable . ' ' . ipd_dose_line([
            'dose_value' => $doseValue, 'dose_unit' => $doseUnit,
            'route' => $route, 'frequency' => $frequency,
        ]) . ' — ' . ($selfApproves ? "self-approved, $slots slot(s) generated" : 'PENDING doctor approval');
        if ($override) { $detail .= ' [ALLERGY OVERRIDE: ' . $overrideReason . ']'; }
        ipd_med_audit($pdo, 'ORDER', $orderId, 'CREATE', $detail, $admissionId);

        // A staff-written order is useless until a doctor sees it — tell them.
        if (!$selfApproves && function_exists('notify_ipd_med_order_pending')) {
            notify_ipd_med_order_pending($pdo, $orderId);
        }

        $out['ok'] = true;
        $out['order_id'] = $orderId;
        $out['pending'] = !$selfApproves;
        $out['slots'] = $slots;
        return $out;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $out['error'] = 'Could not save the order — please try again.';
        return $out;
    }
}

/**
 * Doctor approves a pending order — the signature that lets treatment start.
 *
 * Slots are generated HERE, from the moment of approval rather than the order's
 * original start time, so a sheet written overnight and signed in the morning
 * does not open with a backlog of doses nobody could have given.
 */
function ipd_approve_med_order(PDO $pdo, int $orderId): array {
    $out = ['ok' => false, 'error' => '', 'slots' => 0];

    if (!has_permission('IPD_APPROVE_MED_ORDER')) {
        $out['error'] = 'Only a doctor can approve medication orders.';
        return $out;
    }
    $uid = (int) ($_SESSION['user_id'] ?? 0);

    try {
        $pdo->beginTransaction();

        // Lock the row: two doctors tapping Approve at once must not both
        // generate a set of slots.
        $st = $pdo->prepare('SELECT * FROM ipd_medication_orders WHERE id = ? FOR UPDATE');
        $st->execute([$orderId]);
        $o = $st->fetch();
        if (!$o) { throw new RuntimeException('not_found'); }
        if ($o['approval_status'] === 'APPROVED') { throw new RuntimeException('already'); }
        if ($o['approval_status'] === 'REJECTED') { throw new RuntimeException('rejected'); }
        if ($o['status'] !== 'ACTIVE') { throw new RuntimeException('not_active'); }

        $pdo->prepare("
            UPDATE ipd_medication_orders
            SET approval_status = 'APPROVED', approved_by_id = ?, approved_at = NOW()
            WHERE id = ? AND approval_status = 'PENDING'
        ")->execute([$uid, $orderId]);

        $st->execute([$orderId]);
        $fresh = $st->fetch();
        $slots = ipd_generate_slots($pdo, $fresh, date('Y-m-d H:i:s'));

        $pdo->commit();

        ipd_med_audit($pdo, 'ORDER', $orderId, 'APPROVE',
            'Approved: ' . ipd_order_line($fresh) . " — $slots slot(s) generated",
            (int) $o['admission_id']);

        $out['ok'] = true;
        $out['slots'] = $slots;
        return $out;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $out['error'] = [
            'not_found'  => 'Order not found.',
            'already'    => 'That order is already approved.',
            'rejected'   => 'That order was rejected and cannot be approved.',
            'not_active' => 'That order is no longer active.',
        ][$e->getMessage()] ?? 'Could not approve the order.';
        return $out;
    }
}

/** Doctor rejects a pending order. Terminal — never administrable, never deleted. */
function ipd_reject_med_order(PDO $pdo, int $orderId, string $reason): array {
    $out = ['ok' => false, 'error' => ''];

    if (!has_permission('IPD_APPROVE_MED_ORDER')) {
        $out['error'] = 'Only a doctor can reject medication orders.';
        return $out;
    }
    $reason = mb_substr(trim($reason), 0, 300);
    if ($reason === '') { $out['error'] = 'Give a reason for rejecting this order.'; return $out; }

    $uid = (int) ($_SESSION['user_id'] ?? 0);
    try {
        $st = $pdo->prepare('SELECT * FROM ipd_medication_orders WHERE id = ?');
        $st->execute([$orderId]);
        $o = $st->fetch();
        if (!$o) { $out['error'] = 'Order not found.'; return $out; }
        if ($o['approval_status'] !== 'PENDING') {
            $out['error'] = 'Only a pending order can be rejected.';
            return $out;
        }

        $pdo->prepare("
            UPDATE ipd_medication_orders
            SET approval_status = 'REJECTED', approved_by_id = ?, approved_at = NOW(), rejected_reason = ?
            WHERE id = ? AND approval_status = 'PENDING'
        ")->execute([$uid, $reason, $orderId]);

        ipd_med_audit($pdo, 'ORDER', $orderId, 'REJECT',
            'Rejected: ' . ipd_order_line($o) . " — $reason", (int) $o['admission_id']);

        $out['ok'] = true;
        return $out;
    } catch (Throwable $e) {
        $out['error'] = 'Could not reject the order.';
        return $out;
    }
}

/**
 * Discontinue a running order (spec 5 — the non-negotiable rule).
 *
 * Never deletes. Sets status + who/when/why, cancels only FUTURE pending slots,
 * and leaves every past GIVEN slot exactly as recorded. This is what makes
 * "some meds may be stopped earlier than initially planned" safe.
 */
function ipd_discontinue_med_order(PDO $pdo, int $orderId, string $reason): array {
    $out = ['ok' => false, 'error' => '', 'cancelled' => 0];

    if (!has_permission('IPD_DISCONTINUE_MED')) {
        $out['error'] = 'Only a doctor can discontinue a medication order.';
        return $out;
    }
    $reason = mb_substr(trim($reason), 0, 300);
    if ($reason === '') { $out['error'] = 'Give a reason for stopping this drug.'; return $out; }

    $uid = (int) ($_SESSION['user_id'] ?? 0);
    try {
        $pdo->beginTransaction();

        $st = $pdo->prepare('SELECT * FROM ipd_medication_orders WHERE id = ? FOR UPDATE');
        $st->execute([$orderId]);
        $o = $st->fetch();
        if (!$o) { throw new RuntimeException('not_found'); }
        if ($o['status'] !== 'ACTIVE') { throw new RuntimeException('not_active'); }

        $pdo->prepare("
            UPDATE ipd_medication_orders
            SET status = 'DISCONTINUED', discontinued_by_id = ?, discontinued_at = NOW(), discontinued_reason = ?
            WHERE id = ? AND status = 'ACTIVE'
        ")->execute([$uid, $reason, $orderId]);

        $cancelled = ipd_cancel_future_slots($pdo, $orderId, $reason);

        $pdo->commit();

        ipd_med_audit($pdo, 'ORDER', $orderId, 'DISCONTINUE',
            'Stopped: ' . ipd_order_line($o) . " — $reason ($cancelled future dose(s) cancelled)",
            (int) $o['admission_id']);

        $out['ok'] = true;
        $out['cancelled'] = $cancelled;
        return $out;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $out['error'] = [
            'not_found'  => 'Order not found.',
            'not_active' => 'That order is already stopped.',
        ][$e->getMessage()] ?? 'Could not stop the order.';
        return $out;
    }
}

/**
 * Mark a scheduled dose given / held / missed.
 *
 * Re-reads the parent order and re-applies can_administer_order() server-side:
 * the page may have been rendered before a doctor rejected or stopped the drug,
 * and a stale tab must never be able to record a dose against a dead order.
 */
function ipd_mark_slot(PDO $pdo, int $slotId, string $newStatus, string $note = ''): array {
    $out = ['ok' => false, 'error' => ''];

    if (!has_permission('IPD_ADMINISTER_MED')) {
        $out['error'] = 'You do not have permission to record medication administration.';
        return $out;
    }
    if (!in_array($newStatus, ['GIVEN','HELD','MISSED'], true)) {
        $out['error'] = 'Invalid administration status.';
        return $out;
    }
    // Holding or missing a dose is a clinical event that needs an explanation;
    // giving one does not.
    $note = mb_substr(trim($note), 0, 300);
    if ($newStatus !== 'GIVEN' && $note === '') {
        $out['error'] = 'Give a reason when a dose is held or missed.';
        return $out;
    }

    $uid = (int) ($_SESSION['user_id'] ?? 0);
    try {
        $pdo->beginTransaction();

        $st = $pdo->prepare('
            SELECT s.*, o.approval_status, o.status AS order_status, o.admission_id,
                   o.generic_name_snapshot, o.brand_name_snapshot, o.drug_name_snapshot,
                   o.dose_value, o.dose_unit, o.route, o.frequency
            FROM ipd_medication_admins s
            JOIN ipd_medication_orders o ON o.id = s.order_id
            WHERE s.id = ? FOR UPDATE
        ');
        $st->execute([$slotId]);
        $slot = $st->fetch();
        if (!$slot) { throw new RuntimeException('not_found'); }

        // The gate, re-checked at the moment of administration.
        if (!can_administer_order(['approval_status' => $slot['approval_status'], 'status' => $slot['order_status']])) {
            throw new RuntimeException('not_approved');
        }
        // A dose already actioned is not silently re-written — that would erase
        // who gave it and when.
        if ($slot['status'] !== 'PENDING') { throw new RuntimeException('already'); }

        $upd = $pdo->prepare('
            UPDATE ipd_medication_admins
            SET status = ?, given_by_id = ?, given_at = NOW(), notes = ?, hold_reason = ?
            WHERE id = ? AND status = \'PENDING\'
        ');
        $upd->execute([
            $newStatus, $uid,
            $note ?: null,
            $newStatus === 'GIVEN' ? null : $note,
            $slotId,
        ]);
        // Belt-and-braces against a double-tap that slipped past the row lock.
        if ($upd->rowCount() === 0) { throw new RuntimeException('already'); }

        $pdo->commit();

        ipd_med_audit($pdo, 'SLOT', $slotId, $newStatus,
            ipd_order_line($slot) . ' due ' . date('d/m/Y H:i', strtotime($slot['scheduled_datetime']))
            . ' -> ' . $newStatus . ($note ? " ($note)" : ''),
            (int) $slot['admission_id']);

        $out['ok'] = true;
        return $out;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $out['error'] = [
            'not_found'    => 'That dose was not found.',
            'not_approved' => 'This drug is not approved for administration — a doctor must sign it first.',
            'already'      => 'That dose has already been recorded.',
        ][$e->getMessage()] ?? 'Could not record the dose.';
        return $out;
    }
}

/**
 * Log a PRN dose on demand.
 *
 * PRN orders carry no pre-booked slots, so this inserts a GIVEN row at the
 * current time, after checking the 24-hour ceiling. The cap is re-counted inside
 * the transaction rather than trusted from the rendered page.
 */
function ipd_log_prn_dose(PDO $pdo, int $orderId, string $note = ''): array {
    $out = ['ok' => false, 'error' => ''];

    if (!has_permission('IPD_ADMINISTER_MED')) {
        $out['error'] = 'You do not have permission to record medication administration.';
        return $out;
    }
    $uid = (int) ($_SESSION['user_id'] ?? 0);

    try {
        $pdo->beginTransaction();

        $st = $pdo->prepare('SELECT * FROM ipd_medication_orders WHERE id = ? FOR UPDATE');
        $st->execute([$orderId]);
        $o = $st->fetch();
        if (!$o) { throw new RuntimeException('not_found'); }
        if ($o['order_type'] !== 'PRN') { throw new RuntimeException('not_prn'); }
        if (!can_administer_order($o)) { throw new RuntimeException('not_approved'); }

        $given = ipd_prn_given_24h($pdo, $orderId);
        if ($o['prn_max_per_24h'] !== null && $given >= (int) $o['prn_max_per_24h']) {
            throw new RuntimeException('cap');
        }

        $now = date('Y-m-d H:i:s');
        $pdo->prepare('
            INSERT INTO ipd_medication_admins
                (order_id, admission_id, scheduled_datetime, slot_kind, status, given_by_id, given_at, notes)
            VALUES (?, ?, ?, \'PRN\', \'GIVEN\', ?, ?, ?)
        ')->execute([
            $orderId, (int) $o['admission_id'], $now, $uid, $now,
            mb_substr(trim($note), 0, 300) ?: null,
        ]);
        $slotId = (int) $pdo->lastInsertId();

        $pdo->commit();

        ipd_med_audit($pdo, 'SLOT', $slotId, 'GIVE_PRN',
            'PRN dose: ' . ipd_order_line($o) . ' (' . ($given + 1) . ' of ' . (int) $o['prn_max_per_24h'] . ' in 24h)',
            (int) $o['admission_id']);

        $out['ok'] = true;
        return $out;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $out['error'] = [
            'not_found'    => 'Order not found.',
            'not_prn'      => 'That is not a PRN order.',
            'not_approved' => 'This drug is not approved for administration — a doctor must sign it first.',
            'cap'          => 'The 24-hour maximum for this PRN drug has already been given.',
        ][$e->getMessage()] ?? 'Could not record the PRN dose.';
        return $out;
    }
}

/**
 * Record a patient allergy. Uppercased to match the ALL-CAPS house style and
 * because the allergy matcher compares uppercase.
 */
function ipd_add_allergy(PDO $pdo, int $patientId, array $in): array {
    $out = ['ok' => false, 'error' => ''];

    if (!has_permission('IPD_MANAGE_ALLERGIES')) {
        $out['error'] = 'You do not have permission to record allergies.';
        return $out;
    }
    $substance = mb_strtoupper(trim((string) ($in['substance'] ?? '')), 'UTF-8');
    if ($substance === '') { $out['error'] = 'Enter the substance the patient reacts to.'; return $out; }

    $severity = strtoupper(trim((string) ($in['severity'] ?? 'UNKNOWN')));
    if (!in_array($severity, ['MILD','MODERATE','SEVERE','UNKNOWN'], true)) { $severity = 'UNKNOWN'; }

    $group = mb_strtoupper(trim((string) ($in['allergy_group'] ?? '')), 'UTF-8') ?: null;
    // Inherit the cross-reactivity group where we can — that is what upgrades a
    // bare name match into a real class-wide check. Three ways the typed
    // substance can resolve to a group, and all three are common in practice:
    //   "AMOXICILLIN" -> a generic name
    //   "AMOXIL"      -> a brand name
    //   "PENICILLIN"  -> the GROUP itself, which is how a clinician most often
    //                    documents it and which the earlier name-only lookup
    //                    missed, silently degrading the check to substring
    //                    matching for the most important case.
    if (!$group) {
        try {
            $g = $pdo->prepare('
                SELECT allergy_group FROM ipd_drug_formulary
                WHERE allergy_group IS NOT NULL AND (
                      UPPER(allergy_group) = :s
                   OR UPPER(generic_name)  = :s2
                   OR UPPER(brand_name)    = :s3
                )
                ORDER BY CASE WHEN UPPER(allergy_group) = :s4 THEN 0 ELSE 1 END
                LIMIT 1
            ');
            $g->execute([':s' => $substance, ':s2' => $substance, ':s3' => $substance, ':s4' => $substance]);
            $group = $g->fetchColumn() ?: null;
        } catch (Throwable $e) { $group = null; }
    }

    try {
        $pdo->prepare('
            INSERT INTO patient_allergies
                (patient_id, substance, allergy_group, reaction, severity, is_active, recorded_by_id, recorded_at)
            VALUES (?, ?, ?, ?, ?, 1, ?, NOW())
        ')->execute([
            $patientId, mb_substr($substance, 0, 150), $group,
            mb_substr(trim((string) ($in['reaction'] ?? '')), 0, 300) ?: null,
            $severity, (int) ($_SESSION['user_id'] ?? 0),
        ]);
        $id = (int) $pdo->lastInsertId();

        ipd_med_audit($pdo, 'ALLERGY', $id, 'CREATE',
            "Allergy recorded for patient #$patientId: $substance ($severity)");

        $out['ok'] = true;
        return $out;
    } catch (Throwable $e) {
        $out['error'] = 'Could not record the allergy.';
        return $out;
    }
}

/** Retire an allergy (soft — the history explains any past override). */
function ipd_retire_allergy(PDO $pdo, int $allergyId, string $reason): array {
    $out = ['ok' => false, 'error' => ''];

    if (!has_permission('IPD_MANAGE_ALLERGIES')) {
        $out['error'] = 'You do not have permission to change allergies.';
        return $out;
    }
    $reason = mb_substr(trim($reason), 0, 300);
    if ($reason === '') { $out['error'] = 'Give a reason for retiring this allergy.'; return $out; }

    try {
        $pdo->prepare('
            UPDATE patient_allergies
            SET is_active = 0, deactivated_by_id = ?, deactivated_at = NOW(), deactivated_reason = ?
            WHERE id = ? AND is_active = 1
        ')->execute([(int) ($_SESSION['user_id'] ?? 0), $reason, $allergyId]);

        ipd_med_audit($pdo, 'ALLERGY', $allergyId, 'RETIRE', "Allergy retired — $reason");
        $out['ok'] = true;
        return $out;
    } catch (Throwable $e) {
        $out['error'] = 'Could not retire the allergy.';
        return $out;
    }
}
