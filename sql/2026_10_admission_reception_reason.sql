-- Reception-admit reason + late-admission gap (short-stay ER admissions only).
-- RUN ONCE in phpMyAdmin (flat statement - no stored procedures; CREATE ROUTINE is
-- denied on the host). A second run fails with "Duplicate column name", which is
-- harmless. Independent of the other 2026_10 migrations - any order is safe.
--
-- Code is guarded by information_schema (adm_has / rx_has_cols): until this runs,
-- admit works exactly as before (no reason asked, nothing stored), and the
-- "Admitted by reception" card on the Revenue Exceptions page says it is not
-- migrated instead of showing zeros.
--
--   reception_admit_reason  NULL for doctor-marked admits and for every admission
--                           made before this migration (unknown is never a reason).
--                           One of: 'Doctor did not mark it', 'Emergency - doctor busy',
--                           'Walk-in direct admit', or 'Other: <free text>'.
--   admit_gap_minutes       NULL unless earlier ER/doctor-visit evidence for the same
--                           patient (previous 24h) was MORE than 30 minutes before the
--                           admit clock; then the whole minutes between them.

ALTER TABLE admissions
    ADD COLUMN reception_admit_reason VARCHAR(200) NULL,
    ADD COLUMN admit_gap_minutes INT NULL;
