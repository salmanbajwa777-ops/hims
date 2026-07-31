<?php
/**
 * In-app notifications — the data layer behind the header bell.
 *
 * Companion to config/notify.php, which sends the same events by email. Those
 * functions resolve their audience already; each now also calls notify_users()
 * here so the bell mirrors what went to the inbox.
 *
 * Every write is best-effort and swallows its own errors: a notification must
 * never break the page that triggered it, and must never be the reason a bill
 * fails to save. Same contract as the mailer.
 */

require_once __DIR__ . '/permissions.php';

/** Bell dropdown page size. */
const NOTIF_FEED_LIMIT = 12;

/**
 * Clip a value to its column width.
 *
 * Falls back to substr() when mbstring is unavailable. The app leans on mb_*
 * in ~47 places so it is certainly loaded in production, but a notification is
 * the last thing that should disappear because of a missing extension — and
 * inside notify_users()'s catch, that failure would be silent.
 */
function notif_clip(?string $s, int $max): ?string
{
    if ($s === null) {
        return null;
    }
    return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
}

/**
 * True once sql/add_notifications.sql has been applied.
 *
 * Cached per request. Every read path checks this so the header keeps rendering
 * on a server where the migration hasn't been run yet — the bell just shows
 * nothing instead of fatalling on all 51 pages at once.
 */
function notifications_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $pdo->query('SELECT 1 FROM notifications LIMIT 1');
        $ready = true;
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

/**
 * Write one notification per recipient.
 *
 * $userIds is fanned out at write time rather than stored as a single shared
 * event row: it keeps "unread for me" a single indexed lookup, and lets one
 * person read an item without affecting anyone else's copy. Duplicate ids are
 * collapsed, and 0/null ids dropped, so callers can pass a raw recipient list
 * without pre-cleaning it.
 */
