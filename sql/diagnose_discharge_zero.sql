-- ============================================================================
-- Diagnose: why do today's DISCHARGED rows show Rs 0 in the reception queue?
--
-- READ-ONLY. Nothing here writes, locks, or alters anything — safe to paste
-- into phpMyAdmin on the live DB (u402528120_hmis).
--
-- The reception queue used to read ONLY bills.paid_amount (the consultation
-- bill). Stay money lives in the separate admission_bills table ("A" series).
-- The code fix makes the queue read both. These queries tell you whether the
-- Rs 0 was purely a DISPLAY bug (money is there, just unread) or ALSO a DATA
-- problem (discharges that were never billed/paid at all).
-- ============================================================================


-- ----------------------------------------------------------------------------
-- Q1. THE ANSWER. One row per short-stay patient registered today.
--     Read the `verdict` column:
--
--       DISPLAY BUG ONLY   -> stay was billed + paid; the fix alone solves it.
--       UNPAID - finalized -> bill exists, total set, money never collected.
--       UNPAID - draft     -> discharged without the billing step being completed.
--       NO ADMISSION BILL  -> discharged with no bill raised at all.
-- ----------------------------------------------------------------------------
SELECT
    v.token_no                          AS token,
    p.mrn,
    p.name                              AS patient,
    adm.status                          AS admission_status,
    COALESCE(b.paid_amount, 0)          AS consult_paid,
    ab.invoice_number                   AS adm_invoice,
    ab.status                           AS adm_bill_status,
    ab.grand_total                      AS adm_total,
    COALESCE(ab.paid_amount, 0)         AS adm_paid,
    COALESCE(ab.write_off_amount, 0)    AS adm_written_off,
    COALESCE(b.paid_amount, 0) + COALESCE(ab.paid_amount, 0) AS row_shows_after_fix,
    CASE
        WHEN ab.id IS NULL                       THEN 'NO ADMISSION BILL'
        WHEN ab.status = 'paid'                  THEN 'DISPLAY BUG ONLY'
        WHEN ab.status = 'finalized'             THEN 'UNPAID - finalized'
        ELSE                                          'UNPAID - draft'
    END                                 AS verdict
FROM visits v
JOIN patients p            ON p.id  = v.patient_id
JOIN admissions adm        ON adm.visit_id = v.id
LEFT JOIN bills b          ON b.visit_id = v.id  AND b.voided_at IS NULL
LEFT JOIN admission_bills ab ON ab.admission_id = adm.id AND ab.voided_at IS NULL
WHERE v.visit_date = CURDATE()
ORDER BY v.created_at DESC;


-- ----------------------------------------------------------------------------
-- Q2. Today's real collected total, split by source.
--     `admission_cash` is the money the queue's stat card was silently
--     dropping before the fix. If it is > 0, the day card was under-reporting
--     by exactly that amount.
-- ----------------------------------------------------------------------------
SELECT
    (SELECT COALESCE(SUM(b.paid_amount), 0)
       FROM bills b JOIN visits v ON v.id = b.visit_id
      WHERE v.visit_date = CURDATE() AND b.voided_at IS NULL)          AS consult_cash,
    (SELECT COALESCE(SUM(ab.paid_amount), 0)
       FROM admission_bills ab
       JOIN admissions a ON a.id = ab.admission_id
       JOIN visits v     ON v.id = a.visit_id
      WHERE v.visit_date = CURDATE() AND ab.voided_at IS NULL)         AS admission_cash,
    (SELECT COALESCE(SUM(r.amount), 0)
       FROM refunds r
       JOIN bills b  ON b.id = r.bill_id
       JOIN visits v ON v.id = b.visit_id
      WHERE v.visit_date = CURDATE() AND r.voided_at IS NULL)          AS refunded;


-- ----------------------------------------------------------------------------
-- Q3. Backlog sweep — ALL TIME, not just today.
--     Any discharged stay still carrying an uncollected balance. Empty result
--     = clean. Rows here are real money owed, unrelated to the display bug.
-- ----------------------------------------------------------------------------
SELECT
    DATE(a.discharged_at)               AS discharged_on,
    p.mrn,
    p.name                              AS patient,
    ab.invoice_number                   AS adm_invoice,
    ab.status                           AS adm_bill_status,
    ab.grand_total,
    COALESCE(ab.paid_amount, 0)         AS paid,
    COALESCE(ab.write_off_amount, 0)    AS written_off,
    ab.grand_total - COALESCE(ab.paid_amount, 0) - COALESCE(ab.write_off_amount, 0)
                                        AS balance_due
FROM admissions a
JOIN visits v   ON v.id = a.visit_id
JOIN patients p ON p.id = v.patient_id
LEFT JOIN admission_bills ab ON ab.admission_id = a.id AND ab.voided_at IS NULL
WHERE a.status = 'DISCHARGED'
  AND (ab.id IS NULL
       OR ab.grand_total - COALESCE(ab.paid_amount, 0) - COALESCE(ab.write_off_amount, 0) > 0)
ORDER BY a.discharged_at DESC
LIMIT 200;


-- ----------------------------------------------------------------------------
-- Q4. Sanity check — confirms the columns the code fix relies on actually
--     exist. Expect 3 rows: voided_at, paid_amount, status.
--     If voided_at is MISSING, sql/add_void_actions.sql was never run and the
--     new `AND ab.voided_at IS NULL` join clause will throw a fatal error.
--     Run that migration BEFORE deploying the code.
-- ----------------------------------------------------------------------------
SELECT column_name, data_type, is_nullable
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name   = 'admission_bills'
  AND column_name IN ('voided_at', 'paid_amount', 'status')
ORDER BY column_name;
