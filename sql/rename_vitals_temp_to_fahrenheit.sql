-- Rename admission_vitals.temp_c -> temp_f (2026-07-24).
--
-- add_admission_vitals.sql uses CREATE TABLE IF NOT EXISTS, so on a live DB where
-- the table already existed with the old `temp_c` column, the switch to `temp_f`
-- never took. The code now writes/reads `temp_f`, so this renames the column in
-- place. Column value is unchanged — only the name (and comment) change; existing
-- readings keep their stored number. If you actually need C->F conversion of old
-- data, do it separately; this assumes the stored numbers are already the intended
-- Fahrenheit values (or the table is empty).
--
-- Idempotent + safe on every DB state:
--   * table missing            -> nothing to do (guard skips).
--   * has temp_c, no temp_f     -> renames it.
--   * already has temp_f        -> nothing to do.
-- Safe to paste into phpMyAdmin repeatedly.

DROP PROCEDURE IF EXISTS hims_rename_vitals_temp;
DELIMITER $$
CREATE PROCEDURE hims_rename_vitals_temp()
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.columns
               WHERE table_schema = DATABASE()
                 AND table_name = 'admission_vitals'
                 AND column_name = 'temp_c')
       AND NOT EXISTS (SELECT 1 FROM information_schema.columns
                       WHERE table_schema = DATABASE()
                         AND table_name = 'admission_vitals'
                         AND column_name = 'temp_f') THEN
        ALTER TABLE admission_vitals
            CHANGE temp_c temp_f DECIMAL(4,1) NULL COMMENT 'Fahrenheit, e.g. 98.6';
    END IF;
END$$
DELIMITER ;
CALL hims_rename_vitals_temp();
DROP PROCEDURE IF EXISTS hims_rename_vitals_temp;
