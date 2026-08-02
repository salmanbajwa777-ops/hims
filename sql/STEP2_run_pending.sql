-- =============================================================================
-- STEP 2 — RUN THE PENDING MIGRATIONS
--
-- RUN sql/STEP1_check_pending.sql FIRST and keep its result. Every section
-- below is labelled with the same step code (2A, 2B, ...). Run ONLY the
-- sections STEP1 marked 'RUN THIS'. Running an already-applied section is
-- mostly harmless but will throw #1060 "Duplicate column name" on the ALTERs,
-- which is noise that hides a real error.
--
-- RUN THE SECTIONS IN THE ORDER GIVEN. Two real dependencies:
--   * 2D (MANAGER) before 2C (expense approvals) — 2C grants a permission TO
--     'MANAGER', and on a database where that ENUM value does not exist the
--     grant row is written against a role nobody can hold.
--   * 2H (consent) before 2I (consent CNIC) — 2I places its column AFTER a
--     column 2H creates, so on its own it FAILS, and depending on the client
--     that failure can be reported in a way that looks like success.
--
-- HOST CONSTRAINTS observed throughout — these are why several sections below
-- do not match the original files in sql/:
--   * NO stored procedures / NO DELIMITER. This DB user is denied CREATE
--     ROUTINE (#1044). sql/add_expense_approvals.sql and
--     sql/add_admission_manual_discount.sql are both written with
--     DELIMITER + CREATE PROCEDURE and therefore CANNOT RUN on this host —
--     sections 2C and 2F below are flat rewrites of them that do the same work.
--     This is the same trap that silently blocked add_expense_over_limit.sql.
--   * No ENGINE/CHARSET clause on CREATE TABLE — it must match the users table
--     these reference, and forcing utf8mb4 has aborted a migration here before
--     with errno 150.
--   * `key` is a reserved word and is always backticked.
--
-- ABOUT #1060 "Duplicate column name": MySQL has no ADD COLUMN IF NOT EXISTS,
-- and this user cannot use the procedure trick that works around it. So the
-- ALTERs below are NOT idempotent. If you see #1060, that column already
-- exists — the section was already applied, nothing is broken, and nothing was
-- lost. Move to the next section.
--
-- Every table is fully qualified. Replace u402528120_hmis if your DB differs.
--
-- VERIFY WHEN DONE: re-run sql/STEP1_check_pending.sql. Every row must say
-- 'already ok'. That, not the absence of a red error box, is the proof.
-- =============================================================================


-- #############################################################################
-- 2A — IPD ADVANCES
-- Enables the advance ledger, discharge settlement and the excess-return
-- receipt. The app fails soft without it, so the symptom today is the feature
-- simply not appearing rather than an error.
--
-- Paste the whole of sql/ipd/add_ipd_advances.sql, which already carries the
-- correct CREATE TABLE bodies, and THEN the two ALTERs below (that file leaves
-- them separate on purpose because MySQL cannot make them conditional).
-- #############################################################################

-- After running sql/ipd/add_ipd_advances.sql, run these two.
-- #1060 here means they were already added.
ALTER TABLE u402528120_hmis.ipd_bills ADD COLUMN advance_applied  DECIMAL(10,2) NOT NULL DEFAULT 0;
ALTER TABLE u402528120_hmis.ipd_bills ADD COLUMN advance_refunded DECIMAL(10,2) NOT NULL DEFAULT 0;


-- #############################################################################
-- 2B — NOTIFICATIONS
-- The app bar renders a bell against this table on every page for every role.
-- Fully self-contained and idempotent (CREATE TABLE IF NOT EXISTS), so this
-- section is safe to run even if you are unsure.
-- #############################################################################

CREATE TABLE IF NOT EXISTS u402528120_hmis.notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    -- Event category, e.g. 'invoice_raised'. Drives the icon + colour.
    type VARCHAR(60) NOT NULL,
    title VARCHAR(180) NOT NULL,
    body VARCHAR(400) NULL,
    -- Where clicking it lands, relative to the app root. NULL = not clickable.
    link VARCHAR(255) NULL,
    -- Human reference shown in the row, used to de-duplicate re-sends.
    ref VARCHAR(80) NULL,
    read_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    -- The bell's two queries: the recent list, and the unread count. Declared
    -- inline because CREATE INDEX has no IF NOT EXISTS in MySQL, so a re-run of
    -- this file stays a no-op instead of erroring.
    KEY idx_notif_user_created (user_id, created_at),
    KEY idx_notif_user_unread (user_id, read_at),
    FOREIGN KEY (user_id) REFERENCES u402528120_hmis.users(id) ON DELETE CASCADE
);


