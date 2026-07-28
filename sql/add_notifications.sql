-- In-app notifications: one row per (event, recipient), with per-user read state.
--
-- Companion to the email fan-out in config/notify.php. Every notify_* function
-- already resolves its audience; it now also writes a row here per recipient so
-- the bell shows what the inbox got. Fan-out at WRITE time (not a shared event
-- row read through a join) is what makes "unread for me" a single indexed
-- lookup, and lets one person read an item without touching anyone else's copy.
--
-- No stored procedures / triggers — the Hostinger DB user is denied
-- CREATE ROUTINE (#1044). Flat, idempotent statements only.
-- No ENGINE/CHARSET clause: it must match the users table it references, and
-- forcing utf8mb4 here has silently aborted a migration before (errno 150).

CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    -- Event category, e.g. 'invoice_raised'. Drives the icon + colour, and lets
    -- a future preferences screen mute a class of notification per user.
    type VARCHAR(60) NOT NULL,
    title VARCHAR(180) NOT NULL,
    body VARCHAR(400) NULL,
    -- Where clicking it should land, relative to the app root (e.g.
    -- 'expenses.php?id=12'). NULL renders as a non-clickable row.
    link VARCHAR(255) NULL,
    -- Human reference shown in the row and used to de-duplicate re-sends,
    -- e.g. 'INV-2026-0042'. Mirrors send_mail()'s dedupe tag.
    ref VARCHAR(80) NULL,
    read_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    -- The bell's two queries: the recent list, and the unread count. Declared
    -- inline (not as CREATE INDEX, which has no IF NOT EXISTS in MySQL) so a
    -- re-run of this file is a no-op rather than an error.
    KEY idx_notif_user_created (user_id, created_at),
    KEY idx_notif_user_unread (user_id, read_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
