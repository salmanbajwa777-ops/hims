-- =============================================================================
-- Dental module permissions (2026-07-28)
--
-- Nine keys in a NEW 'dental' category. permissions.category is VARCHAR(20)
-- (widened from an ENUM by rbac_overhaul_4_fix_category_enum.sql), so a new
-- category needs no schema change here — but the Permissions SCREEN groups by
-- category through a $categoryLabels map, so 'dental' => 'Dental' must also be
-- added to config/../permissions.php or the section renders as a bare ucfirst
-- heading appended after the known buckets.
--
-- THE SPLIT THAT MATTERS: reception can SEE an account in full and TAKE money
-- against it, but cannot change what it says. Adding or voiding an item moves
-- the quoted total on a figure the patient signed for, so it is the dentist's
-- call. That is why DENTAL_VIEW_ACCOUNTS and DENTAL_EDIT_ACCOUNT_ITEMS are two
-- keys and not one.
--
-- Conversely a dentist does not run a till, so DENTAL_TAKE_ACCOUNT_PAYMENT is
-- withheld from DOCTOR by default. In a solo practice where the dentist does
-- take money, an admin grants it per-user from Settings > Staff — that is what
-- user_permission_overrides is for, and it beats loosening the role default
-- for every doctor in the system.
--
-- Flat and idempotent; no stored procedures (#1044). Run in phpMyAdmin.
--
-- RUN ORDER: add_dental_procedure_fields.sql, then add_dental_module.sql, then
-- THIS FILE LAST. (Order matters only in that the pages these keys gate will
-- fatal without the tables; the INSERTs themselves are independent.)
--
-- NB: the column is `key` (a reserved word — always backticked) and the join
-- table keys on base_role, not role.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. The catalogue of keys.
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO permissions (`key`, label, category) VALUES
    ('DENTAL_RECORD_TREATMENT',     'Record dental treatment (per-visit chart)', 'dental'),
    ('DENTAL_VIEW_TREATMENT',       'View dental treatment history',             'dental'),
    ('DENTAL_OPEN_ACCOUNT',         'Open a dental procedure account (package)', 'dental'),
    ('DENTAL_VIEW_ACCOUNTS',        'View dental accounts and balances',         'dental'),
    ('DENTAL_EDIT_ACCOUNT_ITEMS',   'Add / void items on a dental account',      'dental'),
    ('DENTAL_TAKE_ACCOUNT_PAYMENT', 'Take a payment against a dental account',   'dental'),
    ('DENTAL_CANCEL_ACCOUNT',       'Cancel a dental account (freeze the balance)', 'dental'),
    ('DENTAL_CAPTURE_CONSENT',      'Capture / upload dental consent forms',     'dental'),
    ('DENTAL_MANAGE_LAB_WORK',      'Log and update dental lab work',            'dental');


-- -----------------------------------------------------------------------------
-- 2. ADMIN gets all nine.
--
--    Every INSERT ... SELECT below reads role_permissions while inserting into
--    it, which MySQL refuses (#1093) unless the subquery is materialised first
--    — hence the derived-table wrapper on the grants that copy an existing
--    role. This first one selects from `permissions` instead, so it needs no
--    wrapper. INSERT IGNORE against the unique (base_role, permission_id) key
--    makes re-running safe.
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO role_permissions (base_role, permission_id)
SELECT 'ADMIN', p.id FROM permissions p WHERE p.category = 'dental';


-- -----------------------------------------------------------------------------
-- 3. DOCTOR gets eight — everything except taking payment.
--
--    The dentist owns the clinical record, the quote and the consent; the till
--    belongs to the front desk. See the header for the solo-practice case.
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO role_permissions (base_role, permission_id)
SELECT 'DOCTOR', p.id FROM permissions p
 WHERE p.category = 'dental'
   AND p.`key` <> 'DENTAL_TAKE_ACCOUNT_PAYMENT';


-- -----------------------------------------------------------------------------
-- 4. STAFF (reception) gets six.
--
--    Deliberately WITHOUT:
--      DENTAL_EDIT_ACCOUNT_ITEMS  reception must not move a signed quote
--      DENTAL_CANCEL_ACCOUNT      cancelling can strand an overpayment
--      DENTAL_RECORD_TREATMENT    the clinical chart is the dentist's record
--
--    Granted to whichever base_role already holds RECEPTION_RAISE_PROCEDURE_BILL,
--    so this tracks however the clinic has actually configured its front desk
--    rather than assuming the literal 'STAFF' role. Derived-table wrapper for
--    #1093 as described above.
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO role_permissions (base_role, permission_id)
SELECT src.base_role, p.id
FROM (
    SELECT rp.base_role
    FROM role_permissions rp
    JOIN permissions p2 ON p2.id = rp.permission_id
    WHERE p2.`key` = 'RECEPTION_RAISE_PROCEDURE_BILL'
) AS src
CROSS JOIN permissions p
WHERE p.`key` IN (
    'DENTAL_OPEN_ACCOUNT',
    'DENTAL_VIEW_ACCOUNTS',
    'DENTAL_VIEW_TREATMENT',
    'DENTAL_TAKE_ACCOUNT_PAYMENT',
    'DENTAL_CAPTURE_CONSENT',
    'DENTAL_MANAGE_LAB_WORK'
);


-- =============================================================================
-- End dental permissions migration.
-- Verify with (fully qualified — a query against information_schema switches
-- phpMyAdmin's current-database context, so a following bare `FROM permissions`
-- resolves against information_schema and fails with #1109):
--
--   SELECT `key`, label, category FROM u402528120_hmis.permissions
--    WHERE category = 'dental' ORDER BY `key`;
--   -- expect 9 rows
--
--   SELECT rp.base_role, p.`key`
--     FROM u402528120_hmis.role_permissions rp
--     JOIN u402528120_hmis.permissions p ON p.id = rp.permission_id
--    WHERE p.category = 'dental'
--    ORDER BY rp.base_role, p.`key`;
--   -- expect ADMIN  x9
--   --        DOCTOR x8  (no DENTAL_TAKE_ACCOUNT_PAYMENT)
--   --        STAFF  x6  (no EDIT_ACCOUNT_ITEMS / CANCEL_ACCOUNT / RECORD_TREATMENT)
--   --
--   -- STAFF x0 means RECEPTION_RAISE_PROCEDURE_BILL is not granted to any role
--   -- (add_procedure_bills.sql not run, or the desk is configured per-user).
--   -- Grant the six keys from Settings > Permissions in that case.
-- =============================================================================
