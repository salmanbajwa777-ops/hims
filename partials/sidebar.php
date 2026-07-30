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
    $dsActive = ['patients' => 'patients', 'profile' => 'profile', 'ipd' => 'ipd',
                 'dental_treatment' => 'dental_treatment', 'dental_accounts' => 'dental_accounts',
                 'dental_lab' => 'dental_lab'][$navActive] ?? '';
    if (!isset($dsUserName)) {
        // specialty comes along so the doctor nav can hide the dental section
        // from non-dentists: the DENTAL_* keys are DOCTOR-role defaults, so a
        // paediatrician holds them too and would otherwise see a tooth chart.
        $sbMe = $pdo->prepare('SELECT name, specialty FROM users WHERE id = ?');
        $sbMe->execute([$_SESSION['user_id']]);
        $sbMeRow = $sbMe->fetch() ?: [];
        $dsUserName = (string) ($sbMeRow['name'] ?? '');
        $dsSpecialty = (string) ($sbMeRow['specialty'] ?? '');
    }
    echo '<div class="app">';
    require __DIR__ . '/doctor_sidebar.php';
    echo '<div class="main">';
    require __DIR__ . '/quick_header.php'; // same app bar as every other role
    return; // caller closes .main and .app exactly as with the shared nav
}

// "Home" destination. DOCTOR + ADMIN are role-driven; STAFF is chosen by
// capability now that the reception/nurse sub-roles are gone — the reception
// work-queue if they register patients, else the admitted-patient list if they do
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
            'tooth'    => '<path d="M12 5.5c-1.5-1.2-3-1.8-4.5-1.5C5.6 4.4 4.5 6 4.5 8.3c0 1.6.4 3 .8 4.4.5 1.9.7 3.6.9 5.3.1 1.2.6 2 1.5 2s1.3-.8 1.6-2l.8-3.4c.2-.8.5-1.2.9-1.2s.7.4.9 1.2l.8 3.4c.3 1.2.7 2 1.6 2s1.4-.8 1.5-2c.2-1.7.4-3.4.9-5.3.4-1.4.8-2.8.8-4.4 0-2.3-1.1-3.9-3-4.3-1.5-.3-3 .3-4.5 1.5Z"/>',
            'wallet'   => '<path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/>',
            'gear'     => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/>',
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
 * Nurses get a Nursing group instead of Reception: their work is the bedside,
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
            // Consents printed with a procedure bill, and the signed scans filed
            // back against them. Workspace rather than Settings: chasing the
            // unsigned ones is front-desk work, not configuration.
            ['slug' => 'procedure_consents', 'label' => 'Consents', 'icon' => 'receipt', 'href' => 'procedure_consents.php',
             'perm' => 'RECEPTION_MANAGE_CONSENT'],
            // Money handling is the ADMIN's Finances group (below). For everyone
            // else these two stay right here in Workspace, because for a
            // receptionist posting an expense and closing the till IS the day's
            // work, not a separate finance function. 'notAdmin' keeps the admin
            // copy from appearing twice.
            ['slug' => 'expenses',    'label' => 'Expenses',        'icon' => 'wallet',   'href' => 'expenses.php',
             'perm' => 'FINANCIAL_POST_EXPENSES', 'notAdmin' => true],
            ['slug' => 'shift_closing', 'label' => 'Day Closing',   'icon' => 'clock',    'href' => 'shift_closing.php',
             'perm' => 'RECEPTION_CLOSE_DAY', 'notAdmin' => true],
        ],
    ],
    [
        // Dental. Its own group rather than three more Workspace rows: dental is
        // a distinct workflow (chart -> quote -> pay down -> lab), and on a
        // clinic that does no dentistry the whole group disappears — the
        // renderer drops a group whose items all filter out on permission.
        'label' => 'Dental',
        'items' => [
            ['slug' => 'dental_treatment', 'label' => 'Treatment Records', 'icon' => 'tooth',
             'href' => 'dental_treatment.php', 'perm' => 'DENTAL_RECORD_TREATMENT'],
            ['slug' => 'dental_accounts',  'label' => 'Dental Accounts',   'icon' => 'receipt',
             'href' => 'dental_accounts.php', 'perm' => 'DENTAL_VIEW_ACCOUNTS'],
            ['slug' => 'dental_lab',       'label' => 'Lab Work',          'icon' => 'clock',
             'href' => 'dental_lab.php',      'perm' => 'DENTAL_MANAGE_LAB_WORK'],
        ],
    ],
    [
        // Admin-only money group: everything that MOVES or SETTLES money —
        // post spending, close the till, receive the cash, pay the doctors,
        // close the books, file the tax. Analytics is for reading numbers; if a
        // screen writes a financial record or someone acts on it with a
        // chequebook, it belongs here.
        // Collapsible for the same reason as Settings — see that group's note.
        'label' => 'Finances',
        'admin' => true,
        'items' => [
            ['slug' => 'finances', 'label' => 'Finances', 'icon' => 'wallet', 'href' => '#', 'children' => [
                ['slug' => 'expenses',    'label' => 'Expenses',        'icon' => 'wallet',  'href' => 'expenses.php'],
                ['slug' => 'shift_closing', 'label' => 'Day Closing',   'icon' => 'clock',   'href' => 'shift_closing.php'],
                ['slug' => 'handovers',   'label' => 'Cash Handovers',  'icon' => 'wallet',  'href' => 'admin_handovers.php'],
                // Statement -> payout -> books -> tax: the month-end sequence,
                // in the order it is actually worked through.
                ['slug' => 'doctor_share_statement', 'label' => 'Doctor Share Statement', 'icon' => 'stetho', 'href' => 'doctor_share_statement.php',
                 'perm' => 'FINANCIAL_VIEW_ALL_COMMISSIONS'],
                ['slug' => 'doctor_payouts', 'label' => 'Doctor Payouts', 'icon' => 'wallet', 'href' => 'doctor_payouts.php',
                 'perm' => 'FINANCIAL_RUN_PAYOUT'],
                ['slug' => 'pnl_report', 'label' => 'Profit & Loss', 'icon' => 'chart', 'href' => 'pnl_report.php',
                 'perm' => 'FINANCIAL_VIEW_DAILY_PL'],
                ['slug' => 'tax_register', 'label' => 'Tax Register', 'icon' => 'receipt', 'href' => 'tax_register.php',
                 'perm' => 'FINANCIAL_VIEW_ALL_COMMISSIONS'],
            ]],
        ],
    ],
    [
        'label' => 'Management',
        'admin' => true,
        'items' => [
            ['slug' => 'sheet_log',   'label' => 'Google Sheet Sync','icon' => 'receipt', 'href' => 'sheet_log.php'],
            // These eight configuration screens used to sit flat in this group,
            // which made Management a wall of ten links where only two were
            // day-to-day work. They are now ONE collapsible parent: the setup
            // you touch rarely folds away, and the renderer auto-expands the
            // parent when the current page is one of its children.
            ['slug' => 'settings', 'label' => 'Settings', 'icon' => 'gear', 'href' => '#', 'children' => [
                ['slug' => 'staff',       'label' => 'Staff & Doctors', 'icon' => 'stetho',  'href' => 'staff.php'],
                ['slug' => 'permissions', 'label' => 'Permissions',     'icon' => 'lock',    'href' => 'permissions.php'],
                ['slug' => 'locations',   'label' => 'Cities & Areas',  'icon' => 'pin',     'href' => 'locations.php'],
                ['slug' => 'er_services', 'label' => 'ER Services & Rates','icon' => 'receipt','href' => 'er_services.php'],
                ['slug' => 'ipd_room_rates', 'label' => 'In-Door Room Categories & Rates','icon' => 'bed','href' => 'ipd_room_rates.php'],
                ['slug' => 'discount_categories', 'label' => 'Discount Categories', 'icon' => 'percent', 'href' => 'discount_categories.php'],
                ['slug' => 'expense_categories', 'label' => 'Expense Categories', 'icon' => 'wallet', 'href' => 'expense_categories.php'],
                ['slug' => 'procedure_master', 'label' => 'Procedures & Dental Catalogue',  'icon' => 'receipt', 'href' => 'procedure_master.php'],
            ]],
        ],
    ],
    [
        'label' => 'Analytics',
        'admin' => true,
        'items' => [
            // Read-only views. Anything that SETTLES money — payouts, the
            // month-end close, the tax register — lives in Finances instead.
            ['slug' => 'analytics', 'label' => 'Analytics', 'icon' => 'chart', 'href' => '#', 'children' => [
                ['slug' => 'income_report', 'label' => 'Income Report', 'icon' => 'wallet', 'href' => 'income_report.php',
                 'perm' => 'FINANCIAL_VIEW_CLINIC_REPORTS'],
                ['slug' => 'expense_report', 'label' => 'Expense Report', 'icon' => 'chart', 'href' => 'expense_report.php',
                 'perm' => 'FINANCIAL_VIEW_CLINIC_REPORTS'],
                ['slug' => 'discount_report', 'label' => 'Discount Report', 'icon' => 'percent', 'href' => 'discount_report.php'],
                ['slug' => 'reports', 'label' => 'More Reports', 'icon' => 'chart', 'href' => '#', 'disabled' => true],
            ]],
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
$sbItemVisible = function (array $it) use ($sbBaseRole, $sbIsAdmin) {
    // 'notAdmin': this item exists elsewhere in the admin's nav (Finances), so
    // hide the non-admin copy from admins instead of listing it twice.
    if (!empty($it['notAdmin']) && $sbIsAdmin) { return false; }
    if (!empty($it['roles']) && !in_array($sbBaseRole, $it['roles'], true)) { return false; }
    if (!empty($it['perm']) && !has_permission($it['perm'])) { return false; }
    return true;
};

// The drawer and the desktop rail render the SAME nav markup, so every id must
// be unique per rendering or the second copy's collapse toggle would target the
// first copy's submenu. This counter suffixes them.
$sbSubmenuSeq = 0;

$sbRenderNav = function () use ($sbGroups, $sbIsAdmin, $sbBaseRole, $navActive, $sbItemVisible, &$sbSubmenuSeq) {
    foreach ($sbGroups as $g) {
        // An 'admin' group is admin-by-default, but a perm-granted item inside it
        // still surfaces — same principle as the role gate below. Without this a
        // MANAGER holding FINANCIAL_VIEW_CLINIC_REPORTS could reach the Expense
        // Report by URL yet never see a link to it.
        if (!empty($g['admin']) && !$sbIsAdmin) {
            $permItems = [];
            foreach ($g['items'] as $it) {
                // A collapsible parent survives if ANY of its children is
                // perm-granted, and is then narrowed to just those children.
                if (!empty($it['children'])) {
                    $kids = array_filter($it['children'], fn($k) => !empty($k['perm']) && has_permission($k['perm']));
                    if ($kids) { $it['children'] = $kids; $permItems[] = $it; }
                    continue;
                }
                if (!empty($it['perm']) && has_permission($it['perm'])) { $permItems[] = $it; }
            }
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
            // A collapsible parent has no 'perm' of its own — it qualifies on its
            // children, which the per-item pass above has already filtered.
            $visibleItems = array_filter($visibleItems, fn($it) => !empty($it['perm']) || !empty($it['children']));
        }
        if (!$visibleItems) { continue; }
        // Suppress the group heading when the group is nothing but one
        // collapsible parent of the same name — otherwise Finances and
        // Analytics each render an uppercase "FINANCES" label directly above a
        // "Finances" button, saying the same word twice in two type styles.
        // Management keeps its heading: it holds Google Sheet Sync alongside
        // the Settings parent, so the label still groups more than one thing.
        $sbOnlyItem  = count($visibleItems) === 1 ? reset($visibleItems) : null;
        $sbHideLabel = $sbOnlyItem
            && !empty($sbOnlyItem['children'])
            && $sbOnlyItem['label'] === $g['label'];
        echo '<div class="nav-group">';
        if (!$sbHideLabel) {
            echo '<div class="nav-group-label">' . htmlspecialchars($g['label']) . '</div>';
        }
        foreach ($visibleItems as $it) {
            // ---- Collapsible parent (e.g. Settings) ----------------------
            // Children are filtered by exactly the same visibility rule as
            // top-level items, so a non-admin who was granted just one of them
            // sees the Settings parent with only that one link inside. A parent
            // whose children ALL filter out is dropped entirely rather than
            // rendering an empty disclosure.
            if (!empty($it['children'])) {
                $kids = array_filter($it['children'], $sbItemVisible);
                // A parent offering nothing but not-built-yet stubs is a
                // disclosure that opens onto a dead end — drop it entirely.
                if (!array_filter($kids, fn($k) => empty($k['disabled']))) { continue; }
                $openHere = false;
                foreach ($kids as $k) {
                    if ($navActive === $k['slug']) { $openHere = true; break; }
                }
                $subId = 'sbSub' . (++$sbSubmenuSeq);
                echo '<button type="button" class="nav-item nav-parent' . ($openHere ? ' open' : '') . '"'
                   . ' aria-expanded="' . ($openHere ? 'true' : 'false') . '" aria-controls="' . $subId . '"'
                   . ' onclick="himsToggleSub(this)">'
                   . '<span class="nav-icon">' . sb_icon($it['icon']) . '</span> '
                   . '<span class="nav-parent-label">' . htmlspecialchars($it['label']) . '</span>'
                   . '<span class="nav-caret"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg></span>'
                   . '</button>';
                echo '<div class="nav-sub' . ($openHere ? ' open' : '') . '" id="' . $subId . '">';
                foreach ($kids as $k) {
                    // Children honour 'disabled' exactly as top-level items do —
                    // without this a not-built-yet child (More Reports) would
                    // render as a live link straight to '#'.
                    $kcls = 'nav-item nav-child';
                    if (!empty($k['disabled']))    { $kcls .= ' disabled'; }
                    if ($navActive === $k['slug']) { $kcls .= ' active'; }
                    $khref = !empty($k['disabled']) ? '#' : $k['href'];
                    // Sub-item labels are the longest in the tree and a very long
                    // one ellipsises, so carry the full text as a tooltip. The
                    // disabled reason wins where both would apply.
                    $kattr = !empty($k['disabled'])
                        ? ' title="Not built yet" aria-disabled="true"'
                        : ' title="' . htmlspecialchars($k['label']) . '"';
                    if ($navActive === $k['slug']) { $kattr .= ' aria-current="page"'; }
                    echo '<a class="' . $kcls . '" href="' . htmlspecialchars($khref) . '"' . $kattr . '>'
                       . '<span class="nav-icon">' . sb_icon($k['icon']) . '</span> '
                       . '<span class="nav-label">' . htmlspecialchars($k['label']) . '</span></a>';
                }
                echo '</div>';
                continue;
            }

            $cls = 'nav-item';
            if (!empty($it['disabled']))     { $cls .= ' disabled'; }
            if ($navActive === $it['slug'])  { $cls .= ' active'; }
            $href = !empty($it['disabled']) ? '#' : $it['href'];
            $attr = !empty($it['disabled'])
                ? ' title="Not built yet" aria-disabled="true"'
                : ' title="' . htmlspecialchars($it['label']) . '"';
            if ($navActive === $it['slug'])  { $attr .= ' aria-current="page"'; }
            echo '<a class="' . $cls . '" href="' . htmlspecialchars($href) . '"' . $attr . '>'
               . '<span class="nav-icon">' . sb_icon($it['icon']) . '</span> '
               . '<span class="nav-label">' . htmlspecialchars($it['label']) . '</span></a>';
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
<?php
/* The application bar, on EVERY page that has a sidebar — which is every
   logged-in page. It used to be opt-in: 15 pages required it by hand and the
   other 27 simply had no top bar, so search, alerts and logout came and went
   as you moved around the product. Including it here makes it structural: a
   page cannot lose the bar without also losing its navigation. The pages that
   still require it themselves are harmless — quick_header.php renders once per
   request and ignores later includes. */
require __DIR__ . '/quick_header.php';
?>
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
// Expand/collapse a Settings-style submenu. The parent button and its sibling
// .nav-sub are toggled together; state is not persisted because the server
// already opens the group containing the current page on every render.
function himsToggleSub(btn) {
    var sub = document.getElementById(btn.getAttribute('aria-controls'));
    var open = !btn.classList.contains('open');
    btn.classList.toggle('open', open);
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (sub) { sub.classList.toggle('open', open); }
}
// Close on Esc, and after tapping any real nav link inside the drawer.
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { himsCloseNav(); } });
// NOT .nav-parent: that button expands a submenu, it doesn't navigate — closing
// the drawer on it would make the Settings group impossible to open on mobile.
document.querySelectorAll('#himsSidebar .nav-item:not(.disabled):not(.nav-parent)').forEach(function (a) {
    a.addEventListener('click', himsCloseNav);
});
</script>
