-- =============================================================================
-- Phase 2 — Revisit Billing (OPD consultation follow-ups)
-- Reconciled to the live HIMS. See hims-PHASE-2-revisit-RECONCILED.md.
-- Idempotent: guarded ALTERs via a stored procedure; CREATE TABLE IF NOT EXISTS.
-- Run in phpMyAdmin against the hims database. Timestamps are PKT (+05:00).
-- =============================================================================

-- 1. procedures — placeholder catalogue (one-time services, no revisit).
--    Built out in a later phase; seeded empty. Admin will manage rates here.
CREATE TABLE IF NOT EXISTS procedures (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    fee DECIMAL(10,2) NOT NULL DEFAULT 0,
    is_active TINYINT NOT NULL DEFAULT 1,
    created_by_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_procedure_name (name),
    FOREIGN KEY (created_by_id) REFERENCES users(id) ON DELETE SET NULL
);

-- 2. Column additions (guarded — safe to re-run).
DROP PROCEDURE IF EXISTS hims_add_revisit_columns;
DELIMITER $$
CREATE PROCEDURE hims_add_revisit_columns()
BEGIN
    -- visits: how the fee was decided + the window anchor + override flag.
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = DATABASE() AND table_name = 'visits' AND column_name = 'consultation_fee_type') THEN
        ALTER TABLE visits ADD COLUMN consultation_fee_type
            ENUM('FULL','FREE_FOLLOWUP','HALF_FOLLOWUP','THREE_QUARTER_FOLLOWUP') NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = DATABASE() AND table_name = 'visits' AND column_name = 'revisit_of_visit_id') THEN
        ALTER TABLE visits ADD COLUMN revisit_of_visit_id INT NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = DATABASE() AND table_name = 'visits' AND column_name = 'fee_overridden') THEN
        ALTER TABLE visits ADD COLUMN fee_overridden TINYINT NOT NULL DEFAULT 0;
    END IF;

    -- doctor_consult_types: admin flag marking a type as a real consultation
    -- (revisit rules apply) vs a procedure-like one (they don't). Default 1 so
    -- existing types keep working; admin unticks procedures.
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = DATABASE() AND table_name = 'doctor_consult_types' AND column_name = 'is_revisit_eligible') THEN
        ALTER TABLE doctor_consult_types ADD COLUMN is_revisit_eligible TINYINT NOT NULL DEFAULT 1;
    END IF;
END$$
DELIMITER ;
CALL hims_add_revisit_columns();
DROP PROCEDURE IF EXISTS hims_add_revisit_columns;

-- Backfill: every pre-Phase-2 consultation visit was a full-fee visit, so mark
-- them FULL. This lets the window engine find a valid "last full-paid" anchor
-- for returning patients whose history predates this migration. Only rows with
-- no fee_type set and no discount are treated as clean FULL anchors.
UPDATE visits
   SET consultation_fee_type = 'FULL'
 WHERE consultation_fee_type IS NULL
   AND discount_pct = 0;

-- =============================================================================
-- End Phase 2 revisit-billing migration.
-- =============================================================================
