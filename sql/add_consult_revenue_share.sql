-- Per-DOCTOR consultation revenue share + tax deduction (approved 2026-07-23).
-- One setting per doctor (NOT per consultation type), managed on staff.php's
-- doctor add/edit panel alongside max_discount_pct / specialty.
--
-- Payout math (confirmed rule — tax comes off the FULL fee first, then split):
--   taxable doctor:      doctor gets  (fee − fee × tax%) × share%
--   non-taxable doctor:  doctor gets   fee × share%
-- Patient invoices stay tax-free regardless (see run_now_billing_no_tax.sql);
-- this tax is withheld on the clinic side when computing the doctor's cut.
-- Note this differs from doctor_procedures.tax_percent, which is withheld from
-- the doctor's SHARE (share first, then tax) — procedures keep their own rule.
--
-- Columns live on users (doctor rows only; zero/ignored for other roles), the
-- same pattern as max_discount_pct.

ALTER TABLE users
    ADD COLUMN consult_share_pct DECIMAL(5,2) NOT NULL DEFAULT 0
        COMMENT 'Doctor revenue share % of consultation fees (clinic keeps the rest)',
    ADD COLUMN consult_has_tax TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = tax is deducted from the full fee before the share split',
    ADD COLUMN consult_tax_pct DECIMAL(5,2) NOT NULL DEFAULT 0
        COMMENT 'Tax % taken off the full consultation fee first (only when consult_has_tax=1)';
