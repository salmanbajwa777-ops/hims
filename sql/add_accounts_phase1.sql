-- ============================================================================
-- ACCOUNTS PHASE 1 — period-matched expenses + non-counter payment sources
-- (2026-07-26)
--
-- Context: there is NO accountant role. Admin does the accounting. What was
-- actually missing is reporting + a doctor payout engine. This is step 1 of 6.
--
-- WHAT THIS DOES
--   1. expenses.period_month  — the month an expense BELONGS to, when that
--      differs from the day the cash moved. NULL = petty cash (period = the
--      expense_date itself). Only Salaries and Doctor Shares use it.
--   2. expense_categories.is_period_based — drives the month picker in the UI
--      from DATA, not from hardcoded category names.
--   3. expenses.source widens CASH_COUNTER -> + BANK, OWNER. Salaries and rent
--      never leave the counter drawer; without this they corrupt the day tally.
--   4. Three admin-only categories: Salaries, Rent, Doctor Shares.
--
-- WHY period_month IS NEEDED (the bug it prevents)
--   Salaries for July get paid on, say, 5 August. With only expense_date, that
--   Rs 400,000 lands in AUGUST — July's P&L understates by 400k and August
--   overstates by the same. Rent is NOT period-based: it is paid the 8th-15th
--   of its own month, so expense_date already puts it in the right period.
--   Every MONTHLY report groups on COALESCE(period_month, expense_date).
--   Every DAILY/drawer figure keeps using expense_date alone — untouched.
--
-- DOCTOR SHARES IS A DISBURSEMENT, NOT AN OPERATING EXPENSE
--   The P&L deducts the COMPUTED (earned) doctor share above the line
--   (gross - tax - doctor share = clinic income). This posted category tracks
--   the CASH going out, so earned-vs-paid can be reconciled. Reports MUST
--   exclude it from operating expenses or profit is understated by exactly the
--   doctor share. Flagged below with is_disbursement = 1 so no future report
--   has to remember a category name.
--
-- ORDER: run verify_accounts_state.sql FIRST and read its verdicts.
-- Idempotent: information_schema-guarded. ADDITIVE. Safe to re-run.
-- Run in phpMyAdmin against the hims database BEFORE deploying the code.
-- ============================================================================

-- ---- 1 + 2 + 3. Guarded column additions ----------------------------------
DROP PROCEDURE IF EXISTS hims_accounts_phase1;
DELIMITER $$
CREATE PROCEDURE hims_accounts_phase1()
BEGIN
    -- 1. The accounting period an expense belongs to (1st of the month).
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = 'u402528120_hmis'
                     AND table_name = 'expenses'
                     AND column_name = 'period_month') THEN
        ALTER TABLE `u402528120_hmis`.expenses
            ADD COLUMN period_month DATE NULL
                COMMENT 'Month this expense belongs to (1st of month). NULL = use expense_date. Only period-based categories set it.'
                AFTER expense_date,
            ADD INDEX idx_expense_period (period_month);
    END IF;

    -- 2. Which categories demand a month picker. Data-driven, not name-matched.
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = 'u402528120_hmis'
                     AND table_name = 'expense_categories'
                     AND column_name = 'is_period_based') THEN
        ALTER TABLE `u402528120_hmis`.expense_categories
            ADD COLUMN is_period_based TINYINT(1) NOT NULL DEFAULT 0
                COMMENT '1 = paid in a different month than it belongs to; UI must ask which month';
    END IF;

    -- 2b. Admin-only categories (a receptionist must not post a salary).
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = 'u402528120_hmis'
                     AND table_name = 'expense_categories'
                     AND column_name = 'is_admin_only') THEN
        ALTER TABLE `u402528120_hmis`.expense_categories
            ADD COLUMN is_admin_only TINYINT(1) NOT NULL DEFAULT 0
                COMMENT '1 = only an admin may post under this category';
    END IF;

    -- 2c. Disbursement, not an operating expense. Keeps the P&L honest without
    --     any report having to hardcode 'Doctor Shares'.
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = 'u402528120_hmis'
                     AND table_name = 'expense_categories'
                     AND column_name = 'is_disbursement') THEN
        ALTER TABLE `u402528120_hmis`.expense_categories
            ADD COLUMN is_disbursement TINYINT(1) NOT NULL DEFAULT 0
                COMMENT '1 = money passed through, NOT a clinic operating cost. Excluded from P&L expenses.';
    END IF;

    -- 3. Where the money came from. The original migration explicitly left the
    --    enum room to grow ("a petty-cash float or bank later"); we need it now.
    --    day_cash_tally() already filters source = 'CASH_COUNTER', so widening
    --    this is what keeps a Rs 400,000 salary out of the receptionist's drawer.
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = 'u402528120_hmis'
                     AND table_name = 'expenses'
                     AND column_name = 'source'
                     AND column_type LIKE '%BANK%') THEN
        ALTER TABLE `u402528120_hmis`.expenses
            MODIFY COLUMN source ENUM('CASH_COUNTER','BANK','OWNER')
                NOT NULL DEFAULT 'CASH_COUNTER'
                COMMENT 'CASH_COUNTER = left the reception drawer (hits the day tally). BANK/OWNER = paid outside the counter.';
    END IF;
