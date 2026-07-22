-- =============================================================================
-- Procedures Master + Doctor↔Procedure Assignments (setup screens phase)
-- 1. Renames the empty Phase-2 placeholder `procedures` table to
--    `procedure_master` (the name `procedures` is reserved for the future
--    per-visit procedure transaction table in HMIS-PHP-PLAN.md).
-- 2. Adds the mandatory_consent flag to the master.
-- 3. Creates doctor_procedures: which procedures a doctor can perform, with a
--    per-doctor fee override (NULL = inherit master rate), doctor/clinic share
--    split, and an optional tax % withheld from the doctor's share.
-- Idempotent: guarded via a stored procedure; CREATE TABLE IF NOT EXISTS.
-- Run in phpMyAdmin against the hims database. Timestamps are PKT (+05:00).
-- Depends on sql/add_revisit_billing.sql (or runs standalone via the fallback
-- CREATE below on a fresh install).
-- =============================================================================

-- 1 + 2. Rename placeholder and add the consent column (guarded — safe to re-run).
DROP PROCEDURE IF EXISTS hims_setup_procedure_master;
DELIMITER $$
CREATE PROCEDURE hims_setup_procedure_master()
BEGIN
    -- Rename only if the old placeholder exists and the new name doesn't.
    IF EXISTS (SELECT 1 FROM information_schema.tables
               WHERE table_schema = DATABASE() AND table_name = 'procedures')
       AND NOT EXISTS (SELECT 1 FROM information_schema.tables
               WHERE table_schema = DATABASE() AND table_name = 'procedure_master') THEN
        RENAME TABLE procedures TO procedure_master;
    END IF;

    -- mandatory_consent: 1 = adding this procedure to a visit must generate a
    -- consent form (generation itself is a later phase; flag captured now).
    IF EXISTS (SELECT 1 FROM information_schema.tables
               WHERE table_schema = DATABASE() AND table_name = 'procedure_master')
       AND NOT EXISTS (SELECT 1 FROM information_schema.columns
               WHERE table_schema = DATABASE() AND table_name = 'procedure_master'
                 AND column_name = 'mandatory_consent') THEN
        ALTER TABLE procedure_master
            ADD COLUMN mandatory_consent TINYINT NOT NULL DEFAULT 0 AFTER fee;
    END IF;
END$$
DELIMITER ;
CALL hims_setup_procedure_master();
DROP PROCEDURE IF EXISTS hims_setup_procedure_master;

-- Fresh-install fallback: if the Phase-2 placeholder never existed.
CREATE TABLE IF NOT EXISTS procedure_master (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    fee DECIMAL(10,2) NOT NULL DEFAULT 0,
    mandatory_consent TINYINT NOT NULL DEFAULT 0,
    is_active TINYINT NOT NULL DEFAULT 1,
    created_by_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_procedure_name (name),
    FOREIGN KEY (created_by_id) REFERENCES users(id) ON DELETE SET NULL
);

-- 3. doctor_procedures — the procedures each doctor performs + money config.
--    fee NULL = charge the master's current rate (rate changes propagate);
--    a value = per-doctor override. Clinic share = 100 - doctor_share_pct.
--    tax_percent is withheld from the DOCTOR's share at commission time
--    (patient invoices carry no tax — see run_now_billing_no_tax.sql policy).
CREATE TABLE IF NOT EXISTS doctor_procedures (
    id INT AUTO_INCREMENT PRIMARY KEY,
    doctor_id INT NOT NULL,
    procedure_master_id INT NOT NULL,
    fee DECIMAL(10,2) NULL,
    doctor_share_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
    has_tax TINYINT NOT NULL DEFAULT 0,
    tax_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    is_active TINYINT NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_doctor_procedure (doctor_id, procedure_master_id),
    FOREIGN KEY (doctor_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (procedure_master_id) REFERENCES procedure_master(id) ON DELETE CASCADE
);

-- =============================================================================
-- End procedures-master migration.
-- =============================================================================
