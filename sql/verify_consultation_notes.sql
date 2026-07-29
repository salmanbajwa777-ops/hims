-- Structural verification of consultation_notes.
-- Fully qualified so it does not depend on which DB is selected in phpMyAdmin.

-- 1. Columns: expect 7 rows.
SELECT column_name, column_type, is_nullable, column_default, extra
FROM information_schema.columns
WHERE table_schema = 'u402528120_hmis' AND table_name = 'consultation_notes'
ORDER BY ordinal_position;

-- 2. Foreign keys: expect 3 (visit_id, doctor_id, patient_id).
SELECT constraint_name, column_name, referenced_table_name, referenced_column_name
FROM information_schema.key_column_usage
WHERE table_schema = 'u402528120_hmis' AND table_name = 'consultation_notes'
  AND referenced_table_name IS NOT NULL;

-- 3. Indexes: expect PRIMARY, the UNIQUE on visit_id, and idx_note_doctor_patient.
SELECT index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols, non_unique
FROM information_schema.statistics
WHERE table_schema = 'u402528120_hmis' AND table_name = 'consultation_notes'
GROUP BY index_name, non_unique;

-- 4. Engine/charset must match `visits` — a mismatch is what causes errno 150.
SELECT table_name, engine, table_collation
FROM information_schema.tables
WHERE table_schema = 'u402528120_hmis'
  AND table_name IN ('consultation_notes','visits','users','patients');
