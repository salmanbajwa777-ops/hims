-- New MRN scheme: YY + NNNN + MM, where NNNN is a 4-digit sequence that RESETS to
-- 0001 at the start of each month. Example: first patient of July 2026 = 26000107
-- (26 = year, 0001 = that month's sequence, 07 = month). Next in July = 26000207;
-- first patient of August 2026 = 26000108.
--
-- The old scheme derived MRN from the patient's own auto-increment id (HMS-00001),
-- which is race-safe by construction. A monthly-reset sequence can't come from that id,
-- so we need a dedicated counter keyed by (year, month), incremented atomically with the
-- same INSERT ... ON DUPLICATE KEY UPDATE / LAST_INSERT_ID() trick already used for
-- per-doctor queue tokens (see visit_queue_counters). MySQL serializes concurrent
-- increments via row locking, so two receptionists registering at once can't collide.
--
-- Existing patients keep their old HMS-xxxxx MRNs untouched — only new registrations
-- use the new format. Search already matches on the mrn column with LIKE, so both
-- formats remain findable.

CREATE TABLE IF NOT EXISTS mrn_counters (
    yr SMALLINT NOT NULL,          -- full year, e.g. 2026
    mo TINYINT NOT NULL,           -- 1-12
    next_seq INT NOT NULL DEFAULT 1,
    PRIMARY KEY (yr, mo)
);
