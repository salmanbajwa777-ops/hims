<?php
/**
 * View-mode toggle — the Auto / Desktop / Mobile segmented control.
 *
 * Drop this INSIDE the sidebar (both partials/sidebar.php and
 * partials/doctor_sidebar.php include it in their footer). It writes the user's
 * choice to localStorage under 'hims-view'; partials/head.php reads it back on
 * every page load — before paint — and stamps <html data-view>. The matching
 * layout CSS lives in assets/app.css (search "View-mode override").
 *
 * Auto  = follow the screen size (default).
 * Desktop = force the full wide layout even on a phone (we also swap the
 *           viewport meta so the phone renders at desktop width, pan/zoom).
 * Mobile  = force the compact touch layout even on a big monitor.
 *
 * Include-guard so two sidebars on one page (never happens today, but cheap
 * insurance) don't emit the markup/JS twice.
 */
if (defined('HIMS_VIEW_TOGGLE_RENDERED')) return;
define('HIMS_VIEW_TOGGLE_RENDERED', true);
?>
<style>
.view-toggle { margin-top: 10px; }
.view-toggle .vt-label { font-size: 10.5px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--text-muted); padding: 0 4px 6px; }
.view-seg { display: grid; grid-template-columns: repeat(3, 1fr); gap: 3px; background: var(--bg); border: 1px solid var(--border); border-radius: 12px; padding: 3px; }
.view-seg button {
    appearance: none; border: none; background: transparent; cursor: pointer;
    font: inherit; font-size: 11.5px; font-weight: 600; color: var(--text-secondary);
    padding: 8px 4px; border-radius: 9px; display: flex; flex-direction: column;
    align-items: center; gap: 3px; line-height: 1; transition: background .15s ease, color .15s ease;
}
.view-seg button svg { width: 15px; height: 15px; }
.view-seg button:hover { color: var(--text); }
.view-seg button[aria-pressed="true"] { background: var(--card); color: var(--primary-dark); box-shadow: var(--shadow-sm); }
</style>

<div class="view-toggle" role="group" aria-label="Screen view">
    <div class="vt-label">View</div>
    <div class="view-seg">
        <button type="button" data-view-set="auto" aria-pressed="false" title="Follow this screen automatically">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20v-6M9 17l3 3 3-3"/><rect x="3" y="4" width="18" height="10" rx="2"/></svg>
            Auto
        </button>
        <button type="button" data-view-set="desktop" aria-pressed="false" title="Force the full desktop layout">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
            Desktop
        </button>
        <button type="button" data-view-set="mobile" aria-pressed="false" title="Force the compact mobile layout">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/></svg>
            Mobile
        </button>
    </div>
</div>

<script>
(function () {
    var KEY = 'hims-view';
    var seg = document.querySelector('.view-seg');
    if (!seg) return;

    function read() {
        var v = 'auto';
        try { v = localStorage.getItem(KEY) || 'auto'; } catch (e) {}
        return (v === 'mobile' || v === 'desktop') ? v : 'auto';
    }

    /* When desktop is forced on a genuinely small screen, render the page at a
       fixed desktop width so it doesn't reflow to one column — the user pans and
       pinch-zooms, exactly like "Request desktop site". Any other mode restores
       the responsive width=device-width viewport. */
    function applyViewport(v) {
        var meta = document.getElementById('hims-viewport') || document.querySelector('meta[name="viewport"]');
        if (!meta) return;
        var sw = (window.screen && window.screen.width) ? window.screen.width : 400;
        var wide = 'width=1280, initial-scale=' + (sw / 1280).toFixed(3);
        var responsive = 'width=device-width, initial-scale=1.0';
        meta.setAttribute('content', v === 'desktop' ? wide : responsive);
    }

    function paint(v) {
        document.documentElement.setAttribute('data-view', v);
        applyViewport(v);
        seg.querySelectorAll('button[data-view-set]').forEach(function (b) {
            b.setAttribute('aria-pressed', b.getAttribute('data-view-set') === v ? 'true' : 'false');
        });
    }

    function set(v) {
        try { localStorage.setItem(KEY, v); } catch (e) {}
        paint(v);
    }

    seg.addEventListener('click', function (e) {
        var btn = e.target.closest('button[data-view-set]');
        if (btn) set(btn.getAttribute('data-view-set'));
    });

    /* head.php already stamped data-view before paint; sync the control's
       pressed state (and the viewport, in case desktop was pinned). */
    paint(read());
})();
</script>
