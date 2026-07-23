<?php
// Google Sheet settings — TEMPLATE. Copy this file to config/sheets_config.php on
// the server (Hostinger File Manager) and paste in the real web-app URL. Like
// config/db.php and config/mail.php, config/sheets_config.php is gitignored so the
// endpoint never enters the repo.
//
// (The name is sheets_CONFIG.php, not sheets.php — config/sheets.php is the
// library that reads this file.)
//
// SETUP (once, ~5 minutes):
//   1. Open the Google Sheet → Extensions → Apps Script.
//   2. Delete the placeholder code, paste the contents of
//      docs/google-apps-script.gs, and Save.
//   3. Deploy → New deployment → type "Web app".
//        Execute as:      Me
//        Who has access:  Anyone            <-- required; HMIS sends no Google login
//   4. Authorise when prompted, then copy the /exec URL it gives you.
//   5. Paste that URL below as 'webapp_url' and pick a 'shared_secret'.
//   6. Put the SAME secret in the SHARED_SECRET line at the top of the script.
//
// "Anyone" access sounds open, but the URL is unguessable and the script rejects
// any request without the matching shared secret — that pair is the auth.
return [
    'enabled'       => true,        // master switch — false silences ALL pushes without touching code
    'webapp_url'    => 'https://script.google.com/macros/s/PASTE_DEPLOYMENT_ID/exec',
    'shared_secret' => 'CHANGE_ME', // must match SHARED_SECRET in the Apps Script
    'timeout'       => 10,          // seconds; the push is after-commit so a slow sheet only delays the page
    // Tab name per year. {year} is replaced, e.g. "Baby Medics 2026".
    'tab_pattern'   => 'Baby Medics {year}',
];
