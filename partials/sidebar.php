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

// ---- DOCTOR delegation -----------------------------------------------------
// A doctor must NEVER land in the reception/admin nav: their world is the
// clinical sidebar (My Console / My Queue / Find Patient / My Schedule /
// My Reports / My Profile). Any shared page a doctor opens (patients.php,
// profile.php, ...) therefore renders doctor_sidebar.php here instead —
// one fix for every current and future include of this partial.
// Layout contracts differ (this partial opens .app/.main itself; the doctor
// partial is included INSIDE .app with .main opened by the caller), so the
// wrappers are emitted around it to keep the page markup identical.
if ($sbBaseRole === 'DOCTOR') {
    // Map the caller's reception slug onto the doctor nav's active states;
    // unmapped pages simply highlight nothing.
    $dsActive = ['patients' => 'patients', 'profile' => 'profile', 'ipd' => 'ipd'][$navActive] ?? '';
    if (!isset($dsUserName)) {
        $sbMe = $pdo->prepare('SELECT name FROM users WHERE id = ?');
        $sbMe->execute([$_SESSION['user_id']]);
        $dsUserName = (string) $sbMe->fetchColumn();
    }
    echo '<div class="app">';
    require __DIR__ . '/doctor_sidebar.php';
    echo '<div class="main">';
    return; // caller closes .main and .app exactly as with the shared nav
}

// "Home" destination. DOCTOR + ADMIN are role-driven; STAFF is chosen by
// capability now that the reception/nurse sub-roles are gone — the reception
// work-queue if they register patients, else the ward list if they do
// admissions, else the dashboard. Mirrors index.php's landing_page_for_role().
if ($sbBaseRole === 'DOCTOR') {
    $sbHome = 'doctor.php';
} elseif ($sbBaseRole !== 'ADMIN' && has_permission('RECEPTION_REGISTER_PATIENTS')) {
    $sbHome = 'receptionist.php';
} elseif ($sbBaseRole !== 'ADMIN' && has_permission('NURSING_RECORD_ADMISSIONS')) {
    $sbHome = 'admissions.php';
} else {
    $sbHome = 'dashboard.php';
}

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
            'user'     => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
            'clock'    => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
            'wallet'   => '<path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/>',
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
        // One work group for all STAFF. Every item is gated on the SAME permission
        // its page requires, so a person sees exactly what they can open — no role
        // lists, no nurse-vs-reception split. Admin holds every key, so admin sees
        // all of them. Items with no 'perm' (like the disabled In-Door stub) show
        // to anyone who reaches the group; the group itself only appears if at
        // least one of its items is visible to this user.
        'label' => 'Workspace',
        'items' => [
            ['slug' => 'patients',    'label' => 'Patients',        'icon' => 'users',    'href' => 'patients.php',
             'perm' => 'RECEPTION_REGISTER_PATIENTS'],
            ['slug' => 'doctor_timings', 'label' => 'Doctor Timings', 'icon' => 'clock',  'href' => 'doctor_timings.php',
             'perm' => 'RECEPTION_EDIT_DOCTOR_TIMINGS'],
            ['slug' => 'checkout',    'label' => 'Checkout & Billing','icon'=> 'receipt',  'href' => 'checkout.php',
             'perm' => 'RECEPTION_GENERATE_INVOICES'],
            ['slug' => 'admissions',  'label' => 'Admissions',      'icon' => 'bed',      'href' => 'admissions.php',
             'perm' => 'NURSING_RECORD_ADMISSIONS'],
            ['slug' => 'ipd',         'label' => 'In-Door (IPD)',   'icon' => 'bed',      'href' => 'ipd_admissions.php',
             'perm' => 'IPD_VIEW_WARD'],
            ['slug' => 'bookings',    'label' => 'Bookings',        'icon' => 'calendar', 'href' => 'bookings.php',
             'perm' => 'RECEPTION_MANAGE_BOOKINGS'],
            ['slug' => 'expenses',    'label' => 'Expenses',        'icon' => 'wallet',   'href' => 'expenses.php',
             'perm' => 'FINANCIAL_POST_EXPENSES'],
            ['slug' => 'shift_closing', 'label' => 'Day Closing',   'icon' => 'wallet',   'href' => 'shift_closing.php',
             'perm' => 'RECEPTION_CLOSE_DAY'],
        ],
    ],
    [
        'label' => 'Management',
        'admin' => true,
        'items' => [
            ['slug' => 'handovers',   'label' => 'Cash Handovers',  'icon' => 'wallet',  'href' => 'admin_handovers.php'],
            ['slug' => 'staff',       'label' => 'Staff & Doctors', 'icon' => 'stetho',  'href' => 'staff.php'],
            ['slug' => 'locations',   'label' => 'Cities & Areas',  'icon' => 'pin',     'href' => 'locations.php'],
            ['slug' => 'er_services', 'label' => 'ER Services & Rates','icon' => 'receipt','href' => 'er_services.php'],
            ['slug' => 'ipd_ward_rates', 'label' => 'In-Door Ward Rates','icon' => 'bed','href' => 'ipd_ward_rates.php'],
            ['slug' => 'discount_categories', 'label' => 'Discount Categories', 'icon' => 'percent', 'href' => 'discount_categories.php'],
            ['slug' => 'expense_categories', 'label' => 'Expense Categories', 'icon' => 'wallet', 'href' => 'expense_categories.php'],
            ['slug' => 'procedure_master', 'label' => 'Procedures',  'icon' => 'receipt', 'href' => 'procedure_master.php'],
            ['slug' => 'sheet_log',   'label' => 'Google Sheet Sync','icon' => 'receipt', 'href' => 'sheet_log.php'],
            ['slug' => 'permissions', 'label' => 'Permissions',     'icon' => 'lock',    'href' => 'permissions.php'],
        ],
    ],
    [
        'label' => 'Analytics',
        'admin' => true,
        'items' => [
            ['slug' => 'expense_report', 'label' => 'Expense Report', 'icon' => 'chart', 'href' => 'expense_report.php',
             'perm' => 'FINANCIAL_VIEW_CLINIC_REPORTS'],
            ['slug' => 'discount_report', 'label' => 'Discount Report', 'icon' => 'percent', 'href' => 'discount_report.php'],
            ['slug' => 'reports', 'label' => 'More Reports', 'icon' => 'chart', 'href' => '#', 'disabled' => true],
        ],
    ],
    [
        'label' => 'Account',
        // Every role: self-service name/email/phone/password (profile.php).
        'items' => [
            ['slug' => 'profile', 'label' => 'My Profile', 'icon' => 'user', 'href' => 'profile.php'],
        ],
    ],
];

