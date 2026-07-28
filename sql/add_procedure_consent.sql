-- ============================================================================
-- PROCEDURE CONSENT
--
-- Generalises the dental-only consent into a consent that ANY procedure in the
-- catalogue can carry, and turns the printed sheet into a per-procedure
-- template the admin edits — so adding "Circumcision" (or the next procedure
-- after it) needs no code change, only a template on the catalogue row.
--
-- THREE CHANGES, in dependency order:
--
--   1. procedure_master.consent_template — the wording, with {{placeholders}}.
--   2. dental_consents gains procedure_bill_id + signer columns, so a consent
--      can be tied to the invoice it was printed with.
--   3. A permission key for the consent register.
--
-- WHY THE TABLE IS NOT RENAMED. dental_consents is already generic in every
-- column that matters (procedure_master_id, procedure_ref, consent_text,
-- signed_name, signed_relation, status, scan_path); only its NAME says dental.
-- Renaming it would mean touching dental_consent.php, dental_consent_file.php,
-- dental_account.php, dental_treatment.php, procedure_bill.php and
-- config/dental.php in one migration, with a live billing gate depending on
-- every one of them. The rename buys nothing a comment cannot. It stays.
--
-- Hostinger constraints observed throughout (see sql/add_procedure_bills.sql):
--   - no stored procedures / no DELIMITER (DB user denied CREATE ROUTINE, #1044)
--   - no ENGINE/CHARSET clause (mismatches patients/users and fails FKs, errno 150)
--   - `key` is a reserved word and is always backticked
--
-- Safe to re-run: every statement is IF NOT EXISTS / INSERT IGNORE, except the
-- ALTERs, which MySQL cannot make conditional. Re-running those errors with
-- #1060 "Duplicate column name" — that error is EXPECTED on a second run and
-- means the column is already there. Nothing is lost.
-- ============================================================================


-- ---------------------------------------------------------------------------
-- 1. The consent wording lives on the procedure
-- ---------------------------------------------------------------------------
-- NULL (not '') means "this procedure has no consent form". An empty string
-- would be indistinguishable from a template the admin cleared by accident, and
-- printing a blank consent sheet is worse than printing none.
ALTER TABLE procedure_master
    ADD COLUMN consent_template TEXT NULL AFTER mandatory_consent;


-- ---------------------------------------------------------------------------
-- 2. Tie a consent to the invoice it was printed with
-- ---------------------------------------------------------------------------
-- procedure_bill_id is NULLable and ON DELETE SET NULL: the dental module
-- creates consents that belong to an account or a bare procedure and have no
-- procedure bill at all, and those rows must keep working untouched.
ALTER TABLE dental_consents
    ADD COLUMN procedure_bill_id INT NULL AFTER visit_id;

ALTER TABLE dental_consents
    ADD CONSTRAINT fk_dc_procedure_bill
    FOREIGN KEY (procedure_bill_id) REFERENCES procedure_bills(id) ON DELETE SET NULL;

-- The register's lookup: which consents were printed for this bill?
ALTER TABLE dental_consents
    ADD INDEX idx_dc_procedure_bill (procedure_bill_id);

-- The rendered sheet is frozen onto the row at print time, exactly as the
-- dental module freezes financial_snapshot. The template on the catalogue can
-- be edited tomorrow; what THIS patient signed must not move with it. This is
-- the single reason the text is stored per-consent instead of being re-rendered
-- from procedure_master.consent_template on every reprint.
--   (consent_text already exists on the table and is reused for exactly this.)

-- Who signed, captured optionally at billing time so the sheet can print
-- pre-filled. Left NULL the sheet prints a blank rule to be written by hand.
-- signed_name / signed_relation already exist on dental_consents; this only
-- widens relation to fit "Grandparent"/"Legal guardian" comfortably.
ALTER TABLE dental_consents
    MODIFY COLUMN signed_relation VARCHAR(60) NULL;


-- ---------------------------------------------------------------------------
-- 3. Permission for the consent register
-- ---------------------------------------------------------------------------
-- Printing a consent needs no key of its own: it happens inside the procedure
-- bill the user already holds RECEPTION_RAISE_PROCEDURE_BILL to raise. This key
-- gates only the register — the list of printed consents and the scan upload.
INSERT IGNORE INTO permissions (`key`, label, category)
VALUES ('RECEPTION_MANAGE_CONSENT', 'View the consent register and upload signed consents', 'reception');

-- Granted to whoever can already raise a procedure bill: the same front-desk
-- person prints the sheet and files the scan back in.
-- The subquery reads the SAME table it inserts into, which MySQL refuses
-- (#1093) unless it is materialised first — hence the derived-table wrapper.
INSERT IGNORE INTO role_permissions (base_role, permission_id)
SELECT src.base_role, (SELECT id FROM permissions WHERE `key` = 'RECEPTION_MANAGE_CONSENT')
FROM (
    SELECT rp.base_role
    FROM role_permissions rp
    JOIN permissions p ON p.id = rp.permission_id
    WHERE p.`key` = 'RECEPTION_RAISE_PROCEDURE_BILL'
) AS src;


-- ---------------------------------------------------------------------------
-- 4. Seed the circumcision template
-- ---------------------------------------------------------------------------
-- Matches the wording on the printed sheet the clinic has been using. Applied
-- only if a procedure named "Circumcision" already exists and has no template
-- yet — this migration never creates catalogue rows, and never overwrites
-- wording an admin has already edited.
--
-- Placeholders: {{patient_name}} {{father_name}} {{doctor_name}} {{mrn}}
--               {{procedure_name}} {{signer_name}} {{signer_relation}}
UPDATE procedure_master
   SET consent_template = 'I, {{signer_name}}, {{signer_relation}} of the child {{patient_name}}, hereby give my consent for the circumcision procedure of {{patient_name}} S/O {{father_name}} by plastibell method under local anaesthesia. I have been told in detail about possible complications of the procedure like bleeding, infection or bell dehiscence and need to revise the procedure. Having understood all these details, I give my consent for the procedure to doctor {{doctor_name}} and I am willing to accept any mishap or unfortunate event that may arise during or after the procedure.',
       mandatory_consent = 1
 WHERE name = 'Circumcision'
   AND (consent_template IS NULL OR consent_template = '');


-- ---------------------------------------------------------------------------
-- VERIFY — fully qualified, run after the migration
-- ---------------------------------------------------------------------------
-- Every row should report OK. A "MISSING" means that statement did not apply.
SELECT 'procedure_master.consent_template' AS checkpoint,
       CASE WHEN COUNT(*) = 1 THEN 'OK' ELSE 'MISSING' END AS result
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'procedure_master'
   AND COLUMN_NAME = 'consent_template'
UNION ALL
SELECT 'dental_consents.procedure_bill_id',
       CASE WHEN COUNT(*) = 1 THEN 'OK' ELSE 'MISSING' END
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dental_consents'
   AND COLUMN_NAME = 'procedure_bill_id'
UNION ALL
SELECT 'fk_dc_procedure_bill',
       CASE WHEN COUNT(*) = 1 THEN 'OK' ELSE 'MISSING' END
  FROM information_schema.TABLE_CONSTRAINTS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dental_consents'
   AND CONSTRAINT_NAME = 'fk_dc_procedure_bill'
UNION ALL
SELECT 'idx_dc_procedure_bill',
       CASE WHEN COUNT(*) > 0 THEN 'OK' ELSE 'MISSING' END
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dental_consents'
   AND INDEX_NAME = 'idx_dc_procedure_bill'
UNION ALL
SELECT 'permission RECEPTION_MANAGE_CONSENT',
       CASE WHEN COUNT(*) = 1 THEN 'OK' ELSE 'MISSING' END
  FROM permissions
 WHERE `key` = 'RECEPTION_MANAGE_CONSENT'
UNION ALL
SELECT 'RECEPTION_MANAGE_CONSENT granted to >=1 role',
       CASE WHEN COUNT(*) >= 1 THEN 'OK' ELSE 'MISSING' END
  FROM role_permissions rp
  JOIN permissions p ON p.id = rp.permission_id
 WHERE p.`key` = 'RECEPTION_MANAGE_CONSENT'
UNION ALL
-- Informational, not a failure: the seed only fires if the catalogue already
-- has a row named exactly "Circumcision". Admins create catalogue rows by hand.
SELECT 'Circumcision template seeded (informational)',
       CASE WHEN COUNT(*) >= 1 THEN 'OK'
            ELSE 'NOT SEEDED - no procedure named Circumcision, add the template on the catalogue page' END
  FROM procedure_master
 WHERE name = 'Circumcision' AND consent_template IS NOT NULL AND consent_template <> '';
