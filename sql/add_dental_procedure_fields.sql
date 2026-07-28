-- =============================================================================
-- Dental fields on the procedure catalogue (2026-07-28)
--
-- Dental does NOT get its own catalogue table. The spec originally proposed a
-- separate dental_procedure_master, but procedure_master already carries every
-- column such a table would need (name, fee, mandatory_consent, is_active) AND
-- it is already wired to doctor_procedures for the per-doctor share/tax config
-- and to procedure_bills for same-visit billing. A second catalogue would mean
-- duplicating that whole money plumbing for no gain, and would leave the admin
-- maintaining two lists that look identical.
--
-- So dental extends the one catalogue with four columns:
--
--   is_dental          The filter. Dental pickers show only these; the ordinary
--                      procedure biller is unaffected and still sees everything.
--   category           The dental taxonomy (ENDODONTIC, PROSTHETICS, ...). NULL
--                      for non-dental rows, which is why it is nullable rather
--                      than DEFAULT'ed — a NULL category on a general procedure
--                      is meaningful ("not applicable"), a default would not be.
--   has_lab_component  Does this send work to an outside lab (crown, denture,
--                      bridge)? Drives whether the lab panel is offered.
--   default_lab_charge The usual vendor charge, offered as a prefill. NOT a
--                      binding price — the actual charge is typed per case,
--                      because vendors quote per unit and shade.
--
-- is_dental and category are deliberately redundant (a category implies dental).
-- Two columns rather than "category IS NOT NULL" because the flag is what the
-- UI filters on and an index on a TINYINT beats one on a nullable ENUM. The
-- application keeps them in sync BOTH ways: unticking Dental clears the
-- category, and picking a category ticks Dental.
--
-- DEFAULT 0 / NULL everywhere means every existing procedure_master row stays
-- exactly as it is — a non-dental catalogue behaves identically after this runs.
--
-- Flat and idempotent; no stored procedures (the Hostinger user is denied
-- CREATE ROUTINE #1044). No ENGINE/CHARSET clause — forcing one broke the FKs
-- in add_procedure_bills.sql; inherit the server default.
-- Run in phpMyAdmin against the hims database.
--
-- MySQL has no "ADD COLUMN IF NOT EXISTS", so re-running any ALTER below errors
-- with #1060 "Duplicate column name". That error is harmless and simply means
-- the column is already there.
--
-- RUN ORDER: this file FIRST, then add_dental_module.sql, then
-- add_dental_permissions.sql.
--
-- DEPENDS ON procedure_master existing (sql/add_procedure_master.sql) and on
-- has_disposables (sql/add_procedure_disposables.sql), which the first ALTER
-- positions itself after. Verify that FIRST:
--   SELECT column_name FROM information_schema.columns
--    WHERE table_schema = 'u402528120_hmis' AND table_name = 'procedure_master';
-- =============================================================================

-- 1. The filter flag. Dental pickers select on this.
ALTER TABLE procedure_master
    ADD COLUMN is_dental TINYINT NOT NULL DEFAULT 0 AFTER has_disposables;

-- 2. The dental taxonomy from the module spec. NULL on non-dental rows.
ALTER TABLE procedure_master
    ADD COLUMN category ENUM('DIAGNOSTIC','RESTORATIVE','ENDODONTIC','EXTRACTION',
                             'PROSTHETICS','ORTHO','SURGERY') NULL AFTER is_dental;

-- 3. Does this procedure send work out to a dental lab?
ALTER TABLE procedure_master
    ADD COLUMN has_lab_component TINYINT NOT NULL DEFAULT 0 AFTER category;

-- 4. Usual vendor charge — a prefill, not a fixed price.
ALTER TABLE procedure_master
    ADD COLUMN default_lab_charge DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER has_lab_component;

-- Dental pickers all filter "is_dental = 1 AND is_active = 1".
ALTER TABLE procedure_master
    ADD INDEX idx_pm_dental (is_dental, is_active);

-- =============================================================================
-- End dental catalogue migration.
-- Verify with (fully qualified — querying information_schema switches
-- phpMyAdmin's current-database context, which is what made an earlier verify
-- block fail with "#1109 Unknown table 'permissions' in information_schema"):
--
--   SELECT column_name, column_type, is_nullable, column_default
--     FROM information_schema.columns
--    WHERE table_schema = 'u402528120_hmis'
--      AND table_name   = 'procedure_master'
--      AND column_name IN ('is_dental','category','has_lab_component','default_lab_charge');
--   -- expect 4 rows:
--   --   is_dental          tinyint(4)   NO   0
--   --   category           enum(...)    YES  NULL
--   --   has_lab_component  tinyint(4)   NO   0
--   --   default_lab_charge decimal(10,2) NO  0.00
--
-- Nothing is dental until an admin ticks the box, so this is expected to be 0
-- immediately after running:
--   SELECT COUNT(*) FROM u402528120_hmis.procedure_master WHERE is_dental = 1;
-- =============================================================================
