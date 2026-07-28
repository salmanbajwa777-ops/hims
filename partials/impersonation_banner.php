<?php
/**
 * Persistent "you are viewing as X" bar, emitted from partials/head.php right
 * after <body> so it appears on every logged-in page without touching any of
 * them.
 *
 * Deliberately loud (clay/amber, fixed to the top, always on screen): writes
 * ARE live while viewing as staff, so the one failure mode that actually costs
 * money is an admin forgetting whose account they're in and taking a real
 * payment. A dismissible or scroll-away notice would defeat that. The <body>
 * padding-top offset keeps it from covering the app's own fixed headers.
 */
if (!function_exists('is_impersonating') || !is_impersonating()) {
    return;
}
$impTarget = htmlspecialchars(imp_target_name(), ENT_QUOTES);
$impRole   = htmlspecialchars((string) ($_SESSION['base_role'] ?? ''), ENT_QUOTES);
$impAdmin  = htmlspecialchars(imp_admin_name(), ENT_QUOTES);
?>
<style>
  :root { --imp-bar-h: 40px; }
  body { padding-top: var(--imp-bar-h) !important; }

  /* The bar is position:fixed, so body padding alone is NOT enough — it moves
     the document down but every piece of chrome that pins itself to the
     VIEWPORT keeps using top:0 and ends up underneath this bar (z-index 9999).
     Measured in Chrome at 1440x900: scrolled down, .appbar sat at top:0 with
     the bar's bottom at 40 — search, date, bell, avatar and Logout were fully
     covered the moment the page moved. And .sidebar's height:100vh, once
     pushed down 40px, overflowed the viewport by exactly that much, cutting
     the Auto/Desktop/Mobile toggle off below the fold.
     So every fixed/sticky offset the shell uses is re-based on the bar here.
     Scoped to this partial: it emits nothing outside impersonation, so the
     normal layout is untouched. */

  /* Sticky chrome — stick BELOW the bar, not under it. !important for the same
     specificity reason as .sidebar: the html[data-view="mobile"] rules re-set
     top:0 on .mobile-bar/.doc-mobile-bar from a stronger selector. */
  .appbar,
  .mobile-bar,
  .doc-mobile-bar { top: var(--imp-bar-h) !important; }

  /* Sticky full-height sidebar: offset it, then take the bar's height back
     out of 100vh so its footer (the view toggle) stays on screen.
     !important because app.css sets top/height on the SAME element from more
     specific selectors — `html[data-view="mobile"] .sidebar` (0,2,1) and the
     @media drawer rule both beat a bare `.sidebar` (0,1,0). Without it the
     forced-mobile drawer would silently win and re-clip the toggle. */
  .sidebar {
      top: var(--imp-bar-h) !important;
      height: calc(100vh - var(--imp-bar-h)) !important;
  }

  /* The off-canvas drawer is position:fixed, so body padding never reaches it
     at all. It is declared TWICE in app.css — once under @media (max-width:900px)
     and again under html[data-view="mobile"], which forces the drawer at ANY
     width (the view toggle). Both need the offset, and the data-view rule has
     to be matched at full desktop width too, so this is not nested in a media
     query. The doctor console reuses these same .sidebar/.sidebar-overlay
     classes, so it is covered by the same rules.
     .sidebar's offset is already set above; the overlay is the addition here. */
  .sidebar-overlay { top: var(--imp-bar-h) !important; }

  .imp-bar {
      position: fixed; top: 0; left: 0; right: 0; z-index: 9999;
      height: var(--imp-bar-h);
      display: flex; align-items: center; justify-content: center; gap: 14px;
      background: #9A3412; color: #fff;
      font-family: 'Inter', system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
      font-size: 13px; font-weight: 600;
      padding: 0 14px; box-shadow: 0 2px 8px rgba(0,0,0,.18);
  }
  .imp-bar .imp-eye { width: 15px; height: 15px; flex: none; }
  .imp-bar .imp-txt { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .imp-bar .imp-role {
      background: rgba(255,255,255,.2); border-radius: 5px;
      padding: 2px 7px; font-size: 11px; letter-spacing: .03em;
  }
  .imp-bar form { margin: 0; }
  .imp-bar button {
      background: #fff; color: #9A3412; border: none; border-radius: 8px;
      padding: 6px 13px; font: inherit; font-size: 12.5px; font-weight: 700;
      cursor: pointer; white-space: nowrap;
  }
  .imp-bar button:hover { background: #FFEDD5; }
  /* Print: zero the TOKEN rather than each rule, so the padding and all four
     derived offsets above collapse together. A5 slips are printed constantly
     from checkout/refund, and a 40px push on every one of them would be a
     visible regression. */
  @media print {
      :root { --imp-bar-h: 0px; }
      .imp-bar { display: none !important; }
      body { padding-top: 0 !important; }
  }
  @media (max-width: 600px) {
      :root { --imp-bar-h: 36px; }
      /* The exit button must ALWAYS be fully reachable, so it is pinned right
         and the name label is the only thing allowed to shrink/ellipsize.
         justify-content:center would push the button off-screen at 390px. */
      .imp-bar { font-size: 11.5px; gap: 8px; justify-content: flex-start; padding: 0 10px; }
      .imp-bar .imp-role, .imp-bar .imp-admin { display: none; }
      .imp-bar .imp-txt { flex: 1 1 auto; min-width: 0; }
      .imp-bar form { flex: none; margin-left: auto; }
      .imp-bar button { padding: 6px 10px; font-size: 12px; }
  }
</style>
<div class="imp-bar" role="status">
  <svg class="imp-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
  <span class="imp-txt">Viewing as <strong><?= $impTarget ?></strong></span>
  <span class="imp-role"><?= $impRole ?></span>
  <span class="imp-txt imp-admin">Signed in as <?= $impAdmin ?> · changes save under <?= $impTarget ?>'s name</span>
  <form method="POST" action="/impersonate.php">
    <input type="hidden" name="action" value="stop">
    <input type="hidden" name="imp_csrf" value="<?= htmlspecialchars(imp_csrf_token()) ?>">
    <button type="submit">Return to my account</button>
  </form>
</div>
