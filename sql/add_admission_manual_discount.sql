-- ============================================================================
-- Manual discount at admission discharge.
--
-- Adds a discretionary discount the biller can apply on the discharge screen
-- (a lump-sum rupee amount OR a percentage of the total), BEFORE recording
-- payment. This is separate from the automatic category discount:
--
--   * discount_amount / discount_category_id  (existing) — the automatic
--     Family/Charity/Loyalty category discount, baked into each line's amount.
--   * manual_discount_*                        (new)      — the biller's ad-hoc
--     discount at discharge. When set, it REPLACES the category discount for the
--     grand total (config/billing.php:recalc_admission_bill_totals restores the
--     gross from subtotal + category discount_amount, then subtracts the manual
--     amount). The category snapshot is kept for reporting but no longer reduces
--     the total once a manual discount is present.
--
-- manual_discount_pct is stored for the audit trail / slip only; the rupee
-- amount is authoritative (a % entry is converted to an amount on save so later
-- line edits don't silently re-scale the discount).
--
-- Idempotent: safe to run more than once.
-- ============================================================================

DROP PROCEDURE IF EXISTS hims_add_admission_manual_discount;
DELIMITER $$
CREATE PROCEDURE hims_add_admission_manual_discount()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = DATABASE() AND table_name = 'admission_bills' AND column_name = 'manual_discount_amount') THEN
        ALTER TABLE admission_bills ADD COLUMN manual_discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = DATABASE() AND table_name = 'admission_bills' AND column_name = 'manual_discount_pct') THEN
        -- 0 when the discount was entered as a flat amount; the % when entered as
        -- a percentage of the total (kept for the slip / audit only).
        ALTER TABLE admission_bills ADD COLUMN manual_discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = DATABASE() AND table_name = 'admission_bills' AND column_name = 'manual_discount_by_id') THEN
        ALTER TABLE admission_bills ADD COLUMN manual_discount_by_id INT NULL;
    END IF;
END$$
DELIMITER ;
CALL hims_add_admission_manual_discount();
DROP PROCEDURE IF EXISTS hims_add_admission_manual_discount;
