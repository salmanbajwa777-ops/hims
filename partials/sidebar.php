<?php
/**
 * Shared application sidebar — the single primary navigation for every
 * logged-in HIMS page.
 *
 * Replaces the 8 hand-copied <aside class="sidebar"> blocks (which had drifted
 * into two icon dialects — emoji vs SVG — and different item lists for the same
 * role) with one data-driven, role-aware, responsive nav.
 *
 * DESKTOP (>=900px): fixed left column, width var(--sidebar-w).
 * MOBILE  (<900px):  off-canvas drawer. A top app-bar (brand + hamburger) is
 *                    shown; tapping the hamburger slides the drawer in over a
 *                    dimming overlay. Tap overlay / press Esc / tap a link to
 *                    close. This is the standard responsive breakpoint the app
 *                    now uses everywhere; before this partial there was NO
 *                    mobile navigation at all.
 *
 * Caller sets, before including:
 *   $navActive — slug of the current page: 'dashboard' | 'patients' | 'staff'
 *                | 'locations' | 'permissions' | 'checkout' | 'reports' ...
 *
 * Requires $pdo + session in scope (already true on every page). The caller's
 * page markup goes inside <div class="main">…</div>, which this partial opens;
 * the caller must close it. Layout contract:
 *
 *     require __DIR__ . '/partials/head.php';      // opens <body>
 *     $navActive = 'patients';
 *     require __DIR__ . '/partials/sidebar.php';    // renders <div class="app"><aside>…<div class="main">
 *     ... page content (typically a .content wrapper) ...
 *     </div></div>  <!-- .main + .app -->
 *     </body></html>
 */

$navActive  = $navActive ?? '';
$sbBaseRole = $_SESSION['base_role'] ?? '';
$sbIsAdmin  = $sbBaseRole === 'ADMIN';

// The "home" destination differs by role: admins land on the full dashboard,
// reception on their own console, nurses on the ward list. Keeps one nav
// definition working for all of them.
$sbHome = $sbBaseRole === 'RECEPTIONIST' ? 'receptionist.php'
        : ($sbBaseRole === 'DOCTOR' ? 'doctor.php'
        : ($sbBaseRole === 'NURSE' ? 'admissions.php' : 'dashboard.php'));

if (!function_exists('sb_icon')) {
    function sb_icon(string $name): string {
        $paths = [
            'grid'     => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
            'users'    => '<path d="M17 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
            'stetho'   => '<path d="M4.8 2.3A.3.3 0 1 0 5 2H4a2 2 0 0 0-2 2v5a6 6 0 0 0 6 6 6 6 0 0 0 6-6V4a2 2 0 0 0-2-2h-1a.2.2 0 1 0 .3.3"/><path d="M8 15v1a6 6 0 0 0 6 6 6 6 0 0 0 6-6v-4"/><circle cx="20" cy="10" r="2"/>',
            'receipt'  => '<path d="M4 2v20l3-2 3 2 3-2 3 2 3-2V2l-3 2-3-2-3 2-3-2Z"/><path d="M8 7h8M8 11h8M8 15h5"/>',
            'pin'      => '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
            'lock'     => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
            'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
            'bed'      => '<path d="M3 20v-8a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v8"/><path d="M3 16h18"/><path d="M7 10V7a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v3"/>',
            'chart'    => '<path d="M3 3v18h18"/><rect x="7" y="12" width="3" height="6"/><rect x="12" y="8" width="3" height="10"/><rect x="17" y="5" width="3" height="13"/>',
            'percent'  => '<path d="M19 5L5 19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
        ];
        $p = $paths[$name] ?? '';
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $p . '</svg>';
    }
}

/**
 * The nav model — the ONE definition of what's in the sidebar and for whom.
 * Each group: label + items. Each item: slug, label, icon, href, and optional
 * 'admin' => true (admin-only), 'roles' => [...] (only these base roles see
 * it), or 'disabled' => true (not built yet, shown greyed with a tooltip
 * rather than silently dropped).
 *
 * Nurses get a Nursing group instead of Reception: their work is the ward,
 * not registration/checkout. Their Dashboard item points at admissions too
 * (via $sbHome), so 'admissions' is dropped from their duplicate listing.
 */
