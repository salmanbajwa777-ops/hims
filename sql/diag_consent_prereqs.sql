-- ============================================================================
-- Which consent tables/columns actually exist on THIS database?
-- ============================================================================
-- Run all four blocks. information_schema is the authority — a green "query OK"
-- from an ALTER means nothing if the verify below does not show the column.

-- 1. Do the two consent-related tables exist at all?
--    dental_consents is the generic consent store (its name is historical).
SELECT TABLE_NAME,
       CASE WHEN TABLE_NAME IS NULL THEN 'MISSING' ELSE 'EXISTS' END AS verdict
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME IN ('dental_consents', 'procedure_master', 'procedure_bills');

-- 2. Every column on dental_consents, if it exists.
--    Zero rows here = the table does not exist = add_dental_module.sql never ran.
SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME   = 'dental_consents'
 ORDER BY ORDINAL_POSITION;

-- 3. The three signer columns specifically. Expect 3 rows once migrated.
SELECT COLUMN_NAME
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME   = 'dental_consents'
   AND COLUMN_NAME IN ('signed_name', 'signed_relation', 'signed_cnic');

-- 4. procedure_master.consent_template — the switch that makes a procedure
--    print a consent form at all. Confirmed 0 in the screenshot.
SELECT COLUMN_NAME
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME   = 'procedure_master'
   AND COLUMN_NAME  = 'consent_template';
