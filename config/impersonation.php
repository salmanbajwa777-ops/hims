<?php
/**
 * "View as staff" — lets an ADMIN see the app exactly as another user sees it.
 *
 * WHY THIS EXISTS
 * When a receptionist's day-closing won't balance or a nurse's vitals chart
 * renders wrong, the screen itself is the evidence. Reading permissions off a
 * table doesn't reproduce it, and the admin was previously driving to another
 * building to look at the actual monitor. This lets them look from where they
 * are.
 *
 * HOW IT WORKS
 * Every page in HIMS derives identity from exactly three session keys:
 *   $_SESSION['user_id'], ['base_role'], ['permissions']
 * so swapping those three swaps the entire app — sidebar, landing page, every
 * require_permission() gate, every "my patients" query — with no per-page
 * changes. The admin's real identity is parked in $_SESSION['imp_admin_*'] and
 * is what we restore on stop.
 *
 * The impersonated permission set is loaded through the SAME load_permissions()
 * the target uses at their own login, so what the admin gets is precisely the
 * target's effective set (role defaults + that user's grants/revokes) — never
 * the admin's own rights leaking through. An admin viewing a STAFF member
 * genuinely loses admin pages for the duration; that is the point.
 *
 * ATTRIBUTION (deliberate, see is_impersonating() callers)
 * Writes are ALLOWED and are attributed to the TARGET user, because the day's
 * cash/closing totals must reconcile against what that staff member sees on
 * their own screen. Accountability is preserved in audit_logs instead: every
 * impersonated write is tagged with the acting admin via imp_audit_suffix(),
 * and start/stop are themselves logged. So the money stays consistent and the
 * "who really did this" question is still answerable.
 *
 * SAFETY RAILS
 *   - ADMIN base_role only, checked against the DB at start (not the session).
 *   - An admin may never impersonate another ADMIN — that would launder an
 *     admin action into someone else's name with no trace of the difference.
 *   - Never self-impersonate (pointless, and would orphan the restore keys).
 *   - Inactive accounts can be viewed (that's often WHY you're looking).
 *   - Nested impersonation is impossible: start while impersonating is refused,
 *     so imp_admin_* can never be overwritten with a staff identity and strand
 *     the admin in a staff session.
 */

// NOTE: permissions.php is deliberately NOT required at the top of this file.
// It is loaded from config/auth.php (so the banner helpers are always in
// scope), and permissions.php runs a top-level `SET time_zone` guarded by
// isset($pdo) — the PKT belt-and-suspenders pin. Requiring it that early, before
// db.php has created $pdo, would run that guard against nothing and quietly
// disarm the safeguard. imp_start()/imp_stop() are the only functions here that
// need load_permissions(), they always receive a live $pdo, and every caller
// reaches them after db.php + permissions.php are loaded — so they require it
// lazily instead.

/** Is the current session an admin currently viewing the app as someone else? */
function is_impersonating(): bool
{
    return !empty($_SESSION['imp_admin_id']);
}

/** The real admin's user id, whether or not impersonation is active. */
function real_user_id(): int
{
    return (int) ($_SESSION['imp_admin_id'] ?? $_SESSION['user_id'] ?? 0);
}

/** The real admin's name (only meaningful while impersonating). */
function imp_admin_name(): string
{
    return (string) ($_SESSION['imp_admin_name'] ?? '');
}

/** The impersonated user's name (only meaningful while impersonating). */
function imp_target_name(): string
{
    return (string) ($_SESSION['imp_target_name'] ?? '');
}

/**
 * Suffix for audit_logs.details on any write made while impersonating, so a
 * row attributed to the staff member still says who actually clicked it.
 * Returns '' in a normal session, making it safe to append unconditionally.
 */
function imp_audit_suffix(): string
{
    if (!is_impersonating()) {
        return '';
    }
    return ' [via ADMIN ' . imp_admin_name() . ' #' . real_user_id() . ' viewing as ' . imp_target_name() . ']';
}

/**
 * Begin impersonation. Returns an error string, or '' on success.
 * Caller redirects on success.
 */
