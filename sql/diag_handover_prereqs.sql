-- =============================================================================
-- Prerequisite check before running add_per_user_closings.sql (read-only).
-- That file depends on add_shift_closings.sql AND add_closing_expenses.sql.
-- Every row must read present = 1 before you proceed.
-- =============================================================================

SELECT 'shift_closings (table)'  AS object_checked, COUNT(*) AS present, 'sql/add_shift_closings.sql' AS migration
FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_name = 'shift_closings'

UNION ALL
SELECT 'closing_sequences (table)', COUNT(*), 'sql/add_shift_closings.sql'
FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_name = 'closing_sequences'

UNION ALL
SELECT 'shift_closings.cashier_id', COUNT(*), 'sql/add_shift_closings.sql'
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = 'shift_closings' AND column_name = 'cashier_id'

UNION ALL
SELECT 'shift_closings.printed_at', COUNT(*), 'sql/add_shift_closings.sql'
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = 'shift_closings' AND column_name = 'printed_at'

UNION ALL
SELECT 'shift_closings.expense_total', COUNT(*), 'sql/add_closing_expenses.sql'
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = 'shift_closings' AND column_name = 'expense_total'

UNION ALL
SELECT 'refunds (table)', COUNT(*), 'sql/add_refunds.sql'
FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_name = 'refunds'

UNION ALL
SELECT 'refunds.generated_by_id', COUNT(*), 'sql/add_refunds.sql'
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = 'refunds' AND column_name = 'generated_by_id'

UNION ALL
SELECT 'bills.paid_by_id (added by per_user)', COUNT(*), 'sql/add_per_user_closings.sql'
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = 'bills' AND column_name = 'paid_by_id';
