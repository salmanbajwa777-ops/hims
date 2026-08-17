<?php
// Coded queue tokens — "SB-1", "HS-3".
//
// A token is a per-doctor, per-SESSION running number shown with the doctor's initials.
// Session 1 is the doctor's first sitting of the day, session 2 the second (evening
// clinic); each restarts at 1, so two patients can both hold "SB-1" on one date.
//
// The session is NOT displayed — staff read a restart as the next session beginning.
// It is stored on the visit because it still drives the counter reset and the queue
// ordering, but it never reaches a screen, a slip, or an email.
//
// All four places that create a visit (registration, follow-up, ER admit, IPD admit)
// issue tokens through issue_token() so the session logic and the race-safe counter
// upsert live in exactly one place.
//
// Requires sql/add_token_codes.sql.

/**
 * Which sitting of the day a moment falls in, from the doctor's confirmed day sheet.
 *
 * Session 2 only exists when reception has filled the second window on
 * doctor_day_timings for that date. The boundary rule is deliberately generous: a
 * patient registered at 16:30 for a 17:00 evening clinic is queued into the evening,
 * not stranded at the tail of the morning run. So the split point is the MIDPOINT of
 * the gap between the two windows — before it you're still in session 1, after it
 * you're in session 2. That keeps early arrivals and late-running mornings on the
 * sensible side of the line without reception having to intervene.
 *
 * Falls back to session 1 whenever there's no second window, no day sheet at all, or
 * the times are unusable — a missing schedule must never block a registration.
 */
