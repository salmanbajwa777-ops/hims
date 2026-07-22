-- Second (evening) session per doctor per day.
--
-- Doctors often sit in two windows (e.g. morning OPD + evening clinic). The
-- edit sheet always offers both pairs; session 2 is optional — display shows
-- one window when only session 1 is filled, both when both are.
--
-- Idempotent: guarded ALTERs via a throwaway stored procedure (same pattern as
-- add_revisit_billing.sql) — safe to re-run in phpMyAdmin.

DROP PROCEDURE IF EXISTS add_timings_session2;
DELIMITER $$
CREATE PROCEDURE add_timings_session2()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'doctor_day_timings'
                     AND COLUMN_NAME = 'start_time_2') THEN
        ALTER TABLE doctor_day_timings
            ADD COLUMN start_time_2 TIME NULL AFTER end_time,
            ADD COLUMN end_time_2 TIME NULL AFTER start_time_2;
    END IF;
END$$
DELIMITER ;
CALL add_timings_session2();
DROP PROCEDURE IF EXISTS add_timings_session2;
