-- Doctor weekly schedule (fixed year-round template).
--
-- Most doctors keep the same hours all year, so the schedule is a WEEKLY
-- template: one row per doctor per weekday with a time-in/time-out window,
-- an optional second session, and an is_off flag (e.g. Sundays off).
-- Set by the DOCTOR on my_schedule.php.
--
-- This does NOT replace doctor_day_timings — that stays the per-DATE sheet
-- reception confirms each morning (delays, one-off offs). The weekly template
-- is the doctor's standing pattern; the day sheet is the day's reality.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS only — safe to paste into phpMyAdmin.

CREATE TABLE IF NOT EXISTS doctor_weekly_schedule (
    id INT AUTO_INCREMENT PRIMARY KEY,
    doctor_id INT NOT NULL,
    -- ISO weekday: 1 = Monday … 7 = Sunday (matches PHP date('N')).
    weekday TINYINT NOT NULL,
    is_off TINYINT(1) NOT NULL DEFAULT 0,
    -- NULL times allowed on off days (no window to state).
    start_time TIME NULL,
    end_time TIME NULL,
    start_time_2 TIME NULL,
    end_time_2 TIME NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_doctor_weekday (doctor_id, weekday),
    FOREIGN KEY (doctor_id) REFERENCES users(id) ON DELETE CASCADE
);
