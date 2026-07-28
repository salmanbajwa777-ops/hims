-- ============================================================================
-- Consent signer CNIC
-- ============================================================================
--
-- The consent sheet identifies the person giving consent by name and relation.
-- For a procedure like circumcision the clinic keeps the sheet as its record of
-- who authorised it, and a name alone does not identify anybody — two parents
-- called MUHAMMAD ALI are not a hypothetical in this patient population. The
-- CNIC is the identifier that actually distinguishes them.
--
-- Nullable, like signed_name and signed_relation beside it, and for the same
-- reason: the cashier usually does NOT have the CNIC in hand when the bill is
-- raised. The parent writes it on the printed sheet. A NOT NULL column here
-- would force the cashier to invent a value to get the bill out, which is worse
-- than no value at all.
--
-- VARCHAR(15), not an integer. A CNIC is a 13-digit identifier conventionally
-- written 00000-0000000-0 — 15 characters with the dashes. It is never
-- arithmetic, it has leading digits that matter, and storing it as a number
-- would eat a leading zero. Stored as typed, dashes and all.
--
-- Table is dental_consents only by name; it is the generic consent store for
-- both the dental module and procedure billing. See sql/add_procedure_consent.sql.
--
-- Safe to re-run: if the column already exists MySQL raises #1060 and nothing
-- else in this file has run yet, so there is nothing to undo.
--
-- ---------------------------------------------------------------------------
-- RUN sql/add_procedure_consent.sql FIRST. THIS IS NOT OPTIONAL.
-- ---------------------------------------------------------------------------
-- That migration creates signed_name / signed_relation. The ALTER below places
-- the new column AFTER signed_relation, so on a database where it has not run,
-- this file FAILS — and depending on the client that failure can be reported in
-- a way that looks like success while adding nothing at all.
--
-- Confirm the prerequisite before running anything below. Expect 2 rows:
SELECT COLUMN_NAME
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME   = 'dental_consents'
   AND COLUMN_NAME IN ('signed_name', 'signed_relation');
-- 0 or 1 rows  -> STOP. Run sql/add_procedure_consent.sql, then come back.
-- 2 rows       -> proceed.
-- ============================================================================

ALTER TABLE dental_consents
    ADD COLUMN signed_cnic VARCHAR(15) NULL
        COMMENT 'CNIC of the consent giver, as written 00000-0000000-0. NULL = to be filled by hand on the sheet.'
        AFTER signed_relation;


-- ---------------------------------------------------------------------------
-- Verify. Expect exactly one row: signed_cnic / varchar / 15 / YES.
-- ---------------------------------------------------------------------------
SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE, COLUMN_COMMENT
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME   = 'dental_consents'
   AND COLUMN_NAME  = 'signed_cnic';
