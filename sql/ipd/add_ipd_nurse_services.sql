-- =============================================================================
-- IPD — nurse-logged services.
--
-- WHY: chargeable IPD services could only be logged from ipd_discharge.php, a
-- reception/billing screen. The person who GIVES the service (the nurse, at the
-- bedside) was not the person who recorded it, so services were reconstructed
-- from memory at discharge — losing charges and losing the clinical record.
--
-- The nurse-facing panel needs NO new grant: IPD_LOG_SERVICES is already held
-- by ADMIN + STAFF (add_ipd_billing_discharge.sql), and nurses are STAFF. This
-- migration therefore only adds:
--   1. IPD_REMOVE_SERVICE — admin takes a nurse-logged line OFF the bill.
--   2. A composite index, because the new panel and the stay report both read
--      ipd_services ordered by logged_at.
--
-- ipd_services already carries logged_by_id / logged_by_role / logged_at /
-- clinical_note / is_billable, so the DATA MODEL needs no change at all.
-- is_billable was created in Phase 4 but never set to 0 by any UI; the admin
-- "Remove" action is a flag flip, so the clinical record always survives.
--
-- No stored procedures (the DB user is denied CREATE ROUTINE, #1044), so the
-- ALTER is guarded by an information_schema check run through a prepared
-- statement. Idempotent: safe to run more than once.
--
-- Schema is named EXPLICITLY, never DATABASE(): phpMyAdmin often lands you in
-- information_schema, where DATABASE() resolves there and EVERY guard finds
-- nothing -- so each IF takes the "already exists" branch and the migration
-- reports success while doing absolutely nothing.
--
-- All timestamps are PKT (+05:00). Run in phpMyAdmin against the hims database.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. Permission: remove a logged service from the bill.
--    Category 'financial' (not the 'admin' the older IPD migrations used) — this
--    key controls money, and it sits alongside IPD_FINALIZE_BILL on the
--    Permissions screen under "Money & Billing" where an admin expects it.
-- -----------------------------------------------------------------------------
INSERT INTO permissions (`key`, label, category)
SELECT * FROM (
    SELECT 'IPD_REMOVE_SERVICE' AS `key`,
           'Remove a logged IPD service from the bill' AS label,
           'financial' AS category
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM permissions p WHERE p.`key` = seed.`key`);

-- Removing a charge is a money action -> admin only.
-- NOTE: there is no admin short-circuit in load_permissions(); ADMIN holds every
-- key only because role_permissions genuinely has an ADMIN row per permission.
-- Without this INSERT an admin would get a 403 on the Remove action.
INSERT INTO role_permissions (base_role, permission_id)
SELECT r.base_role, p.id
FROM (SELECT 'ADMIN' AS base_role) r
JOIN permissions p ON p.`key` = 'IPD_REMOVE_SERVICE'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.base_role = r.base_role AND rp.permission_id = p.id);

-- -----------------------------------------------------------------------------
-- 2. Composite index on (admission_id, logged_at).
--    The Phase 4 table shipped idx_ipd_service_admission (admission_id) only,
--    unlike its siblings idx_ipd_vitals_admission / idx_ipd_care_admission which
--    are both composite. The nurse panel and the stay report BOTH read
--    "WHERE admission_id = ? ORDER BY logged_at", so give them the covering key.
--    Added as a separate guarded step so a re-run after a partial apply lands.
-- -----------------------------------------------------------------------------
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE u402528120_hmis.ipd_services ADD INDEX idx_ipd_service_adm_time (admission_id, logged_at)',
        'SELECT ''idx_ipd_service_adm_time already exists'''
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = 'u402528120_hmis'
      AND TABLE_NAME   = 'ipd_services'
      AND INDEX_NAME   = 'idx_ipd_service_adm_time'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =============================================================================
-- End IPD nurse-services migration.
-- =============================================================================

-- ---- Verify (read-only; run separately) ----
-- Every app table is FULLY QUALIFIED. Touching information_schema switches
-- phpMyAdmin's current-database context, so a bare `permissions` here resolves
-- to information_schema.permissions and fails with
--   #1109 Unknown table 'permissions' in information_schema
-- Expect: permission = 1 row granted to ADMIN, index = 1 row.
--
-- SELECT 'permission' AS what, p.`key` AS detail,
--        COALESCE(GROUP_CONCAT(rp.base_role ORDER BY rp.base_role), '(none)') AS granted_to
-- FROM u402528120_hmis.permissions p
-- LEFT JOIN u402528120_hmis.role_permissions rp ON rp.permission_id = p.id
-- WHERE p.`key` = 'IPD_REMOVE_SERVICE'
-- GROUP BY p.id, p.`key`
-- UNION ALL
-- SELECT 'index', INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX)
-- FROM information_schema.STATISTICS
-- WHERE TABLE_SCHEMA = 'u402528120_hmis'
--   AND TABLE_NAME   = 'ipd_services'
--   AND INDEX_NAME   = 'idx_ipd_service_adm_time'
-- GROUP BY INDEX_NAME;
