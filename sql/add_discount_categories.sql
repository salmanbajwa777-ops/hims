-- =============================================================================
-- Patient discount categories (Family & Friends / Charity / Loyalty).
--
-- Admin assigns a category to a patient; every future invoice is then
-- auto-discounted at the category's rates — a separate percentage for
-- consultations, ER services and procedures. Rates are SNAPSHOTTED onto each
-- visit / admission bill at billing time, so changing a category's rate later
-- never rewrites history — and month-end reporting can attribute every
-- discounted rupee to its category.
--
-- Stacking rule (confirmed): the revisit engine prices first (free/50%/75%),
-- then the category percentage applies ON TOP of that result.
-- The printed slip stays generic ("Discount") — the category name is internal.
--
-- Run in phpMyAdmin BEFORE deploying the code that depends on it.
-- Idempotent: safe to re-run.
-- =============================================================================

CREATE TABLE IF NOT EXISTS discount_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    -- Percentages 0–100; 100 = fully free (invoice still raised at 0 payable).
    consultation_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
    er_services_pct  DECIMAL(5,2) NOT NULL DEFAULT 0,
    procedures_pct   DECIMAL(5,2) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by_id INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_discount_category_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed the three defaults at 0% — admin sets the real rates from the new
-- Discount Categories page. INSERT IGNORE keeps the script re-runnable.
INSERT IGNORE INTO discount_categories (name) VALUES
    ('Family & Friends'),
    ('Charity'),
    ('Loyalty');

-- -----------------------------------------------------------------------------
-- Guarded column additions (same idempotent pattern as add_admissions.sql).
-- -----------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS hims_add_discount_category_columns;
DELIMITER $$
CREATE PROCEDURE hims_add_discount_category_columns()
BEGIN
    -- patients: the assignment itself (admin-only), plus who/when for audit.
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = DATABASE() AND table_name = 'patients' AND column_name = 'discount_category_id') THEN
        ALTER TABLE patients ADD COLUMN discount_category_id INT NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = DATABASE() AND table_name = 'patients' AND column_name = 'discount_assigned_by_id') THEN
        ALTER TABLE patients ADD COLUMN discount_assigned_by_id INT NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = DATABASE() AND table_name = 'patients' AND column_name = 'discount_assigned_at') THEN
        ALTER TABLE patients ADD COLUMN discount_assigned_at DATETIME NULL;
    END IF;

    -- visits: snapshot of which category contributed to this visit's discount
    -- and at what rate — visits.discount_pct stays the TOTAL applied discount
    -- (category + revisit + any manual reception adjustment), so all existing
    -- billing/print code keeps working unchanged. category_discount_pct is the
    -- reporting split: the portion attributable to the category.
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = DATABASE() AND table_name = 'visits' AND column_name = 'discount_category_id') THEN
        ALTER TABLE visits ADD COLUMN discount_category_id INT NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = DATABASE() AND table_name = 'visits' AND column_name = 'category_discount_pct') THEN
        ALTER TABLE visits ADD COLUMN category_discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0;
    END IF;
    -- Exact rupee value the category step saved on this visit (computed at
    -- billing time, after revisit pricing). Stored, not derived, so reporting
    -- needs no stacking math and the 100%-free edge case stays exact.
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = DATABASE() AND table_name = 'visits' AND column_name = 'category_discount_amount') THEN
        ALTER TABLE visits ADD COLUMN category_discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0;
    END IF;

    -- admission_bills: category discount applies to SERVICE lines only (STAY is
    -- never discounted). Lines are stored net; these columns snapshot the
    -- category + total discounted amount for the slip's "Discount" row and for
    -- month-end reporting.
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = DATABASE() AND table_name = 'admission_bills' AND column_name = 'discount_category_id') THEN
        ALTER TABLE admission_bills ADD COLUMN discount_category_id INT NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = DATABASE() AND table_name = 'admission_bills' AND column_name = 'discount_amount') THEN
        ALTER TABLE admission_bills ADD COLUMN discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0;
    END IF;

    -- admission_bill_items: per-line discount snapshot so month-end reporting
    -- can split ER-service discounts from procedure discounts (both live on
    -- the same bill at potentially different category rates).
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = DATABASE() AND table_name = 'admission_bill_items' AND column_name = 'discount_pct') THEN
        ALTER TABLE admission_bill_items ADD COLUMN discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = DATABASE() AND table_name = 'admission_bill_items' AND column_name = 'discount_amount') THEN
        ALTER TABLE admission_bill_items ADD COLUMN discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = DATABASE() AND table_name = 'admission_bill_items' AND column_name = 'service_type') THEN
        ALTER TABLE admission_bill_items ADD COLUMN service_type ENUM('SERVICE','PROCEDURE') NULL;
    END IF;
END$$
DELIMITER ;
CALL hims_add_discount_category_columns();
DROP PROCEDURE IF EXISTS hims_add_discount_category_columns;
