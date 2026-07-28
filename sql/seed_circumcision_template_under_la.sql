-- ============================================================================
-- Seed the circumcision consent template onto THIS clinic's catalogue row
-- ============================================================================
--
-- sql/add_procedure_consent.sql seeds the wording only WHERE name =
-- 'Circumcision'. The row in this database is named "CIRCUMCISION under LA",
-- so that seed matched nothing and the row came out of the migration with
-- mandatory_consent = 1 and consent_template = NULL.
--
-- THAT COMBINATION IS THE WORST OF BOTH: procedure_bill.php refuses to raise
-- the bill without a consent on file, and consent_template_for() returns NULL
-- so no sheet can print. Circumcision billing is blocked until this runs.
--
-- The wording is the seed's, verbatim, plus {{signer_cnic}} in the signer
-- clause. Do NOT re-run this after an admin has edited the template on
-- procedure_consent_template.php — the guard below prevents that, but the
-- point stands: this file is a first fill, not a reset.
--
-- Placeholders: {{patient_name}} {{father_name}} {{doctor_name}} {{mrn}}
--               {{procedure_name}} {{signer_name}} {{signer_relation}}
--               {{signer_cnic}}
--
-- {{signer_cnic}} works whether or not sql/add_consent_signer_cnic.sql has run.
-- Unfilled, it survives the freeze and prints as a rule to write on — the
-- column only decides whether a typed CNIC is also stored for the register.
-- ============================================================================


-- ---------------------------------------------------------------------------
-- 1. Fill the template.
-- ---------------------------------------------------------------------------
-- LIKE '%CIRCUM%' so it catches "CIRCUMCISION under LA", "Circumcision (LA)"
-- or whatever the row is actually called. The IS NULL / = '' guard means an
-- edited template is never overwritten.
UPDATE procedure_master
   SET consent_template = 'I, {{signer_name}}, {{signer_relation}} of the child {{patient_name}} (CNIC {{signer_cnic}}), hereby give my consent for the circumcision procedure of {{patient_name}} S/O {{father_name}} by plastibell method under local anaesthesia. I have been told in detail about possible complications of the procedure like bleeding, infection or bell dehiscence and need to revise the procedure. Having understood all these details, I give my consent for the procedure to doctor {{doctor_name}} and I am willing to accept any mishap or unfortunate event that may arise during or after the procedure.',
       mandatory_consent = 1
 WHERE name LIKE '%CIRCUM%'
   AND (consent_template IS NULL OR consent_template = '');


-- ---------------------------------------------------------------------------
-- 2. Verify. Expect 'READY - sheet will print'.
-- ---------------------------------------------------------------------------
SELECT id, name, mandatory_consent,
       CASE
         WHEN consent_template IS NULL OR consent_template = ''
              THEN 'STILL EMPTY - the UPDATE matched nothing, check the name'
         WHEN consent_template NOT LIKE '%{{signer_cnic}}%'
              THEN 'HAS A TEMPLATE, but no CNIC placeholder (edited by an admin?)'
         ELSE 'READY - sheet will print'
       END AS verdict,
       CHAR_LENGTH(consent_template) AS template_chars
  FROM procedure_master
 WHERE name LIKE '%CIRCUM%';
