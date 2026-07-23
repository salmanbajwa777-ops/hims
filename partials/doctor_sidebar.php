<?php
/**
 * Doctor console sidebar — shared between doctor.php and doctor_analytics.php.
 *
 * The doctor console keeps its OWN clinical nav taxonomy (different from the
 * shared admin/reception partials/sidebar.php), so this partial carries both
 * the markup AND its CSS + mobile-drawer JS. Include it INSIDE <div class="app">
 * (it renders the mobile bar, the overlay and the <aside>), after setting:
 *
 *   $dsActive       — 'console' | 'analytics' | 'schedule' (which nav item highlights)
 *   $dsUserName     — display name for the footer
 *   $dsWaitingCount — today's waiting count for the My Queue badge (0 hides it)
 *
 * CSS classes here intentionally match the ones doctor.php always used, so its
 * page-specific styles keep working unchanged.
 */
$dsActive = $dsActive ?? 'console';
$dsUserName = $dsUserName ?? '';
$dsWaitingCount = (int) ($dsWaitingCount ?? 0);

// Self-contained icon set (subset of doctor.php's icon() — kept local so the
// partial doesn't depend on a page-level helper being defined first).
function ds_icon(string $name): string {
    $paths = [
        'grid'    => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'users'   => '<path d="M17 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'stetho'  => '<path d="M4.8 2.3A.3.3 0 1 0 5 2H4a2 2 0 0 0-2 2v5a6 6 0 0 0 6 6a6 6 0 0 0 6-6V4a2 2 0 0 0-2-2h-1a.2.2 0 1 0 .3.3"/><path d="M8 15v1a6 6 0 0 0 6 6a6 6 0 0 0 6-6v-4"/><circle cx="20" cy="10" r="2"/>',
        'file'    => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/>',
        'search'  => '<circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>',
        'calendar'=> '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'chart'   => '<path d="M3 3v18h18"/><path d="M18 9l-5 5-3-3-4 4"/>',
    ];
    return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . ($paths[$name] ?? '') . '</svg>';
}
?>
<style>
/* Doctor sidebar (shared) — mirrors the styles doctor.php always carried. */
.sidebar { background: var(--card); border-right: 1px solid var(--border); padding: 24px 16px; position: sticky; top: 0; height: 100vh; overflow-y: auto; }
.sidebar-brand { display: flex; align-items: center; gap: 10px; padding: 0 8px 24px; font-weight: 700; font-size: 18px; }
.sidebar-brand .logo-mark { width: 34px; height: 34px; border-radius: 10px; background: linear-gradient(135deg, var(--primary-dark), var(--primary)); display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 700; font-size: 14px; }
.nav-group { margin-bottom: 18px; }
.nav-group-label { font-size: 11px; font-weight: 600; letter-spacing: .06em; color: var(--text-muted); padding: 0 12px 8px; text-transform: uppercase; }
.nav-item { display: flex; align-items: center; gap: 10px; padding: 9px 12px; border-radius: 12px; color: var(--text-secondary); font-weight: 500; font-size: 13.5px; transition: background .15s ease; }
.nav-item:hover { background: #EEF4F4; }
.nav-item.active { background: var(--primary-light); color: var(--primary-dark); font-weight: 600; position: relative; }
.nav-item.active::before { content: ""; position: absolute; left: -16px; top: 8px; bottom: 8px; width: 3px; background: var(--primary); border-radius: 0 3px 3px 0; }
.nav-item.disabled { opacity: .45; cursor: not-allowed; }
.nav-item .count { margin-left: auto; font-size: 11.5px; font-weight: 700; background: var(--primary); color: #fff; border-radius: 20px; padding: 1px 8px; }
.nav-icon { width: 28px; height: 28px; border-radius: 8px; background: #F1F5F9; display: flex; align-items: center; justify-content: center; flex-shrink: 0; color: var(--text-secondary); }
.nav-icon svg { width: 15px; height: 15px; }
.nav-item.active .nav-icon { background: #fff; color: var(--primary-dark); }
.sidebar-foot { margin-top: 8px; padding: 12px; border-radius: 14px; background: var(--primary-light); font-size: 12px; color: var(--text-secondary); }
.sidebar-foot b { color: var(--text); }

/* Mobile drawer (same contract as partials/sidebar.php: body.nav-open + overlay). */
.doc-mobile-bar { display: none; }
.doc-mobile-bar .hamburger { width: 40px; height: 40px; border-radius: 10px; border: 1px solid var(--border); background: var(--card); display: flex; align-items: center; justify-content: center; color: var(--text-secondary); cursor: pointer; flex-shrink: 0; }
.doc-mobile-bar .hamburger svg { width: 20px; height: 20px; }
.doc-mobile-bar .m-brand { display: flex; align-items: center; gap: 10px; font-weight: 700; font-size: 16px; }
.doc-mobile-bar .m-brand .logo-mark { width: 30px; height: 30px; border-radius: 9px; background: linear-gradient(135deg, var(--primary-dark), var(--primary)); display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 700; font-size: 12px; }
.sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(15,23,42,.45); z-index: 40; }
@media (max-width: 900px) {
    .app { grid-template-columns: 1fr; }
    .content { padding: 20px 18px 48px; }
    .doc-mobile-bar { display: flex; align-items: center; gap: 14px; position: sticky; top: 0; z-index: 30; padding: 12px 16px; background: var(--card); border-bottom: 1px solid var(--border); }
    .sidebar { position: fixed; top: 0; left: 0; z-index: 50; width: min(84vw, 300px); height: 100vh; transform: translateX(-100%); transition: transform .22s ease; box-shadow: var(--shadow-lg); }
    body.nav-open .sidebar { transform: translateX(0); }
    body.nav-open .sidebar-overlay { display: block; }
    .sidebar .nav-item.active::before { left: -8px; }
}
</style>

<div class="doc-mobile-bar">
    <button type="button" class="hamburger" aria-label="Open navigation" aria-expanded="false" onclick="himsToggleNav()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
    </button>
    <a class="m-brand" href="doctor.php"><span class="logo-mark">H</span> HIMS</a>
</div>
<div class="sidebar-overlay" onclick="himsCloseNav()"></div>

<aside class="sidebar" id="himsSidebar">
    <div class="sidebar-brand"><div class="logo-mark">H</div> HIMS</div>

    <div class="nav-group">
        <div class="nav-group-label">Clinical</div>
        <a class="nav-item <?= $dsActive === 'console' ? 'active' : '' ?>" href="doctor.php"><span class="nav-icon"><?= ds_icon('grid') ?></span> My Console</a>
        <a class="nav-item" href="doctor.php"><span class="nav-icon"><?= ds_icon('users') ?></span> My Queue <?php if ($dsWaitingCount): ?><span class="count"><?= $dsWaitingCount ?></span><?php endif; ?></a>
        <a class="nav-item disabled" href="#"><span class="nav-icon"><?= ds_icon('stetho') ?></span> Consultations</a>
        <a class="nav-item disabled" href="#"><span class="nav-icon"><?= ds_icon('file') ?></span> Prescriptions</a>
    </div>

    <div class="nav-group">
        <div class="nav-group-label">Records</div>
        <a class="nav-item" href="patients.php"><span class="nav-icon"><?= ds_icon('search') ?></span> Find Patient</a>
        <a class="nav-item <?= $dsActive === 'schedule' ? 'active' : '' ?>" href="my_schedule.php"><span class="nav-icon"><?= ds_icon('calendar') ?></span> My Schedule</a>
    </div>

    <div class="nav-group">
        <div class="nav-group-label">Analytics</div>
        <a class="nav-item <?= $dsActive === 'analytics' ? 'active' : '' ?>" href="doctor_analytics.php"><span class="nav-icon"><?= ds_icon('chart') ?></span> My Reports</a>
    </div>

    <div class="sidebar-foot">
        Signed in as <b><?= htmlspecialchars($dsUserName) ?></b><br>Doctor
    </div>
</aside>

<script>
// Mobile drawer for the doctor console sidebar (same contract as partials/sidebar.php).
function himsToggleNav() {
    var open = document.body.classList.toggle('nav-open');
    var btn = document.querySelector('.doc-mobile-bar .hamburger');
    if (btn) { btn.setAttribute('aria-expanded', open ? 'true' : 'false'); }
}
function himsCloseNav() {
    document.body.classList.remove('nav-open');
    var btn = document.querySelector('.doc-mobile-bar .hamburger');
    if (btn) { btn.setAttribute('aria-expanded', 'false'); }
}
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { himsCloseNav(); } });
document.querySelectorAll('#himsSidebar .nav-item:not(.disabled)').forEach(function (a) { a.addEventListener('click', himsCloseNav); });
</script>
