-- Coded queue tokens: SB-1, SB-2 … per doctor, restarting at 1 each SESSION.
--
-- Two changes:
--
-- 1. users.token_prefix — the doctor's initials ("SALMAN BAJWA" -> "SB"). Auto-derived
--    on save in staff.php, editable there so two doctors who collide on initials can be
--    told apart (SB / SBJ). Nullable: staff who never see a queue don't need one, and
--    doctor_token_prefix() falls back to deriving from the name at read time.
--
-- 2. visits.token_session + a widened counter key — the token restarts at 1 for each
--    of the doctor's sittings that day (morning OPD, evening clinic). Session is 1 or 2,
--    matching doctor_day_timings.start_time/start_time_2. Both patients legitimately
--    hold "SB-1" on the same date, so token_session is what disambiguates them; every
--    queue screen shows the session alongside the code.
--
-- Existing visits keep token_session = 1 and simply gain a prefix on display — nothing
-- is renumbered, and invoices already printed with a bare number stay truthful.
--
-- No stored procedures: this DB user is denied CREATE ROUTINE (#1044). Guarded ALTERs
-- are expressed as prepared statements off information_schema instead, so the file is
-- idempotent and safe to paste into phpMyAdmin more than once.
--
-- RUN THIS BEFORE DEPLOYING THE CODE. The visit INSERTs write visits.token_session, so
-- registration fails on the old schema. issue_token() itself falls back to the 2-column
-- counter, but that does not save the INSERT — migrate first, then push.

-- ---------------------------------------------------------------- users.token_prefix
SET @sql := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE users ADD COLUMN token_prefix VARCHAR(6) NULL AFTER specialty',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'token_prefix'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ------------------------------------------------------------- visits.token_session
SET @sql := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE visits ADD COLUMN token_session TINYINT NOT NULL DEFAULT 1 AFTER token_no',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'visits' AND COLUMN_NAME = 'token_session'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ------------------------------------------- visit_queue_counters: add session to key
-- The counter is keyed (doctor_id, visit_date) today. Session 2 needs its own run of
-- numbers, so the session joins the PRIMARY KEY. Existing rows become session 1, which
-- is exactly what they were counting.
SET @sql := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE visit_queue_counters ADD COLUMN token_session TINYINT NOT NULL DEFAULT 1 AFTER visit_date',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'visit_queue_counters'
      AND COLUMN_NAME = 'token_session'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Re-key only if the PK is still the old 2-column one. DROP + ADD PRIMARY KEY must run
-- as a single ALTER — the table is never without a primary key mid-statement.
SET @sql := (
    SELECT IF(COUNT(*) = 2,
        'ALTER TABLE visit_queue_counters DROP PRIMARY KEY, ADD PRIMARY KEY (doctor_id, visit_date, token_session)',
        'DO 0')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'visit_queue_counters'
      AND INDEX_NAME = 'PRIMARY'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ------------------------------------------------------------------------- backfill
-- Seed prefixes for doctors already on file: first letter of the first two words of the
-- name ("SALMAN BAJWA" -> SB, "HIJAB SHAHEEN" -> HS, single-word "ASAD" -> AS). Only
-- fills NULLs, so an admin override is never overwritten by a re-run.
UPDATE users
SET token_prefix = UPPER(
        IF(LOCATE(' ', TRIM(name)) > 0,
           CONCAT(LEFT(TRIM(name), 1), LEFT(SUBSTRING(TRIM(name), LOCATE(' ', TRIM(name)) + 1), 1)),
           LEFT(TRIM(name), 2))
    )
WHERE token_prefix IS NULL
  AND base_role = 'DOCTOR'
  AND TRIM(name) <> '';

-- Collisions are left alone deliberately: the migration cannot know which doctor should
-- keep "SB". Run this to see any that need an admin to pick a different prefix on
-- staff.php — an empty result means nothing to do.
--
--   SELECT token_prefix, GROUP_CONCAT(name) AS doctors, COUNT(*) AS n
--   FROM users WHERE base_role = 'DOCTOR' AND token_prefix IS NOT NULL
--   GROUP BY token_prefix HAVING n > 1;
