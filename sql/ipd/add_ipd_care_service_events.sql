-- =============================================================================
-- IPD — a given service is also a care action.
--
-- WHY: the nursing flow-sheet (ipd_care_events) is the clinical record of what
-- was DONE to the patient — nursing care, medication, observations, handovers.
-- But when a nurse logged a chargeable service ("IV Injection x16") it went only
-- to ipd_services, which is the BILLING log. So the clinical timeline had a hole
-- exactly where the most concrete actions were: the printed care record omitted
-- every injection, cannulation and enema that happened to carry a price.
--
-- Widening the ENUM lets a logged service ALSO write a care-event row, priced at
-- nothing — the money stays in ipd_services, the action appears in the care
-- record. ref_table/ref_id already exist to point back at the source row, the
-- same way a daily-round note does.
--
-- No stored procedures (the DB user is denied CREATE ROUTINE, #1044), so the
-- ALTER is guarded by an information_schema check run through a prepared
-- statement. Idempotent: safe to run more than once.
--
-- Schema is named EXPLICITLY, never DATABASE(): phpMyAdmin often lands you in
-- information_schema, where DATABASE() resolves there and the guard finds
-- nothing -- so the IF takes the "already done" branch and the migration
-- reports success while doing absolutely nothing.
--
-- All timestamps are PKT (+05:00). Run in phpMyAdmin against the hims database.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- Add 'SERVICE' to ipd_care_events.event_type.
-- Every existing value is kept, so historic rows stay valid and no data
-- migration is needed.
-- -----------------------------------------------------------------------------
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE u402528120_hmis.ipd_care_events MODIFY COLUMN event_type ENUM(''DOCTOR_VISIT'',''NURSING_CARE'',''MEDICATION'',''OBSERVATION'',''HANDOVER'',''SERVICE'',''OTHER'') NOT NULL',
        'SELECT ''ipd_care_events.event_type already has SERVICE'''
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'u402528120_hmis'
      AND TABLE_NAME   = 'ipd_care_events'
      AND COLUMN_NAME  = 'event_type'
      AND COLUMN_TYPE LIKE '%SERVICE%'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =============================================================================
-- End IPD care-service-events migration.
-- =============================================================================

-- ---- Verify (read-only; run separately) ----
-- Expect one row whose COLUMN_TYPE contains 'SERVICE'.
--
-- SELECT COLUMN_NAME, COLUMN_TYPE
-- FROM information_schema.COLUMNS
-- WHERE TABLE_SCHEMA = 'u402528120_hmis'
--   AND TABLE_NAME   = 'ipd_care_events'
--   AND COLUMN_NAME  = 'event_type';
