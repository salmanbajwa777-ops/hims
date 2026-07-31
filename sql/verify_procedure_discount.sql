-- ============================================================================
-- VERIFY -- procedure flat discount + doctor sign-off.
--
-- READ-ONLY. Writes nothing, changes nothing. Safe to run any number of times,
-- before OR after add_procedure_manual_discount.sql.
--
-- HOW TO READ IT
-- Run the whole file in phpMyAdmin. You get several result sets; QUERY 1 is
-- the answer and the rest are supporting detail. In QUERY 1, read the STATUS
-- column top to bottom:
--
--     OK ...            nothing to do
--     ACTION ...        expected before the migration -- run the named file
--     ** ... **         a real problem, stop and read it
--
-- If phpMyAdmin only shows you the LAST result set, run the queries one at a
-- time -- they are numbered and independent.
--
-- Everything here reads information_schema rather than trusting that a
-- migration "was run". Four times now that claim has turned out to be wrong;
-- the database is the only authority.
-- ============================================================================


-- ===================================================== QUERY 1 -- THE ANSWER
-- One row per prerequisite. This is the summary; everything after is detail.
SELECT
    '1. notifications table' AS check_,
    CASE WHEN COUNT(*) > 0
         THEN 'OK - exists'
         ELSE '** BLOCKER: missing. Run sql/add_notifications.sql. Until then notifications_ready() returns false and EVERY notification is dropped silently -- no error, the doctor is simply never told. **'
    END AS status
FROM information_schema.tables
WHERE table_schema = 'u402528120_hmis' AND table_name = 'notifications'

UNION ALL SELECT
    '2. procedure_bills table',
    CASE WHEN COUNT(*) > 0 THEN 'OK - exists'
         ELSE '** BLOCKER: missing. Run sql/add_procedure_bills.sql. **'
    END
FROM information_schema.tables
WHERE table_schema = 'u402528120_hmis' AND table_name = 'procedure_bills'

UNION ALL SELECT
    '3. procedure_bill_items table',
    CASE WHEN COUNT(*) > 0 THEN 'OK - exists'
         ELSE '** BLOCKER: missing. Run sql/add_procedure_bills.sql. **'
    END
FROM information_schema.tables
WHERE table_schema = 'u402528120_hmis' AND table_name = 'procedure_bill_items'

UNION ALL SELECT
    '4. discount columns (need 8)',
    CASE WHEN COUNT(*) = 8 THEN CONCAT('OK - applied, all 8 present')
         WHEN COUNT(*) = 0 THEN 'ACTION - not applied yet. Run sql/add_procedure_manual_discount.sql. (Expected before the migration.)'
         ELSE CONCAT('** PARTIAL: only ', COUNT(*), ' of 8 columns exist. A previous run failed part-way -- see QUERY 3 for which are present, and add only the missing ones. Do NOT re-run the whole file. **')
    END
FROM information_schema.columns
WHERE table_schema = 'u402528120_hmis'
  AND table_name = 'procedure_bills'
  AND column_name IN ('manual_discount_amount','manual_discount_reason','discount_approval',
                      'discount_doctor_id','discount_decided_by_id','discount_decided_at',
                      'discount_reject_reason','discount_notified_at')

UNION ALL SELECT
    '5. indexes (need 2)',
    CASE WHEN COUNT(DISTINCT index_name) = 2 THEN 'OK - both present'
         WHEN COUNT(DISTINCT index_name) = 0 THEN 'ACTION - none yet (expected before the migration)'
         ELSE CONCAT('** PARTIAL: ', COUNT(DISTINCT index_name), ' of 2. The cron sweep needs idx_pb_discount_pending. **')
    END
FROM information_schema.statistics
WHERE table_schema = 'u402528120_hmis'
  AND table_name = 'procedure_bills'
  AND index_name IN ('idx_pb_discount_pending','idx_pb_discount_doctor')

