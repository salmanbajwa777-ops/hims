-- =============================================================================
-- ER-direct controls (2026-10). Idempotent; run in phpMyAdmin. Flat ALTERs
-- (no stored procedures). Code works before AND after this (adm_has() guards).
--
-- er_bills:       who gave the service + "session N of M" per paper advice.
-- shift_closings: the nurse's paper-register tally declared at close, a snapshot
--                 of the ER bills the receptionist raised, a "no register kept"
--                 tick (itself recorded) and a note for any mismatch.
-- =============================================================================

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE u402528120_hmis.er_bills ADD COLUMN service_given_by_id INT NULL',
  'SELECT ''er_bills.service_given_by_id already exists''')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'u402528120_hmis' AND TABLE_NAME = 'er_bills' AND COLUMN_NAME = 'service_given_by_id');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE u402528120_hmis.er_bills ADD COLUMN service_given_by_manual VARCHAR(255) NULL',
  'SELECT ''er_bills.service_given_by_manual already exists''')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'u402528120_hmis' AND TABLE_NAME = 'er_bills' AND COLUMN_NAME = 'service_given_by_manual');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE u402528120_hmis.er_bills ADD COLUMN advice_session_no TINYINT UNSIGNED NULL',
  'SELECT ''er_bills.advice_session_no already exists''')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'u402528120_hmis' AND TABLE_NAME = 'er_bills' AND COLUMN_NAME = 'advice_session_no');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE u402528120_hmis.er_bills ADD COLUMN advice_sessions_total TINYINT UNSIGNED NULL',
  'SELECT ''er_bills.advice_sessions_total already exists''')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'u402528120_hmis' AND TABLE_NAME = 'er_bills' AND COLUMN_NAME = 'advice_sessions_total');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE u402528120_hmis.shift_closings ADD COLUMN er_register_count INT NULL',
  'SELECT ''shift_closings.er_register_count already exists''')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'u402528120_hmis' AND TABLE_NAME = 'shift_closings' AND COLUMN_NAME = 'er_register_count');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE u402528120_hmis.shift_closings ADD COLUMN er_register_none TINYINT(1) NULL',
  'SELECT ''shift_closings.er_register_none already exists''')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'u402528120_hmis' AND TABLE_NAME = 'shift_closings' AND COLUMN_NAME = 'er_register_none');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE u402528120_hmis.shift_closings ADD COLUMN er_register_system_count INT NULL',
  'SELECT ''shift_closings.er_register_system_count already exists''')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'u402528120_hmis' AND TABLE_NAME = 'shift_closings' AND COLUMN_NAME = 'er_register_system_count');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE u402528120_hmis.shift_closings ADD COLUMN er_register_note VARCHAR(255) NULL',
  'SELECT ''shift_closings.er_register_note already exists''')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'u402528120_hmis' AND TABLE_NAME = 'shift_closings' AND COLUMN_NAME = 'er_register_note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
