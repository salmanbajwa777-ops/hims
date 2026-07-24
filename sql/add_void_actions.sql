-- =============================================================================
-- Admin VOID for billable actions (2026-07-24). Soft-void, mirroring the
-- expenses void pattern (voided_at / voided_by_id / void_reason). A voided row
-- and its number are kept forever for audit; every money calculation excludes it.
--
-- Applies to three money tables:
--   * bills             (consultation invoices)
--   * admission_bills   (admission invoices, "A" series)
--   * refunds           (refund vouchers, RF- series)
--
-- Plus a new admin-assignable permission FINANCIAL_VOID_BILL.
--
-- Idempotent: information_schema-guarded ADD COLUMN (shared MySQL lacks
-- ADD COLUMN IF NOT EXISTS) + INSERT ... WHERE NOT EXISTS for the permission.
-- Run in phpMyAdmin against the HMIS database. Safe to re-run.
-- =============================================================================

DROP PROCEDURE IF EXISTS hims_add_void_columns;
DELIMITER $$
CREATE PROCEDURE hims_add_void_columns()
BEGIN
    -- bills
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = DATABASE() AND table_name = 'bills' AND column_name = 'voided_at') THEN
        ALTER TABLE bills
            ADD COLUMN voided_at   TIMESTAMP NULL,
            ADD COLUMN voided_by_id INT NULL,
            ADD COLUMN void_reason VARCHAR(255) NULL,
            ADD CONSTRAINT fk_bills_voided_by FOREIGN KEY (voided_by_id) REFERENCES users(id);
    END IF;

    -- admission_bills
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = DATABASE() AND table_name = 'admission_bills' AND column_name = 'voided_at') THEN
        ALTER TABLE admission_bills
            ADD COLUMN voided_at   TIMESTAMP NULL,
            ADD COLUMN voided_by_id INT NULL,
            ADD COLUMN void_reason VARCHAR(255) NULL,
            ADD CONSTRAINT fk_adm_bills_voided_by FOREIGN KEY (voided_by_id) REFERENCES users(id);
    END IF;

    -- refunds
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = DATABASE() AND table_name = 'refunds' AND column_name = 'voided_at') THEN
        ALTER TABLE refunds
            ADD COLUMN voided_at   TIMESTAMP NULL,
            ADD COLUMN voided_by_id INT NULL,
            ADD COLUMN void_reason VARCHAR(255) NULL,
            ADD CONSTRAINT fk_refunds_voided_by FOREIGN KEY (voided_by_id) REFERENCES users(id);
    END IF;
END $$
DELIMITER ;

CALL hims_add_void_columns();
DROP PROCEDURE IF EXISTS hims_add_void_columns;

-- ---- Permission: FINANCIAL_VOID_BILL (admin-assignable, financial category) ----
INSERT INTO permissions (`key`, label, category)
SELECT * FROM (
    SELECT 'FINANCIAL_VOID_BILL' AS `key`,
           'Void / reverse a paid bill, admission bill or refund' AS label,
           'financial' AS category
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM permissions p WHERE p.`key` = seed.`key`);

-- Grant to ADMIN (and MANAGER). Per-staff grants are managed on Staff & Doctors.
INSERT INTO role_permissions (base_role, permission_id)
SELECT r.base_role, p.id
FROM (SELECT 'ADMIN' AS base_role UNION ALL SELECT 'MANAGER') r
JOIN permissions p ON p.`key` = 'FINANCIAL_VOID_BILL'
WHERE NOT EXISTS (
    SELECT 1 FROM role_permissions rp WHERE rp.base_role = r.base_role AND rp.permission_id = p.id
);
