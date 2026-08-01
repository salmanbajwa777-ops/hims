-- =============================================================================
-- VERIFY: add_formulary_dose_forms.sql actually ran.
--
-- Run AFTER the migration. Every row must read PASS.
-- The migration's ALTERs are deliberately unguarded (no CREATE ROUTINE on this
-- host), so a re-run shows errors on the already-applied ALTERs — that is
-- expected and harmless. THIS is what decides whether it worked.
-- =============================================================================

SELECT chk, status, detail FROM (

-- ---- 1. The new columns exist ----
SELECT 1 AS ord, 'columns_added' AS chk,
       CASE WHEN COUNT(*) = 3 THEN 'PASS' ELSE 'FAIL' END AS status,
       CONCAT(COUNT(*), ' of 3 new columns (dose_form, strength, dose_form_snapshot)') AS detail
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND ( (table_name = 'ipd_drug_formulary'    AND column_name IN ('dose_form','strength'))
     OR (table_name = 'ipd_medication_orders' AND column_name = 'dose_form_snapshot') )

UNION ALL

-- ---- 2. THE FIX: the old single-column unique key is GONE ----
--       While it exists, a second dose form of any generic cannot be saved.
SELECT 2, 'old_unique_dropped',
       CASE WHEN COUNT(*) = 0 THEN 'PASS' ELSE 'FAIL' END,
       CONCAT(COUNT(*), ' index named uq_formulary_generic (MUST be 0)')
FROM information_schema.statistics
WHERE table_schema = DATABASE() AND table_name = 'ipd_drug_formulary'
  AND index_name = 'uq_formulary_generic'

UNION ALL

-- ---- 3. The new composite unique key exists and covers BOTH columns ----
SELECT 3, 'new_unique_key',
       CASE WHEN COUNT(*) = 2 THEN 'PASS' ELSE 'FAIL' END,
       CONCAT('uq_formulary_generic_form covers ', COUNT(*), ' of 2 columns')
FROM information_schema.statistics
WHERE table_schema = DATABASE() AND table_name = 'ipd_drug_formulary'
  AND index_name = 'uq_formulary_generic_form'

UNION ALL

-- ---- 4. generic_name still indexed (the typeahead groups on it) ----
SELECT 4, 'generic_still_indexed',
       CASE WHEN COUNT(*) > 0 THEN 'PASS' ELSE 'FAIL' END,
       CONCAT(COUNT(*), ' index(es) leading on generic_name')
FROM information_schema.statistics
WHERE table_schema = DATABASE() AND table_name = 'ipd_drug_formulary'
  AND column_name = 'generic_name' AND seq_in_index = 1

UNION ALL

-- ---- 5. No row was left on the placeholder without a real form ----
SELECT 5, 'every_row_has_a_form',
       CASE WHEN COUNT(*) = 0 THEN 'PASS' ELSE 'FAIL' END,
       CONCAT(COUNT(*), ' rows with an empty dose_form (MUST be 0)')
FROM ipd_drug_formulary
WHERE dose_form IS NULL OR dose_form = ''

UNION ALL

-- ---- 6. THE ACTUAL BUG: a generic can now hold several forms ----
SELECT 6, 'multi_form_generics_exist',
       CASE WHEN COUNT(*) >= 1 THEN 'PASS' ELSE 'FAIL' END,
       CONCAT(COUNT(*), ' generics now carry more than one dose form')
FROM (
    SELECT generic_name FROM ipd_drug_formulary
    GROUP BY generic_name HAVING COUNT(DISTINCT dose_form) > 1
) m

UNION ALL

-- ---- 7. Paracetamol specifically — the case that surfaced this ----
SELECT 7, 'paracetamol_forms',
       CASE WHEN COUNT(*) >= 3 THEN 'PASS' ELSE 'FAIL' END,
       CONCAT('PARACETAMOL has ', COUNT(*), ' forms: ',
              COALESCE(GROUP_CONCAT(dose_form ORDER BY dose_form SEPARATOR ', '), 'none'))
FROM ipd_drug_formulary WHERE generic_name = 'PARACETAMOL'

UNION ALL

-- ---- 8. Injectable rows must not still offer PO ----
--       A row that kept 'PO,IV' after the split would put an oral route on an
--       injection, which is the same confusion this migration exists to remove.
SELECT 8, 'injection_rows_not_oral',
       CASE WHEN COUNT(*) = 0 THEN 'PASS' ELSE 'FAIL' END,
       CONCAT(COUNT(*), ' Injection rows still listing PO (MUST be 0)')
FROM ipd_drug_formulary
WHERE dose_form = 'Injection' AND default_routes LIKE '%PO%'

UNION ALL

-- ---- 9. Tablet rows must not still offer IV ----
SELECT 9, 'tablet_rows_not_iv',
       CASE WHEN COUNT(*) = 0 THEN 'PASS' ELSE 'FAIL' END,
       CONCAT(COUNT(*), ' Tablet rows still listing IV/IM (MUST be 0)')
FROM ipd_drug_formulary
WHERE dose_form = 'Tablet' AND (default_routes LIKE '%IV%' OR default_routes LIKE '%IM%')

UNION ALL

-- ---- 10. No malformed route lists left by the REPLACE chain ----
SELECT 10, 'routes_well_formed',
       CASE WHEN COUNT(*) = 0 THEN 'PASS' ELSE 'FAIL' END,
       CONCAT(COUNT(*), ' rows with a stray comma in default_routes (MUST be 0)')
FROM ipd_drug_formulary
WHERE default_routes LIKE ',%' OR default_routes LIKE '%,' OR default_routes LIKE '%,,%'

UNION ALL

-- ---- 11. brand_names widened enough for 6 trade names ----
SELECT 11, 'brand_names_widened',
       CASE WHEN character_maximum_length >= 600 THEN 'PASS' ELSE 'FAIL' END,
       CONCAT('brand_names is VARCHAR(', character_maximum_length, '), need >= 600')
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = 'ipd_drug_formulary' AND column_name = 'brand_names'

UNION ALL

-- ---- 12. No generic+form pair duplicated (the new key really is enforcing) ----
SELECT 12, 'no_duplicate_pairs',
       CASE WHEN COUNT(*) = 0 THEN 'PASS' ELSE 'FAIL' END,
       CONCAT(COUNT(*), ' duplicated generic+form pairs (MUST be 0)')
FROM (
    SELECT generic_name, dose_form FROM ipd_drug_formulary
    GROUP BY generic_name, dose_form HAVING COUNT(*) > 1
) d

UNION ALL

-- ---- 13. Nothing was lost: the formulary did not shrink ----
SELECT 13, 'formulary_grew',
       CASE WHEN COUNT(*) >= 47 THEN 'PASS' ELSE 'FAIL' END,
       CONCAT(COUNT(*), ' rows (was 47 before the split; expect >= 47)')
FROM ipd_drug_formulary

UNION ALL

-- ---- 14. No existing order lost its drug link ----
SELECT 14, 'orders_intact',
       CASE WHEN COUNT(*) = 0 THEN 'PASS' ELSE 'FAIL' END,
       CONCAT(COUNT(*), ' orders pointing at a drug_id that no longer exists (MUST be 0)')
FROM ipd_medication_orders o
WHERE o.drug_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM ipd_drug_formulary f WHERE f.id = o.drug_id)

) AS checks
ORDER BY ord;
