-- ============================================================================
-- Doctor Shares: record WHICH doctor was paid  (Accounts Phase 1b, 2026-07-26)
--
-- A lump-sum "Doctor Shares — Rs 300,000" posting cannot answer who was paid,
-- cannot reconcile earned-vs-paid per doctor, and cannot feed the Phase 6
-- payout statements or the tax deposit register. The doctor's name would only
-- ever live in the free-text description, which is not queryable.
--
-- This adds a real FK so every disbursement names its doctor from the first
-- posting, instead of Phase 6 having to migrate free text later.
--
-- NULL for every other category (utilities have no doctor) and for any row
-- posted before this migration — reports must treat NULL as "unattributed".
--
-- NO STORED PROCEDURE — the Hostinger DB user is denied CREATE ROUTINE
-- (#1044). Flat statements, fully qualified. Re-running section 1 throws
-- "#1060 Duplicate column name", which is harmless and just means it applied.
-- ============================================================================

-- ---- 1. The column + FK ----------------------------------------------------
-- ON DELETE SET NULL, matching how admission_bills.admitting_doctor_id and
-- refunds.paid_out_by_id already behave: deleting a user must never delete the
-- financial record of a payment that really happened.
ALTER TABLE `u402528120_hmis`.expenses
    ADD COLUMN paid_to_doctor_id INT NULL
        COMMENT 'Doctor this disbursement was paid to. NULL for ordinary expenses.'
        AFTER paid_to,
    ADD INDEX idx_expense_doctor (paid_to_doctor_id, period_month),
    ADD CONSTRAINT fk_expenses_paid_to_doctor
        FOREIGN KEY (paid_to_doctor_id) REFERENCES `u402528120_hmis`.users(id)
        ON DELETE SET NULL;

-- ---- 2. Flag which categories need a doctor --------------------------------
-- Data-driven, exactly like is_period_based: the dropdown appears because the
-- category says so, never because the code matched the name 'Doctor Shares'.
ALTER TABLE `u402528120_hmis`.expense_categories
    ADD COLUMN needs_doctor TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = posting must name the doctor being paid (disbursements)';

UPDATE `u402528120_hmis`.expense_categories
   SET needs_doctor = 1 WHERE name = 'Doctor Shares';

-- ---- Verify (read-only) ----------------------------------------------------
-- Expect Doctor Shares to read 1,1,1,1 and every other category 0 for needs_doctor.
SELECT id, name, is_period_based, is_admin_only, is_disbursement, needs_doctor
  FROM `u402528120_hmis`.expense_categories
 ORDER BY needs_doctor DESC, is_admin_only DESC, name;

-- Expect paid_to_doctor_id = int / YES.
SELECT column_name, column_type, is_nullable
  FROM information_schema.columns
 WHERE table_schema = 'u402528120_hmis' AND table_name = 'expenses'
   AND column_name IN ('paid_to', 'paid_to_doctor_id', 'period_month');