-- #############################################################################
-- 2D — MANAGER ROLE      << RUN THIS BEFORE 2C >>
-- Adds MANAGER to the users.base_role ENUM.
--
-- Until this runs, MySQL in non-strict mode COERCES an out-of-ENUM write to '',
-- so promoting someone to MANAGER silently stripped them of every role default.
-- staff.php now reads this ENUM at runtime and only offers MANAGER once the
-- column can store it, so the hazard is already closed in code — running this
-- is what makes the role selectable at all.
--
-- MODIFY, not ADD: this rewrites the ENUM definition in place. The value list
-- must contain EVERY value already in use, or MySQL coerces the rows it cannot
-- represent to '' — which is the very failure this migration exists to prevent.
-- Older schemas here carried a NURSE role that collapse_roles_to_staff.sql
-- removed, so check before rewriting.
--
-- PRE-FLIGHT — run this first. It must return ZERO rows:
--
--   SELECT DISTINCT base_role, COUNT(*) AS users
--     FROM u402528120_hmis.users
--    WHERE base_role NOT IN ('ADMIN','DOCTOR','MANAGER','STAFF')
--    GROUP BY base_role;
--
--   0 rows  -> safe, run the ALTER below.
--   any row -> STOP. Add those values to the list below, or reassign those
--              users first. Running it as-is would blank their role.
-- #############################################################################

ALTER TABLE u402528120_hmis.users
    MODIFY COLUMN base_role ENUM('ADMIN','DOCTOR','MANAGER','STAFF') NOT NULL DEFAULT 'STAFF';


