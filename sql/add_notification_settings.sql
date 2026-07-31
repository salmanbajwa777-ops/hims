-- Global notification settings: one row per event type, holding the EMAIL switch.
--
-- In-app notifications are NOT configurable and have no row here — every event
-- always writes to the bell. This table governs one thing only: whether the
-- matching send_mail() in config/notify.php is allowed to fire.
--
-- GLOBAL, not per-user. There is one switchboard for the whole clinic, and it
-- OVERRIDES the per-doctor users.email_on_new_patient opt-in: if the global
-- switch for invoice_raised is off, no doctor gets that email regardless of
-- their own checkbox. (See notif_email_enabled() in config/notifications.php.)
--
-- DEFAULTS: everything OFF except staff_welcome. That one is ON *and* locked in
-- the UI because the welcome email is the only delivery path for a new account's
-- temporary password -- switching it off would create accounts nobody can sign
-- into. The seed below reflects that; the settings screen refuses to unset it.
--
-- No stored procedures / triggers -- the Hostinger DB user is denied
-- CREATE ROUTINE (#1044). Flat, idempotent statements only.
-- No ENGINE/CHARSET clause: forcing utf8mb4 has silently aborted a migration
-- here before (errno 150). Matches sql/add_notifications.sql.

CREATE TABLE IF NOT EXISTS notification_settings (
    -- The event slug passed to notify_users()/notif_email_enabled(), e.g.
    -- 'refund_issued'. PRIMARY KEY so the seed below is re-runnable and so a
    -- lookup is a single point read.
    event_type VARCHAR(60) NOT NULL PRIMARY KEY,
    email_enabled TINYINT(1) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    updated_by_id INT NULL
);

-- Seed one row per event. INSERT IGNORE so re-running never resets a switch the
-- admin has already changed -- this file is safe to apply repeatedly, and safe
-- to re-apply after adding a new event to the bottom of the list.
INSERT IGNORE INTO notification_settings (event_type, email_enabled) VALUES
    ('staff_welcome',        1),   -- LOCKED ON: carries the temporary password
    ('invoice_raised',       0),
    ('refund_issued',        0),
    ('patient_admitted',     0),
    ('ipd_patient_admitted', 0),
    ('patient_discharged',   0),
    ('booking_created',      0),
    ('booking_cancelled',    0),
    ('expense_approval',     0),
    ('expense_decided',      0),
    ('day_closed',           0),
    ('closing_edited',       0),
    ('procedure_discount',   0),
    ('daily_summary',        0);

-- VERIFY -- if this returns fewer than 14 rows, the seed did not land.
-- Do not trust a green run; read the output.
SELECT event_type, email_enabled, updated_at
FROM notification_settings
ORDER BY event_type;
