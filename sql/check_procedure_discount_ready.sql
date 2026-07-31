-- ============================================================================
-- READ-ONLY PRE-FLIGHT for the procedure flat-discount + doctor sign-off work.
--
-- Run this FIRST, in phpMyAdmin, before add_procedure_manual_discount.sql.
-- It writes nothing. It answers three questions:
--
--   1. Does the notifications table exist? If it does NOT, the whole feature
--      is inert: notifications_ready() (config/notifications.php:42) catches
--      the missing table, returns false, and every notify_users() call drops
--      silently. No error, no white screen -- the doctor is simply never told.
--
--   2. Has the procedure discount migration already run?
--
--   3. Do the tables it depends on actually exist?
--
-- Read the STATUS column. Anything that is not 'OK' is a blocker.
-- ============================================================================

-- ---------------------------------------------------------------- 1. SUMMARY
-- One row per prerequisite. This is the answer -- everything below is detail.
SELECT
    'notifications table'                       AS what,
    CASE WHEN COUNT(*) > 0 THEN 'OK - exists'
         ELSE '** MISSING -- run sql/add_notifications.sql FIRST, or the doctor is never notified **'
    END                                         AS status
FROM information_schema.tables
WHERE table_schema = 'u402528120_hmis' AND table_name = 'notifications'

UNION ALL SELECT
    'procedure_bills table',
    CASE WHEN COUNT(*) > 0 THEN 'OK - exists'
         ELSE '** MISSING -- run sql/add_procedure_bills.sql first **'
    END
FROM information_schema.tables
WHERE table_schema = 'u402528120_hmis' AND table_name = 'procedure_bills'

UNION ALL SELECT
    'procedure_bill_items table',
    CASE WHEN COUNT(*) > 0 THEN 'OK - exists'
         ELSE '** MISSING -- run sql/add_procedure_bills.sql first **'
    END
FROM information_schema.tables
WHERE table_schema = 'u402528120_hmis' AND table_name = 'procedure_bill_items'

UNION ALL SELECT
    'manual discount columns',
    CASE WHEN COUNT(*) = 8 THEN 'ALREADY APPLIED - all 8 columns present, do not re-run'
         WHEN COUNT(*) = 0 THEN 'NOT APPLIED - run add_procedure_manual_discount.sql (this is expected)'
         ELSE CONCAT('** PARTIAL -- only ', COUNT(*), ' of 8 columns. Inspect before re-running. **')
    END
FROM information_schema.columns
WHERE table_schema = 'u402528120_hmis'
  AND table_name = 'procedure_bills'
  AND column_name IN ('manual_discount_amount','manual_discount_reason','discount_approval',
                      'discount_doctor_id','discount_decided_by_id','discount_decided_at',
                      'discount_reject_reason','discount_notified_at');

-- ------------------------------------------------- 2. NOTIFICATIONS DETAIL
-- Empty result = the table does not exist = blocker #1 above.
SELECT column_name, column_type, is_nullable
FROM information_schema.columns
WHERE table_schema = 'u402528120_hmis' AND table_name = 'notifications'
ORDER BY ordinal_position;

-- Is the bell actually being fed today? A table that exists but has never
-- received a row usually means the migration ran AFTER the notify_* calls
-- were deployed, so nothing has exercised it yet.
SELECT COUNT(*) AS notification_rows,
       COALESCE(MAX(created_at), 'never') AS newest
FROM notifications;

-- ------------------------------------------------ 3. PROCEDURE BILLS DETAIL
-- What discount-ish columns procedure_bills carries right now. Before the
-- migration you should see ONLY discount_pct and discount_amount -- those are
-- the existing automatic CATEGORY discount (Family/Charity/Loyalty), which is
-- a different thing from the new manual one and is left alone.
SELECT column_name, column_type, is_nullable, column_default
FROM information_schema.columns
WHERE table_schema = 'u402528120_hmis'
  AND table_name = 'procedure_bills'
  AND column_name LIKE '%discount%'
ORDER BY ordinal_position;

-- ------------------------------------------------------ 4. MONEY INVARIANT
-- The rule the whole feature rests on: every bill's line amounts must re-sum
-- to its grand_total. Doctor share is computed per LINE, so if this ever
-- drifts, the share statement and the P&L silently disagree with the drawer.
--
-- Run this NOW to get a clean baseline, and again after the first discounted
-- bill. Both times it must return ZERO rows.
SELECT pb.id,
       pb.invoice_number,
       pb.grand_total,
       pb.paid_amount,
       ROUND(COALESCE(SUM(pbi.amount), 0), 2) AS line_sum,
       ROUND(pb.grand_total - COALESCE(SUM(pbi.amount), 0), 2) AS drift
FROM procedure_bills pb
LEFT JOIN procedure_bill_items pbi ON pbi.procedure_bill_id = pb.id
WHERE pb.voided_at IS NULL
GROUP BY pb.id, pb.invoice_number, pb.grand_total, pb.paid_amount
HAVING ABS(drift) > 0.009
ORDER BY pb.id DESC;
