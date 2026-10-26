-- =============================================================================
-- Follow-up pricing policy + cross-doctor fee type.
--
--  1. revisit_policy  : one row, admin-editable from "Follow-up Pricing".
--     Defaults reproduce the original hard-coded rules exactly:
--       free window 5 days, hard limit 15 days,
--       1st follow-up 100% off, repeat 50% off, late (day 6-15) 25% off,
--       seen-by-another-doctor 50% off (no other discount stacks on it).
--  2. visits.consultation_fee_type gains 'CROSS_DOCTOR_HALF' so reports can tell
--     "seen by another doctor" apart from a same-doctor 50% follow-up.
--
-- Run in phpMyAdmin. Safe to run BEFORE or AFTER the code deploy: the code falls
-- back to the old values / HALF_FOLLOWUP until this has run. Idempotent.
-- No stored procedures (CREATE ROUTINE is denied on this host).
-- =============================================================================

CREATE TABLE IF NOT EXISTS revisit_policy (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    free_days           SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    max_days            SMALLINT UNSIGNED NOT NULL DEFAULT 15,
    first_discount_pct  DECIMAL(5,2) NOT NULL DEFAULT 100,
    repeat_discount_pct DECIMAL(5,2) NOT NULL DEFAULT 50,
    late_discount_pct   DECIMAL(5,2) NOT NULL DEFAULT 25,
    cross_discount_pct  DECIMAL(5,2) NOT NULL DEFAULT 50,
    updated_by_id INT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO revisit_policy (id) VALUES (1);

-- Widen the ENUM (re-running is harmless). NULL stays allowed, as before.
ALTER TABLE visits MODIFY COLUMN consultation_fee_type
    ENUM('FULL','FREE_FOLLOWUP','HALF_FOLLOWUP','THREE_QUARTER_FOLLOWUP','CROSS_DOCTOR_HALF') NULL;

-- Verify
SELECT * FROM revisit_policy;
SELECT column_type FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'visits' AND column_name = 'consultation_fee_type';
