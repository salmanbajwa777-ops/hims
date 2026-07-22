-- Day closing now deducts counter expenses (EXP- vouchers, source CASH_COUNTER)
-- from the expected-cash figure:
--
--   expected cash = float + cash payments − cash refunds − expenses
--
-- Snapshot columns keep the printed DC- slip reproducible even if an expense
-- is later voided. Voided expenses are excluded at closing time; voiding an
-- expense on an already-closed date is blocked in expenses.php (day-lock).
--
-- Idempotent via a guarded stored procedure (MySQL has no ADD COLUMN IF NOT
-- EXISTS). Depends on: add_shift_closings.sql, add_expenses.sql (both run).

DROP PROCEDURE IF EXISTS closing_expenses_migrate;
DELIMITER $$
CREATE PROCEDURE closing_expenses_migrate()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'shift_closings'
          AND column_name = 'expense_total'
    ) THEN
        ALTER TABLE shift_closings
            ADD COLUMN expense_total DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER cash_refund_count,
            ADD COLUMN expense_count INT NOT NULL DEFAULT 0 AFTER expense_total;
    END IF;
END$$
DELIMITER ;
CALL closing_expenses_migrate();
DROP PROCEDURE closing_expenses_migrate;
