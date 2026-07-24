<?php
/**
 * Shared document head for every logged-in HIMS page.
 *
 * Replaces the per-page copy-pasted <!DOCTYPE>/<head>/<style> boilerplate and
 * the 13 divergent inlined :root blocks. Emits the meta tags, loads Inter
 * (which no page ever actually imported before — the whole app had been
 * silently falling back to system-ui), and links the single shared
 * assets/app.css that carries the tokens, reset and shared components.
 *
 * Caller may set, before including:
 *   $pageTitle  — text after "HIMS — " in <title>. Defaults to "HIMS".
 *   $headExtra  — raw HTML injected at the end of <head> for page-specific
 *                 <style> blocks that haven't been fully migrated yet.
 *
 * This partial OPENS the document (through <body>). The page supplies the body
 * markup and its own closing </body></html>.
 */
$pageTitle = $pageTitle ?? 'HIMS';
$headExtra = $headExtra ?? '';
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HIMS &mdash; <?= htmlspecialchars($pageTitle) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/app.css">
    <?= $headExtra ?>
    <script>
    /* Auto-dismiss success flash messages after 2s, everywhere in the app.
       Targets every success-flash dialect in use across the app:
       `.alert.success`, `.alert-ok`, `.alert-success`, `.flash` (doctor
       console) and `.appr-flash.ok` (expense approval). Fades out then
       collapses the box so the page reflows and nothing is left hanging.
       Error alerts, persistent build notices and CTA-bearing messages are
       deliberately left alone. */
    (function () {
        var SELECTOR = '.alert.success, .alert-ok, .alert-success, .flash, .appr-flash.ok';
        function dismiss(el) {
            el.style.maxHeight = el.offsetHeight + 'px';
            el.style.overflow = 'hidden';
            el.style.transition = 'opacity .4s ease, margin .4s ease, padding .4s ease, max-height .4s ease';
            /* next frame: fade + collapse so surrounding content reflows */
            requestAnimationFrame(function () {
                el.style.opacity = '0';
                el.style.maxHeight = '0';
                el.style.marginTop = '0';
                el.style.marginBottom = '0';
                el.style.paddingTop = '0';
                el.style.paddingBottom = '0';
            });
            setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 500);
        }
        function arm() {
            var els = document.querySelectorAll(SELECTOR);
            for (var i = 0; i < els.length; i++) {
                (function (el) { setTimeout(function () { dismiss(el); }, 2000); })(els[i]);
            }
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', arm);
        } else {
            arm();
        }
    })();
    </script>
</head>
<body>
