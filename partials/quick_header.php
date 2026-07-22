<?php
/**
 * Two-row application header for the front-desk and ward consoles.
 *
 * Row 1 carries the handful of destinations those roles hit dozens of times a
 * shift; row 2 keeps global search, alerts and identity below them. Reception
 * and nursing get different action sets — bookings and registration are
 * front-desk work, vitals are not.
 *
 * The caller must have $pdo and a session in scope. Optional locals the caller
 * may set before including this file:
 *   $qhActive  — slug of the button to mark current ('patients', 'today',
 *                'admissions', 'bookings', 'vitals')
 *   $qhBrand   — false on pages whose sidebar already shows the HIMS mark,
 *                so it is not rendered twice. Defaults to true.
 *   $firstName — used for the avatar initial; looked up if absent
 *
 * Pages that include this must NOT also define .header/.search-box rules of
 * their own; the styles below ship with the partial.
 */

$qhActive   = $qhActive ?? '';
$qhBrand    = $qhBrand ?? true;
$qhBaseRole = $_SESSION['base_role'] ?? '';
$qhIsNurse  = $qhBaseRole === 'NURSE';
// Most callers already have the signed-in user loaded; those that don't get one
// cheap lookup rather than an anonymous avatar.
$qhName = $firstName ?? '';
if ($qhName === '' && !empty($_SESSION['user_id'])) {
    $qhStmt = $pdo->prepare('SELECT name FROM users WHERE id = ?');
    $qhStmt->execute([$_SESSION['user_id']]);
    $qhName = (string) ($qhStmt->fetchColumn() ?: '');
}
$qhInitial = $qhName !== '' ? strtoupper(substr(trim($qhName), 0, 1)) : '?';

/**
 * Today's queue counts for the badges.
 *
 * Deliberately one aggregate query rather than reusing the caller's row set —
 * the partial is included by pages that never load today's visits, and a badge
 * that silently reads zero is worse than one extra indexed count.
 */
