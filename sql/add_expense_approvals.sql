-- Expense approvals: every posted expense now needs a manager/admin sign-off.
--
-- Decisions (2026-07-24):
--   * Posting is unchanged — category + overall shift limits still HARD-BLOCK an
--     over-cap posting up front, and the cash still leaves the drawer the moment
--     it is posted (so a PENDING expense keeps counting toward the shift tally).
--   * A posted expense starts PENDING. An email fans out to every ADMIN + MANAGER
--     (and the admin alert address) carrying a signed, single-use, 60-minute
--     magic link straight to the approval page — one click, no login needed.
--   * If the 60 minutes lapse, any admin/manager can still Approve or Reject from
--     inside the app (approve_expense.php or the Expenses page) at any time.
--   * REJECTED means the cash is to be returned to the drawer — a rejected row is
--     excluded from every total and the shift tally, exactly like a voided one.
--   * Voiding (admin only) is unchanged and independent of approval.
--
-- Idempotent: uses a guarded stored procedure for the column adds (same pattern
-- as add_revisit_billing.sql), and CREATE TABLE / INSERT ... WHERE NOT EXISTS
-- elsewhere. Safe to paste into phpMyAdmin repeatedly. Timestamps are PKT (the
-- DB session is pinned +05:00).

-- 1. Approval state on the expense row (guarded — safe to re-run).
DROP PROCEDURE IF EXISTS hims_add_expense_approval_columns;
DELIMITER $$
CREATE PROCEDURE hims_add_expense_approval_columns()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = DATABASE() AND table_name = 'expenses' AND column_name = 'approval_status') THEN
        ALTER TABLE expenses ADD COLUMN approval_status
            ENUM('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING' AFTER posted_by_id;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = DATABASE() AND table_name = 'expenses' AND column_name = 'approved_by_id') THEN
        ALTER TABLE expenses ADD COLUMN approved_by_id INT NULL AFTER approval_status;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = DATABASE() AND table_name = 'expenses' AND column_name = 'approved_at') THEN
        ALTER TABLE expenses ADD COLUMN approved_at TIMESTAMP NULL AFTER approved_by_id;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = DATABASE() AND table_name = 'expenses' AND column_name = 'rejection_reason') THEN
        ALTER TABLE expenses ADD COLUMN rejection_reason VARCHAR(255) NULL AFTER approved_at;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.statistics
                   WHERE table_schema = DATABASE() AND table_name = 'expenses' AND index_name = 'idx_expense_approval') THEN
        ALTER TABLE expenses ADD INDEX idx_expense_approval (approval_status);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.table_constraints
                   WHERE constraint_schema = DATABASE() AND table_name = 'expenses'
                     AND constraint_name = 'fk_expense_approved_by') THEN
        -- ON DELETE SET NULL to match the codebase convention (audit_logs etc.):
        -- deleting a user who once approved an expense must not be blocked.
        ALTER TABLE expenses ADD CONSTRAINT fk_expense_approved_by
            FOREIGN KEY (approved_by_id) REFERENCES users(id) ON DELETE SET NULL;
    END IF;
END$$
DELIMITER ;
CALL hims_add_expense_approval_columns();
DROP PROCEDURE IF EXISTS hims_add_expense_approval_columns;

-- 2. Single-use magic-link tokens (created before the backfill below, which
-- references it). The RAW token travels in the email link; only its SHA-256 hash
-- is stored, so a leaked database never yields a working link. One row per posted
-- expense; the token expires 60 minutes after posting and is burned (used_at) on
-- first action.
CREATE TABLE IF NOT EXISTS expense_approval_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    expense_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,   -- SHA-256 hex of the raw token
    expires_at TIMESTAMP NOT NULL,
    used_at TIMESTAMP NULL,
    used_by_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (expense_id) REFERENCES expenses(id) ON DELETE CASCADE,
    FOREIGN KEY (used_by_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_token_expense (expense_id)
);

-- Any expenses that existed BEFORE this migration were posted under the old
-- no-approval rule; treat them as already approved so they don't retroactively
-- show up "awaiting approval". A token row marks an expense that went through the
-- NEW flow, so we only approve rows that have no token — leaving genuinely
-- pending, freshly-posted rows alone on a re-run. New rows default to PENDING.
UPDATE expenses e
LEFT JOIN expense_approval_tokens t ON t.expense_id = e.id
SET e.approval_status = 'APPROVED'
WHERE e.approval_status = 'PENDING' AND t.id IS NULL;

-- 3. Permission: who may approve/reject an expense from inside the app.
-- The 60-min magic link stands in for this for the life of the link (the signed
-- token is the authorization).
INSERT INTO permissions (`key`, label, category)
SELECT * FROM (
    SELECT 'FINANCIAL_APPROVE_EXPENSES' AS `key`,
           'Approve or reject posted expenses' AS label,
           'financial' AS category
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM permissions p WHERE p.`key` = seed.`key`);

INSERT INTO role_permissions (base_role, permission_id)
SELECT r.base_role, p.id
FROM (
    SELECT 'ADMIN' AS base_role
    UNION ALL SELECT 'MANAGER'
) r
JOIN permissions p ON p.`key` = 'FINANCIAL_APPROVE_EXPENSES'
WHERE NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.base_role = r.base_role AND rp.permission_id = p.id
);
