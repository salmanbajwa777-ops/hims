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

/**
 * Per-session CSRF token for the impersonation forms.
 *
 * This app has no CSRF protection anywhere, which is pre-existing debt across
 * every POST. It is fixed HERE first because this is the one endpoint where a
 * forged request changes WHO YOU ARE rather than what you did: a malicious page
 * could auto-submit action=start and silently drop a signed-in admin into a
 * staff identity, after which their own legitimate clicks write under that
 * person's name. Deliberately scoped to this endpoint — app-wide CSRF is its
 * own piece of work.
 */
function imp_csrf_token(): string
{
    if (empty($_SESSION['imp_csrf'])) {
        $_SESSION['imp_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['imp_csrf'];
}

/** Constant-time check of a submitted token. */
function imp_csrf_valid(?string $token): bool
{
    return !empty($_SESSION['imp_csrf'])
        && is_string($token)
        && hash_equals($_SESSION['imp_csrf'], $token);
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
 * Server-side brake on a high-risk money action while viewing as someone else.
 *
 * Returns an error string to show, or '' to let the action proceed.
 *
 * The banner and the audit suffix are both AFTER the fact: they tell you who
 * did it, not that it shouldn't happen. The failure mode that actually costs
 * money is an admin forgetting whose account they are in and taking a real
 * payment, and a coloured bar is thin protection against that. So the four
 * irreversible cash actions (refund, void, close-day, take-payment) require the
 * caller to re-affirm, in the request itself, that they mean to act AS the
 * impersonated person.
 *
 * Server-side deliberately: the "View as" confirm dialog in staff.php is a JS
 * confirm() and is shown once, at the start of a session that may run for an
 * hour. This is checked on the request that moves the money.
 *
 * Normal (non-impersonated) sessions are never affected — the function returns
 * '' immediately, so call sites need no is_impersonating() branch of their own.
 */
function imp_block_money_action(string $whatItIs): string
{
    if (!is_impersonating()) {
        return '';
    }
    if (!empty($_POST['imp_confirm'])) {
        return '';   // explicitly re-affirmed on this request
    }
    return 'You are viewing HIMS as ' . imp_target_name() . '. ' . $whatItIs
         . ' would be recorded under their name and against their cash drawer. '
         . 'Tick the confirmation to go ahead, or stop viewing as them first.';
}

/**
 * The re-affirmation checkbox for a high-risk form. Emits nothing in a normal
 * session, so it can be dropped into any money form unconditionally.
 */
function imp_confirm_field(string $whatItIs): string
{
    if (!is_impersonating()) {
        return '';
    }
    return '<label style="display:flex;gap:8px;align-items:flex-start;background:#FFF7E8;'
         . 'border:1px solid #FBCFB0;border-radius:8px;padding:10px 12px;margin:10px 0;'
         . 'font-size:12.5px;color:#8a5a00;font-weight:600;cursor:pointer;">'
         . '<input type="checkbox" name="imp_confirm" value="1" required style="margin-top:2px;flex:none;">'
         . '<span>' . htmlspecialchars($whatItIs) . ' as <b>' . htmlspecialchars(imp_target_name())
         . '</b> — this is their cash drawer, not yours.</span></label>';
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

    // The rail above tests one literal string, and the rule it is enforcing is
    // "do not launder an elevated action into someone else's name". Those are
    // not the same test: a MANAGER is not the string 'ADMIN' but holds
    // FINANCIAL_VOID_BILL, ADMIN_RECEIVE_HANDOVER, RECEPTION_CLOSE_DAY,
    // FINANCIAL_APPROVE_EXPENSES and ADMISSION_APPROVE_WRITEOFF — so
    // impersonating one gives exactly the laundering the rail exists to stop.
    // The same hole opens for any STAFF user granted one of these keys as a
    // per-user override.
    //
    // So test what the target can actually DO, via the same resolver the app
    // uses, rather than what their role is called. Fails soft: if the
    // permission tables cannot be read we fall back to the role check above
    // rather than blocking a legitimate support action.
    if (function_exists('load_permissions')) {
        try {
            $elevated = [
                'FINANCIAL_VOID_BILL',
                'FINANCIAL_APPROVE_EXPENSES',
                'ADMISSION_APPROVE_WRITEOFF',
                'ADMIN_RECEIVE_HANDOVER',
                'RECEPTION_CLOSE_DAY',
            ];
            $held = load_permissions($pdo, (int) $target['id'], (string) $target['base_role']);
            $hits = array_values(array_intersect($elevated, $held));
            if ($hits) {
                return 'You cannot view the app as ' . $target['name'] . ': they hold '
                     . implode(', ', $hits) . '. Acting as them would record an elevated '
                     . 'action under their name.';
            }
        } catch (Throwable $e) {
            // fall through to the role check already performed above
        }
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
 * impersonating (no-op).
 *
 * Returns the admin's landing page, or '' if the session was destroyed because
 * the admin is no longer entitled to it — the caller must send them to /index.php.
 */
function imp_stop(PDO $pdo): string
{
    require_once __DIR__ . '/permissions.php';

    if (!is_impersonating()) {
        return '/dashboard.php';
    }

    $adminId   = (int) $_SESSION['imp_admin_id'];
    $adminName = (string) $_SESSION['imp_admin_name'];
    $targetId  = (int) $_SESSION['user_id'];
    $targetNm  = imp_target_name();
    $started   = (int) ($_SESSION['imp_started_at'] ?? 0);
    $mins      = $started ? max(0, (int) round((time() - $started) / 60)) : 0;

    // Re-authorise against the DB, exactly as imp_start() does — the parked
    // base_role is a memory of what was true when this began, not a fact.
    //
    // Without this, an admin demoted (staff.php's edit form) or deactivated
    // (its toggle_active) DURING an impersonation would be handed a full ADMIN
    // session back: the old code restored $_SESSION['imp_admin_role'], falling
    // back to the literal 'ADMIN', and load_permissions() grants whatever role
    // it is told without checking the user still holds it. is_active is verified
    // only at login (index.php), so nothing downstream would have caught it.
    $stmt = $pdo->prepare('SELECT id, name, base_role, is_active, must_change_password FROM users WHERE id = ?');
    $stmt->execute([$adminId]);
    $admin = $stmt->fetch();

    $stillAdmin = $admin
        && $admin['base_role'] === 'ADMIN'
        && (int) ($admin['is_active'] ?? 1) === 1;

    if (!$stillAdmin) {
        // Deleted, demoted or deactivated mid-impersonation. Do not restore
        // anything — end the session and make them sign in again, which is the
        // only path that re-checks entitlement from scratch.
        try {
            audit_log($pdo, 'impersonation_stop_denied',
                "Session ended: ADMIN #$adminId was demoted, deactivated or removed while viewing as $targetNm #$targetId",
                $adminId);
        } catch (Throwable $e) { /* never block the exit */ }
        $_SESSION = [];
        // Guarded: session_destroy() warns on an unstarted session, and a
        // warning rendered before the redirect header would break it.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        return '';
    }

    $adminRole = (string) $admin['base_role'];

    // Restore the admin BEFORE logging, so a failed insert can't strand the
    // session in the target's identity.
    $_SESSION['user_id']   = $adminId;
    $_SESSION['base_role'] = $adminRole;
    // Their own forced-password-change state is a DB fact, not something the
    // impersonation may clear — imp_start() set this false for the TARGET's
    // benefit, and restoring a hardcoded false here would let an admin skip a
    // reset that another admin had just required of them.
    $_SESSION['must_change_password'] = !empty($admin['must_change_password']);
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
