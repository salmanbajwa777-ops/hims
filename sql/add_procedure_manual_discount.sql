-- ============================================================================
-- Procedure bills: reception's flat (Rs) discount + the doctor's after-the-fact
-- sign-off.
--
-- WHAT THIS IS FOR
-- Reception can already NOT enter a discount on a procedure bill. The only one
-- today is the automatic CATEGORY discount (Family / Charity / Loyalty), which
-- comes from patient_discount_category() and lands in the existing
-- discount_pct / discount_amount columns. Those are left completely alone --
-- this adds a SECOND, manual, ad-hoc discount beside them.
--
-- The patient never waits. The bill is raised, paid and printed exactly as
-- today; the doctor is notified and has 24 hours to record whether they agree.
--
-- THE DOCTOR IS PAYING FOR THIS DISCOUNT
-- procedure_bill.php spreads a discount proportionally across the bill's lines
-- and doctor share is computed per LINE on the discounted amount ("the doctor's
-- share is then computed on what was ACTUALLY collected, not list price").
-- So a discount reduces the doctor's earnings at their own share percentage.
-- That is why their sign-off exists, and why the approval screen must show the
-- doctor their own rupee exposure rather than just the headline discount.
--
-- A DECISION MOVES NO MONEY -- BY DESIGN
-- Approving or objecting changes NO figure anywhere. The bill is settled and
-- the patient has gone; this records agreement, not a reversal. That is a
-- deliberate choice, and it is also the only workable one: a procedure bill
-- cannot be refunded (refunds.bill_id is FK'd to the OPD bills table) and a
-- void is refused once the cashier's day is signed (void_procedure_bill), which
-- inside a 24-hour window is the normal case, not an edge case.
--
-- Because no decision moves money, none of the money reads need a "pending"
-- concept: day_cash_tally(), clinic_revenue() and the closing sheet all sum
-- paid_amount, which is final the moment the bill is raised.
--
-- No stored procedures / triggers -- the Hostinger DB user is denied
-- CREATE ROUTINE (#1044). Flat statements only.
-- No ENGINE/CHARSET clause: it must match the table it alters, and forcing
-- utf8mb4 has silently aborted a migration here before (errno 150).
--
-- NOT IDEMPOTENT: MySQL has no ADD COLUMN IF NOT EXISTS. Run the pre-flight
-- sql/check_procedure_discount_ready.sql first. If it says ALREADY APPLIED,
-- do not run this file.
-- ============================================================================

-- ---------------------------------------------------------------- the money
-- Rupees, not a percentage, and deliberately so: the admission manual discount
-- learned this the hard way. A stored % silently re-scales itself if the bill's
-- lines are ever edited afterwards. The rupee figure is authoritative.
ALTER TABLE procedure_bills
    ADD COLUMN manual_discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0
        COMMENT 'Reception ad-hoc flat discount, Rs. Separate from the automatic category discount.';

ALTER TABLE procedure_bills
    ADD COLUMN manual_discount_reason VARCHAR(255) NULL
        COMMENT 'Why reception gave it. Shown to the doctor when they decide.';

-- ------------------------------------------------------------- the decision
-- NONE is the default, not PENDING: every historical row and every bill with
-- no manual discount must be unambiguously "nothing to decide" rather than
-- showing up in the doctor's queue forever.
--
-- AUTO_APPROVED is kept distinct from APPROVED on purpose. "The doctor agreed"
-- and "the doctor never looked and 24 hours elapsed" are different facts and
-- must not collapse into one value -- the admin report's whole job is telling
-- them apart.
ALTER TABLE procedure_bills
    ADD COLUMN discount_approval ENUM('NONE','PENDING','APPROVED','REJECTED','AUTO_APPROVED')
        NOT NULL DEFAULT 'NONE'
        COMMENT 'REJECTED is an objection on the record; it moves no money.';

-- Who must decide: the performing doctor, snapshotted at billing time from the
-- doctor already chosen on the form. Stored rather than re-derived so a later
-- change to the bill can never silently reassign whose call it was.
ALTER TABLE procedure_bills
    ADD COLUMN discount_doctor_id INT NULL
        COMMENT 'Performing doctor who owes a decision.';

ALTER TABLE procedure_bills
    ADD COLUMN discount_decided_by_id INT NULL
        COMMENT 'Who actually decided. NULL when AUTO_APPROVED -- nobody did.';

ALTER TABLE procedure_bills
    ADD COLUMN discount_decided_at DATETIME NULL;

ALTER TABLE procedure_bills
    ADD COLUMN discount_reject_reason VARCHAR(255) NULL
        COMMENT 'Required when objecting. This is the signal the admin report surfaces.';

-- Starts the 24-hour clock. Set when the bill is raised, NOT when the doctor
-- happens to open the app, so a doctor who never logs in still times out.
ALTER TABLE procedure_bills
    ADD COLUMN discount_notified_at DATETIME NULL
        COMMENT 'When the doctor was told. The 24h timeout is measured from here.';

-- ------------------------------------------------------------------- keys
-- The cron sweep is exactly (approval, notified_at): find PENDING older than
-- 24h. Declared as an index, not a UNIQUE, and named so a re-run fails loudly
-- on a duplicate key rather than quietly adding a second one.
ALTER TABLE procedure_bills
    ADD INDEX idx_pb_discount_pending (discount_approval, discount_notified_at);

-- The doctor's own queue: "my pending decisions".
ALTER TABLE procedure_bills
    ADD INDEX idx_pb_discount_doctor (discount_doctor_id, discount_approval);

-- Foreign keys added separately from the columns: if a FK fails (errno 150,
-- usually a column-type mismatch against users.id) the columns above still
-- survive and only these two statements need retrying.
ALTER TABLE procedure_bills
    ADD CONSTRAINT fk_pb_discount_doctor
        FOREIGN KEY (discount_doctor_id) REFERENCES users(id);

ALTER TABLE procedure_bills
    ADD CONSTRAINT fk_pb_discount_decided_by
        FOREIGN KEY (discount_decided_by_id) REFERENCES users(id);

-- ============================================================================
-- VERIFY -- run this after, and read the STATUS column. Do not trust "it ran".
-- ============================================================================
SELECT
    CASE WHEN COUNT(*) = 8
         THEN CONCAT('OK - all 8 columns present (', COUNT(*), '/8)')
         ELSE CONCAT('** INCOMPLETE -- ', COUNT(*), ' of 8. Re-read the errors above. **')
    END AS status
FROM information_schema.columns
WHERE table_schema = 'u402528120_hmis'
  AND table_name = 'procedure_bills'
  AND column_name IN ('manual_discount_amount','manual_discount_reason','discount_approval',
                      'discount_doctor_id','discount_decided_by_id','discount_decided_at',
                      'discount_reject_reason','discount_notified_at');

SELECT column_name, column_type, is_nullable, column_default
FROM information_schema.columns
WHERE table_schema = 'u402528120_hmis'
  AND table_name = 'procedure_bills'
  AND (column_name LIKE '%discount%')
ORDER BY ordinal_position;

-- Both indexes and both foreign keys must appear.
SELECT index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols
FROM information_schema.statistics
WHERE table_schema = 'u402528120_hmis'
  AND table_name = 'procedure_bills'
  AND index_name IN ('idx_pb_discount_pending','idx_pb_discount_doctor')
GROUP BY index_name;

SELECT constraint_name, column_name, referenced_table_name
FROM information_schema.key_column_usage
WHERE table_schema = 'u402528120_hmis'
  AND table_name = 'procedure_bills'
  AND constraint_name IN ('fk_pb_discount_doctor','fk_pb_discount_decided_by');

-- Every existing row must be NONE / 0.00 -- no historical bill should land in
-- anyone's approval queue.
SELECT discount_approval,
       COUNT(*) AS rows_,
       SUM(manual_discount_amount) AS total_manual_discount
FROM procedure_bills
GROUP BY discount_approval;