function notify_users(PDO $pdo, array $userIds, string $type, string $title, ?string $body = null, ?string $link = null, ?string $ref = null): int
{
    try {
        if (!notifications_ready($pdo)) {
            return 0;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (!$ids) {
            return 0;
        }

        $stmt = $pdo->prepare('INSERT INTO notifications (user_id, type, title, body, link, ref) VALUES (?, ?, ?, ?, ?, ?)');
        $n = 0;
        foreach ($ids as $uid) {
            // Columns are sized to the schema; a long patient name or reason
            // must truncate rather than throw and lose the whole notification.
            $stmt->execute([
                $uid,
                notif_clip($type, 60),
                notif_clip($title, 180),
                notif_clip($body, 400),
                notif_clip($link, 255),
                notif_clip($ref, 80),
            ]);
            $n++;
        }
        return $n;
    } catch (Throwable $e) {
        return 0; // never break the page for a notification
    }
}

/**
 * Is the EMAIL for this event switched on globally?
 *
 * Governs one thing: whether the send_mail() in config/notify.php is allowed to
 * fire. In-app notifications never consult this — every event always writes to
 * the bell, which is why notify_users() sits ABOVE this check in every sender.
 *
 * GLOBAL and OVERRIDING. This is the clinic-wide switchboard set by an admin on
 * notification_settings.php, and it wins over any per-user opt-in: a doctor who
 * ticked email_on_new_patient still gets nothing if invoice_raised is off here.
 * The per-user flag can only narrow an already-enabled event, never widen one.
 *
 * Defaults to TRUE when the row or the whole table is missing, so a server that
 * hasn't run sql/add_notification_settings.sql keeps today's behaviour rather
 * than going silently mute — the same fallback rule as the email_on_new_patient
 * read in notify.php. A brand-new event type with no seeded row therefore mails
 * until someone switches it off, which is the safe direction for a missing row.
 *
 * Cached per request: several senders check the same key more than once, and a
 * page that raises two bills would otherwise repeat the lookup.
 */
function notif_email_enabled(PDO $pdo, string $eventType): bool
{
    static $cache = [];
    if (array_key_exists($eventType, $cache)) {
        return $cache[$eventType];
    }
    try {
        $stmt = $pdo->prepare('SELECT email_enabled FROM notification_settings WHERE event_type = ?');
        $stmt->execute([$eventType]);
        $row = $stmt->fetchColumn();
        // false === no such row (unseeded event); null === row exists but NULL.
        $cache[$eventType] = ($row === false) ? true : (bool) $row;
    } catch (Throwable $e) {
        $cache[$eventType] = true; // table not migrated yet
    }
    return $cache[$eventType];
}

/**
 * Every active user holding $permKey, as user ids.
 *
 * Mirrors load_permissions(): role grant minus a user revoke, plus a user
 * grant. Kept in SQL (rather than looping users in PHP) because the email
 * fan-out in notify.php already resolves its audience this way — the two must
 * agree, or the bell and the inbox disagree about who was told.
 */
function notif_users_with_permission(PDO $pdo, string $permKey): array
{
    try {
        $stmt = $pdo->prepare("
            SELECT u.id FROM users u
            WHERE u.is_active = 1
              AND (
                    (   EXISTS (SELECT 1 FROM role_permissions rp
                                JOIN permissions p ON p.id = rp.permission_id
                                WHERE rp.base_role = u.base_role AND p.`key` = :k)
                     AND NOT EXISTS (SELECT 1 FROM user_permission_overrides o
                                     JOIN permissions p ON p.id = o.permission_id
                                     WHERE o.user_id = u.id AND p.`key` = :k AND o.granted = 0))
                 OR EXISTS (SELECT 1 FROM user_permission_overrides o
                            JOIN permissions p ON p.id = o.permission_id
                            WHERE o.user_id = u.id AND p.`key` = :k AND o.granted = 1)
              )
        ");
        $stmt->execute([':k' => $permKey]);
        return array_map('intval', array_column($stmt->fetchAll(), 'id'));
    } catch (Throwable $e) {
        return [];
    }
}

/** Every active ADMIN, as user ids. The in-app stand-in for admin_alert_email(). */
function notif_admin_ids(PDO $pdo): array
{
    try {
        $rows = $pdo->query("SELECT id FROM users WHERE base_role = 'ADMIN' AND is_active = 1")->fetchAll();
        return array_map('intval', array_column($rows, 'id'));
    } catch (Throwable $e) {
        return [];
    }
}

/** Unread count for the bell badge. */
function notif_unread_count(PDO $pdo, int $userId): int
{
    try {
        if (!notifications_ready($pdo)) {
            return 0;
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** Most recent notifications for the dropdown, newest first. */
function notif_recent(PDO $pdo, int $userId, int $limit = NOTIF_FEED_LIMIT): array
{
    try {
        if (!notifications_ready($pdo)) {
            return [];
        }
        // LIMIT is an int cast from a typed param, never user text.
        $limit = max(1, min(100, $limit));
        $stmt = $pdo->prepare("SELECT id, type, title, body, link, ref, read_at, created_at
                               FROM notifications WHERE user_id = ?
                               ORDER BY created_at DESC, id DESC LIMIT $limit");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** Mark one notification read. Scoped to the owner so an id from another user is a no-op. */
function notif_mark_read(PDO $pdo, int $userId, int $notifId): bool
{
    try {
        if (!notifications_ready($pdo)) {
            return false;
        }
        $stmt = $pdo->prepare('UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ? AND read_at IS NULL');
        $stmt->execute([$notifId, $userId]);
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/** Mark everything read for one user. Returns how many rows changed. */
function notif_mark_all_read(PDO $pdo, int $userId): int
{
    try {
        if (!notifications_ready($pdo)) {
            return 0;
        }
        $stmt = $pdo->prepare('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL');
        $stmt->execute([$userId]);
        return $stmt->rowCount();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * "3m ago" / "2h ago" / "5d ago" for the feed.
 *
 * Deliberately not a date: the bell is about recency. Anything past a week
 * falls back to the app-wide dd/mm/yyyy format.
 */
function notif_ago(?string $ts): string
{
    if (!$ts) {
        return '';
    }
    $t = strtotime($ts);
    if ($t === false) {
        return '';
    }
    $diff = time() - $t;
    if ($diff < 60)     { return 'just now'; }
    if ($diff < 3600)   { return floor($diff / 60) . 'm ago'; }
    if ($diff < 86400)  { return floor($diff / 3600) . 'h ago'; }
    if ($diff < 604800) { return floor($diff / 86400) . 'd ago'; }
    return date('d/m/Y', $t);
}

/**
 * Accent colour per event class, used for the dot on each row.
 * Money in, money out, clinical, and administrative read differently at a glance.
 */
function notif_tone(string $type): string
{
    if (str_contains($type, 'refund') || str_contains($type, 'void') || str_contains($type, 'rejected')) {
        return 'danger';
    }
    if (str_contains($type, 'expense') || str_contains($type, 'approval') || str_contains($type, 'closing')) {
        return 'warn';
    }
    if (str_contains($type, 'discharge') || str_contains($type, 'admitted') || str_contains($type, 'booking')) {
        return 'info';
    }
    return 'ok';
}
