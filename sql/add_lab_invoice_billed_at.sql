-- Lab invoice back-dating (2026-11-11). RUN THIS BEFORE pushing the matching PHP.
-- billed_at = the date/time printed on the bill and used for the monthly number (staff may
-- back-date it when they charged earlier and document at end of shift). created_at stays the
-- real entry time and is never edited, so back-dated bills remain visible in the audit trail.
-- Run ONCE (MySQL has no ADD COLUMN IF NOT EXISTS).
ALTER TABLE lab_invoices ADD COLUMN billed_at DATETIME NULL AFTER received;
UPDATE lab_invoices SET billed_at = created_at WHERE billed_at IS NULL;
