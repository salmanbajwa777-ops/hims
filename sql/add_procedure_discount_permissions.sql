-- ============================================================================
-- Permissions for the procedure flat discount + doctor sign-off.
--
-- Run AFTER add_procedure_manual_discount.sql (the columns must exist first).
--
-- Two keys, deliberately separate:
--
--   RECEPTION_APPLY_PROCEDURE_DISCOUNT
--     Lets a receptionist enter a flat Rs discount when raising a procedure
--     bill. SEPARATE from RECEPTION_RAISE_PROCEDURE_BILL so the discount can
--     be withdrawn from one person without stopping them billing at all --
--     which, since a decision moves no money, is the only real lever there is.
--
--   DOCTOR_APPROVE_PROCEDURE_DISCOUNT
--     Lets a doctor see and decide their own pending discounts. Granted to
--     DOCTOR (they are the whole point) and to ADMIN, who needs to see the
--     queue without being the treating doctor.
--
-- The column is `key` -- a reserved word, always backticked. role_permissions
-- keys on base_role, not role.
--
-- INSERT IGNORE throughout, so re-running is a no-op rather than an error.
-- No stored procedures: the Hostinger user is denied CREATE ROUTINE (#1044).
-- ============================================================================

-- ------------------------------------------------------------ 1. the keys
INSERT IGNORE INTO permissions (`key`, label, category)
VALUES ('RECEPTION_APPLY_PROCEDURE_DISCOUNT', 'Apply a flat discount on procedure bills', 'reception');

INSERT IGNORE INTO permissions (`key`, label, category)
VALUES ('DOCTOR_APPROVE_PROCEDURE_DISCOUNT', 'Approve or object to procedure discounts', 'clinical');

-- --------------------------------------------------- 2. reception discount
-- Granted to whichever base_role already holds RECEPTION_RAISE_PROCEDURE_BILL:
-- whoever can raise the bill can discount it. Narrow it per-user afterwards
-- from Settings > Permissions if you want only senior staff to have it.
--
-- The subquery reads the SAME table it inserts into, which MySQL refuses
-- (#1093) unless materialised first -- hence the derived-table wrapper.
INSERT IGNORE INTO role_permissions (base_role, permission_id)
SELECT src.base_role, (SELECT id FROM permissions WHERE `key` = 'RECEPTION_APPLY_PROCEDURE_DISCOUNT')
FROM (
    SELECT rp.base_role
    FROM role_permissions rp
    JOIN permissions p ON p.id = rp.permission_id
    WHERE p.`key` = 'RECEPTION_RAISE_PROCEDURE_BILL'
) AS src;

-- ----------------------------------------------------- 3. doctor approval
-- DOCTOR and ADMIN explicitly, not copied from another key: no existing
-- permission has the right audience. A doctor decides their own; an admin
-- needs the queue visible because the admin report is where the actual
-- control lives (a decision moves no money).
INSERT IGNORE INTO role_permissions (base_role, permission_id)
SELECT 'DOCTOR', id FROM permissions WHERE `key` = 'DOCTOR_APPROVE_PROCEDURE_DISCOUNT';

INSERT IGNORE INTO role_permissions (base_role, permission_id)
SELECT 'ADMIN', id FROM permissions WHERE `key` = 'DOCTOR_APPROVE_PROCEDURE_DISCOUNT';

-- ============================================================================
-- VERIFY. Every table is FULLY QUALIFIED on purpose: a query against
-- information_schema switches phpMyAdmin's current-database context, so a
-- following bare `FROM permissions` resolves to information_schema and fails
-- with #1109. Read the status column -- do not trust "it ran".
-- ============================================================================

-- Both keys must exist.
SELECT `key`, label, category
FROM u402528120_hmis.permissions
WHERE `key` IN ('RECEPTION_APPLY_PROCEDURE_DISCOUNT','DOCTOR_APPROVE_PROCEDURE_DISCOUNT');

-- Who holds them. DOCTOR and ADMIN must both appear for the approval key.
-- If the reception key shows NO rows, then RECEPTION_RAISE_PROCEDURE_BILL is
-- not granted to any base_role either -- it is assigned per-user instead, and
-- you should grant this one per-user too from Settings > Permissions.
SELECT p.`key`, rp.base_role
FROM u402528120_hmis.role_permissions rp
JOIN u402528120_hmis.permissions p ON p.id = rp.permission_id
WHERE p.`key` IN ('RECEPTION_APPLY_PROCEDURE_DISCOUNT','DOCTOR_APPROVE_PROCEDURE_DISCOUNT')
ORDER BY p.`key`, rp.base_role;

-- Per-user overrides. These layer ON TOP of the role grants above:
-- granted=1 adds the key to someone whose role lacks it, granted=0 REVOKES it
-- from someone whose role has it. Expect zero rows immediately after this
-- migration -- anything here is a deliberate per-person exception.
SELECT p.`key`,
       u.name,
       u.base_role,
       CASE o.granted WHEN 1 THEN 'granted' ELSE 'REVOKED' END AS effect
FROM u402528120_hmis.user_permission_overrides o
JOIN u402528120_hmis.permissions p ON p.id = o.permission_id
JOIN u402528120_hmis.users u ON u.id = o.user_id
WHERE p.`key` IN ('RECEPTION_APPLY_PROCEDURE_DISCOUNT','DOCTOR_APPROVE_PROCEDURE_DISCOUNT')
ORDER BY p.`key`, u.name;
