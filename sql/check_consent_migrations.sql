-- ============================================================================
-- CONSENT MIGRATION CHECK — read-only, safe to run any number of times
--
-- Answers three questions before the circumcision consent wording is touched:
--
--   1. Has sql/add_procedure_consent.sql run?      (procedure_master.consent_template)
--   2. Has sql/add_consent_signer_cnic.sql run?    (dental_consents.signed_cnic)
--   3. Does the CIRCUMCISION row actually carry a template right now?
--
-- Run in phpMyAdmin. Every row should read OK.
--
-- TABLE_SCHEMA IS HARDCODED, NOT DATABASE(). This matters: phpMyAdmin sets the
-- active database from whatever is selected in the left tree, and clicking into
-- information_schema to browse COLUMNS makes DATABASE() return
-- 'information_schema'. A DATABASE()-based check then searches the wrong schema
-- and reports MISSING for columns that are actually present — a false alarm
-- that reads exactly like a real one. Naming the schema makes the result
-- independent of where the tree happens to be pointing.
-- ============================================================================

SELECT '1. consent_template  (add_procedure_consent.sql)' AS checkpoint,
       CASE WHEN COUNT(*) = 1 THEN 'OK'
            ELSE 'MISSING -> STOP. No consent can print. Run sql/add_procedure_consent.sql first.'
       END AS result
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = 'u402528120_hmis'
   AND TABLE_NAME  = 'procedure_master'
   AND COLUMN_NAME = 'consent_template'

UNION ALL

SELECT '2. signed_cnic  (add_consent_signer_cnic.sql)',
       CASE WHEN COUNT(*) = 1 THEN 'OK'
            ELSE 'MISSING -> either run sql/add_consent_signer_cnic.sql, or drop {{signer_cnic}} from the wording'
       END
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = 'u402528120_hmis'
   AND TABLE_NAME  = 'dental_consents'
   AND COLUMN_NAME = 'signed_cnic'

UNION ALL

-- Does the consent table exist at all? Distinguishes "the dental module never
-- ran" from "it ran but the CNIC column was never added" — row 2 alone cannot
-- tell those apart, and they need different fixes.
SELECT '2b. dental_consents table exists',
       CASE WHEN COUNT(*) = 1 THEN 'OK'
            ELSE 'MISSING -> the dental module migration never ran either'
       END
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = 'u402528120_hmis'
   AND TABLE_NAME   = 'dental_consents'

UNION ALL

-- Informational. The column existing does not mean THIS clinic's circumcision
-- row has wording on it — the seed only fires on an exact name match, and this
-- database names the row "CIRCUMCISION under LA".
SELECT '3. CIRCUMCISION row has a template',
       CASE WHEN COUNT(*) >= 1 THEN 'OK'
            ELSE 'NOT SEEDED -> paste the wording on the consent-template admin page'
       END
  FROM u402528120_hmis.procedure_master
 WHERE name LIKE '%CIRCUM%'
   AND consent_template IS NOT NULL
   AND consent_template <> '';


-- ---------------------------------------------------------------------------
-- Which circumcision rows exist, and what is on them right now?
-- Run this second — it shows the exact row name to edit on the admin page,
-- and how long the current wording is (the one being shortened is ~88 words).
-- ---------------------------------------------------------------------------
SELECT id,
       name,
       is_active,
       mandatory_consent,
       CASE WHEN consent_template IS NULL OR consent_template = ''
            THEN '(none)' ELSE CONCAT(CHAR_LENGTH(consent_template), ' chars') END AS template_len,
       LEFT(COALESCE(consent_template, ''), 120) AS template_start
  FROM u402528120_hmis.procedure_master
 WHERE name LIKE '%CIRCUM%'
 ORDER BY id;
