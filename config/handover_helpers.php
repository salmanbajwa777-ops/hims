<?php
/**
 * Reception hand-off of OPEN short-stay admissions (Phase 4).
 *
 * RULE (owner decision): every open short-stay admission that has no finalised
 * bill is owned by exactly one receptionist. At shift close that receptionist
 * must BILL it or PASS it on with a note. The close is blocked until they have.
 *
 * OWNERSHIP (one rule, evaluated per admission, newest fact wins):
 *   1. A handover row exists for the admission  -> look at the NEWEST one only:
 *        ACCEPTED -> accepted_by_id owns it.
 *        PASSED   -> in transit: NOBODY owns it for close purposes (the sender is
 *                    released, the receiver has not yet taken it). It stays on
 *                    the receiver's "Passed to you" list and, if nobody accepts,
 *                    on the manager's un-accepted list.
 *   2. No handover row, admitted by STAFF (reception)  -> admitted_by_id.
 *   3. No handover row, admitted by doctor/admin/manager -> visits.created_by_id,
 *        i.e. the receptionist who registered the visit and issued the token.
 *        (Chosen because it is the one reception fact that always exists and
 *        needs no extra click; flagged to the owner in the report.)
 *
 * OPEN = status PENDING_ASSIGNMENT, ACTIVE or DISCHARGE_IN_PROGRESS, or
 *        DISCHARGED with discharge_finalized_at still NULL (billing unfinished),
 *        AND admitted at/after HO_GATE_START, AND with no non-draft bill row yet
 *        (a finalised, even short-paid, bill = receptionist done; see ho_billed_sql).
 *
 * Everything here tolerates the migration (sql/add_admission_reception_handovers.sql)
 * not being run: while the table is missing, every "open for user" list is empty,
 * so the close-block is OFF and nothing fatals.
 */

require_once __DIR__ . '/impersonation.php';   // imp_csrf_*: used by the callers' forms

/** Cached information_schema probe for a table (sqlite_master under the test harness). */
function ho_table_exists(PDO $pdo, string $table, bool $reset = false): bool {
    static $cache = [];
    if ($reset) { $cache = []; return false; }
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    try {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
        } else {
            $stmt = $pdo->prepare('SELECT 1 FROM information_schema.tables
                                   WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1');
        }
        $stmt->execute([$table]);
        return $cache[$table] = (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return $cache[$table] = false;
    }
}

/** True once the migration has been applied. False = the whole feature is OFF. */
function ho_ready(PDO $pdo): bool {
    return ho_table_exists($pdo, 'admission_reception_handovers')
        && ho_table_exists($pdo, 'admissions');
}

function ho_lock_sql(PDO $pdo): string {
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
}

/**
 * GATE START (owner-tunable). Only admissions admitted AT OR AFTER this moment count
 * as "open" for the close gate, the pass/accept lists and the orphan card. Stays
 * admitted before it are legacy (abandoned/test) rows from before this feature and
 * must not block anyone's first close; clean them up with
 * tools/diag_open_admissions_preflight.php. Override without a deploy by inserting a
 * clinic_settings row (setting_key 'ho_gate_start', value 'YYYY-MM-DD HH:MM:SS').
 */
const HO_GATE_START = '2026-10-18 00:00:00';

function ho_gate_start(?PDO $pdo = null): string {
    if ($pdo !== null && ho_table_exists($pdo, 'clinic_settings')) {
        try {
            $s = $pdo->prepare("SELECT setting_value FROM clinic_settings WHERE setting_key = 'ho_gate_start'");
            $s->execute();
            $v = $s->fetchColumn();
            if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', trim($v))) {
                return trim($v);
            }
        } catch (Throwable $e) { /* fall through to the constant */ }
    }
    return HO_GATE_START;
}

/**
 * SQL predicate: this admission (alias a) already has a bill row that is no longer a
 * draft (finalised/paid). A short-paid finalised bill deliberately leaves the stay
 * DISCHARGE_IN_PROGRESS until an admin approves the write-off; the receptionist has
 * done their part, so it is RESOLVED for the gate. Surfaced instead by the
 * short-payment / pending-approval cards on the exceptions page.
 */
function ho_billed_sql(PDO $pdo): string {
    if (!ho_table_exists($pdo, 'admission_bills')) { return '(1 = 0)'; }
    return "EXISTS (SELECT 1 FROM admission_bills hb WHERE hb.admission_id = a.id AND hb.status <> 'draft')";
}

