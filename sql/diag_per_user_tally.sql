-- Diagnostic: is the PER-USER day tally actually wired up on the live DB?
-- Paste into phpMyAdmin (SQL tab) and read the three result sets.
-- Nothing here writes; it only inspects. (2026-07-24)
--
-- WHY THIS MATTERS: config/billing.php day_cash_tally() filters every
-- collection by `paid_by_id = ?`. If add_per_user_closings.sql was never
-- applied, that column does not exist, the code silently falls back to the
-- pre-per-user INSERTs (which never set paid_by_id), and EVERY cashier's
-- expected-cash reads as if they collected the NULL-owner rows only. The
-- tally then looks "fine" (no error) while being wrong. This check makes the
-- failure visible.

-- 1. Do the collector-stamp columns exist at all?
--    Expect TWO rows (bills.paid_by_id, admission_bills.paid_by_id).
--    ZERO or ONE row  => migration NOT (fully) applied — per-user tally is broken.
SELECT table_name, column_name, column_type, is_nullable
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND column_name = 'paid_by_id'
  AND table_name IN ('bills', 'admission_bills')
ORDER BY table_name;

-- 2. Is the shift-closing uniqueness PER (cashier, date) or per date only?
--    Expect uq_cashier_date on (cashier_id, closing_date).
--    If you instead see a UNIQUE on closing_date alone, colleagues still
--    lock each other out (old day-wide model).
SELECT index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols, non_unique
FROM information_schema.statistics
WHERE table_schema = DATABASE() AND table_name = 'shift_closings'
GROUP BY index_name, non_unique
ORDER BY non_unique, index_name;

-- 3. Of PAID bills, how many actually carry a collector stamp?
--    A large paid-but-unattributed count means historical rows never got
--    backfilled -> those collections land on NOBODY's shift tally.
--    (Runs only if column exists; comment out block 3 if query 1 returned 0 rows.)
SELECT
    'bills'            AS tbl,
    COUNT(*)          AS paid_rows,
    SUM(paid_by_id IS NULL) AS unattributed
FROM bills
WHERE status = 'paid' AND voided_at IS NULL
UNION ALL
SELECT
    'admission_bills',
    COUNT(*),
    SUM(paid_by_id IS NULL)
FROM admission_bills
WHERE status = 'paid' AND voided_at IS NULL;