UNION ALL SELECT
    '6. foreign keys (need 2)',
    CASE WHEN COUNT(*) = 2 THEN 'OK - both present'
         WHEN COUNT(*) = 0 THEN 'ACTION - none yet (expected before the migration)'
         ELSE CONCAT('** PARTIAL: ', COUNT(*), ' of 2. A FK failure is usually errno 150 -- a column-type mismatch against users.id. **')
    END
FROM information_schema.table_constraints
WHERE table_schema = 'u402528120_hmis'
  AND table_name = 'procedure_bills'
  AND constraint_name IN ('fk_pb_discount_doctor','fk_pb_discount_decided_by')

UNION ALL SELECT
    '7. money invariant',
    'see QUERY 1b below -- it cannot live in this UNION, read on';

-- ------------------------------------------ QUERY 1b -- MONEY INVARIANT COUNT
-- SEPARATE STATEMENT ON PURPOSE. This cannot be another UNION branch above.
--
-- Once a statement's earlier branches read information_schema, MySQL resolves
-- the whole statement's default schema to information_schema, and an unqualified
-- app table in a later branch fails with:
--     #1109 - Unknown table 'procedure_bills' in information_schema
--
-- Qualifying the tables would also work, but splitting it is clearer and
-- survives someone reordering the branches later.
SELECT CASE WHEN COUNT(*) = 0
            THEN 'OK - every bill re-sums to its grand_total'
            ELSE CONCAT('** ', COUNT(*), ' BILL(S) DRIFTED -- see QUERY 5. Doctor share is computed per LINE, so if line amounts do not re-sum to grand_total the share statement and P&L silently disagree with the drawer. **')
       END AS money_invariant
FROM (
    SELECT pb.id
    FROM procedure_bills pb
    LEFT JOIN procedure_bill_items pbi ON pbi.procedure_bill_id = pb.id
    WHERE pb.voided_at IS NULL
    GROUP BY pb.id, pb.grand_total
    HAVING ABS(ROUND(pb.grand_total - COALESCE(SUM(pbi.amount), 0), 2)) > 0.009
) AS drifted;


-- ============================================ QUERY 2 -- NOTIFICATIONS DETAIL
-- An EMPTY result here means the table does not exist -- blocker #1 above.
SELECT column_name, column_type, is_nullable
FROM information_schema.columns
WHERE table_schema = 'u402528120_hmis' AND table_name = 'notifications'
ORDER BY ordinal_position;


-- ====================================== QUERY 2b -- IS THE BELL BEING FED?
-- The table can exist and still never have received a row, which usually means
-- the migration ran but nothing has exercised it yet. Comment this out if the
-- table does not exist (it will error rather than return empty).
SELECT COUNT(*)                              AS notification_rows,
       COUNT(DISTINCT user_id)               AS distinct_recipients,
       COALESCE(MAX(created_at), 'never')    AS newest,
       SUM(read_at IS NULL)                  AS unread
FROM notifications;


-- ================================== QUERY 3 -- PROCEDURE DISCOUNT COLUMNS
-- BEFORE the migration you should see exactly two rows: discount_pct and
-- discount_amount. Those are the EXISTING automatic category discount
-- (Family / Charity / Loyalty) and are deliberately left alone.
--
-- AFTER, you should see those two plus the eight new ones.
SELECT column_name, column_type, is_nullable, column_default, column_comment
FROM information_schema.columns
WHERE table_schema = 'u402528120_hmis'
  AND table_name = 'procedure_bills'
  AND column_name LIKE '%discount%'
ORDER BY ordinal_position;


-- ================================================ QUERY 4 -- INDEXES + FKs
SELECT index_name,
       GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns_
FROM information_schema.statistics
WHERE table_schema = 'u402528120_hmis'
  AND table_name = 'procedure_bills'
  AND index_name IN ('idx_pb_discount_pending','idx_pb_discount_doctor')
GROUP BY index_name;

SELECT constraint_name, column_name, referenced_table_name, referenced_column_name
FROM information_schema.key_column_usage
WHERE table_schema = 'u402528120_hmis'
  AND table_name = 'procedure_bills'
  AND constraint_name IN ('fk_pb_discount_doctor','fk_pb_discount_decided_by');


