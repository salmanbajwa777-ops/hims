<?php
/**
 * Shared "who can be a primary nurse" roster.
 *
 * A primary nurse is an ACCOUNTABILITY owner, not an access gate: they are the
 * staff member answerable for a stay, and picking one is mandatory at admit on
 * both routes (ER short-stay and In-Door). It deliberately does NOT restrict who
 * may work on the admission — any staff member holding the relevant nursing
 * permission can still record vitals, log ER services / medications, and add
 * care entries against a stay that is not theirs. The only thing reserved to the
 * primary nurse is handing the stay over (admission.php), which is what makes
 * the chain of custody meaningful.
 *
 * "Nurse" is a PERMISSION, never a base_role — the 3-role world is
 * ADMIN/DOCTOR/STAFF and nursing duties are granted per user. Effective
 * permission is computed in SQL exactly the way load_permissions() computes it:
 * a role default counts unless a per-user override cancels it, plus any
 * per-user grant. Keeping that logic here means the admit modal, the ER stay
 * page and the IPD stay page can never drift into showing different rosters.
 *
 * The two routes use different permission keys, matching the existing pages:
 *   ER short-stay -> NURSING_ATTEND_SHORT_STAY (admission.php)
 *   In-Door       -> IPD_RECORD_HANDOVER       (ipd_admission.php)
 */

/**
 * Active users holding $permKey, as [['id','name'], ...] ordered by name.
 *
 * Returns [] rather than throwing if the permission tables are missing, so a
 * half-migrated environment degrades to "no nurses to pick" instead of a 500.
 */
function nurse_roster(PDO $pdo, string $permKey): array {
    try {
        $st = $pdo->prepare("
            SELECT DISTINCT u.id, u.name
            FROM users u
            WHERE u.is_active = 1
              AND (
                    (   EXISTS (SELECT 1 FROM role_permissions rp
                                JOIN permissions p ON p.id = rp.permission_id
                                WHERE rp.base_role = u.base_role AND p.`key` = :k1)
                     AND NOT EXISTS (SELECT 1 FROM user_permission_overrides o
                                     JOIN permissions p ON p.id = o.permission_id
                                     WHERE o.user_id = u.id AND p.`key` = :k2 AND o.granted = 0))
                 OR EXISTS (SELECT 1 FROM user_permission_overrides o
                            JOIN permissions p ON p.id = o.permission_id
                            WHERE o.user_id = u.id AND p.`key` = :k3 AND o.granted = 1)
              )
            ORDER BY u.name
        ");
        $st->execute([':k1' => $permKey, ':k2' => $permKey, ':k3' => $permKey]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * True if $userId is a valid pick for $permKey right now.
 *
 * Used by both admit handlers to validate the submitted primary nurse — a POST
 * can name any user id, so the roster is re-checked server-side rather than
 * trusted from the form.
 */
function is_valid_nurse(PDO $pdo, string $permKey, int $userId): bool {
    if ($userId <= 0) {
        return false;
    }
    foreach (nurse_roster($pdo, $permKey) as $n) {
        if ((int) $n['id'] === $userId) {
            return true;
        }
    }
    return false;
}
