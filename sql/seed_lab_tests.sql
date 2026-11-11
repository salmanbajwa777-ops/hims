-- =============================================================================
-- Lab catalogue seed (2026-11-11) -- QA set only: CRP, CBC, Dengue NS-1.
-- Reference ranges are transcribed from the sample report (CBC ranges are
-- PAEDIATRIC, one range per test for now; age bands are a later step).
-- Idempotent (INSERT IGNORE on the unique keys). Run AFTER add_lab_module.sql.
-- =============================================================================

INSERT IGNORE INTO lab_tests (section, name, sort_order) VALUES
    ('Hematology',        'Complete Blood Picture',       10),
    ('Clinical Chemistry','C-Reactive Protein',           20),
    ('Immunology',        'Dengue NS-1 Antigen (ELISA)',  30);

INSERT IGNORE INTO lab_test_params (test_id, group_label, name, unit, ref_range, sort_order)
SELECT t.id, v.g, v.n, v.u, v.r, v.s FROM lab_tests t JOIN (
    SELECT '' g, 'WBC Count' n, '/cmm' u, '4500-13500' r, 10 s
UNION ALL
    SELECT '' g, 'RBC Count' n, 'mil/cmm' u, '4.75-4.85' r, 20 s
UNION ALL
    SELECT '' g, 'Hemoglobin Level' n, 'g/dL' u, '12.0-14.4' r, 30 s
UNION ALL
    SELECT '' g, 'Hematocrit' n, '%' u, '36-42' r, 40 s
UNION ALL
    SELECT '' g, 'MCV' n, 'fL' u, '75-87' r, 50 s
UNION ALL
    SELECT '' g, 'MCH' n, 'pg' u, '25-31' r, 60 s
UNION ALL
    SELECT '' g, 'MCHC' n, 'g/dL' u, '33-35' r, 70 s
UNION ALL
    SELECT '' g, 'RDW-CV' n, '%' u, '11-16' r, 80 s
UNION ALL
    SELECT '' g, 'Platelets' n, '/cmm' u, '150,000-450,000' r, 90 s
UNION ALL
    SELECT '' g, 'Mean Platelet Volume (MPV)' n, 'fL' u, '7.2-11.7' r, 100 s
UNION ALL
    SELECT 'DLC' g, 'Neutrophils' n, '%' u, '30-60' r, 110 s
UNION ALL
    SELECT 'DLC' g, 'Lymphocytes' n, '%' u, '25-55' r, 120 s
UNION ALL
    SELECT 'DLC' g, 'Monocytes' n, '%' u, '4-5' r, 130 s
UNION ALL
    SELECT 'DLC' g, 'Eosinophils' n, '%' u, '2-6' r, 140 s
UNION ALL
    SELECT 'Absolute DLC' g, 'Absolute Neutrophils' n, '/cmm' u, '1400-8100' r, 150 s
UNION ALL
    SELECT 'Absolute DLC' g, 'Absolute Lymphocytes' n, '/cmm' u, '1125-7400' r, 160 s
UNION ALL
    SELECT 'Absolute DLC' g, 'Absolute Monocytes' n, '/cmm' u, '90-1620' r, 170 s
UNION ALL
    SELECT 'Absolute DLC' g, 'Absolute Eosinophils' n, '/cmm' u, '90-810' r, 180 s
) v WHERE t.name = 'Complete Blood Picture';

INSERT IGNORE INTO lab_test_params (test_id, group_label, name, unit, ref_range, sort_order)
SELECT t.id, v.g, v.n, v.u, v.r, v.s FROM lab_tests t JOIN (
    SELECT '' g, 'CRP (Quantitative)' n, 'mg/L' u, 'Negative: 0 - 5 | Positive: >5' r, 10 s
) v WHERE t.name = 'C-Reactive Protein';

INSERT IGNORE INTO lab_test_params (test_id, group_label, name, unit, ref_range, sort_order)
SELECT t.id, v.g, v.n, v.u, v.r, v.s FROM lab_tests t JOIN (
    SELECT '' g, 'Dengue Virus NS1 (ELISA)' n, 'AU/ml' u, 'Negative: < 10 | Positive: =/> 10' r, 10 s
) v WHERE t.name = 'Dengue NS-1 Antigen (ELISA)';
