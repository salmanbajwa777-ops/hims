-- =============================================================================
-- users.email_on_new_patient — opt-in for the "new patient in your queue" email.
--
-- WHY: notify_invoice_raised() (config/notify.php) emails the assigned doctor on
-- EVERY patient registration and EVERY follow-up visit. It is by a wide margin
-- the app's highest-volume email — all day, every day — and it pushed the
-- Hostinger account against its sending limit. Every other notification is
-- low-frequency (refunds, admissions, expense approvals) and the only cron mail
-- is one daily summary, so this single email is the whole problem.
--
-- DEFAULT 0 is the point of this migration: the email becomes OPT-IN. Existing
-- rows and every future user start switched OFF. Nothing is lost — the in-app
-- notification (the header bell) is written BEFORE the email path in
-- notify_invoice_raised() and is unaffected, so a doctor still sees every new
-- patient instantly. A doctor who genuinely wants the email turns it on for
-- themselves in My Profile (profile.php).
--
-- Scoped deliberately to this ONE email rather than a general per-notification
-- preferences table: one column, one checkbox, one guard. The other 11
-- notify_* functions keep their current behaviour untouched.
--
-- No stored procedures (the DB user is denied CREATE ROUTINE, #1044), so the
-- ALTER is guarded by an information_schema check run through a prepared
-- statement. Idempotent: safe to run more than once.
-- Schema is named EXPLICITLY, never DATABASE(): phpMyAdmin often lands you in
-- information_schema, where DATABASE() resolves there and the guard finds
-- nothing -- so the IF takes the "already exists" branch and the migration
-- reports success while doing absolutely nothing.
-- Run in phpMyAdmin against the hims database.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. users.email_on_new_patient — per-user switch for that one email.
--    NOT NULL DEFAULT 0 so there is no tri-state to reason about: every row has
--    a real answer, and that answer is "off" until someone opts in.
-- -----------------------------------------------------------------------------
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE u402528120_hmis.users ADD COLUMN email_on_new_patient TINYINT(1) NOT NULL DEFAULT 0',
        'SELECT ''users.email_on_new_patient already exists'''
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'u402528120_hmis'
      AND TABLE_NAME   = 'users'
      AND COLUMN_NAME  = 'email_on_new_patient'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- Verify. Expect EXACTLY one row: tinyint(1), NO, default 0.
-- If this returns nothing, the ALTER did not land -- do not trust a green run.
-- -----------------------------------------------------------------------------
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'u402528120_hmis'
  AND TABLE_NAME   = 'users'
  AND COLUMN_NAME  = 'email_on_new_patient';

-- Everyone should start opted OUT. Expect a single row: 0 | <total user count>.
SELECT email_on_new_patient, COUNT(*) AS users
FROM u402528120_hmis.users
GROUP BY email_on_new_patient;
