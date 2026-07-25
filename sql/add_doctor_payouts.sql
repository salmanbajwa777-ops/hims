-- ============================================================================
-- DOCTOR PAYOUT ENGINE  (Accounts Phase 6, 2026-07-26)
--
-- doctor_share_statement.php already shows the right numbers, but records
-- NOTHING — the only trace of a payout today is a Doctor Shares expense row
-- with an amount and a month, so nothing can answer "which bills was I paid
-- for?" six months later.
--
-- WHY SNAPSHOTS
--   Everything else reads live rows. A bill voided in August silently rewrites
--   a July already paid out against; a refund reduces a settled month; and
--   because consult_share_pct is read live, moving a doctor 70% -> 75%
--   retroactively claims every past month underpaid them. So a payout freezes
--   its figures INCLUDING the share and tax percentages in force at the time.
--
-- CLAWBACKS (decided 2026-07-26)
--   A bill voided or refunded after its payout is settled becomes a NEGATIVE
--   line on the next payout, at the ORIGINALLY PAID figures — never recomputed
--   at today's rate, which would over-claw if the doctor's % has since risen.
--
-- CARRY-FORWARD (decided 2026-07-26)
--   A clawback can exceed a period's earnings. The payout is still created and
--   shows the negative figure, but no cash moves and the shortfall carries to
--   the next payout. Nobody is asked to hand money back.
--
-- NO STORED PROCEDURE: the Hostinger DB user is denied CREATE ROUTINE (#1044).
-- Flat statements, fully qualified, guarded INSERTs. Re-runnable.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `u402528120_hmis`.doctor_payouts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payout_number  VARCHAR(30) UNIQUE NOT NULL,        -- DP-2026-0001
    doctor_id      INT NOT NULL,

    -- "Paid up to this date" is recorded, not inferred. The next payout for a
    -- doctor defaults to the day after their last settled period_end, so gaps
    -- and overlaps cannot be created by accident.
    period_start   DATE NOT NULL,
    period_end     DATE NOT NULL,

    -- ---- Frozen figures ----
    gross_amount   DECIMAL(12,2) NOT NULL DEFAULT 0,   -- fees the work generated
    tax_withheld   DECIMAL(12,2) NOT NULL DEFAULT 0,
    earned_amount  DECIMAL(12,2) NOT NULL DEFAULT 0,   -- doctor's share of THIS period
    clawback_amount DECIMAL(12,2) NOT NULL DEFAULT 0,  -- positive number, deducted
    carried_in     DECIMAL(12,2) NOT NULL DEFAULT 0,   -- debt brought forward
    carried_out    DECIMAL(12,2) NOT NULL DEFAULT 0,   -- debt going forward
    net_paid       DECIMAL(12,2) NOT NULL DEFAULT 0,   -- cash actually paid (never < 0)

    -- ---- Rate snapshot. Without these, editing a doctor's % silently
    --      re-prices every historical payout. ----
    share_pct      DECIMAL(5,2) NOT NULL DEFAULT 0,
    has_tax        TINYINT(1)   NOT NULL DEFAULT 0,
    tax_pct        DECIMAL(5,2) NOT NULL DEFAULT 0,

    -- The Doctor Shares expense row that moved the cash. NULL while draft, and
    -- NULL forever on a fully-carried-forward payout where no cash moved.
    expense_id     INT NULL,

    status         ENUM('draft','paid','voided') NOT NULL DEFAULT 'draft',
    paid_at        TIMESTAMP NULL,
    notes          VARCHAR(255) NULL,

    created_by_id  INT NOT NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    voided_at      TIMESTAMP NULL,
    voided_by_id   INT NULL,
    void_reason    VARCHAR(255) NULL,

    INDEX idx_payout_doctor (doctor_id, period_end),
    INDEX idx_payout_status (status, period_end),
    FOREIGN KEY (doctor_id)     REFERENCES `u402528120_hmis`.users(id),
    FOREIGN KEY (created_by_id) REFERENCES `u402528120_hmis`.users(id),
    FOREIGN KEY (voided_by_id)  REFERENCES `u402528120_hmis`.users(id)
);