function imp_start(PDO $pdo, int $targetId): string
{
    require_once __DIR__ . '/permissions.php';

    if (is_impersonating()) {
        return 'Already viewing as someone else. Stop the current session first.';
    }

    $adminId = (int) ($_SESSION['user_id'] ?? 0);
    if ($adminId === 0) {
        return 'Not signed in.';
    }

    // Authorise against the DB, not the session: if this admin was demoted since
    // they signed in, the stale session must not still open the door.
    $stmt = $pdo->prepare('SELECT id, name, base_role FROM users WHERE id = ?');
    $stmt->execute([$adminId]);
    $admin = $stmt->fetch();
    if (!$admin || $admin['base_role'] !== 'ADMIN') {
        return 'Only administrators can view the app as another user.';
    }

    if ($targetId === $adminId) {
        return 'You are already signed in as yourself.';
    }

    $stmt = $pdo->prepare('SELECT id, name, base_role, is_active FROM users WHERE id = ?');
    $stmt->execute([$targetId]);
    $target = $stmt->fetch();
    if (!$target) {
        return 'That user no longer exists.';
    }
    if ($target['base_role'] === 'ADMIN') {
        return 'You cannot view the app as another administrator.';
    }

    // Park the real identity. These keys existing IS the impersonation flag.
    $_SESSION['imp_admin_id']    = $adminId;
    $_SESSION['imp_admin_name']  = (string) $admin['name'];
    $_SESSION['imp_admin_role']  = (string) $admin['base_role'];
    $_SESSION['imp_target_name'] = (string) $target['name'];
    $_SESSION['imp_started_at']  = time();

    // Become the target.
    $_SESSION['user_id']     = (int) $target['id'];
    $_SESSION['base_role']   = (string) $target['base_role'];
    $_SESSION['permissions'] = load_permissions($pdo, (int) $target['id'], (string) $target['base_role']);

    // The target's own first-login password prompt is not the admin's to answer;
    // forcing it would bounce them to change-password.php and change a password
    // that isn't theirs.
    $_SESSION['must_change_password'] = false;

    // The doctor shift-timings popup keys off this; don't fire it for a viewer.
    $_SESSION['timings_popup_shown'] = true;

    audit_log($pdo, 'impersonation_start', 'ADMIN ' . $admin['name'] . " #$adminId started viewing as " . $target['base_role'] . ' ' . $target['name'] . " #$targetId", $adminId);

    return '';
}

/**
 * End impersonation and restore the admin's own session. Safe to call when not
 * impersonating (no-op). Returns the admin's landing page.
 */
function imp_stop(PDO $pdo): string
{
    require_once __DIR__ . '/permissions.php';

    if (!is_impersonating()) {
        return '/dashboard.php';
    }

    $adminId   = (int) $_SESSION['imp_admin_id'];
    $adminName = (string) $_SESSION['imp_admin_name'];
    $adminRole = (string) ($_SESSION['imp_admin_role'] ?? 'ADMIN');
    $targetId  = (int) $_SESSION['user_id'];
    $targetNm  = imp_target_name();
    $started   = (int) ($_SESSION['imp_started_at'] ?? 0);
    $mins      = $started ? max(0, (int) round((time() - $started) / 60)) : 0;

    // Restore the admin BEFORE logging, so a failed insert can't strand the
    // session in the target's identity.
    $_SESSION['user_id']   = $adminId;
    $_SESSION['base_role'] = $adminRole;
    $_SESSION['must_change_password'] = false;
    unset(
        $_SESSION['imp_admin_id'],
        $_SESSION['imp_admin_name'],
        $_SESSION['imp_admin_role'],
        $_SESSION['imp_target_name'],
        $_SESSION['imp_started_at'],
        $_SESSION['timings_popup_shown']
    );
    $_SESSION['permissions'] = load_permissions($pdo, $adminId, $adminRole);

    try {
        audit_log($pdo, 'impersonation_stop', 'ADMIN ' . $adminName . " #$adminId stopped viewing as $targetNm #$targetId" . ($mins ? " after {$mins} min" : ''), $adminId);
    } catch (Throwable $e) {
        // Session is already restored; a lost log line must not block the exit.
    }

    return '/dashboard.php';
}
