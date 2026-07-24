-- =============================================================================
-- Room-stay discount rate for discount categories.
--
-- Adds a 4th rate to each discount category — the room stay itself — alongside
-- the existing consultation / ER-services / procedures rates. Previously the
-- category discount on admission bills applied to service/procedure lines only
-- and NEVER the room stay, so a 100% Charity patient still paid the full stay
-- charge. This rate lets the STAY line be discounted (or fully waived) too.
--
-- A single rate covers every admission type (Routine/ER, Private, Long Private).
-- Snapshotted onto each admission bill at billing time like the other rates,
-- so editing it here never rewrites past bills.
--
-- Run in phpMyAdmin BEFORE deploying the code that depends on it.
-- Idempotent: safe to re-run.
-- =============================================================================

DROP PROCEDURE IF EXISTS hims_add_room_stay_discount_column;
DELIMITER $$
CREATE PROCEDURE hims_add_room_stay_discount_column()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = DATABASE()
                     AND table_name = 'discount_categories'
                     AND column_name = 'room_stay_pct') THEN
        ALTER TABLE discount_categories
            ADD COLUMN room_stay_pct DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER er_services_pct;
    END IF;
END$$
DELIMITER ;
CALL hims_add_room_stay_discount_column();
DROP PROCEDURE IF EXISTS hims_add_room_stay_discount_column;
