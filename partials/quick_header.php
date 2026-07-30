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
 * WHERE IT COMES FROM (2026-07-30): nothing includes this by hand any more —
 * partials/sidebar.php requires it as it opens .main, so the bar is present on
 * every logged-in page for every role, admin/doctor/staff alike, and cannot go
 * missing by a page forgetting to ask for it. The doctor console pages open
 * .app/.main themselves rather than through sidebar.php, so those four include
 * it directly. The guard at the top makes any duplicate include a no-op.
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

// ---- Render exactly once per request ---------------------------------------
// partials/sidebar.php now includes this partial itself, immediately after it
// opens .main, so EVERY logged-in page gets the bar without opting in. The ~15
// pages that already required it by hand (patients.php, receptionist.php, ...)
// still do, and would otherwise draw a second bar directly under the first.
// Rather than edit all of them, those later includes become no-ops here.
if (!empty($GLOBALS['__qh_rendered'])) { return; }
$GLOBALS['__qh_rendered'] = true;

// A doctor used to be returned early here, on the reasoning that the clinical
// console had its own chrome. In practice that meant the search box, the alerts
// bell and the logout link vanished the moment a doctor opened a shared page —
// the bar was the one piece of the UI that had to be constant and was the least
// constant thing in the product. Doctors now get the identical bar; only the
// search target differs, since a doctor searching a patient wants their own
// patient list, not reception's.
$qhIsDoctor = $qhBaseRole === 'DOCTOR';

// Most callers already have the signed-in user loaded; those that don't get one
// cheap lookup rather than an anonymous avatar. $dsUserName is the doctor
// pages' local for the same value.
$qhName = $firstName ?? ($dsUserName ?? '');
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
    <?php /* The search posts to patients.php for every role: the page already
             scopes its own results by permission, so a doctor lands in their
             own patient view rather than reception's. */ ?>

    <span class="appbar-spacer"></span>
    <span class="appbar-date"><?= date('D, d/m/Y') ?></span>

    <?php $nbClass = 'appbar-icon'; require __DIR__ . '/notification_bell.php'; ?>
    <a class="appbar-avatar" href="profile.php" title="My Profile" aria-label="My Profile"><?= htmlspecialchars($qhInitial) ?></a>
    <a class="appbar-logout" href="logout.php">Logout</a>
</header>
