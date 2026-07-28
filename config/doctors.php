<?php
/**
 * Shared "who is this patient's doctor" helpers.
 *
 * Naming an admitting doctor is MANDATORY at admit (both routes) and on a
 * walk-in ER service bill: the money and the clinical record both hang off it,
 * and a stay showing "—" for doctor is unauditable after the fact.
 *
 * To keep that from being friction at the counter, the pickers default to the
 * doctor who last saw the patient — which is nearly always the doctor standing
 * there. Reception can override it; they just cannot leave it blank.
 */

/**
 * The doctor from this patient's most recent visit, or null.
 *
 * Deliberately ANY status, not just DONE consults: the likely admitter is the
 * doctor seeing the patient right now, and a today visit is usually still
 * WAITING/IN_CONSULT at the moment reception opens the admit dialog. Ordered by
 * id (not visit_date) so same-day visits resolve to the genuinely latest one.
 *
 * Only returns a doctor who is still an active system user — defaulting the
 * picker to a deactivated doctor would put an unselectable value in the box.
 */
function last_seen_doctor_id(PDO $pdo, int $patientId): ?int {
    if ($patientId <= 0) {
        return null;
    }
    try {
        $st = $pdo->prepare("
            SELECT v.doctor_id
            FROM visits v
            JOIN users u ON u.id = v.doctor_id AND u.is_active = 1 AND u.base_role = 'DOCTOR'
            WHERE v.patient_id = ?
            ORDER BY v.id DESC
            LIMIT 1
        ");
        $st->execute([$patientId]);
        $id = (int) ($st->fetchColumn() ?: 0);
        return $id > 0 ? $id : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Resolve a submitted doctor pick into [$doctorId, $manualName].
 *
 * The pickers offer system doctors plus an explicit "Other" option; choosing
 * Other reveals a free-text box for visiting/locum doctors who are not system
 * users. Exactly one of the pair comes back non-null, so callers store the id
 * when it is a real user and fall back to the typed name otherwise — matching
 * the existing admitting_doctor_id / admitting_doctor_manual column pair that
 * slips, notifications and the Google Sheet already read via COALESCE.
 *
 * Returns [null, null] when neither was supplied, which callers treat as the
 * "doctor is required" error. A submitted id is verified against users so a
 * hand-crafted POST cannot attach an arbitrary user as the doctor.
 */
function resolve_doctor_pick(PDO $pdo, $postedId, $postedManual): array {
    $id = (int) ($postedId ?? 0);
    $manual = mb_strtoupper(trim((string) ($postedManual ?? '')), 'UTF-8');
    $manual = $manual === '' ? null : mb_substr($manual, 0, 255);

    if ($id > 0) {
        $ok = $pdo->prepare("SELECT 1 FROM users WHERE id = ? AND base_role = 'DOCTOR'");
        $ok->execute([$id]);
        if ($ok->fetchColumn()) {
            return [$id, null];      // a real system doctor wins outright
        }
        return [null, null];         // bogus id — treated as "not supplied"
    }
    return $manual !== null ? [null, $manual] : [null, null];
}
