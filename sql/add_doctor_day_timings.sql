-- Doctor timings for the day.
--
-- One row per doctor per date: the confirmed consultation window reception has
-- verified for that day, plus a status and free-text note. Reception confirms /
-- edits these at shift start; the next receptionist on duty sees the latest
-- confirmed picture (last_updated_by/at make the handover explicit).
--
-- Idempotent: CREATE TABLE IF NOT EXISTS only — safe to paste into phpMyAdmin.

CREATE TABLE IF NOT EXISTS doctor_day_timings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    doctor_id INT NOT NULL,
    timing_date DATE NOT NULL,
    -- NULL times are allowed for OFF days (no window to state).
    start_time TIME NULL,
    end_time TIME NULL,
    status ENUM('AVAILABLE','DELAYED','OFF') NOT NULL DEFAULT 'AVAILABLE',
    note VARCHAR(255) NULL,
    updated_by INT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_doctor_date (doctor_id, timing_date),
    FOREIGN KEY (doctor_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
);