-- ========================================== QUERY 5 -- MONEY INVARIANT DETAIL
-- MUST RETURN ZERO ROWS. Run it now for a clean baseline, and again after the
-- first discounted bill is raised. Any row here is a real accounting fault:
-- a discount that did not spread proportionally across the bill's lines.
SELECT pb.id,
       pb.invoice_number,
       pb.grand_total,
       pb.paid_amount,
       ROUND(COALESCE(SUM(pbi.amount), 0), 2)                     AS line_sum,
       ROUND(pb.grand_total - COALESCE(SUM(pbi.amount), 0), 2)    AS drift
FROM procedure_bills pb
LEFT JOIN procedure_bill_items pbi ON pbi.procedure_bill_id = pb.id
WHERE pb.voided_at IS NULL
GROUP BY pb.id, pb.invoice_number, pb.grand_total, pb.paid_amount
HAVING ABS(drift) > 0.009
ORDER BY pb.id DESC;


-- ======================================= QUERY 6 -- POST-MIGRATION SANITY
-- Skip this before the migration (the column will not exist).
--
-- Every pre-existing bill must be NONE / 0.00. If any historical row shows
-- PENDING, it would sit in a doctor's approval queue forever.
SELECT discount_approval,
       COUNT(*)                                   AS bills,
       ROUND(SUM(manual_discount_amount), 2)      AS total_manual_discount,
       SUM(discount_doctor_id IS NOT NULL)        AS with_doctor_assigned
FROM procedure_bills
GROUP BY discount_approval
ORDER BY discount_approval;


-- ===================================== QUERY 7 -- LIVE OPERATIONAL CHECKS
-- Only meaningful once the feature is running. Useful day to day.

-- 7a. Anything PENDING past its 24h window means the cron is not firing.
--     Several HIMS crons were written but never registered in hPanel and
--     silently never ran -- this is how you would find out.
SELECT COUNT(*)                                        AS overdue_pending,
       MIN(discount_notified_at)                       AS oldest_pending,
       ROUND(SUM(manual_discount_amount), 2)           AS rupees_awaiting
FROM procedure_bills
WHERE discount_approval = 'PENDING'
  AND discount_notified_at < (NOW() - INTERVAL 24 HOUR);

-- 7b. Who is discounting, how much, and did a doctor ever actually look.
--     AUTO_APPROVED means nobody did -- that is the number to watch.
SELECT u.name                                                        AS applied_by,
       COUNT(*)                                                      AS discounted_bills,
       ROUND(SUM(pb.manual_discount_amount), 2)                      AS total_rs,
       SUM(pb.discount_approval = 'APPROVED')                        AS approved,
       SUM(pb.discount_approval = 'REJECTED')                        AS objected,
       SUM(pb.discount_approval = 'AUTO_APPROVED')                   AS nobody_looked,
       SUM(pb.discount_approval = 'PENDING')                         AS still_pending
FROM procedure_bills pb
LEFT JOIN users u ON u.id = pb.created_by_id
WHERE pb.manual_discount_amount > 0
  AND pb.voided_at IS NULL
GROUP BY u.name
ORDER BY total_rs DESC;

-- 7c. Objections -- the signal worth acting on. A decision moves no money, so
--     this list IS the control: it is the only place a doctor's disagreement
--     is visible.
SELECT pb.invoice_number,
       pb.paid_at,
       ROUND(pb.manual_discount_amount, 2)  AS discount_rs,
       pb.manual_discount_reason            AS reception_reason,
       d.name                               AS doctor,
       pb.discount_reject_reason            AS objection,
       pb.discount_decided_at
FROM procedure_bills pb
LEFT JOIN users d ON d.id = pb.discount_doctor_id
WHERE pb.discount_approval = 'REJECTED'
  AND pb.voided_at IS NULL
ORDER BY pb.discount_decided_at DESC;
