-- =============================================================================
-- DIAGNOSTIC (read-only) — which column is missing behind the
-- admin_handovers.php HTTP 500? Run in phpMyAdmin against the HMIS database.
--
-- Every row should read present = 1. Any row with present = 0 names the
-- migration you still need to run (right-hand column).
-- =============================================================================

SELECT 'shift_closings.edit_count'         AS column_checked,
       COUNT(*)                            AS present,
       'sql/add_per_user_closings.sql'     AS migration
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = 'shift_closings' AND column_name = 'edit_count'

UNION ALL
SELECT 'shift_closings.edited_at', COUNT(*), 'sql/add_per_user_closings.sql'
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = 'shift_closings' AND column_name = 'edited_at'

UNION ALL
SELECT 'shift_closing_edits (table)', COUNT(*), 'sql/add_per_user_closings.sql'
FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_name = 'shift_closing_edits'

UNION ALL
SELECT 'refunds.paid_out_by_id', COUNT(*), 'sql/add_refund_paid_out_by.sql'
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = 'refunds' AND column_name = 'paid_out_by_id'

UNION ALL
SELECT 'shift_closings.closed_by_admin_id', COUNT(*), 'sql/add_admin_late_close.sql'
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = 'shift_closings' AND column_name = 'closed_by_admin_id';

-- The 'EDITED' status enum value also comes from add_per_user_closings.sql.
-- The page's $pending query filters on status IN ('PENDING_RECEIPT','EDITED'),
-- which errors (or warns) if the enum was never widened.
SELECT COLUMN_TYPE AS shift_closings_status_enum
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = 'shift_closings' AND column_name = 'status';
