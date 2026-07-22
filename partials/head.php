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
</head>
<body>
