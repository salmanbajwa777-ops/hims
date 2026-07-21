-- Invoice numbers become one continuous run of digits: sequence + YY + MM.
-- e.g. 1202607 = 12th invoice of July 2026. Same encoding as the MRN, and the
-- sequence restarts at 1 each month, so (yr, mo) is the uniqueness key.
--
-- The printed invoice shows only this number — no date, no separator. The date is
-- still recorded on the bill itself (bills.created_at / paid_at), so the encoding
-- is a backend rule rather than something the reader has to decode.
--
-- Replaces the old daily "94345 - 2026-07-17 14:03:00" format driven by
-- invoice_sequences; that table is left in place untouched so existing invoice
-- numbers stay readable. Idempotent: safe to re-run.

CREATE TABLE IF NOT EXISTS invoice_counters (
    yr SMALLINT NOT NULL,
    mo TINYINT NOT NULL,
    next_seq INT NOT NULL DEFAULT 1,
    PRIMARY KEY (yr, mo)
);