CREATE TABLE IF NOT EXISTS `u402528120_hmis`.doctor_payout_lines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payout_id     INT NOT NULL,

    source_type   ENUM('OPD','IPD') NOT NULL,
    source_id     INT NOT NULL,              -- bills.id or ipd_doctor_visits.id
    occurred_on   DATE NOT NULL,
    patient_name  VARCHAR(255) NULL,         -- snapshot; survives a rename/merge

    -- NEGATIVE on a clawback line, so SUM() over a payout is always its true
    -- value with no CASE on line_kind anywhere in the reporting.
    gross         DECIMAL(10,2) NOT NULL DEFAULT 0,
    tax           DECIMAL(10,2) NOT NULL DEFAULT 0,
    doctor_share  DECIMAL(10,2) NOT NULL DEFAULT 0,

    -- EARNING  = work done in this payout's own period
    -- CLAWBACK = reversal of something paid on an EARLIER payout
    line_kind     ENUM('EARNING','CLAWBACK') NOT NULL DEFAULT 'EARNING',
    clawback_of_payout_id INT NULL,
    clawback_reason VARCHAR(255) NULL,

    -- THE load-bearing constraint. Clawbacks are found by re-scanning
    -- previously-paid source rows for voids/refunds, so without this a bill
    -- voided in August is deducted again in September, and again in October.
    -- Permits exactly one EARNING and one CLAWBACK per source row: paid once,
    -- reversed at most once.
    UNIQUE KEY uniq_source_kind (source_type, source_id, line_kind),
    INDEX idx_line_payout (payout_id),
    FOREIGN KEY (payout_id) REFERENCES `u402528120_hmis`.doctor_payouts(id) ON DELETE CASCADE,
    FOREIGN KEY (clawback_of_payout_id) REFERENCES `u402528120_hmis`.doctor_payouts(id) ON DELETE SET NULL
);

-- Yearly counter for DP-YYYY-NNNN, same pattern as expense/refund sequences.
CREATE TABLE IF NOT EXISTS `u402528120_hmis`.payout_sequences (
    sequence_year INT PRIMARY KEY,
    last_sequence INT NOT NULL DEFAULT 0
);

-- ---- Permission ------------------------------------------------------------
-- Running a payout moves money and freezes a record, so it is its own key
-- rather than riding on FINANCIAL_VIEW_ALL_COMMISSIONS (which only reads).
-- ADMIN only by decision: not part of the MANAGER bundle. An admin can still
-- grant it per-user from the Staff screen.
INSERT INTO `u402528120_hmis`.permissions (`key`, label, category)
SELECT * FROM (
    SELECT 'FINANCIAL_RUN_PAYOUT' AS `key`,
           'Create and settle doctor payouts' AS label,
           'managerial' AS category
) AS seed
WHERE NOT EXISTS (
    SELECT 1 FROM `u402528120_hmis`.permissions p WHERE p.`key` = seed.`key`
);

INSERT INTO `u402528120_hmis`.role_permissions (base_role, permission_id)
SELECT 'ADMIN', p.id
FROM `u402528120_hmis`.permissions p
WHERE p.`key` = 'FINANCIAL_RUN_PAYOUT'
  AND NOT EXISTS (
      SELECT 1 FROM `u402528120_hmis`.role_permissions rp
      WHERE rp.base_role = 'ADMIN' AND rp.permission_id = p.id
  );

-- ---- Verify (read-only) ----------------------------------------------------
SELECT table_name,
       CASE WHEN table_name IS NULL THEN 'MISSING' ELSE 'EXISTS' END AS verdict
  FROM information_schema.tables
 WHERE table_schema = 'u402528120_hmis'
   AND table_name IN ('doctor_payouts', 'doctor_payout_lines', 'payout_sequences');

SELECT p.`key`, p.category,
       CASE WHEN rp.permission_id IS NULL THEN 'NOT granted' ELSE 'granted to ADMIN' END AS admin_grant
  FROM `u402528120_hmis`.permissions p
  LEFT JOIN `u402528120_hmis`.role_permissions rp
         ON rp.permission_id = p.id AND rp.base_role = 'ADMIN'
 WHERE p.`key` = 'FINANCIAL_RUN_PAYOUT';