$sbGroups = [
    [
        'label' => 'Overview',
        'items' => [
            ['slug' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'grid', 'href' => $sbHome],
        ],
    ],
    [
        'label' => 'Nursing',
        'roles' => ['NURSE'],
        'items' => [
            ['slug' => 'admissions', 'label' => 'Ward / Admissions', 'icon' => 'bed', 'href' => 'admissions.php'],
        ],
    ],
    [
        'label' => 'Reception',
        // Everyone except nurses (their ward work lives in the Nursing group;
        // registration/checkout is not theirs to see).
        'roles' => ['ADMIN', 'MANAGER', 'RECEPTIONIST', 'DOCTOR', 'ACCOUNTANT'],
        'items' => [
            ['slug' => 'patients',    'label' => 'Patients',        'icon' => 'users',    'href' => 'patients.php'],
            ['slug' => 'checkout',    'label' => 'Checkout & Billing','icon'=> 'receipt',  'href' => 'checkout.php'],
            ['slug' => 'admissions',  'label' => 'Admissions',      'icon' => 'bed',      'href' => 'admissions.php'],
            ['slug' => 'bookings',    'label' => 'Bookings',        'icon' => 'calendar', 'href' => 'bookings.php'],
        ],
    ],
    [
        'label' => 'Management',
        'admin' => true,
        'items' => [
            ['slug' => 'staff',       'label' => 'Staff & Doctors', 'icon' => 'stetho',  'href' => 'staff.php'],
            ['slug' => 'locations',   'label' => 'Cities & Areas',  'icon' => 'pin',     'href' => 'locations.php'],
            ['slug' => 'er_services', 'label' => 'ER Services & Rates','icon' => 'receipt','href' => 'er_services.php'],
            ['slug' => 'discount_categories', 'label' => 'Discount Categories', 'icon' => 'percent', 'href' => 'discount_categories.php'],
            ['slug' => 'procedure_master', 'label' => 'Procedures',  'icon' => 'receipt', 'href' => 'procedure_master.php'],
            ['slug' => 'permissions', 'label' => 'Permissions',     'icon' => 'lock',    'href' => 'permissions.php'],
        ],
    ],
    [
        'label' => 'Analytics',
        'admin' => true,
        'items' => [
            ['slug' => 'discount_report', 'label' => 'Discount Report', 'icon' => 'percent', 'href' => 'discount_report.php'],
            ['slug' => 'reports', 'label' => 'Reports', 'icon' => 'chart', 'href' => '#', 'disabled' => true],
        ],
    ],
];

/** Render the nav groups once; reused verbatim by the desktop rail and the
 *  mobile drawer so the two can never drift. */