function token_session_for(PDO $pdo, int $doctorId, ?string $atTime = null): int
{
    $now = $atTime ?: date('H:i:s');

    try {
        $stmt = $pdo->prepare('
            SELECT end_time, start_time_2
            FROM doctor_day_timings
            WHERE doctor_id = ? AND timing_date = CURDATE()
        ');
        $stmt->execute([$doctorId]);
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        // Table or the session-2 columns not present yet — single session.
        return 1;
    }

    // NO ROW FOR TODAY — fall back to the doctor's weekly template.
    //
    // This is the bug that made the restart fail in practice. doctor_day_timings
    // only gets a row when a human opens Doctor Timings and saves, or when
    // cron/materialize_doctor_timings.php runs — and that cron has to be
    // registered on the host, which is easy to miss. On any day nobody saved the
    // timings, this function found no row, returned 1 all day, and the evening
    // tokens carried on from the morning even though the schema was perfectly
    // correct and the doctor's template clearly stated two sittings.
    //
    // The weekly template is the same data the cron would have copied, so
    // reading it directly makes the restart work whether or not anything
    // materialized today's row. Reception overriding today's hours still wins,
    // because that path only runs when a real row is absent.
    if (!$row) {
        try {
            $wk = $pdo->prepare('
                SELECT end_time, start_time_2
                FROM doctor_weekly_schedule
                WHERE doctor_id = ? AND weekday = ? AND is_off = 0
            ');
            // ISO weekday, 1 = Monday, matching the column's stated convention
            // and date(\'N\') as used by the materialize cron.
            $wk->execute([$doctorId, (int) date('N')]);
            $row = $wk->fetch();
        } catch (PDOException $e) {
            return 1;   // template table absent — single session
        }
    }

    if (!$row || empty($row['start_time_2'])) {
        return 1;
    }

    $start2 = strtotime($row['start_time_2']);
    $end1 = !empty($row['end_time']) ? strtotime($row['end_time']) : null;
    $nowTs = strtotime($now);
    if ($start2 === false || $nowTs === false) {
        return 1;
    }

    // Midpoint of the gap between session 1 ending and session 2 starting. With no
    // stated session-1 end (or an end after session 2 starts, i.e. overlapping or
    // mis-entered windows), fall back to session 2's start as the boundary.
    $boundary = ($end1 !== null && $end1 < $start2) ? (int) (($end1 + $start2) / 2) : $start2;

    return $nowTs >= $boundary ? 2 : 1;
}

/**
 * Issue the next token for a doctor's current session.
 *
 * Returns ['no' => 3, 'session' => 2] — the running number and the session it belongs
 * to. Both go on the visit row; the display code is rebuilt from them by token_code().
 *
 * Race-safe: the counter is bumped with INSERT … ON DUPLICATE KEY UPDATE using
 * LAST_INSERT_ID(), which MySQL serializes through row locking. A fresh row inserts
 * next_token = 2 directly and never calls LAST_INSERT_ID(), so lastInsertId() reports 0
 * (or a stale value) on that branch — rowCount() is what distinguishes them reliably:
 * MySQL reports 1 affected row for a plain INSERT and 2 for the ODKU update path.
 * Must be called inside the caller's transaction.
 */
function issue_token(PDO $pdo, int $doctorId, ?int $session = null): array
{
    $session = $session ?? token_session_for($pdo, $doctorId);

    try {
        $stmt = $pdo->prepare('
            INSERT INTO visit_queue_counters (doctor_id, visit_date, token_session, next_token)
            VALUES (?, CURDATE(), ?, 2)
            ON DUPLICATE KEY UPDATE next_token = LAST_INSERT_ID(next_token) + 1
        ');
        $stmt->execute([$doctorId, $session]);
    } catch (PDOException $e) {
        // add_token_codes.sql not applied yet: fall back to the pre-migration
        // (doctor, date) counter so registration keeps working on old schema. Deploying
        // code ahead of the migration must never take the front desk down.
        $stmt = $pdo->prepare('
            INSERT INTO visit_queue_counters (doctor_id, visit_date, next_token)
            VALUES (?, CURDATE(), 2)
            ON DUPLICATE KEY UPDATE next_token = LAST_INSERT_ID(next_token) + 1
        ');
        $stmt->execute([$doctorId]);
        $session = 1;
    }

    // rowCount() 1 = fresh counter row, this session's first token.
    $no = $stmt->rowCount() === 1 ? 1 : (int) $pdo->lastInsertId();

    return ['no' => $no > 0 ? $no : 1, 'session' => $session];
}

/**
 * The doctor's token prefix: their stored one, else initials derived from the name.
 *
 * The fallback keeps tokens readable for a doctor added before the migration ran or
 * saved without a prefix; admin can always pin an explicit one on staff.php when two
 * doctors would otherwise share initials.
 */
function doctor_token_prefix(?string $storedPrefix, ?string $doctorName): string
{
    $stored = trim((string) $storedPrefix);
    if ($stored !== '') {
        return strtoupper($stored);
    }
    return derive_token_prefix((string) $doctorName);
}

/**
 * Initials from a name: first letter of the FIRST and LAST name parts.
 *
 *   SALMAN BAJWA      -> SB
 *   HIJAB SHAHEEN     -> HS
 *   RIAZ KHAN         -> RK
 *   BASHIR UR REHMAN  -> BR
 *
 * First-and-last, not first-two-words. "BASHIR UR REHMAN" must give BR: "ur" is a
 * connector particle in the surname, not a middle name, so first-two-words would give
 * the wrong BU. Same reasoning covers "ud din", "bin", "al" and the like — they are
 * skipped outright so a three-part name still initialises on its real surname.
 *
 * Honorifics are dropped first, and that is the common case here, not an edge one:
 * doctors are stored WITH the title ("DR SALMAN A BAJWA"), so keeping it would make
 * every prefix start with D and collide. Punctuation is normalised to whitespace
 * before splitting, so "DR." and a trailing "RAHMAN." both behave.
 *
 * Anything this gets wrong is fixable: admin sets an explicit prefix on staff.php and
 * the stored value always wins over this fallback.
 */
function derive_token_prefix(string $name): string
{
    $clean = preg_replace('/[^A-Za-z ]+/', ' ', $name);
    $words = preg_split('/\s+/', trim((string) $clean), -1, PREG_SPLIT_NO_EMPTY);

    // Strip leading titles, but never strip away the whole name — a doctor genuinely
    // called "Dr" with no other name still needs a prefix.
    $titles = ['DR', 'DOCTOR', 'PROF', 'PROFESSOR', 'MR', 'MRS', 'MS', 'MISS'];
    while (count($words) > 1 && in_array(strtoupper($words[0]), $titles, true)) {
        array_shift($words);
    }

    // Connector particles carry no initial of their own. Dropped only while other
    // words survive, so a name made entirely of them still yields something.
    $particles = ['UR', 'UD', 'UL', 'AL', 'BIN', 'BINT', 'IBN', 'DIN', 'E', 'VON', 'VAN', 'DE', 'DA', 'DEL'];
    $core = array_values(array_filter($words, fn($w) => !in_array(strtoupper($w), $particles, true)));
    if ($core) {
        $words = $core;
    }

    if (!$words) {
        return 'DR';
    }
    if (count($words) === 1) {
        return strtoupper(substr(str_pad($words[0], 2, 'X'), 0, 2));
    }
    // First + LAST, so a middle name never displaces the surname.
    return strtoupper(substr($words[0], 0, 1) . substr($words[count($words) - 1], 0, 1));
}

/**
 * The token as shown to humans: "SB-1".
 *
 * Session is deliberately NOT shown anywhere. The number restarts at 1 for each of the
 * doctor's sittings, so "SB-1" can occur twice in a day — staff read the restart as
 * the start of the next session, and a session caption was judged noise. token_session
 * still exists on the row: it drives the counter reset and the queue ordering.
 */
function token_code(?string $prefix, ?string $doctorName, $tokenNo): string
{
    return doctor_token_prefix($prefix, $doctorName) . '-' . (int) $tokenNo;
}

/**
 * Clean an admin-typed prefix for storage, falling back to the name's initials.
 *
 * Letters and digits only (so "SB2" is a valid tie-breaker), uppercased, capped at the
 * column's 6 chars. A blank field means "derive it for me" rather than "no prefix" —
 * that way admin only types one when the automatic initials actually collide.
 */
function normalize_token_prefix(?string $typed, string $doctorName): string
{
    $clean = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', (string) $typed));
    if ($clean === '') {
        return derive_token_prefix($doctorName);
    }
    return substr($clean, 0, 6);
}
