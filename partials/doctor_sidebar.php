<?php
/**
 * Doctor console sidebar — shared between doctor.php and doctor_analytics.php.
 *
 * The doctor console keeps its OWN clinical nav taxonomy (different from the
 * shared admin/reception partials/sidebar.php), so this partial carries both
 * the markup AND its CSS + mobile-drawer JS. Include it INSIDE <div class="app">
 * (it renders the mobile bar, the overlay and the <aside>), after setting:
 *
 *   $dsActive       — 'console' | 'analytics' | 'schedule' | 'patients' | 'profile' (which nav item highlights)
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
        'clock'   => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'user'    => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'bed'     => '<path d="M3 20v-8a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v8"/><path d="M3 16h18"/><path d="M7 10V7a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v3"/>',
    ];
    // No width/height attributes: `.nav-icon svg` in assets/app.css sizes these,
    // exactly as it does for the shared sidebar's sb_icon(). Hardcoding 18px
    // here was the second of the two icon dialects.
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . ($paths[$name] ?? '') . '</svg>';
}
?>
<?php /* Sidebar styling lives in assets/app.css (search "Sidebar - the app's
   ONE navigation"), shared with partials/sidebar.php. */ ?>

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
        <?php if (function_exists('has_permission') && has_permission('IPD_VIEW_WARD')): ?>
        <a class="nav-item <?= $dsActive === 'ipd' ? 'active' : '' ?>" href="ipd_admissions.php"><span class="nav-icon"><?= ds_icon('bed') ?></span> In-Door (IPD)</a>
        <?php endif; ?>
        <a class="nav-item disabled" href="#"><span class="nav-icon"><?= ds_icon('stetho') ?></span> Consultations</a>
        <a class="nav-item disabled" href="#"><span class="nav-icon"><?= ds_icon('file') ?></span> Prescriptions</a>
    </div>

    <div class="nav-group">
        <div class="nav-group-label">Records</div>
        <a class="nav-item <?= $dsActive === 'patients' ? 'active' : '' ?>" href="patients.php"><span class="nav-icon"><?= ds_icon('search') ?></span> Find Patient</a>
        <a class="nav-item <?= $dsActive === 'schedule' ? 'active' : '' ?>" href="my_schedule.php"><span class="nav-icon"><?= ds_icon('calendar') ?></span> My Schedule</a>
    </div>

    <div class="nav-group">
        <div class="nav-group-label">Analytics</div>
        <a class="nav-item <?= $dsActive === 'analytics' ? 'active' : '' ?>" href="doctor_analytics.php"><span class="nav-icon"><?= ds_icon('chart') ?></span> My Reports</a>
    </div>

    <div class="nav-group">
        <div class="nav-group-label">Account</div>
        <a class="nav-item <?= $dsActive === 'profile' ? 'active' : '' ?>" href="profile.php"><span class="nav-icon"><?= ds_icon('user') ?></span> My Profile</a>
    </div>

    <div class="sidebar-foot">
        Signed in as <b><?= htmlspecialchars($dsUserName) ?></b><br>Doctor
    </div>

    <?php require __DIR__ . '/view_toggle.php'; ?>
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
