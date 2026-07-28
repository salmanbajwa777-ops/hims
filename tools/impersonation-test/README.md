# "View as staff" test harness

Offline tests for the admin impersonation feature (`config/impersonation.php`,
`impersonate.php`, `partials/impersonation_banner.php`) and for the shared
`audit_log()` helper the refactor introduced.

They run the **real** application files against an in-memory sqlite database, so
they exercise the actual code rather than a copy of it. Nothing here touches the
live MySQL database, writes to the repo, or needs a web server.

## Running

There is no PHP on the deploy host or (by default) on the dev machine — fetch a
portable build once:

```sh
curl -sL -o php.zip "https://downloads.php.net/~windows/releases/php-8.3.32-nts-Win32-vs16-x64.zip"
unzip -q php.zip -d php
printf 'extension_dir=ext\nextension=pdo_sqlite\nextension=sqlite3\nextension=mbstring\n' > php/php.ini
```

Then, from this directory:

```sh
php/php.exe test_logic.php            # 58 assertions on the impersonation logic
php/php.exe test_boot.php             # include-order / timezone-pin safety
php/php.exe test_banner.php           # banner renders, escapes, and stays silent
php/php.exe check_include_chain.php   # every audit_log() caller can reach it
```

Each exits non-zero on failure.

## What each one guards

**`test_logic.php`** — the core contract. Permissions while impersonating are the
target's *effective* set (role defaults plus that user's individual grants and
revokes), and the admin's own rights are gone for the duration. Every refusal
path: no admin-on-admin, no self-view, no nesting, unknown user. Audit rows are
attributed to the staff member but tagged with the acting admin. Restore is
clean.

It also covers the cases that make impersonation genuinely dangerous:

- an admin **demoted or deactivated mid-impersonation** must NOT be handed an
  admin session back on exit — `imp_stop()` re-reads the DB and destroys the
  session instead, because the parked `base_role` is only a memory of what was
  true when the impersonation began;
- a **forced password change** on the admin's own account survives the round
  trip (`imp_start()` suppresses it for the target's benefit, so `imp_stop()`
  must restore the DB fact rather than a hardcoded `false`).

**`test_boot.php`** — asserts `config/auth.php` loads standalone with the
impersonation helpers in scope, and that it does **not** pull in
`config/permissions.php` early. That file runs a top-level `SET time_zone`
guarded by `isset($pdo)`; loading it before `db.php` creates `$pdo` would run the
guard against nothing and silently disarm the PKT pin.

**`test_banner.php`** — the bar renders nothing in a normal session, and when
impersonating it names both parties, posts to the stop endpoint, escapes HTML in
user names, hides itself when printing, and offsets the body.

**`check_include_chain.php`** — resolves each page's real `require` graph and
asserts `audit_log()` is reachable. A grep for "does this file require
permissions.php" gives false passes: `profile.php` and `login_fix.php` both
called the helper without loading it (they gate on nothing, so they never had a
reason to) and fatalled on submit. This catches that class of break.

## Not covered here

Banner **geometry** is not asserted by these scripts. `--window-size` does not
shrink a headless viewport below ~500px, so screenshots taken that way falsely
look clipped. Measure over the DevTools protocol instead — start Chrome with
`--remote-debugging-port=9222`, then use
`Emulation.setDeviceMetricsOverride {width, height, deviceScaleFactor, mobile}`
before reading `getBoundingClientRect()`. Verified that way at 320/360/390/414/
768/1280: the exit button fits at every width and `body` padding-top matches the
bar height.