$sbRenderNav = function () use ($sbGroups, $sbIsAdmin, $sbBaseRole, $navActive) {
    foreach ($sbGroups as $g) {
        if (!empty($g['admin']) && !$sbIsAdmin) { continue; }
        if (!empty($g['roles']) && !in_array($sbBaseRole, $g['roles'], true)) { continue; }
        echo '<div class="nav-group"><div class="nav-group-label">' . htmlspecialchars($g['label']) . '</div>';
        foreach ($g['items'] as $it) {
            $cls = 'nav-item';
            if (!empty($it['disabled']))     { $cls .= ' disabled'; }
            if ($navActive === $it['slug'])  { $cls .= ' active'; }
            $href = !empty($it['disabled']) ? '#' : $it['href'];
            $attr = !empty($it['disabled']) ? ' title="Not built yet" aria-disabled="true"' : '';
            if ($navActive === $it['slug'])  { $attr .= ' aria-current="page"'; }
            echo '<a class="' . $cls . '" href="' . htmlspecialchars($href) . '"' . $attr . '>'
               . '<span class="nav-icon">' . sb_icon($it['icon']) . '</span> '
               . htmlspecialchars($it['label']) . '</a>';
        }
        echo '</div>';
    }
};
?>
<style>
/* ---------- Sidebar (shared) ---------- */
.sidebar {
    background: var(--card); border-right: 1px solid var(--border);
    padding: 24px 16px; position: sticky; top: 0; height: 100vh; overflow-y: auto;
}
.sidebar-brand { display: flex; align-items: center; gap: 10px; padding: 0 8px 24px; font-weight: 700; font-size: 18px; }
.sidebar-brand .logo-mark {
    width: 34px; height: 34px; border-radius: 10px;
    background: linear-gradient(135deg, var(--primary-dark), var(--primary));
    display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 700; font-size: 14px;
}
.nav-group { margin-bottom: 18px; }
.nav-group-label { font-size: 11px; font-weight: 600; letter-spacing: .06em; color: var(--text-muted); padding: 0 12px 8px; text-transform: uppercase; }
.nav-item { display: flex; align-items: center; gap: 10px; padding: 9px 12px; border-radius: 12px; color: var(--text-secondary); font-weight: 500; font-size: 13.5px; transition: background .15s ease; }
.nav-item:hover { background: var(--bg); }
.nav-item.active { background: var(--primary-light); color: var(--primary-dark); font-weight: 600; position: relative; }
.nav-item.active::before { content: ""; position: absolute; left: -16px; top: 8px; bottom: 8px; width: 3px; background: var(--primary); border-radius: 0 3px 3px 0; }
.nav-item.disabled { opacity: .45; cursor: not-allowed; }
.nav-icon { width: 28px; height: 28px; border-radius: 8px; background: #F1F5F9; display: flex; align-items: center; justify-content: center; flex-shrink: 0; color: var(--text-secondary); }
.nav-icon svg { width: 15px; height: 15px; }
.nav-item.active .nav-icon { background: #fff; color: var(--primary-dark); }

/* ---------- Mobile top app-bar (hidden on desktop) ---------- */
.mobile-bar { display: none; }
.mobile-bar .hamburger {
    width: 40px; height: 40px; border-radius: 10px; border: 1px solid var(--border);
    background: var(--card); display: flex; align-items: center; justify-content: center;
    color: var(--text-secondary); cursor: pointer; flex-shrink: 0;
}
.mobile-bar .hamburger svg { width: 20px; height: 20px; }
.mobile-bar .m-brand { display: flex; align-items: center; gap: 10px; font-weight: 700; font-size: 16px; }
.mobile-bar .m-brand .logo-mark {
    width: 30px; height: 30px; border-radius: 9px;
    background: linear-gradient(135deg, var(--primary-dark), var(--primary));
    display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 700; font-size: 12px;
}
.sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(15,23,42,.45); z-index: 40; }

/* ---------- Responsive: standard 900px breakpoint ---------- */
@media (max-width: 900px) {
    .mobile-bar {
        display: flex; align-items: center; gap: 14px; position: sticky; top: 0; z-index: 30;
        padding: 12px 16px; background: var(--card); border-bottom: 1px solid var(--border);
    }
    /* Off-canvas drawer */
    .sidebar {
        position: fixed; top: 0; left: 0; z-index: 50; width: min(84vw, 300px); height: 100vh;
        transform: translateX(-100%); transition: transform .22s ease; box-shadow: var(--shadow-lg);
    }
    body.nav-open .sidebar { transform: translateX(0); }
    body.nav-open .sidebar-overlay { display: block; }
    /* The active-item accent bar is clipped off-canvas on desktop; inside the
       drawer bring it inside the padding so it stays visible. */
    .sidebar .nav-item.active::before { left: -8px; }
}
</style>

<div class="app">
    <div class="mobile-bar">
        <button type="button" class="hamburger" aria-label="Open navigation" aria-expanded="false" onclick="himsToggleNav()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
        </button>
        <a class="m-brand" href="<?= htmlspecialchars($sbHome) ?>"><span class="logo-mark">H</span> HIMS</a>
    </div>

    <div class="sidebar-overlay" onclick="himsCloseNav()"></div>

    <aside class="sidebar" id="himsSidebar">
        <a class="sidebar-brand" href="<?= htmlspecialchars($sbHome) ?>">
            <div class="logo-mark">H</div> HIMS
        </a>
        <?php $sbRenderNav(); ?>
    </aside>

    <div class="main">
<?php /* caller closes .main and .app */ ?>

<script>
function himsToggleNav() {
    var open = document.body.classList.toggle('nav-open');
    var btn = document.querySelector('.mobile-bar .hamburger');
    if (btn) { btn.setAttribute('aria-expanded', open ? 'true' : 'false'); }
}
function himsCloseNav() {
    document.body.classList.remove('nav-open');
    var btn = document.querySelector('.mobile-bar .hamburger');
    if (btn) { btn.setAttribute('aria-expanded', 'false'); }
}
// Close on Esc, and after tapping any real nav link inside the drawer.
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { himsCloseNav(); } });
document.querySelectorAll('#himsSidebar .nav-item:not(.disabled)').forEach(function (a) {
    a.addEventListener('click', himsCloseNav);
});
</script>
