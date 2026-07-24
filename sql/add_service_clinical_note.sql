-- ============================================================================
-- Optional clinical detail per logged admission service.
--
-- Lets the nurse record what a chargeable service actually involved — the drug
-- and strength given, the fluid volume infused, the O2 flow rate, start/end
-- times — as free text on the same row she logs for billing. It is:
--
--   * OPTIONAL   — blank for routine items (a glucose check needs no note).
--   * DESCRIPTIVE only — plays no part in the charge; billing still comes from
--     quantity / duration / unit_charge. This column never touches money.
--
-- This deliberately stays a single free-text field rather than structured
-- medication columns (drug/dose/route/start/end), so it adds near-zero data
-- entry to a busy nurse's flow. True MAR / fluid-balance charting, if ever
-- needed, belongs in its own module — not bolted onto the billing log.
--
-- Idempotent: guarded by information_schema so it is safe to re-run.
-- Run in phpMyAdmin BEFORE deploying the matching admission.php code (the
-- INSERT writes clinical_note).
-- ============================================================================

DROP PROCEDURE IF EXISTS hims_add_service_clinical_note;
DELIMITER $$
CREATE PROCEDURE hims_add_service_clinical_note()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = DATABASE()
                     AND table_name = 'admission_services'
                     AND column_name = 'clinical_note') THEN
        ALTER TABLE admission_services
            ADD COLUMN clinical_note VARCHAR(200) NULL AFTER calculated_charge;
    END IF;
END$$
DELIMITER ;
CALL hims_add_service_clinical_note();
DROP PROCEDURE IF EXISTS hims_add_service_clinical_note;
