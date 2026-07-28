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
  @media print { .imp-bar { display: none !important; } body { padding-top: 0 !important; } }
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
    <button type="submit">Return to my account</button>
  </form>
</div>
