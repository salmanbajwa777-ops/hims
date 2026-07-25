<?php
/**
 * The application bar — ONE header for every logged-in page.
 *
 * Replaces the old two-row quick header. What changed and why (design spec 1D,
 * "Sage & Clay", 2026-07-26):
 *
 *   - The six tinted destination buttons (Today / Admissions / Patients /
 *     Bookings / ER Service / Day Closing) are GONE. Every one of those
 *     destinations already exists in the sidebar, so the row was a second
 *     navigation competing with the first — five hues fighting each other and
 *     the brand. Removing it, plus the gradient hero, returns ~180px of
 *     vertical space above the queue on the busiest screen in the product.
 *   - What remains is what the row could not express: global search, alerts,
 *     and identity. One 48px bar: search · date · alerts · avatar · logout.
 *   - dashboard.php's separate 72px translucent header is retired in favour of
 *     this same bar, so adjacent screens no longer disagree about their chrome.
 *
 * The caller must have $pdo and a session in scope. Optional locals:
 *   $qhBrand   — deprecated and ignored. The sidebar owns the brand mark now;
 *                it was only ever true to avoid rendering the logo twice.
 *   $qhActive  — deprecated and ignored. Kept so the ~8 existing callers that
 *                set it do not need editing in the same commit; the sidebar
 *                carries the current-page state via $navActive.
 *   $firstName — used for the avatar initial; looked up if absent.
 *
 * Pages that include this must NOT also define .appbar rules of their own.
 * The styles ship in assets/app.css, not here — this partial is markup only.
 */

$qhBaseRole = $_SESSION['base_role'] ?? '';

// Doctors have their own console chrome (partials/doctor_sidebar.php) and
// never get the front-desk bar. Shared pages like patients.php include this
// unconditionally, so the role gate lives here.
if ($qhBaseRole === 'DOCTOR') { return; }

// Most callers already have the signed-in user loaded; those that don't get one
// cheap lookup rather than an anonymous avatar.
$qhName = $firstName ?? '';
if ($qhName === '' && !empty($_SESSION['user_id'])) {
    $qhStmt = $pdo->prepare('SELECT name FROM users WHERE id = ?');
    $qhStmt->execute([$_SESSION['user_id']]);
    $qhName = (string) ($qhStmt->fetchColumn() ?: '');
}
$qhInitial = $qhName !== '' ? strtoupper(substr(trim($qhName), 0, 1)) : '?';

// The old per-destination badge counts are gone with the buttons they sat on.
// Today's queue length now belongs on the page it describes (receptionist.php
// prints it in its own page-meta line), not in shared chrome that every page
// pays a COUNT(*) for whether it shows it or not.
?>
<header class="appbar">
    <form class="search" method="GET" action="patients.php" role="search">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
        <input type="text" name="q" placeholder="Search patients by name, phone or MRN&hellip;"
               value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" aria-label="Search patients">
    </form>

    <span class="appbar-spacer"></span>
    <span class="appbar-date"><?= date('D, d/m/Y') ?></span>

    <button type="button" class="appbar-icon" aria-label="Notifications">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>
        <span class="dot"></span>
    </button>
    <a class="appbar-avatar" href="profile.php" title="My Profile" aria-label="My Profile"><?= htmlspecialchars($qhInitial) ?></a>
    <a class="appbar-logout" href="logout.php">Logout</a>
</header>
