-- =============================================================================
-- Formulary: dose forms + multiple brands per generic.
--
-- THE BUG THIS FIXES
-- ipd_drug_formulary had UNIQUE(generic_name), which says "one row per
-- molecule". That is wrong. PARACETAMOL exists as a tablet, a suppository and
-- an injection; they are different products with different brands, different
-- routes and often different strengths. Trying to add the second one failed
-- with "a drug with that generic name may already exist".
--
-- THE FIX
-- Uniqueness moves to (generic_name, dose_form). Each row is one generic in one
-- form, and carries up to 6 brands of that same product.
--
-- Prescribing flow this is shaped for (doctor's requirement):
--   1. doctor types the GENERIC first
--   2. if that generic has ONE form -> form + route auto-load, no extra clicks
--   3. if it has several -> doctor picks the form, then route auto-loads
--   4. brand auto-loads to the primary, switchable from a dropdown
--
-- Run in phpMyAdmin AFTER add_ipd_treatment_sheet.sql. Idempotent.
-- No stored procedures (this DB user is denied CREATE ROUTINE), so the guarded
-- ALTERs are written flat and are safe to re-run: adding a column that already
-- exists errors harmlessly and the rest of the script still applies. Run the
-- whole file, then verify with verify_formulary_dose_forms.sql.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. dose_form — the physical presentation.
--
--    NOT the same as route. Route is how it enters the patient (PO, IV, PR);
--    dose form is what the nurse picks up (Tablet, Injection, Suppository). One
--    form can serve several routes — an Injection is given IV or IM — which is
--    exactly why both columns exist.
--
--    Defaults to 'Tablet' only so the ALTER can run against existing rows;
--    step 3 immediately corrects the seeded data from each row's real routes.
-- -----------------------------------------------------------------------------
ALTER TABLE ipd_drug_formulary
    ADD COLUMN dose_form VARCHAR(40) NOT NULL DEFAULT 'Tablet' AFTER generic_name;

-- Strength is what actually distinguishes two rows of the same form in a real
-- pharmacy (500 mg vs 1 g ceftriaxone). Optional and free-text: it prints on
-- the sheet and pre-fills the dose, but nothing computes on it.
ALTER TABLE ipd_drug_formulary
    ADD COLUMN strength VARCHAR(60) NULL AFTER dose_form;

-- -----------------------------------------------------------------------------
-- 2. Uniqueness moves from (generic_name) to (generic_name, dose_form).
--
--    Dropping the old key is what unblocks the second form. Strength is
--    deliberately NOT in the key: two strengths of the same form are the same
--    formulary entry with the dose typed at prescribing time. Putting strength
--    in the key would multiply the list without helping the doctor choose.
-- -----------------------------------------------------------------------------
ALTER TABLE ipd_drug_formulary DROP INDEX uq_formulary_generic;

ALTER TABLE ipd_drug_formulary
    ADD UNIQUE KEY uq_formulary_generic_form (generic_name, dose_form);

-- The typeahead groups by generic, so it needs a plain (non-unique) index on
-- generic_name now that the unique one is gone.
ALTER TABLE ipd_drug_formulary
    ADD INDEX idx_formulary_generic (generic_name);

-- -----------------------------------------------------------------------------
-- 3. Correct the seeded rows' dose_form from the routes they already carry.
--
--    Derived rather than hand-listed so it stays right if the seed changes:
--      NEB only            -> Nebuliser solution
--      IV / IM / SC only   -> Injection
--      PR present, no PO   -> Suppository
--      TOP                 -> Topical
--      anything with PO    -> Tablet
--    Ordered most-specific first; each UPDATE only touches rows still holding
--    the 'Tablet' placeholder, so re-running cannot reclassify edited rows.
-- -----------------------------------------------------------------------------
UPDATE ipd_drug_formulary
SET dose_form = 'Nebuliser solution'
WHERE dose_form = 'Tablet'
  AND default_routes LIKE '%NEB%';

UPDATE ipd_drug_formulary
SET dose_form = 'Topical'
WHERE dose_form = 'Tablet'
  AND default_routes LIKE '%TOP%'
  AND default_routes NOT LIKE '%PO%';

UPDATE ipd_drug_formulary
SET dose_form = 'Suppository'
WHERE dose_form = 'Tablet'
  AND default_routes LIKE '%PR%'
  AND default_routes NOT LIKE '%PO%';

UPDATE ipd_drug_formulary
SET dose_form = 'Injection'
WHERE dose_form = 'Tablet'
  AND default_routes NOT LIKE '%PO%'
  AND (default_routes LIKE '%IV%' OR default_routes LIKE '%IM%' OR default_routes LIKE '%SC%');

-- -----------------------------------------------------------------------------
-- 4. Split the dual-route seeds into their real separate products.
--
--    Rows seeded as 'PO,IV' were one row pretending to be two. Each INSERT
--    below adds the INJECTION sibling of a row that is about to be narrowed to
--    oral. Guarded on NOT EXISTS so re-running is a no-op, and each copies the
--    parent's class/allergy group so the safety checks behave identically.
--
--    Brands differ by form in reality (Panadol tablet vs Perfalgan infusion),
--    so the injectable sibling starts with NO brand rather than inheriting a
--    tablet brand that would be wrong on the chart.
-- -----------------------------------------------------------------------------
INSERT INTO ipd_drug_formulary
    (generic_name, dose_form, strength, brand_name, brand_names, drug_class, allergy_group,
     is_high_alert, default_routes, default_frequencies, default_dose_unit, is_enabled)
SELECT f.generic_name, 'Injection', NULL, NULL, NULL, f.drug_class, f.allergy_group,
       f.is_high_alert,
       -- Keep ONLY the parenteral routes. PR is stripped as well as PO: a seed
       -- like paracetamol's 'PO,IV,PR' otherwise leaves the injection row
       -- offering a rectal route, which is exactly the product confusion this
       -- migration exists to remove. The suppository gets its own row below.
       TRIM(BOTH ',' FROM REPLACE(REPLACE(REPLACE(REPLACE(f.default_routes,
            'PO', ''), 'PR', ''), ',,', ','), ',,', ',')),
       f.default_frequencies, f.default_dose_unit, f.is_enabled
FROM ipd_drug_formulary f
WHERE f.dose_form = 'Tablet'
  AND f.default_routes LIKE '%PO%'
  AND (f.default_routes LIKE '%IV%' OR f.default_routes LIKE '%IM%')
  AND NOT EXISTS (
      SELECT 1 FROM (SELECT * FROM ipd_drug_formulary) x
      WHERE x.generic_name = f.generic_name AND x.dose_form = 'Injection'
  );

-- Paracetamol is also a suppository — the case that surfaced this whole bug.
INSERT INTO ipd_drug_formulary
    (generic_name, dose_form, brand_name, drug_class, allergy_group, is_high_alert,
     default_routes, default_frequencies, default_dose_unit, is_enabled)
SELECT 'PARACETAMOL', 'Suppository', 'Calpol', f.drug_class, f.allergy_group, f.is_high_alert,
       'PR', 'QID,TDS,PRN', 'mg', 1
FROM ipd_drug_formulary f
WHERE f.generic_name = 'PARACETAMOL' AND f.dose_form = 'Tablet'
  AND NOT EXISTS (
      SELECT 1 FROM (SELECT * FROM ipd_drug_formulary) x
      WHERE x.generic_name = 'PARACETAMOL' AND x.dose_form = 'Suppository'
  );

DELETE FROM ipd_drug_formulary
WHERE generic_name = 'DICLOFENAC' AND dose_form = 'Suppository'
  AND brand_name IS NULL AND NOT EXISTS (SELECT 1 FROM ipd_medication_orders o WHERE o.drug_id = ipd_drug_formulary.id);

INSERT INTO ipd_drug_formulary
    (generic_name, dose_form, brand_name, drug_class, allergy_group, is_high_alert,
     default_routes, default_frequencies, default_dose_unit, is_enabled)
SELECT 'DICLOFENAC', 'Suppository', 'Voltaren', f.drug_class, f.allergy_group, f.is_high_alert,
       'PR', 'BD,TDS', 'mg', 1
FROM ipd_drug_formulary f
WHERE f.generic_name = 'DICLOFENAC' AND f.dose_form = 'Injection'
  AND NOT EXISTS (
      SELECT 1 FROM (SELECT * FROM ipd_drug_formulary) x
      WHERE x.generic_name = 'DICLOFENAC' AND x.dose_form = 'Suppository'
  );

-- Now narrow the oral rows: they kept 'PO,IV' while their Injection sibling was
-- being created from them, and must not keep offering IV.
UPDATE ipd_drug_formulary
SET default_routes = TRIM(BOTH ',' FROM REPLACE(REPLACE(REPLACE(default_routes, 'IV', ''), 'IM', ''), ',,', ','))
WHERE dose_form = 'Tablet'
  AND default_routes LIKE '%PO%'
  AND (default_routes LIKE '%IV%' OR default_routes LIKE '%IM%');

-- Tidy any trailing/leading separators the REPLACE chain can leave behind.
UPDATE ipd_drug_formulary
SET default_routes = TRIM(BOTH ',' FROM REPLACE(default_routes, ',,', ','))
WHERE default_routes LIKE ',%' OR default_routes LIKE '%,' OR default_routes LIKE '%,,%';

-- Paracetamol's tablet row was seeded 'PO,IV,PR'; PR now lives on its own row.
UPDATE ipd_drug_formulary
SET default_routes = 'PO'
WHERE generic_name = 'PARACETAMOL' AND dose_form = 'Tablet';

-- -----------------------------------------------------------------------------
-- 5. Brands: up to 6 per generic+form.
--
--    brand_name stays the PRIMARY (what auto-loads when the doctor picks the
--    drug). brand_names holds the alternates as a comma-separated list, and the
--    order form turns primary + alternates into the brand dropdown. Widened
--    from 500 to 600 chars so six long trade names always fit.
-- -----------------------------------------------------------------------------
ALTER TABLE ipd_drug_formulary
    MODIFY COLUMN brand_names VARCHAR(600) NULL;

-- -----------------------------------------------------------------------------
-- 6. Orders snapshot the dose form too.
--
--    Without this the chart cannot say WHICH product was given — "PARACETAMOL
--    1 g PR" is inferable from the route, but "CEFTRIAXONE 1 g IV" would not
--    record whether it was the 500 mg or 1 g vial. Snapshotted, like every
--    other order field, so editing the formulary later never rewrites history.
-- -----------------------------------------------------------------------------
ALTER TABLE ipd_medication_orders
    ADD COLUMN dose_form_snapshot VARCHAR(40) NULL AFTER brand_name_snapshot;

-- =============================================================================
-- End. Verify with sql/ipd/verify_formulary_dose_forms.sql — every row PASS.
-- =============================================================================
