-- Resync refund_sequences with the refunds actually present (fixes 2026-07-24
-- "1062 Duplicate entry 'RF-2026-0001' for key 'refund_number'").
--
-- generate_refund_number() (config/billing.php) trusts refund_sequences.last_sequence
-- to hold the highest RF number issued this year. If a refund row exists but the
-- sequence counter is behind (missing row, or reset to 0 by a re-import/seed), the
-- next INSERT re-uses a number already taken and the UNIQUE key on refund_number
-- rejects it. This never advances on its own, so every refund attempt keeps failing.
--
-- Fix: for each year present in refunds, set last_sequence to the MAX numeric suffix
-- of that year's RF-YYYY-#### numbers. Idempotent — safe to re-run; re-running just
-- re-asserts the same max. Run in phpMyAdmin against the HMIS database.

INSERT INTO refund_sequences (sequence_year, last_sequence)
SELECT
    CAST(SUBSTRING(refund_number, 4, 4) AS UNSIGNED)              AS sequence_year,
    MAX(CAST(SUBSTRING_INDEX(refund_number, '-', -1) AS UNSIGNED)) AS last_sequence
FROM refunds
WHERE refund_number LIKE 'RF-____-%'
GROUP BY CAST(SUBSTRING(refund_number, 4, 4) AS UNSIGNED)
ON DUPLICATE KEY UPDATE
    last_sequence = GREATEST(last_sequence, VALUES(last_sequence));
