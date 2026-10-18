-- Short-stay admission_services: soft void instead of hard DELETE.
-- RUN ONCE in phpMyAdmin (plain flat ALTER - no stored procedures, CREATE ROUTINE is
-- denied on the host, so it is NOT re-runnable: a second run fails with
-- "Duplicate column name", which is harmless). Run order: 1 of 2.
-- Code is guarded by information_schema and works before AND after this runs:
-- until it does, admission.php still hard-deletes and every reader sees all rows.
ALTER TABLE admission_services
    ADD COLUMN voided_at DATETIME NULL,
    ADD COLUMN voided_by_id INT NULL,
    ADD COLUMN void_reason VARCHAR(255) NULL,
    ADD INDEX idx_adm_svc_live (admission_id, voided_at);