/** Shared FROM/WHERE: one row per OPEN admission with its resolved owner id. */
function ho_open_owned_sql(?PDO $pdo = null): string {
    $billed = $pdo ? ' AND NOT ' . ho_billed_sql($pdo) : '';
    $start = "'" . ho_gate_start($pdo) . "'";
    return "
        SELECT a.id AS admission_id, a.status, a.admitted_at, a.admission_type,
               a.admitted_by_id, a.admitted_by_role, a.discharge_finalized_at,
               p.name AS patient_name, p.mrn,
               CASE
                   WHEN lh.id IS NOT NULL THEN
                       CASE WHEN lh.status = 'ACCEPTED' THEN lh.accepted_by_id ELSE NULL END
                   WHEN a.admitted_by_role = 'STAFF' THEN a.admitted_by_id
                   ELSE v.created_by_id
               END AS owner_id,
               lh.id AS last_handover_id, lh.status AS last_handover_status
        FROM admissions a
        JOIN visits v ON v.id = a.visit_id
        JOIN patients p ON p.id = v.patient_id
        LEFT JOIN admission_reception_handovers lh
               ON lh.id = (SELECT MAX(h2.id) FROM admission_reception_handovers h2
                           WHERE h2.admission_id = a.id)
        WHERE (a.status IN ('PENDING_ASSIGNMENT','ACTIVE','DISCHARGE_IN_PROGRESS')
               OR (a.status = 'DISCHARGED' AND a.discharge_finalized_at IS NULL))
          AND a.admitted_at >= $start$billed
    ";
}

/** Open admissions this receptionist owns (their close obligation). [] pre-migration. */
function open_admissions_for_user(PDO $pdo, int $uid): array {
    if (!ho_ready($pdo) || $uid <= 0) {
        return [];
    }
    $stmt = $pdo->prepare('SELECT * FROM (' . ho_open_owned_sql($pdo) . ') o WHERE o.owner_id = ? ORDER BY o.admitted_at');
    $stmt->bindValue(1, $uid, PDO::PARAM_INT);   // typed: a CASE expression has no column affinity under sqlite
    $stmt->execute();
    return $stmt->fetchAll();
}

