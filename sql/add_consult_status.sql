-- Doctor console: consultation state on each visit.
-- Depends on sql/add_patients.sql (creates the `visits` table).
--
-- A visit is created by reception in WAITING state (patient queued for the doctor).
-- The doctor's console moves it WAITING -> IN_CONSULT (Start) -> DONE (Finish),
-- stamping started_at / finished_at so we can show wait time and "seen today" counts.
--
-- Idempotent-ish: guarded so re-running on a DB that already has the column is a
-- no-op error you can ignore (MySQL has no "ADD COLUMN IF NOT EXISTS" before 8.0.
-- If your MySQL is 8.0+ you may prefer `ADD COLUMN IF NOT EXISTS`).

ALTER TABLE visits
    ADD COLUMN consult_status ENUM('WAITING','IN_CONSULT','DONE') NOT NULL DEFAULT 'WAITING' AFTER disposition,
    ADD COLUMN started_at DATETIME NULL AFTER consult_status,
    ADD COLUMN finished_at DATETIME NULL AFTER started_at;

-- Queue lookups filter by (doctor_id, visit_date, consult_status) and order by token.
CREATE INDEX idx_visit_console ON visits (doctor_id, visit_date, consult_status, token_no);