-- #############################################################################
-- 2C — EXPENSE APPROVALS      << RUN 2D FIRST >>
--
-- FLAT REWRITE of sql/add_expense_approvals.sql, which cannot run on this host
-- (it uses DELIMITER + CREATE PROCEDURE; this DB user is denied CREATE ROUTINE,
-- #1044). Same end state, no procedures.
--
-- Why this matters operationally: without it there is NO approval flow, while a
-- posted over-limit voucher STILL reduces expected_cash — so the cashier is
-- short at every close with nothing on screen explaining why.
--
-- The five ALTERs are not idempotent; #1060 on any of them means that column is
-- already there. Everything after them is guarded and safe to re-run.
-- #############################################################################

ALTER TABLE u402528120_hmis.expenses
    ADD COLUMN approval_status ENUM('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING' AFTER posted_by_id;
ALTER TABLE u402528120_hmis.expenses
    ADD COLUMN approved_by_id INT NULL AFTER approval_status;
ALTER TABLE u402528120_hmis.expenses
    ADD COLUMN approved_at TIMESTAMP NULL AFTER approved_by_id;
ALTER TABLE u402528120_hmis.expenses
    ADD COLUMN rejection_reason VARCHAR(255) NULL AFTER approved_at;
ALTER TABLE u402528120_hmis.expenses
    ADD INDEX idx_expense_approval (approval_status);

-- ON DELETE SET NULL matches the codebase convention: deleting a user who once
-- approved an expense must not be blocked. #1061/#1826 = already present.
ALTER TABLE u402528120_hmis.expenses
    ADD CONSTRAINT fk_expense_approved_by
    FOREIGN KEY (approved_by_id) REFERENCES u402528120_hmis.users(id) ON DELETE SET NULL;

-- Single-use magic-link tokens. Only the SHA-256 hash is stored, so a leaked
-- database never yields a working link.
CREATE TABLE IF NOT EXISTS u402528120_hmis.expense_approval_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    expense_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,   -- SHA-256 hex of the raw token
    expires_at TIMESTAMP NOT NULL,
    used_at TIMESTAMP NULL,
    used_by_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (expense_id) REFERENCES u402528120_hmis.expenses(id) ON DELETE CASCADE,
    FOREIGN KEY (used_by_id) REFERENCES u402528120_hmis.users(id) ON DELETE SET NULL,
    INDEX idx_token_expense (expense_id)
);

-- Expenses that existed BEFORE this migration were posted under the old
-- no-approval rule — approve them so they do not retroactively appear as
-- "awaiting approval". A token row marks an expense that went through the NEW
-- flow, so only rows without one are back-filled; genuinely pending fresh rows
-- are left alone, which also makes this safe to re-run.
UPDATE u402528120_hmis.expenses e
LEFT JOIN u402528120_hmis.expense_approval_tokens t ON t.expense_id = e.id
SET e.approval_status = 'APPROVED'
WHERE e.approval_status = 'PENDING' AND t.id IS NULL;

-- Who may approve/reject from inside the app. The 60-minute magic link stands
-- in for this for the life of the link.
INSERT INTO u402528120_hmis.permissions (`key`, label, category)
SELECT * FROM (
    SELECT 'FINANCIAL_APPROVE_EXPENSES' AS `key`,
           'Approve or reject posted expenses' AS label,
           'financial' AS category
) AS seed
WHERE NOT EXISTS (
    SELECT 1 FROM u402528120_hmis.permissions p WHERE p.`key` = seed.`key`
);

-- Granted to ADMIN and MANAGER. This is why 2D must run first: on a database
-- without the MANAGER ENUM value this row is written against a role no user
-- can actually hold.
INSERT INTO u402528120_hmis.role_permissions (base_role, permission_id)
SELECT r.base_role, p.id
FROM (
    SELECT 'ADMIN' AS base_role
    UNION ALL SELECT 'MANAGER'
) r
JOIN u402528120_hmis.permissions p ON p.`key` = 'FINANCIAL_APPROVE_EXPENSES'
WHERE NOT EXISTS (
    SELECT 1 FROM u402528120_hmis.role_permissions rp
    WHERE rp.base_role = r.base_role AND rp.permission_id = p.id
);


-- #############################################################################
-- 2E — LOCK THE FINANCE REPORTS TO ADMIN
--
-- Not a schema change — a grant change, and the only SECURITY item in this
-- file. While it is unapplied, any non-admin still holding
-- FINANCIAL_VIEW_CLINIC_REPORTS can open pnl_report.php by typing the URL,
-- even though no link is shown to them.
--
-- Fully idempotent. Safe to run whatever STEP1 said.
--
-- NOTE: permissions are re-read on every request, so this takes effect on the
-- affected users' very next page load. No re-login needed.
-- #############################################################################

-- 1. Strip the finance keys from every role default EXCEPT ADMIN.
DELETE rp FROM u402528120_hmis.role_permissions rp
  JOIN u402528120_hmis.permissions p ON p.id = rp.permission_id
 WHERE p.`key` IN (
        'FINANCIAL_VIEW_ALL_COMMISSIONS',
        'FINANCIAL_VIEW_DAILY_PL',
        'FINANCIAL_VIEW_CLINIC_REPORTS',
        'FINANCIAL_RUN_PAYOUT'
       )
   AND rp.base_role <> 'ADMIN';

-- 2. Belt and braces: make sure ADMIN holds all four, so locking the others
--    down can never leave the reports unreachable by anybody.
INSERT IGNORE INTO u402528120_hmis.role_permissions (base_role, permission_id)
SELECT 'ADMIN', p.id
  FROM u402528120_hmis.permissions p
 WHERE p.`key` IN (
        'FINANCIAL_VIEW_ALL_COMMISSIONS',
        'FINANCIAL_VIEW_DAILY_PL',
        'FINANCIAL_VIEW_CLINIC_REPORTS',
        'FINANCIAL_RUN_PAYOUT'
       );

-- 3. This strips ROLE DEFAULTS only. A per-user override granted by hand is
--    deliberate and is left alone — run this to see any that remain:
--
--    SELECT u.name, u.base_role, p.`key`
--      FROM u402528120_hmis.user_permission_overrides o
--      JOIN u402528120_hmis.permissions p ON p.id = o.permission_id
--      JOIN u402528120_hmis.users u       ON u.id = o.user_id
--     WHERE o.granted = 1
--       AND p.`key` IN ('FINANCIAL_VIEW_ALL_COMMISSIONS','FINANCIAL_VIEW_DAILY_PL',
--                       'FINANCIAL_VIEW_CLINIC_REPORTS','FINANCIAL_RUN_PAYOUT')
--       AND u.base_role <> 'ADMIN';


-- #############################################################################
-- 2F — ADMISSION MANUAL DISCOUNT
--
-- FLAT REWRITE of sql/add_admission_manual_discount.sql, which cannot run on
-- this host for the same #1044 reason as 2C.
--
-- The rupee amount is authoritative; the pct is stored for the slip and audit
-- trail only (a % entry is converted to an amount on save so later line edits
-- cannot silently re-scale the discount).
-- #############################################################################

ALTER TABLE u402528120_hmis.admission_bills
    ADD COLUMN manual_discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0;
ALTER TABLE u402528120_hmis.admission_bills
    ADD COLUMN manual_discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0;
ALTER TABLE u402528120_hmis.admission_bills
    ADD COLUMN manual_discount_by_id INT NULL;


-- #############################################################################
-- 2G — PER-DOCTOR INVOICE PAPER SIZE
-- The consultation slip prints in the visiting doctor's saved size.
-- #############################################################################

ALTER TABLE u402528120_hmis.users
    ADD COLUMN invoice_paper_size ENUM('A5','A4') NOT NULL DEFAULT 'A5';


-- #############################################################################
-- 2H — PROCEDURE CONSENT      << RUN THIS BEFORE 2I >>
--
-- Paste the whole of sql/add_procedure_consent.sql. It is long, carries its own
-- verification block, and seeds the Circumcision template — reproducing it here
-- would risk a transcription error in wording that gets printed and signed.
--
-- IMPORTANT: this one fails SILENTLY when unapplied. Every consent path in the
-- app sits behind a schema probe, so a missing migration looks exactly like
-- "this procedure has no consent form" — no error is ever raised.
-- #############################################################################


-- #############################################################################
-- 2I — CONSENT SIGNER CNIC      << 2H MUST BE APPLIED FIRST >>
--
-- Places its column AFTER signed_relation, which 2H creates. On a database
-- where 2H has not run this FAILS, and some clients report that failure in a
-- way that looks like success while adding nothing.
--
-- Confirm the prerequisite first — this must return 2 rows:
--
--   SELECT COLUMN_NAME FROM information_schema.COLUMNS
--    WHERE TABLE_SCHEMA = 'u402528120_hmis' AND TABLE_NAME = 'dental_consents'
--      AND COLUMN_NAME IN ('signed_name','signed_relation');
--
--   0 or 1 rows -> STOP, run 2H first.   2 rows -> proceed.
--
-- VARCHAR(15), not an integer: a CNIC is written 00000-0000000-0, it is never
-- arithmetic, and a numeric column would eat a leading zero.
-- #############################################################################

ALTER TABLE u402528120_hmis.dental_consents
    ADD COLUMN signed_cnic VARCHAR(15) NULL
        COMMENT 'CNIC of the consent giver, as written 00000-0000000-0. NULL = filled by hand on the sheet.'
        AFTER signed_relation;


-- =============================================================================
-- DONE — NOW VERIFY
--
-- Re-run sql/STEP1_check_pending.sql. Every row must read 'already ok'.
--
-- Then confirm the one item that is a live security hole, empirically: sign in
-- as a non-admin who holds FINANCIAL_VIEW_CLINIC_REPORTS and request
-- /pnl_report.php directly. It must refuse.
--
-- Two of these also need a code deploy to become visible (the notification bell
-- and the consent sheet). Applying the migration alone changes nothing on
-- screen for those two — that is expected, not a failed migration.
-- =============================================================================
