# Email Notifications — Setup (one-time, ~5 minutes)

The code sends email through the existing mailbox **info@babymedics.com** using
Hostinger's SMTP (`smtp.hostinger.com:465`). Three server-side steps remain:

## 1. Create `config/mail.php` on the server
In Hostinger **File Manager**, go to the `hims` folder → `config/`.
Copy `mail.example.php` → new file **`mail.php`**, and change one line:

```php
'password' => 'CHANGE_ME',   // ← put the real mailbox password of info@babymedics.com
```

(Everything else — host, port, username, admin address — is already correct.)
`config/mail.php` is gitignored, exactly like `config/db.php`, so it survives deploys.

> Don't know the mailbox password? hPanel → **Emails** → babymedics.com →
> info@ → **Change password**. (Webmail keeps working with the new password.)

Until `mail.php` exists (or while `enabled` is `false`), the system runs
exactly as before — every email is silently skipped and noted in `email_log`.

## 2. Run the SQL (phpMyAdmin → u402528120_hmis)
Run `sql/add_email_log.sql`. This creates the `email_log` table where every
send attempt (sent / failed / skipped + the SMTP error) is recorded.

## 3. Register the daily-summary cron (admin email each evening)
hPanel → **Advanced → Cron Jobs** → Create:

- Command: `/usr/bin/php /home/u402528120/domains/babymedics.com/public_html/hims/cron/daily_summary.php`
  (verify the real path in File Manager — it must point at `hims/cron/daily_summary.php`)
- Schedule: daily at **21:00** (server time is PKT)

One-off test without waiting for the cron:
`https://hims.babymedics.com/cron/daily_summary.php?key=hims-daily-2026`

## What gets emailed

| Event | Who gets it |
|---|---|
| Patient registered / follow-up visit (invoice raised) | The visit's **doctor** (their registered email) |
| Refund issued | **Admin** (info@) + the **approving doctor** |
| Patient admitted | **Admin** + the **admitting doctor** |
| Patient discharged (paid or write-off) | **Admin** (write-offs flagged in red) |
| Staff account created | The **new staff member** — sign-in link + temporary password |
| Daily summary (cron, 9 pm) | **Admin** — patients, revenue, refunds, admissions, per-doctor table, and any failed emails that day |

Doctors/staff **without an email on their account simply get nothing** — no
errors. Add their email in Staff → Edit and future notifications reach them.

## Notes
- Emails are best-effort: an SMTP failure never blocks registration, refunds,
  or discharge — it's just logged in `email_log`.
- To change where admin alerts go, edit `admin_email` in `config/mail.php`.
- Master off-switch: set `'enabled' => false` in `config/mail.php`.
- The Add Staff form now has an optional **Temporary Password** field; blank
  still uses the old default 123456. Whatever it is gets emailed to them and
  must be changed on first sign-in.
