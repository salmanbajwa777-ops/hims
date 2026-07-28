<?php
/**
 * Header notification bell + dropdown. Shared by all three headers
 * (dashboard.php, doctor.php, partials/quick_header.php) so the behaviour and
 * the unread count can never drift between them.
 *
 * Expects $pdo in scope. Renders nothing at all when the user isn't signed in.
 *
 * Optional before the include:
 *   $nbClass — CSS class for the button, so each header keeps its own chrome
 *              ('appbar-icon' in the appbar, 'icon-btn' on the dashboards).
 *
 * The panel is plain HTML rendered with the page — no fetch, no polling. It
 * costs two indexed queries and is always in sync with the request that drew it.
 */
if (empty($_SESSION['user_id']) || !isset($pdo)) {
    return;
}
require_once __DIR__ . '/../config/notifications.php';

$nbClass  = $nbClass ?? 'appbar-icon';
$nbUid    = (int) $_SESSION['user_id'];
$nbUnread = notif_unread_count($pdo, $nbUid);
$nbItems  = notif_recent($pdo, $nbUid);
// Return here after mark-read. basename() keeps it inside the app, matching
// notif_safe_link()'s rule in notifications.php.
$nbFrom   = basename($_SERVER['PHP_SELF'] ?? 'dashboard.php');
$nbQs     = $_SERVER['QUERY_STRING'] ?? '';
if ($nbQs !== '') {
    $nbFrom .= '?' . $nbQs;
}
?>
<div class="nb-wrap">
    <button type="button" class="<?= htmlspecialchars($nbClass) ?> nb-btn" aria-label="Notifications"
            aria-expanded="false" aria-haspopup="true" aria-controls="nbPanel" onclick="nbToggle(event)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>
        <?php if ($nbUnread > 0): ?>
            <span class="nb-badge" aria-hidden="true"><?= $nbUnread > 9 ? '9+' : $nbUnread ?></span>
            <span class="sr-only"><?= $nbUnread ?> unread notifications</span>
        <?php endif; ?>
    </button>

    <div class="nb-panel" id="nbPanel" role="dialog" aria-label="Notifications" hidden>
        <div class="nb-head">
            <span class="nb-title">Notifications<?= $nbUnread > 0 ? ' <span class="nb-count">' . $nbUnread . ' new</span>' : '' ?></span>
            <?php if ($nbUnread > 0): ?>
                <form method="POST" action="notifications.php" style="margin:0;">
                    <input type="hidden" name="action" value="read_all">
                    <input type="hidden" name="from" value="<?= htmlspecialchars($nbFrom) ?>">
                    <button type="submit" class="nb-readall">Mark all read</button>
                </form>
            <?php endif; ?>
        </div>

        <?php if (!$nbItems): ?>
            <div class="nb-empty">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>
                <div>You're all caught up.</div>
                <div class="nb-empty-sub">Invoices, admissions and approvals will show up here.</div>
            </div>
        <?php else: ?>
            <div class="nb-list">
                <?php foreach ($nbItems as $n): ?>
                    <?php
                        $unread = empty($n['read_at']);
                        $href = 'notifications.php?open=' . (int) $n['id'] . '&from=' . urlencode($nbFrom);
                    ?>
                    <a class="nb-item<?= $unread ? ' unread' : '' ?>" href="<?= htmlspecialchars($href) ?>">
                        <span class="nb-dot <?= htmlspecialchars(notif_tone($n['type'])) ?>" aria-hidden="true"></span>
                        <span class="nb-body">
                            <span class="nb-item-title"><?= htmlspecialchars($n['title']) ?></span>
                            <?php if (!empty($n['body'])): ?>
                                <span class="nb-item-sub"><?= htmlspecialchars($n['body']) ?></span>
                            <?php endif; ?>
                            <span class="nb-meta">
                                <?= htmlspecialchars(notif_ago($n['created_at'])) ?>
                                <?php if (!empty($n['ref'])): ?> · <?= htmlspecialchars($n['ref']) ?><?php endif; ?>
                            </span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if (empty($GLOBALS['__nb_assets_done'])): $GLOBALS['__nb_assets_done'] = true; ?>
<style>
.nb-wrap { position: relative; display: inline-flex; }
.nb-btn { position: relative; }
.nb-badge {
    position: absolute; top: -4px; right: -4px; min-width: 16px; height: 16px;
    padding: 0 4px; border-radius: 9px; background: #C0392B; color: #fff;
    font-size: 10px; font-weight: 700; line-height: 16px; text-align: center;
    border: 1.5px solid #fff; box-sizing: content-box;
}
.sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }

.nb-panel {
    position: absolute; top: calc(100% + 10px); right: 0; width: 360px; max-width: calc(100vw - 24px);
    background: #fff; border: 1px solid var(--border, #e3e6e4); border-radius: 14px;
    box-shadow: 0 12px 34px rgba(16,32,30,.16); z-index: 300; overflow: hidden;
}
.nb-panel[hidden] { display: none; }
.nb-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 12px 14px; border-bottom: 1px solid var(--border, #e3e6e4); }
.nb-title { font-size: 13.5px; font-weight: 700; color: var(--text, #1b1f1e); }
.nb-count { font-size: 11px; font-weight: 700; color: var(--primary-dark, #0E5456); background: var(--primary-light, #d9ecec); padding: 2px 7px; border-radius: 20px; margin-left: 4px; }
.nb-readall { background: none; border: none; padding: 0; font: inherit; font-size: 12px; font-weight: 600; color: var(--primary, #1A7F7E); cursor: pointer; white-space: nowrap; }
.nb-readall:hover { text-decoration: underline; }

.nb-list { max-height: 400px; overflow-y: auto; }
.nb-item { display: flex; gap: 10px; padding: 11px 14px; border-bottom: 1px solid var(--border, #eef1ef); text-decoration: none; color: inherit; align-items: flex-start; }
.nb-item:last-child { border-bottom: none; }
.nb-item:hover { background: var(--bg, #f7f8f7); }
.nb-item.unread { background: rgba(26,127,126,.045); }
.nb-item.unread .nb-item-title { font-weight: 700; }
.nb-dot { width: 7px; height: 7px; border-radius: 50%; margin-top: 6px; flex: none; background: #1A7F7E; }
.nb-dot.warn { background: #C77700; }
.nb-dot.danger { background: #C0392B; }
.nb-dot.info { background: #2C6DB5; }
.nb-body { min-width: 0; display: block; }
.nb-item-title { display: block; font-size: 13px; font-weight: 600; color: var(--text, #1b1f1e); }
.nb-item-sub { display: block; font-size: 12px; color: var(--text-secondary, #5b6360); margin-top: 2px; overflow-wrap: anywhere; }
.nb-meta { display: block; font-size: 11px; color: var(--text-muted, #8a918e); margin-top: 3px; }

.nb-empty { padding: 30px 20px; text-align: center; color: var(--text-secondary, #5b6360); font-size: 13px; }
.nb-empty svg { width: 26px; height: 26px; color: var(--text-muted, #b6bcb9); margin-bottom: 8px; }
.nb-empty-sub { font-size: 11.5px; color: var(--text-muted, #8a918e); margin-top: 3px; }

@media (max-width: 520px) {
    /* Anchored to the viewport instead of the button, which sits near the right
       edge on a phone and would otherwise push the panel off-screen. */
    .nb-panel { position: fixed; top: 62px; right: 12px; left: 12px; width: auto; }
}
</style>
<script>
function nbToggle(e) {
    e.preventDefault();
    e.stopPropagation();
    var btn = e.currentTarget;
    var panel = btn.parentNode.querySelector('.nb-panel');
    var open = !panel.hasAttribute('hidden');
    if (open) { panel.setAttribute('hidden', ''); btn.setAttribute('aria-expanded', 'false'); }
    else { panel.removeAttribute('hidden'); btn.setAttribute('aria-expanded', 'true'); }
}
// Click-away and Escape both close it. Bound once, and only closes panels the
// click landed outside of, so a click inside the list still follows the link.
document.addEventListener('click', function (e) {
    document.querySelectorAll('.nb-panel:not([hidden])').forEach(function (p) {
        if (!p.parentNode.contains(e.target)) {
            p.setAttribute('hidden', '');
            var b = p.parentNode.querySelector('.nb-btn');
            if (b) { b.setAttribute('aria-expanded', 'false'); }
        }
    });
});
document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') { return; }
    document.querySelectorAll('.nb-panel:not([hidden])').forEach(function (p) {
        p.setAttribute('hidden', '');
        var b = p.parentNode.querySelector('.nb-btn');
        if (b) { b.setAttribute('aria-expanded', 'false'); b.focus(); }
    });
});
</script>
<?php endif; ?>