/** Render the nav groups once; reused verbatim by the desktop rail and the
 *  mobile drawer so the two can never drift. */
// Is a single item visible to this user? Role OR permission grants it.
// 'perm' gates on an ACTUAL permission, not a role — so a per-user grant (like a
// nurse given RECEPTION_CLOSE_DAY) surfaces the link even though the role
// wouldn't. Admin holds every key, so perm checks pass for admins automatically.
$sbItemVisible = function (array $it) use ($sbBaseRole) {
    if (!empty($it['roles']) && !in_array($sbBaseRole, $it['roles'], true)) { return false; }
    if (!empty($it['perm']) && !has_permission($it['perm'])) { return false; }
    return true;
};

$sbRenderNav = function () use ($sbGroups, $sbIsAdmin, $sbBaseRole, $navActive, $sbItemVisible) {
    foreach ($sbGroups as $g) {
        // An 'admin' group is admin-by-default, but a perm-granted item inside it
        // still surfaces — same principle as the role gate below. Without this a
        // MANAGER holding FINANCIAL_VIEW_CLINIC_REPORTS could reach the Expense
        // Report by URL yet never see a link to it.
        if (!empty($g['admin']) && !$sbIsAdmin) {
            $permItems = array_filter($g['items'], fn($it) => !empty($it['perm']) && has_permission($it['perm']));
            if (!$permItems) { continue; }
            $g['items'] = $permItems;
        }
        // A group's role gate is the DEFAULT audience, not an absolute lock: a
        // user outside those roles may still enter the group if they hold a
        // perm-granted item inside it (e.g. a nurse with Day Closing sees the
        // Reception group, but ONLY the Day Closing link — the role-only items
        // like Patients/Checkout stay hidden by the per-item check below).
        $groupRoleOk = empty($g['roles']) || in_array($sbBaseRole, $g['roles'], true);
        $visibleItems = array_filter($g['items'], $sbItemVisible);
        if (!$groupRoleOk) {
            // Keep only perm-granted items for an out-of-role visitor; if none, skip.
            $visibleItems = array_filter($visibleItems, fn($it) => !empty($it['perm']));
        }
        if (!$visibleItems) { continue; }
        echo '<div class="nav-group"><div class="nav-group-label">' . htmlspecialchars($g['label']) . '</div>';
        foreach ($visibleItems as $it) {
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
<?php /* Sidebar styling lives in assets/app.css (search "Sidebar - the app's
   ONE navigation"). Both this partial and doctor_sidebar.php used to carry
   their own full copy of these rules, which is exactly how the two drifted
   into different icon dialects. They now emit markup only. */ ?>

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
        <?php require __DIR__ . '/view_toggle.php'; ?>
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
