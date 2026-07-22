<?php
// Mail settings — TEMPLATE. Copy this file to config/mail.php on the server
// (Hostinger File Manager) and fill in the real mailbox password. config/mail.php
// is gitignored, exactly like config/db.php, so the secret never enters the repo.
//
// The mailbox is a normal Hostinger email account (hPanel → Emails). Hostinger's
// SMTP endpoint works from PHP on the same hosting account with SSL on port 465.
return [
    'enabled'    => true,                     // master switch — false silences ALL email without touching code
    'host'       => 'smtp.hostinger.com',
    'port'       => 465,                      // implicit SSL
    'username'   => 'info@babymedics.com',
    'password'   => 'CHANGE_ME',
    'from_email' => 'info@babymedics.com',    // must match the mailbox or Hostinger rejects it
    'from_name'  => 'Babymedics HMIS',
    'admin_email'=> 'info@babymedics.com',    // where admin alerts (refunds, admissions, daily summary) go
    'base_url'   => 'https://hims.babymedics.com', // used for login links in emails
];
