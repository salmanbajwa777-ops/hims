<?php
/**
 * OPD consultation notes — the doctor's own private clinical record.
 *
 * PRIVACY IS THE ENTIRE CONTRACT OF THIS FILE.
 * A note belongs to the doctor who wrote it and to nobody else — not reception,
 * not other doctors, not the admin/owner. Every function here takes the reading
 * doctor's id as a REQUIRED argument and filters `doctor_id = ?` in SQL. There
 * is deliberately no "view any note" permission key and no unfiltered fetch, so
 * a later permission grant cannot widen access by accident: to read someone
 * else's notes you would have to add a new query, which is a visible change.
 *
 * Callers must pass the EFFECTIVE user id ($_SESSION['user_id']). Under "View as
 * staff" that is already the impersonated target, which is what we want — the
 * admin sees what that doctor sees, and imp_audit_suffix() records who really
 * acted. Never pass imp_admin_id here.
 *
 * This is UI-level confidentiality, not encryption — anyone with direct DB or
 * backup access can still read the table. Stated plainly so this is never
 * mistaken for a sealed record.
 *
 * MUTABILITY: unlike IPD daily-round notes (frozen once written), these stay
 * editable by their author indefinitely. That is the chosen behaviour for OPD.
 * updated_at moves on every save and every save is audit-logged, so revisions
 * remain traceable even though previous text is not retained.
 */

/**
 * The note for one visit, but ONLY if $doctorId wrote it.
 *
 * Returns null both when no note exists and when one exists under another
 * doctor — the caller cannot tell those apart, which is intentional: "you have
 * no note here" and "someone else's note is here" must look identical.
 */
function consultation_note_for_visit(PDO $pdo, int $visitId, int $doctorId): ?array
{
    $st = $pdo->prepare('
        SELECT id, visit_id, note, created_at, updated_at
        FROM consultation_notes
        WHERE visit_id = ? AND doctor_id = ?
    ');
    $st->execute([$visitId, $doctorId]);
    return $st->fetch() ?: null;
}

/**
 * This doctor's note history for one patient, newest visit first.
 *
 * Joined to visits so each note carries the date and consultation type it was
 * written at — that pairing IS the history view ("what did I see them for, and
 * what did I write"). Visits with no note are excluded; an empty consultation
 * has nothing to show.
 *
 * Ordered by visit_date (the clinical date), not created_at, so a note written
 * up late still sits against the day the patient was actually seen.
 */
function consultation_note_history(PDO $pdo, int $patientId, int $doctorId, int $limit = 50): array
{
    // LIMIT is interpolated, not bound: with PDO's emulated prepares off, a
    // bound LIMIT parameter binds as a string and MySQL rejects it. Cast to int
    // above makes interpolation safe.
    $limit = max(1, min(200, $limit));
    $st = $pdo->prepare("
        SELECT n.id, n.visit_id, n.note, n.created_at, n.updated_at,
               v.visit_date, v.token_no, v.consult_status,
               t.label AS type_label
        FROM consultation_notes n
        JOIN visits v ON v.id = n.visit_id
        LEFT JOIN doctor_consult_types t ON t.id = v.doctor_consult_type_id
        WHERE n.patient_id = ? AND n.doctor_id = ?
        ORDER BY v.visit_date DESC, v.token_no DESC
        LIMIT $limit
    ");
    $st->execute([$patientId, $doctorId]);
    return $st->fetchAll();
}

/**
 * Which of these visits already have a note by this doctor.
 *
 * The queue renders many rows and each needs to know whether to say "Add note"
 * or "Edit note". Doing that with one query per row is the N+1 that this page
 * would otherwise ship; this returns a visit_id => true map in a single round
 * trip.
 *
 * Returns [] for an empty input rather than building `IN ()`, which is a syntax
 * error in MySQL.
 */
function consultation_notes_by_visit(PDO $pdo, array $visitIds, int $doctorId): array
{
    $ids = array_values(array_unique(array_map('intval', $visitIds)));
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("
        SELECT visit_id
        FROM consultation_notes
        WHERE doctor_id = ? AND visit_id IN ($in)
    ");
    $st->execute(array_merge([$doctorId], $ids));

    $map = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN, 0) as $vid) {
        $map[(int) $vid] = true;
    }
    return $map;
}

/**
 * Create or revise this doctor's note for a visit.
 *
 * Ownership is enforced by the INSERT itself: patient_id and doctor_id are
 * taken from a SELECT that requires the visit to belong to $doctorId, so a
 * forged visit_id from another doctor's queue matches nothing and the write is
 * refused. We never trust a patient_id posted by the client.
 *
 * The upsert only ever touches a row whose doctor_id already equals this doctor
 * — visit_id is UNIQUE, so a collision can only be this doctor's own earlier
 * note (another doctor's visit was already rejected above).
 *
 * Returns true when something was written, false when the visit is not this
 * doctor's or the note is blank.
 */
function consultation_note_save(PDO $pdo, int $visitId, int $doctorId, string $note): bool
{
    $note = trim($note);
    if ($note === '') {
        return false;
    }

    // Ownership + patient resolution in one step. A visit that is not this
    // doctor's returns no row and the save stops here.
    $own = $pdo->prepare('SELECT patient_id FROM visits WHERE id = ? AND doctor_id = ?');
    $own->execute([$visitId, $doctorId]);
    $patientId = $own->fetchColumn();
    if ($patientId === false) {
        return false;
    }

    $st = $pdo->prepare('
        INSERT INTO consultation_notes (visit_id, doctor_id, patient_id, note)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE note = VALUES(note), updated_at = CURRENT_TIMESTAMP
    ');
    $st->execute([$visitId, $doctorId, (int) $patientId, $note]);

    // Log that a note was saved — never the note text. The audit trail is who
    // and when; the content stays in the one table that enforces the privacy
    // filter. Character count gives a revision a shape without leaking it.
    audit_log(
        $pdo,
        'consultation_note_saved',
        "Visit #$visitId — consultation note saved (" . mb_strlen($note) . " chars)"
            . (function_exists('imp_audit_suffix') ? imp_audit_suffix() : ''),
        $doctorId
    );

    return true;
}
