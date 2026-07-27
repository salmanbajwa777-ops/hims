-- Add the void columns that add_void_actions.sql never managed to create.
--
-- WHY THIS EXISTS: add_void_actions.sql wraps its ALTERs in a stored procedure
-- (DELIMITER + CREATE PROCEDURE). This host denies CREATE ROUTINE to the DB user
-- (#1044), so that file fails at the CREATE and every ALTER inside it is skipped —
-- the columns were never added, even though the migration was "run".
--
-- Consequence: receptionist.php 500s. It is the only page that joins
--   LEFT JOIN admission_bills ab ON ab.admission_id = adm.id AND ab.voided_at IS NULL
-- and MySQL errors on the unknown column, fatalling the page. Sibling pages read
-- bills.voided_at / refunds.voided_at, which may already exist, so they stayed up.
--
-- Same columns and same constraint names as the original, so add_void_actions.sql
-- becomes a no-op afterwards and nothing is duplicated.
--
-- Guarded ALTERs as prepared statements off information_schema — no routines, so this
-- actually runs here. Idempotent: safe to paste into phpMyAdmin more than once.

-- ------------------------------------------------------------------------- bills
SET @sql := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE bills
            ADD COLUMN voided_at TIMESTAMP NULL,
            ADD COLUMN voided_by_id INT NULL,
            ADD COLUMN void_reason VARCHAR(255) NULL,
            ADD CONSTRAINT fk_bills_voided_by FOREIGN KEY (voided_by_id) REFERENCES users(id)',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bills' AND COLUMN_NAME = 'voided_at'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- --------------------------------------------------------------- admission_bills
-- This is the one receptionist.php needs.
SET @sql := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE admission_bills
            ADD COLUMN voided_at TIMESTAMP NULL,
            ADD COLUMN voided_by_id INT NULL,
            ADD COLUMN void_reason VARCHAR(255) NULL,
            ADD CONSTRAINT fk_adm_bills_voided_by FOREIGN KEY (voided_by_id) REFERENCES users(id)',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admission_bills' AND COLUMN_NAME = 'voided_at'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ----------------------------------------------------------------------- refunds
SET @sql := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE refunds
            ADD COLUMN voided_at TIMESTAMP NULL,
            ADD COLUMN voided_by_id INT NULL,
            ADD COLUMN void_reason VARCHAR(255) NULL,
            ADD CONSTRAINT fk_refunds_voided_by FOREIGN KEY (voided_by_id) REFERENCES users(id)',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'refunds' AND COLUMN_NAME = 'voided_at'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---- Permission: FINANCIAL_VOID_BILL (plain INSERTs, these always ran fine) ----
INSERT INTO permissions (`key`, label, category)
SELECT * FROM (
    SELECT 'FINANCIAL_VOID_BILL' AS `key`,
           'Void / reverse a paid bill, admission bill or refund' AS label,
           'financial' AS category
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM permissions p WHERE p.`key` = seed.`key`);

INSERT INTO role_permissions (base_role, permission_id)
SELECT r.base_role, p.id
FROM (SELECT 'ADMIN' AS base_role UNION ALL SELECT 'MANAGER') r
JOIN permissions p ON p.`key` = 'FINANCIAL_VOID_BILL'
WHERE NOT EXISTS (
    SELECT 1 FROM role_permissions rp WHERE rp.base_role = r.base_role AND rp.permission_id = p.id
);

-- Confirm all three tables now carry voided_at. Expect 3 rows.
SELECT TABLE_NAME, COLUMN_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND COLUMN_NAME = 'voided_at'
  AND TABLE_NAME IN ('bills', 'admission_bills', 'refunds')
ORDER BY TABLE_NAME;