END $$
DELIMITER ;

CALL hims_accounts_phase1();
DROP PROCEDURE IF EXISTS hims_accounts_phase1;

-- ---- 4. The three new categories ------------------------------------------
-- shift_limit = 0 (no per-shift cap): these are large monthly postings, and a
-- cap only makes sense for counter petty cash.
INSERT INTO `u402528120_hmis`.expense_categories (name, shift_limit, is_period_based, is_admin_only, is_disbursement)
SELECT * FROM (
    -- Paid AFTER the month worked -> must be asked which month it is for.
    SELECT 'Salaries'      AS name, 0 AS shift_limit, 1 AS is_period_based, 1 AS is_admin_only, 0 AS is_disbursement
    -- Paid the 8th-15th of its OWN month -> expense_date is already correct.
    UNION ALL SELECT 'Rent',          0, 0, 1, 0
    -- Pass-through to doctors. Period-based (July's share paid in August) AND a
    -- disbursement, so the P&L never double-counts it as an operating expense.
    UNION ALL SELECT 'Doctor Shares', 0, 1, 1, 1
) AS seed
WHERE NOT EXISTS (
    SELECT 1 FROM `u402528120_hmis`.expense_categories ec WHERE ec.name = seed.name
);

-- Re-assert the flags in case the categories were created by hand earlier
-- without them (a plain INSERT above would have been skipped in that case).
UPDATE `u402528120_hmis`.expense_categories SET is_period_based = 1, is_admin_only = 1, is_disbursement = 0 WHERE name = 'Salaries';
UPDATE `u402528120_hmis`.expense_categories SET is_period_based = 0, is_admin_only = 1, is_disbursement = 0 WHERE name = 'Rent';
UPDATE `u402528120_hmis`.expense_categories SET is_period_based = 1, is_admin_only = 1, is_disbursement = 1 WHERE name = 'Doctor Shares';

-- ---- Verify (read-only) ---------------------------------------------------
-- Expect: Salaries (period 1, admin 1, disb 0), Rent (0,1,0),
--         Doctor Shares (1,1,1), and every pre-existing category (0,0,0).
SELECT id, name, shift_limit, is_period_based, is_admin_only, is_disbursement
  FROM `u402528120_hmis`.expense_categories ORDER BY is_admin_only DESC, name;

-- Expect source to read enum('CASH_COUNTER','BANK','OWNER') and period_month to exist.
SELECT column_name, column_type, is_nullable
  FROM information_schema.columns
 WHERE table_schema = 'u402528120_hmis' AND table_name = 'expenses'
   AND column_name IN ('source', 'expense_date', 'period_month');
