-- Phase 1 (billing overhaul, 2026-07-24): consultation bills are now SETTLED at
-- registration instead of being left 'draft'. Two settled outcomes exist:
--   'paid'   — money collected (paid_amount = grand_total), counts in the cash tally.
--   'waived' — a genuinely free visit (Rs 0 free-follow-up / 100% discount): it still
--              gets a token and appears in the doctor's queue, consumes the free-visit
--              allowance, but carries NO money and must be EXCLUDED from cash counts.
--
-- 'waived' is a NEW distinct state (not 'paid' with amount 0) so the closing slip and
-- daily summary don't count free visits as phantom Rs 0 transactions.
--
-- Safe/idempotent: re-running just re-asserts the same enum definition.
ALTER TABLE bills
    MODIFY COLUMN status ENUM('draft','finalized','paid','waived') NOT NULL DEFAULT 'draft';
