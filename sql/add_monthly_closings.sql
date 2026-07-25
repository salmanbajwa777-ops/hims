-- ============================================================================
-- MONTH-END CLOSE  (Accounts Phase 5, 2026-07-26)
--
-- Freezes a month's profit & loss. A closed month renders from these STORED
-- NUMBERS and never recomputes, because everything else in the app reads live
-- rows — correct for a view, wrong for a record. Without this, a bill voided in
-- August silently rewrites a July that was already reported on and paid out
-- against, and editing a doctor's consult_share_pct re-prices every past month.
--
-- Closing also blocks back-dated postings into the month (see
-- require_month_open() in config/billing.php). expenses.expense_date is
-- admin-editable, so a closed month would otherwise be trivially rewritable.
--
-- Reopening is admin-only, reason-required and audit-logged — deliberately not
-- casual, since the whole point is that a closed month stays closed.
--
-- NO STORED PROCEDURE: the Hostinger DB user is denied CREATE ROUTINE (#1044).
-- Flat statements, fully qualified. CREATE TABLE IF NOT EXISTS is naturally
-- re-runnable; the permission INSERTs are guarded by NOT EXISTS.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `u402528120_hmis`.monthly_closings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    -- First of the month. UNIQUE so a month cannot be closed twice.
    period_month    DATE NOT NULL UNIQUE,

    -- ---- The frozen P&L. Stored, never recomputed. ----
    gross_revenue   DECIMAL(12,2) NOT NULL DEFAULT 0,
    refunds         DECIMAL(12,2) NOT NULL DEFAULT 0,
    tax_withheld    DECIMAL(12,2) NOT NULL DEFAULT 0,
    doctor_shares   DECIMAL(12,2) NOT NULL DEFAULT 0,
    clinic_income   DECIMAL(12,2) NOT NULL DEFAULT 0,
    operating_costs DECIMAL(12,2) NOT NULL DEFAULT 0,
    net_profit      DECIMAL(12,2) NOT NULL DEFAULT 0,

    -- Per-stream and per-category detail as JSON, so a closed month can render
    -- its full breakdown without touching a single live table. TEXT rather than
    -- JSON for MariaDB portability on shared hosting.
    detail_json     TEXT NULL,

    closed_by_id    INT NOT NULL,
    closed_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Reopening keeps the row for audit rather than deleting it; a reopened
    -- month reads as OPEN again (month_closing() filters reopened_at IS NULL).
    reopened_at     TIMESTAMP NULL,
    reopened_by_id  INT NULL,
    reopen_reason   VARCHAR(255) NULL,

    INDEX idx_closing_period (period_month, reopened_at),
    FOREIGN KEY (closed_by_id)   REFERENCES `u402528120_hmis`.users(id),
    FOREIGN KEY (reopened_by_id) REFERENCES `u402528120_hmis`.users(id)
);

-- ---- Permissions -----------------------------------------------------------
-- FINANCIAL_VIEW_DAILY_PL already exists in the catalog (seeded at the RBAC
-- overhaul, checked by nothing until now — pnl_report.php is its first user).
-- FINANCIAL_CLOSE_MONTH is new: closing the books is a heavier act than
-- reading them, so it is a separate key rather than riding on the view.
INSERT INTO `u402528120_hmis`.permissions (`key`, label, category)
SELECT * FROM (
    SELECT 'FINANCIAL_CLOSE_MONTH' AS `key`,
           'Close / reopen the monthly books' AS label,
           'managerial' AS category
) AS seed
WHERE NOT EXISTS (
    SELECT 1 FROM `u402528120_hmis`.permissions p WHERE p.`key` = seed.`key`
);

-- Grant both keys to ADMIN. Nothing else gets FINANCIAL_CLOSE_MONTH by default:
-- an admin can grant it per-user from the Staff screen if a manager needs it.
INSERT INTO `u402528120_hmis`.role_permissions (base_role, permission_id)
SELECT 'ADMIN', p.id
FROM `u402528120_hmis`.permissions p
WHERE p.`key` IN ('FINANCIAL_CLOSE_MONTH', 'FINANCIAL_VIEW_DAILY_PL')
  AND NOT EXISTS (
      SELECT 1 FROM `u402528120_hmis`.role_permissions rp
      WHERE rp.base_role = 'ADMIN' AND rp.permission_id = p.id
  );

-- ---- Verify (read-only) ----------------------------------------------------
-- Expect the table to exist and be empty on a first run.
SELECT COUNT(*) AS closings_so_far FROM `u402528120_hmis`.monthly_closings;

-- Expect both keys present and granted to ADMIN.
SELECT p.`key`, p.category,
       CASE WHEN rp.permission_id IS NULL THEN 'NOT granted to ADMIN' ELSE 'granted to ADMIN' END AS admin_grant
  FROM `u402528120_hmis`.permissions p
  LEFT JOIN `u402528120_hmis`.role_permissions rp
         ON rp.permission_id = p.id AND rp.base_role = 'ADMIN'
 WHERE p.`key` IN ('FINANCIAL_CLOSE_MONTH', 'FINANCIAL_VIEW_DAILY_PL');