$qhCounts = ['today' => 0, 'admissions' => 0];
try {
    $qhRow = $pdo->query("
        SELECT COUNT(*) AS total,
               SUM(disposition = 'SHORT_STAY') AS admitted
        FROM visits
        WHERE visit_date = CURDATE()
    ")->fetch();
    if ($qhRow) {
        $qhCounts['today']      = (int) $qhRow['total'];
        $qhCounts['admissions'] = (int) $qhRow['admitted'];
    }
} catch (Throwable $e) {
    // A badge is not worth a fatal. Fall through with zeros.
}

/** SVG glyphs for the quick row, kept local so the partial does not depend on
 *  each page's own icon() map — those maps differ page to page. */
if (!function_exists('qh_icon')) {
function qh_icon(string $name): string {
    $paths = [
        'users'      => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'clock'      => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'bed'        => '<path d="M3 20v-8a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v8"/><path d="M3 16h18"/><path d="M7 10V7a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v3"/>',
        'calendar'   => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18"/>',
        'plus'       => '<path d="M12 5v14M5 12h14"/>',
        'activity'   => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
        'search'     => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>',
        'bell'       => '<path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
        'mail'       => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 6-10 7L2 6"/>',
        'wallet'     => '<path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/>',
    ];
    $p = $paths[$name] ?? '';
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">' . $p . '</svg>';
}
}

/**
 * The action set, in the order each role wants it. 'tone' selects the tint
 * class; 'count' is the badge, null for buttons that have nothing to count.
 */
if ($qhIsNurse) {
    $qhButtons = [
        ['slug' => 'admissions', 'label' => 'Admissions', 'icon' => 'bed',      'href' => 'admissions.php',  'tone' => 'violet', 'count' => $qhCounts['admissions']],
        ['slug' => 'today',      'label' => 'Today',      'icon' => 'clock',    'href' => 'receptionist.php','tone' => 'amber',  'count' => $qhCounts['today']],
        ['slug' => 'patients',   'label' => 'Patients',   'icon' => 'users',    'href' => 'patients.php',    'tone' => 'teal',   'count' => null],
        ['slug' => 'vitals',     'label' => 'Vitals Due', 'icon' => 'activity', 'href' => '#',               'tone' => 'rose',   'count' => null, 'disabled' => true],
    ];
} else {
    $qhButtons = [
        ['slug' => 'patients',   'label' => 'Patients',        'icon' => 'users',    'href' => 'patients.php',     'tone' => 'teal',    'count' => null],
        ['slug' => 'today',      'label' => 'Today',           'icon' => 'clock',    'href' => 'receptionist.php', 'tone' => 'amber',   'count' => $qhCounts['today']],
        ['slug' => 'admissions', 'label' => 'Admissions',      'icon' => 'bed',      'href' => 'admissions.php',   'tone' => 'violet',  'count' => $qhCounts['admissions']],
        ['slug' => 'bookings',   'label' => 'Bookings',        'icon' => 'calendar', 'href' => 'bookings.php',     'tone' => 'blue',    'count' => null],
        ['slug' => 'register',   'label' => 'Add New Patient', 'icon' => 'plus',     'href' => 'patients.php?register=1', 'tone' => 'primary', 'count' => null],
    ];
    // Counter cash going out is front-desk work too — but only for users who
    // hold the posting permission (function guard in case a caller included
    // this partial without config/permissions.php loaded).
    if (function_exists('has_permission') && has_permission('FINANCIAL_POST_EXPENSES')) {
        $qhButtons[] = ['slug' => 'expenses', 'label' => 'Expenses', 'icon' => 'wallet', 'href' => 'expenses.php', 'tone' => 'rose', 'count' => null];
    }
    // End-of-day cash tally & handover (shift_closing.php).
    if (function_exists('has_permission') && has_permission('RECEPTION_CLOSE_DAY')) {
        $qhButtons[] = ['slug' => 'shift_closing', 'label' => 'Day Closing', 'icon' => 'wallet', 'href' => 'shift_closing.php', 'tone' => 'amber', 'count' => null];
    }
}
?>
<style>
/* ---------- Two-row quick header ---------- */
.qheader { position: sticky; top: 0; z-index: 20; background: var(--card, #fff); border-bottom: 1px solid var(--border); }

.qh-row1 {
    display: flex; align-items: center; gap: 14px;
    padding: 12px 24px; border-bottom: 1px solid #EEF2F6;
    overflow-x: auto; scrollbar-width: thin;
}
.qh-brand { display: flex; align-items: center; gap: 10px; font-weight: 700; font-size: 15px; flex: none; }
.qh-brand .logo-mark {
    width: 30px; height: 30px; border-radius: 10px; font-size: 12px; font-weight: 700;
    background: linear-gradient(135deg, var(--primary-dark), var(--primary));
    color: #fff; display: flex; align-items: center; justify-content: center;
}
.qh-divider { width: 1px; height: 26px; background: var(--border); flex: none; }
.qh-actions { display: flex; align-items: center; gap: 10px; flex: none; }
.qh-spacer { flex: 1; min-width: 8px; }

.qh-btn {
    display: inline-flex; align-items: center; gap: 8px;
    height: 38px; padding: 0 15px; border-radius: var(--radius-btn, 14px);
    border: 1px solid var(--border); background: #fff;
    font-size: 13.5px; font-weight: 600; color: var(--text-secondary);
    font-family: inherit; white-space: nowrap; cursor: pointer;
    transition: background .15s, border-color .15s, color .15s;
}
.qh-btn svg { width: 16px; height: 16px; flex: none; }
.qh-btn:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
.qh-btn .qh-count {
    font-size: 11.5px; font-weight: 700; font-variant-numeric: tabular-nums;
    padding: 1px 7px; border-radius: 999px; background: rgba(15,23,42,.06); color: inherit; opacity: .85;
}
.qh-btn.disabled { opacity: .45; cursor: not-allowed; }

/* One hue per destination, so the row is scannable by color before it is read. */
.qh-btn.teal   { background: rgba(26,127,126,.10);  border-color: rgba(26,127,126,.28);  color: #0E5456; }
.qh-btn.amber  { background: rgba(245,158,11,.12);  border-color: rgba(245,158,11,.30);  color: #92400E; }
.qh-btn.violet { background: rgba(109,40,217,.10);  border-color: rgba(109,40,217,.26);  color: #5B21B6; }
.qh-btn.blue   { background: rgba(37,99,235,.10);   border-color: rgba(37,99,235,.26);   color: #1D4ED8; }
.qh-btn.rose   { background: rgba(225,29,72,.09);   border-color: rgba(225,29,72,.24);   color: #9F1239; }

.qh-btn.teal:hover   { background: rgba(26,127,126,.18); }
.qh-btn.amber:hover  { background: rgba(245,158,11,.20); }
.qh-btn.violet:hover { background: rgba(109,40,217,.18); }
.qh-btn.blue:hover   { background: rgba(37,99,235,.18); }
.qh-btn.rose:hover   { background: rgba(225,29,72,.16); }
.qh-btn.disabled:hover { background: rgba(225,29,72,.09); }

.qh-btn.primary {
    background: linear-gradient(135deg, var(--primary-dark), var(--primary));
    border-color: transparent; color: #fff;
    box-shadow: 0 6px 14px rgba(14,84,86,.24);
}
.qh-btn.primary:hover { filter: brightness(1.07); }
.qh-btn.primary .qh-count { background: rgba(255,255,255,.22); }

/* The current page reads as pressed: full-strength border, no tint ambiguity. */
.qh-btn.is-active { box-shadow: inset 0 0 0 1px currentColor; }

.qh-date { font-size: 13px; color: var(--text-muted); white-space: nowrap; flex: none; }

.qh-row2 { display: flex; align-items: center; gap: 16px; padding: 11px 24px; }
.qh-search { flex: 1; max-width: 560px; position: relative; display: flex; align-items: center; }
.qh-search input {
    width: 100%; height: 38px; padding: 0 74px 0 38px;
    border-radius: var(--radius-input, 12px); border: 1px solid var(--border);
    background: #F8FAFC; font-size: 13.5px; color: var(--text); font-family: inherit;
}
.qh-search input:focus { outline: none; border-color: var(--primary); background: #fff; }
.qh-search .qh-search-icon { position: absolute; left: 13px; display: flex; color: var(--text-muted); pointer-events: none; }
.qh-search .qh-search-icon svg { width: 15px; height: 15px; }
.qh-kbd {
    position: absolute; right: 10px; font-size: 11px; color: var(--text-muted);
    background: #fff; border: 1px solid var(--border); border-radius: 6px; padding: 2px 6px;
}
.qh-row2-right { display: flex; align-items: center; gap: 14px; margin-left: auto; }
.qh-icon-btn {
    width: 38px; height: 38px; border-radius: 12px; border: 1px solid var(--border);
    background: #fff; display: flex; align-items: center; justify-content: center;
    color: var(--text-secondary); position: relative; cursor: pointer;
}
.qh-icon-btn svg { width: 17px; height: 17px; }
.qh-icon-btn:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
.qh-icon-btn .dot {
    position: absolute; top: 6px; right: 6px; width: 7px; height: 7px; border-radius: 50%;
    background: var(--red); border: 1.5px solid #fff;
}
.qh-avatar {
    width: 38px; height: 38px; border-radius: 50%; flex: none;
    background: linear-gradient(135deg, var(--primary-dark), var(--primary));
    color: #fff; display: flex; align-items: center; justify-content: center;
    font-weight: 600; font-size: 13px;
}
.qh-logout { font-size: 13px; color: var(--text-muted); font-weight: 500; white-space: nowrap; }

@media (max-width: 900px) {
    .qh-row1, .qh-row2 { padding-left: 16px; padding-right: 16px; }
    .qh-date, .qh-kbd { display: none; }
    .qh-brand span { display: none; }
}
</style>

<header class="qheader">
    <div class="qh-row1">
        <?php if ($qhBrand): ?>
        <a class="qh-brand" href="<?= $qhIsNurse ? 'admissions.php' : 'receptionist.php' ?>">
            <span class="logo-mark">H</span><span>HIMS</span>
        </a>
        <span class="qh-divider"></span>
        <?php endif; ?>

        <nav class="qh-actions" aria-label="Quick access">
            <?php foreach ($qhButtons as $b): ?>
                <?php
                $cls = 'qh-btn ' . $b['tone'];
                if (!empty($b['disabled']))       { $cls .= ' disabled'; }
                if ($qhActive === $b['slug'])     { $cls .= ' is-active'; }
                $href = !empty($b['disabled']) ? '#' : $b['href'];
                ?>
                <a class="<?= $cls ?>" href="<?= htmlspecialchars($href) ?>"
                   <?= !empty($b['disabled']) ? 'title="Not built yet" aria-disabled="true"' : '' ?>
                   <?= $qhActive === $b['slug'] ? 'aria-current="page"' : '' ?>>
                    <?= qh_icon($b['icon']) ?>
                    <?= htmlspecialchars($b['label']) ?>
                    <?php if ($b['count'] !== null && $b['count'] > 0): ?>
                        <span class="qh-count"><?= $b['count'] ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <span class="qh-spacer"></span>
        <span class="qh-date"><?= date('D, d M Y') ?></span>
    </div>

    <div class="qh-row2">
        <form class="qh-search" method="GET" action="patients.php" role="search">
            <span class="qh-search-icon"><?= qh_icon('search') ?></span>
            <input type="text" name="q" placeholder="Search patients by name, phone or MRN&hellip;"
                   value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" aria-label="Search patients">
            <span class="qh-kbd">Ctrl K</span>
        </form>

        <div class="qh-row2-right">
            <button type="button" class="qh-icon-btn" aria-label="Notifications"><?= qh_icon('bell') ?><span class="dot"></span></button>
            <button type="button" class="qh-icon-btn" aria-label="Messages"><?= qh_icon('mail') ?></button>
            <a class="qh-avatar" href="profile.php" title="My Profile" style="text-decoration:none;"><?= htmlspecialchars($qhInitial) ?></a>
            <a class="qh-logout" href="logout.php">Logout</a>
        </div>
    </div>
</header>

<script>
// Ctrl/Cmd-K focuses the search field the header advertises.
document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        var input = document.querySelector('.qh-search input');
        if (input) { e.preventDefault(); input.focus(); input.select(); }
    }
});
</script>