/** Passes waiting for THIS user to accept (addressed to them, or to anyone). */
function handovers_passed_to_user(PDO $pdo, int $uid): array {
    if (!ho_ready($pdo) || $uid <= 0) {
        return [];
    }
    $stmt = $pdo->prepare("
        SELECT h.*, fu.name AS from_name, p.name AS patient_name, p.mrn, a.status AS admission_status
        FROM admission_reception_handovers h
        JOIN admissions a ON a.id = h.admission_id
        JOIN visits v ON v.id = a.visit_id
        JOIN patients p ON p.id = v.patient_id
        JOIN users fu ON fu.id = h.from_user_id
        WHERE h.status = 'PASSED'
          AND (h.to_user_id = ? OR h.to_user_id IS NULL)
          AND h.from_user_id <> ?
          AND h.id = (SELECT MAX(h2.id) FROM admission_reception_handovers h2 WHERE h2.admission_id = h.admission_id)
        ORDER BY h.passed_at
    ");
    $stmt->execute([$uid, $uid]);
    return $stmt->fetchAll();
}

/**
 * Manager view: every pass nobody has accepted. 'stale' = older than $staleHours
 * (default 12h ~ "the next shift has started and still nobody took it").
 * Pass $onlyStale = true for the exceptions page / daily summary. [] pre-migration.
 */
function pending_handovers_unaccepted(PDO $pdo, bool $onlyStale = false, int $staleHours = 12): array {
    if (!ho_ready($pdo)) {
        return [];
    }
    $rows = $pdo->query("
        SELECT h.id, h.admission_id, h.from_user_id, h.to_user_id, h.note, h.passed_at,
               fu.name AS from_name, tu.name AS to_name,
               p.name AS patient_name, p.mrn, a.status AS admission_status
        FROM admission_reception_handovers h
        JOIN admissions a ON a.id = h.admission_id
        JOIN visits v ON v.id = a.visit_id
        JOIN patients p ON p.id = v.patient_id
        JOIN users fu ON fu.id = h.from_user_id
        LEFT JOIN users tu ON tu.id = h.to_user_id
        WHERE h.status = 'PASSED'
          AND h.id = (SELECT MAX(h2.id) FROM admission_reception_handovers h2 WHERE h2.admission_id = h.admission_id)
        ORDER BY h.passed_at
    ")->fetchAll();
    $cut = time() - $staleHours * 3600;
    $out = [];
    foreach ($rows as $r) {
        $ts = strtotime((string) $r['passed_at']);
        $r['age_hours'] = $ts ? (int) floor((time() - $ts) / 3600) : 0;
        $r['stale'] = $ts !== false && $ts < $cut;
        if (!$onlyStale || $r['stale']) {
            $out[] = $r;
        }
    }
    return $out;
}

/**
 * Open admissions whose resolved owner holds no cash drawer (or is inactive), so
 * nobody will ever see them at a close. For the manager; [] pre-migration.
 * $drawerIds = ids of drawer holders (receptionists_with_drawer() ids).
 */
function orphan_open_admissions(PDO $pdo, array $drawerIds): array {
    if (!ho_ready($pdo)) {
        return [];
    }
    $drawer = array_flip(array_map('intval', $drawerIds));
    $out = [];
    foreach ($pdo->query('SELECT * FROM (' . ho_open_owned_sql($pdo) . ') o WHERE o.owner_id IS NOT NULL')->fetchAll() as $r) {
        if (!isset($drawer[(int) $r['owner_id']])) {
            $out[] = $r;
        }
    }
    return $out;
}

/**
 * The close gate. 'blocked' is true only when the feature is live AND the user
 * still owns unresolved admissions. Pre-migration: never blocked.
 */
function close_block_state(PDO $pdo, int $uid): array {
    $open = open_admissions_for_user($pdo, $uid);
    return ['blocked' => (bool) $open, 'open' => $open, 'passed_to_me' => handovers_passed_to_user($pdo, $uid)];
}

/**
 * The close decision used by shift_closing.php (kept here so it is testable).
 * @return array{error:string,override:bool,open:array}  error '' = may close;
 *         override true = admin forced past $open (caller must audit_log it, in
 *         the SAME transaction as the closing insert).
 */
function close_gate_decision(PDO $pdo, int $uid, bool $isAdmin, bool $overrideTicked, string $reason): array {
    $gate = ['open' => open_admissions_for_user($pdo, $uid)];
    $n = count($gate['open']);
    if (!$n) {
        return ['error' => '', 'override' => false, 'open' => []];
    }
    if (!($isAdmin && $overrideTicked)) {
        return ['error' => $n . ' open admission' . ($n === 1 ? '' : 's')
                . ' must be billed or passed to the next receptionist before you can close.',
                'override' => false, 'open' => $gate['open']];
    }
    if (strlen(trim($reason)) < 8) {
        return ['error' => 'An admin override needs a reason (at least 8 characters).',
                'override' => false, 'open' => $gate['open']];
    }
    return ['error' => '', 'override' => true, 'open' => $gate['open']];
}

/**
 * Pass one open admission on. Only its CURRENT owner may pass it. One transaction,
 * row-locked, so a double click (or two tabs) creates exactly one pass.
 * @return array{ok:bool,msg:string}
 */
function pass_admission(PDO $pdo, int $admissionId, int $fromUid, ?int $toUid, string $note): array {
    if (!ho_ready($pdo)) {
        return ['ok' => false, 'msg' => 'Hand-off is not enabled yet (migration pending).'];
    }
    $note = trim($note);
    if ($note === '') {
        return ['ok' => false, 'msg' => 'Write a short note for the next receptionist.'];
    }
    if (function_exists('mb_substr')) { $note = mb_substr($note, 0, 500); } else { $note = substr($note, 0, 500); }
    if ($toUid !== null && $toUid <= 0) { $toUid = null; }
    if ($toUid !== null && $toUid === $fromUid) {
        return ['ok' => false, 'msg' => 'You cannot pass an admission to yourself.'];
    }
    if ($toUid !== null && function_exists('user_holds_drawer') && !user_holds_drawer($pdo, $toUid)) {
        return ['ok' => false, 'msg' => 'That user is not a receptionist on a cash drawer.'];
    }
    $own = $pdo->inTransaction();
    try {
        if (!$own) { $pdo->beginTransaction(); }
        // Serialise concurrent passes of the same admission.
        $pdo->prepare('SELECT id FROM admissions WHERE id = ?' . ho_lock_sql($pdo))->execute([$admissionId]);
        $stmt = $pdo->prepare('SELECT * FROM (' . ho_open_owned_sql($pdo) . ') o WHERE o.admission_id = ?');
        $stmt->execute([$admissionId]);
        $row = $stmt->fetch();
        if (!$row) {
            if (!$own) { $pdo->rollBack(); }
            return ['ok' => false, 'msg' => 'That admission is not open (already billed?).'];
        }
        if ((int) $row['owner_id'] !== $fromUid) {
            if (!$own) { $pdo->rollBack(); }
            return ['ok' => false, 'msg' => 'That admission is not yours to pass (already passed, or owned by someone else).'];
        }
        $pdo->prepare("INSERT INTO admission_reception_handovers
                (admission_id, from_user_id, to_user_id, note, passed_at, status)
                VALUES (?, ?, ?, ?, ?, 'PASSED')")
            ->execute([$admissionId, $fromUid, $toUid, $note, date('Y-m-d H:i:s')]);
        audit_log($pdo, 'admission_passed',
            "Admission #$admissionId (" . $row['patient_name'] . ") passed on at close"
            . ($toUid ? " to user #$toUid" : ' to the next receptionist') . ': ' . $note, $fromUid);
        if (!$own) { $pdo->commit(); }
        return ['ok' => true, 'msg' => 'Passed on — the next receptionist must accept it.'];
    } catch (Throwable $e) {
        if (!$own && $pdo->inTransaction()) { $pdo->rollBack(); }
        return ['ok' => false, 'msg' => 'Could not pass it on. Please try again.'];
    }
}

/**
 * Receiver accepts a pass: they now OWN the admission (and its close obligation).
 * The UPDATE is guarded on status='PASSED', so a double click accepts once.
 * @return array{ok:bool,msg:string}
 */
function accept_handover(PDO $pdo, int $handoverId, int $uid): array {
    if (!ho_ready($pdo)) {
        return ['ok' => false, 'msg' => 'Hand-off is not enabled yet (migration pending).'];
    }
    $own = $pdo->inTransaction();
    try {
        if (!$own) { $pdo->beginTransaction(); }
        $stmt = $pdo->prepare('SELECT * FROM admission_reception_handovers WHERE id = ?' . ho_lock_sql($pdo));
        $stmt->execute([$handoverId]);
        $h = $stmt->fetch();
        $why = '';
        if (!$h || $h['status'] !== 'PASSED') {
            $why = 'That hand-off was already accepted.';
        } elseif ((int) $h['from_user_id'] === $uid) {
            $why = 'You cannot accept your own pass.';
        } elseif ($h['to_user_id'] !== null && (int) $h['to_user_id'] !== $uid) {
            $why = 'That hand-off is addressed to someone else.';
        }
        if ($why === '' && function_exists('user_holds_drawer') && !user_holds_drawer($pdo, $uid)) {
            $why = 'Only a receptionist on a cash drawer can accept.';
        }
        if ($why !== '') {
            if (!$own) { $pdo->rollBack(); }
            return ['ok' => false, 'msg' => $why];
        }
        $upd = $pdo->prepare("UPDATE admission_reception_handovers
                SET status = 'ACCEPTED', accepted_by_id = ?, accepted_at = ?, to_user_id = COALESCE(to_user_id, ?)
                WHERE id = ? AND status = 'PASSED'");
        $upd->execute([$uid, date('Y-m-d H:i:s'), $uid, $handoverId]);
        if ($upd->rowCount() !== 1) {
            if (!$own) { $pdo->rollBack(); }
            return ['ok' => false, 'msg' => 'That hand-off was already accepted.'];
        }
        audit_log($pdo, 'admission_handover_accepted',
            'Admission #' . (int) $h['admission_id'] . " hand-off #$handoverId accepted", $uid);
        if (!$own) { $pdo->commit(); }
        return ['ok' => true, 'msg' => 'Accepted — it is now yours and counts at your close.'];
    } catch (Throwable $e) {
        if (!$own && $pdo->inTransaction()) { $pdo->rollBack(); }
        return ['ok' => false, 'msg' => 'Could not accept it. Please try again.'];
    }
}

/**
 * Shared POST dispatcher for admissions.php and shift_closing.php.
 * Returns null when the request is not a hand-off action, else [ok,msg].
 */
function ho_handle_post(PDO $pdo, int $uid): ?array {
    $action = $_POST['action'] ?? '';
    if (!in_array($action, ['pass_admission', 'accept_handover'], true)) {
        return null;
    }
    if (!imp_csrf_valid($_POST['imp_csrf'] ?? null)) {
        return ['ok' => false, 'msg' => 'Your session form expired — reload the page and try again.'];
    }
    if (function_exists('imp_block_money_action')) {
        $blocked = imp_block_money_action('Passing or accepting an admission');
        if ($blocked !== '') { return ['ok' => false, 'msg' => $blocked]; }
    }
    if ($action === 'pass_admission') {
        return pass_admission($pdo, (int) ($_POST['admission_id'] ?? 0), $uid,
            ((int) ($_POST['to_user_id'] ?? 0)) ?: null, (string) ($_POST['note'] ?? ''));
    }
    return accept_handover($pdo, (int) ($_POST['handover_id'] ?? 0), $uid);
}
