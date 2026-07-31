-- =============================================================================
-- IPD (In-Door) — Digital Treatment Sheet, Phase 1 (MVP).
--
-- Replaces the free-text medication chart with STRUCTURED medication orders so
-- the system can auto-generate the administration schedule, catch allergy /
-- duplicate-therapy conflicts, and keep a real audit trail.
--
-- Scope note: attached to IPD ADMISSIONS ONLY (ipd_admissions.id). The ER
-- short-stay admission_* tables are untouched. If this later needs to cover ER
-- stays, that is a new nullable column + a resolver, not a change here.
--
-- The approval rule this schema encodes (the reason it is not just "orders"):
--   A staff member MAY write an order, but a DOCTOR must approve it before any
--   dose can be administered. Approval is PER ORDER, not per sheet — so adding
--   a new drug on day 3 never halts the drugs already running. An order written
--   BY a doctor is self-approved at insert.
--
-- Depends on: add_ipd_admissions.sql (ipd_admissions), users, patients.
-- Idempotent — safe to re-run. No stored procedures (this DB user is denied
-- CREATE ROUTINE), so every ALTER is flat and guarded by the caller.
-- All timestamps are PKT (+05:00).
--
-- Run in phpMyAdmin against the hims database.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. ipd_drug_formulary — the structured drug master.
--
--    drug_class is what makes the duplicate-therapy and allergy cross-reaction
--    checks possible at all: "is the patient allergic to penicillins" cannot be
--    answered from a free-typed drug name. allergy_group is deliberately a
--    SEPARATE, coarser bucket than drug_class — cross-reactivity groups drugs
--    that are not in the same therapeutic class (e.g. cephalosporins carry a
--    partial penicillin cross-reaction), so collapsing the two would either
--    over-block or under-block.
--
--    default_routes / default_frequencies are comma-separated enum codes used
--    only to pre-filter the order form's dropdowns. They are a CONVENIENCE, not
--    a constraint — the route/frequency enums on the order are the authority.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ipd_drug_formulary (
    id INT AUTO_INCREMENT PRIMARY KEY,
    generic_name VARCHAR(150) NOT NULL,         -- the molecule, e.g. AMOXICILLIN
    brand_name VARCHAR(150) NULL,               -- the primary trade name, e.g. AMOXIL
    brand_names VARCHAR(500) NULL,              -- other trade names, comma-separated (typeahead only)
    drug_class VARCHAR(100) NULL,               -- therapeutic class -> duplicate-therapy check
    allergy_group VARCHAR(100) NULL,            -- cross-reactivity bucket -> allergy check
    is_high_alert TINYINT NOT NULL DEFAULT 0,   -- renders visually distinct on the sheet
    default_routes VARCHAR(100) NULL,           -- e.g. 'PO,IV'
    default_frequencies VARCHAR(100) NULL,      -- e.g. 'TDS,BD'
    default_dose_unit VARCHAR(20) NULL,         -- pre-fills the dose unit ('mg','ml','g','IU','tab')
    notes VARCHAR(500) NULL,
    is_enabled TINYINT NOT NULL DEFAULT 1,
    created_by_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_formulary_generic (generic_name),
    INDEX idx_formulary_enabled (is_enabled, generic_name),
    INDEX idx_formulary_class (drug_class),
    INDEX idx_formulary_allergy_group (allergy_group),
    FOREIGN KEY (created_by_id) REFERENCES users(id) ON DELETE SET NULL
);

-- -----------------------------------------------------------------------------
-- 2. patient_allergies — per-patient, reusable beyond IPD.
--
--    Lives on the PATIENT, not the admission: an allergy documented during one
--    stay must still be known at the next one. Soft-deleted (is_active) rather
--    than removed, because "this allergy was later refuted" is itself clinical
--    history and the audit trail must be able to explain a past override.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS patient_allergies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    substance VARCHAR(150) NOT NULL,            -- free text, uppercased in app code
    allergy_group VARCHAR(100) NULL,            -- optional link to formulary.allergy_group
    reaction VARCHAR(300) NULL,
    severity ENUM('MILD','MODERATE','SEVERE','UNKNOWN') NOT NULL DEFAULT 'UNKNOWN',
    is_active TINYINT NOT NULL DEFAULT 1,
    recorded_by_id INT NULL,
    recorded_at DATETIME NOT NULL,
    deactivated_by_id INT NULL,
    deactivated_at DATETIME NULL,
    deactivated_reason VARCHAR(300) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_allergy_patient (patient_id, is_active),
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (deactivated_by_id) REFERENCES users(id) ON DELETE SET NULL
);

-- -----------------------------------------------------------------------------
-- 3. ipd_medication_orders — the treatment sheet itself.
--
--    NEVER hard-deleted. A stopped drug is status='DISCONTINUED' with who/when/
--    why; the row and its administration history stay forever.
--
--    drug_id is NULLABLE on purpose, paired with drug_name_manual: a doctor at
--    3am must be able to order a drug that is not in the formulary yet rather
--    than be blocked. drug_name_snapshot always holds the printable name, so the
--    sheet renders identically either way and a later formulary rename cannot
--    rewrite what was historically ordered.
--
--    duration_days NULL = ongoing/until-stopped.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ipd_medication_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admission_id INT NOT NULL,

    drug_id INT NULL,                            -- formulary row, when picked
    drug_name_manual VARCHAR(150) NULL,          -- free-typed fallback

    -- Generic and brand are SEPARATE columns, frozen at order time.
    -- Kept apart rather than blended into one string because they answer
    -- different questions: the generic is what the drug IS (and is what the
    -- allergy / duplicate-therapy checks and the discharge script reason about),
    -- the brand is what the nurse physically picks off the shelf. A ward stocks
    -- brands, a prescription means molecules, and a single merged name would
    -- force the sheet to lie about one of the two.
    generic_name_snapshot VARCHAR(150) NOT NULL, -- the molecule — always present
    brand_name_snapshot VARCHAR(150) NULL,       -- trade name as dispensed, when known
    drug_name_snapshot VARCHAR(150) NOT NULL,    -- printable "GENERIC (BRAND)", derived, kept for
                                                 -- the print/audit trail so a later brand change
                                                 -- cannot rewrite what was historically ordered
    drug_class_snapshot VARCHAR(100) NULL,       -- frozen for duplicate-therapy history
    is_high_alert TINYINT NOT NULL DEFAULT 0,    -- frozen from the formulary at order time

    dose_value DECIMAL(10,3) NOT NULL,
    dose_unit VARCHAR(20) NOT NULL,
    route ENUM('PO','IV','IM','SC','PR','PV','TOP','NEB','SL','NG') NOT NULL,
    frequency ENUM('OD','BD','TDS','QID','Q6H','Q8H','STAT','PRN') NOT NULL,

    order_type ENUM('SCHEDULED','PRN','STAT') NOT NULL DEFAULT 'SCHEDULED',
    start_datetime DATETIME NOT NULL,
    duration_days INT NULL,                      -- NULL = ongoing
    prn_max_per_24h INT NULL,                    -- required when order_type='PRN'
    prn_indication VARCHAR(300) NULL,            -- required when order_type='PRN'
    special_instructions VARCHAR(500) NULL,
    continue_at_discharge TINYINT NOT NULL DEFAULT 0,

    -- ---- Who wrote it ----
    prescribed_by_id INT NOT NULL,
    prescribed_by_role ENUM('ADMIN','DOCTOR','STAFF') NOT NULL,
    prescribed_at DATETIME NOT NULL,

    -- ---- Doctor approval gate ----
    -- PENDING  = written by staff, no dose may be given yet
    -- APPROVED = a doctor signed it (auto at insert when a doctor wrote it)
    -- REJECTED = a doctor refused it; terminal, never administrable
    approval_status ENUM('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING',
    approved_by_id INT NULL,
    approved_at DATETIME NULL,
    rejected_reason VARCHAR(300) NULL,

    -- ---- Lifecycle ----
    status ENUM('ACTIVE','DISCONTINUED','COMPLETED') NOT NULL DEFAULT 'ACTIVE',
    discontinued_by_id INT NULL,
    discontinued_at DATETIME NULL,
    discontinued_reason VARCHAR(300) NULL,

    -- ---- Allergy override (section 4.1 of the spec) ----
    -- An order that matched a documented allergy and was pushed through anyway.
    -- The reason is REQUIRED by app code and the pair is what the audit trail
    -- and the printed sheet surface.
    allergy_override TINYINT NOT NULL DEFAULT 0,
    allergy_override_reason VARCHAR(500) NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_medorder_admission (admission_id, status),
    INDEX idx_medorder_approval (admission_id, approval_status),
    INDEX idx_medorder_drug (drug_id),
    FOREIGN KEY (admission_id) REFERENCES ipd_admissions(id) ON DELETE CASCADE,
    FOREIGN KEY (drug_id) REFERENCES ipd_drug_formulary(id) ON DELETE SET NULL,
    FOREIGN KEY (prescribed_by_id) REFERENCES users(id),
    FOREIGN KEY (approved_by_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (discontinued_by_id) REFERENCES users(id) ON DELETE SET NULL
);

-- -----------------------------------------------------------------------------
-- 4. ipd_medication_admins — the generated administration slots.
--
--    Auto-generated from frequency x duration at order creation (and REGENERATED
--    forward when an order is approved late, so a sheet approved on day 2 does
--    not carry a day-1 backlog of impossible-to-give slots).
--
--    A CANCELLED slot is a future dose killed by discontinuation. Kept, not
--    deleted — the spec is explicit that nothing in this feature hard-deletes.
--    PRN orders generate NO slots; each PRN dose inserts an ad-hoc GIVEN row,
--    which is what prn_max_per_24h is counted against.
--
--    uq_slot prevents the double-tap / double-submit race from booking one
--    scheduled time twice. It is NOT applied to PRN rows because those legally
--    repeat, hence scheduled_datetime is part of the key and PRN doses are
--    inserted with their own distinct given_at-derived time.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ipd_medication_admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    admission_id INT NOT NULL,                   -- denormalised: the nurse "due now" view filters on it

    scheduled_datetime DATETIME NOT NULL,
    slot_kind ENUM('SCHEDULED','PRN','STAT') NOT NULL DEFAULT 'SCHEDULED',

    status ENUM('PENDING','GIVEN','HELD','MISSED','CANCELLED') NOT NULL DEFAULT 'PENDING',
    given_by_id INT NULL,
    given_at DATETIME NULL,
    hold_reason VARCHAR(300) NULL,
    notes VARCHAR(300) NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_slot (order_id, scheduled_datetime, slot_kind),
    INDEX idx_slot_due (admission_id, status, scheduled_datetime),
    INDEX idx_slot_order (order_id, scheduled_datetime),
    FOREIGN KEY (order_id) REFERENCES ipd_medication_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (admission_id) REFERENCES ipd_admissions(id) ON DELETE CASCADE,
    FOREIGN KEY (given_by_id) REFERENCES users(id) ON DELETE SET NULL
);

-- -----------------------------------------------------------------------------
-- 5. ipd_medication_audit — the feature-local audit trail.
--
--    audit_logs already exists and IS still written (via audit_log()), but it is
--    a flat action+details string with no entity key, so "show me everything
--    that ever happened to THIS order" is not answerable from it. This table is
--    that index. Both are written; neither replaces the other.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ipd_medication_audit (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entity_type ENUM('ORDER','SLOT','ALLERGY') NOT NULL,
    entity_id INT NOT NULL,
    admission_id INT NULL,
    action VARCHAR(50) NOT NULL,                 -- CREATE / APPROVE / REJECT / DISCONTINUE / GIVE / HOLD / OVERRIDE ...
    detail VARCHAR(1000) NULL,
    performed_by_id INT NULL,
    performed_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_medaudit_entity (entity_type, entity_id),
    INDEX idx_medaudit_admission (admission_id, performed_at),
    FOREIGN KEY (performed_by_id) REFERENCES users(id) ON DELETE SET NULL
);

-- -----------------------------------------------------------------------------
-- 6. Frequency -> default clock times, site-configurable (spec section 3).
--    Held in a table rather than hardcoded so the clinic can retune drug rounds
--    without a deploy. slots_per_day is derived from times_csv but stored so the
--    slot generator does not have to re-parse to count.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ipd_frequency_times (
    code VARCHAR(10) NOT NULL PRIMARY KEY,
    label VARCHAR(60) NOT NULL,
    times_csv VARCHAR(120) NOT NULL,             -- 'HH:MM,HH:MM' — empty for PRN
    slots_per_day TINYINT NOT NULL DEFAULT 0,
    sort_order TINYINT NOT NULL DEFAULT 0,
    updated_by_id INT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by_id) REFERENCES users(id) ON DELETE SET NULL
);

INSERT INTO ipd_frequency_times (code, label, times_csv, slots_per_day, sort_order)
SELECT * FROM (
    SELECT 'OD'   AS code, 'Once daily'          AS label, '08:00'                   AS times_csv, 1 AS slots_per_day, 1 AS sort_order
    UNION ALL SELECT 'BD',                      'Twice daily',       '08:00,20:00',             2, 2
    UNION ALL SELECT 'TDS',                     'Three times daily', '08:00,14:00,20:00',       3, 3
    UNION ALL SELECT 'QID',                     'Four times daily',  '08:00,13:00,19:00,23:00', 4, 4
    UNION ALL SELECT 'Q6H',                     'Every 6 hours',     '00:00,06:00,12:00,18:00', 4, 5
    UNION ALL SELECT 'Q8H',                     'Every 8 hours',     '06:00,14:00,22:00',       3, 6
    UNION ALL SELECT 'STAT',                    'Once, immediately', '',                        0, 7
    UNION ALL SELECT 'PRN',                     'As needed',         '',                        0, 8
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM ipd_frequency_times f WHERE f.code = seed.code);

-- -----------------------------------------------------------------------------
-- 7. Starter formulary.
--
--    Deliberately small and Pakistan-ward-realistic rather than a pretend
--    national drug database: enough that the typeahead is useful on day one,
--    and the admin screen grows it from there. The high-alert set follows the
--    spec's suggested list (insulin, anticoagulants, opioids, IV potassium).
-- -----------------------------------------------------------------------------
INSERT INTO ipd_drug_formulary
    (generic_name, brand_name, brand_names, drug_class, allergy_group, is_high_alert, default_routes, default_frequencies, default_dose_unit)
SELECT * FROM (
    SELECT 'AMOXICILLIN' AS generic_name, 'Amoxil' AS brand_name, 'Moxikind' AS brand_names, 'Penicillin antibiotic' AS drug_class, 'PENICILLIN' AS allergy_group, 0 AS is_high_alert, 'PO,IV' AS default_routes, 'TDS,BD' AS default_frequencies, 'mg' AS default_dose_unit
    UNION ALL SELECT 'AMOXICILLIN+CLAVULANATE', 'Augmentin',         'Calamox',                 'Penicillin antibiotic','PENICILLIN',0,'PO,IV','BD,TDS','mg'
    UNION ALL SELECT 'AMPICILLIN',              'Penbritin',         NULL,                      'Penicillin antibiotic', 'PENICILLIN', 0,'IV,IM','QID,Q6H','mg'
    UNION ALL SELECT 'PIPERACILLIN+TAZOBACTAM', 'Tazocin',           NULL,                      'Penicillin antibiotic', 'PENICILLIN', 0,'IV','Q8H,Q6H','mg'
    UNION ALL SELECT 'CEFTRIAXONE',             'Rocephin',          'Oticef',                  'Cephalosporin antibiotic','CEPHALOSPORIN', 0,'IV,IM','OD,BD','mg'
    UNION ALL SELECT 'CEFOTAXIME',              'Claforan',          NULL,                      'Cephalosporin antibiotic','CEPHALOSPORIN', 0,'IV,IM','Q8H,Q6H','mg'
    UNION ALL SELECT 'CEFIXIME',                'Cefspan',           NULL,                      'Cephalosporin antibiotic','CEPHALOSPORIN', 0,'PO','BD,OD','mg'
    UNION ALL SELECT 'MEROPENEM',               'Meronem',           NULL,                      'Carbapenem antibiotic', 'CARBAPENEM', 0,'IV','Q8H','mg'
    UNION ALL SELECT 'VANCOMYCIN',              'Vancocin',          NULL,                      'Glycopeptide antibiotic', 'VANCOMYCIN', 0,'IV','Q8H,Q6H','mg'
    UNION ALL SELECT 'AZITHROMYCIN',            'Zithromax',         'Azomax',                  'Macrolide antibiotic', 'MACROLIDE', 0,'PO,IV','OD','mg'
    UNION ALL SELECT 'CLARITHROMYCIN',          'Klaricid',          NULL,                      'Macrolide antibiotic', 'MACROLIDE', 0,'PO','BD','mg'
    UNION ALL SELECT 'GENTAMICIN',              'Garamycin',         NULL,                      'Aminoglycoside antibiotic','AMINOGLYCOSIDE',0,'IV,IM','OD,Q8H','mg'
    UNION ALL SELECT 'AMIKACIN',                'Amikin',            NULL,                      'Aminoglycoside antibiotic','AMINOGLYCOSIDE',0,'IV,IM','OD,BD','mg'
    UNION ALL SELECT 'METRONIDAZOLE',           'Flagyl',            NULL,                      'Nitroimidazole antibiotic','METRONIDAZOLE',0,'PO,IV','TDS,Q8H','mg'
    UNION ALL SELECT 'CIPROFLOXACIN',           'Ciproxin',          NULL,                      'Fluoroquinolone antibiotic','QUINOLONE', 0,'PO,IV','BD','mg'
    UNION ALL SELECT 'LEVOFLOXACIN',            'Levaquin',          NULL,                      'Fluoroquinolone antibiotic','QUINOLONE', 0,'PO,IV','OD','mg'
    UNION ALL SELECT 'PARACETAMOL',             'Panadol',           'Calpol',                  'Analgesic / antipyretic', 'PARACETAMOL', 0,'PO,IV,PR','QID,TDS,PRN','mg'
    UNION ALL SELECT 'IBUPROFEN',               'Brufen',            NULL,                      'NSAID', 'NSAID', 0,'PO','TDS,BD','mg'
    UNION ALL SELECT 'DICLOFENAC',              'Voltaren',          NULL,                      'NSAID', 'NSAID', 0,'IM,PO,PR','BD,TDS','mg'
    UNION ALL SELECT 'KETOROLAC',               'Toradol',           NULL,                      'NSAID', 'NSAID', 0,'IV,IM','Q8H,Q6H','mg'
    UNION ALL SELECT 'ASPIRIN',                 'Disprin',           'Loprin',                  'Antiplatelet / NSAID', 'NSAID', 0,'PO','OD','mg'
    UNION ALL SELECT 'TRAMADOL',                'Tramal',            NULL,                      'Opioid analgesic', 'OPIOID', 1,'IV,IM,PO','Q8H,PRN','mg'
    UNION ALL SELECT 'MORPHINE',                'Morphine Sulph',    NULL,                      'Opioid analgesic', 'OPIOID', 1,'IV,IM,SC','Q6H,PRN','mg'
    UNION ALL SELECT 'NALBUPHINE',              'Nubain',            NULL,                      'Opioid analgesic', 'OPIOID', 1,'IV,IM','Q6H,PRN','mg'
    UNION ALL SELECT 'PETHIDINE',               'Demerol',           NULL,                      'Opioid analgesic', 'OPIOID', 1,'IM,IV','Q6H,PRN','mg'
    UNION ALL SELECT 'INSULIN REGULAR',         'Actrapid',          'Humulin R',               'Insulin', 'INSULIN', 1,'SC,IV','TDS,PRN','IU'
    UNION ALL SELECT 'INSULIN ISOPHANE (NPH)',  'Insulatard',        'Humulin N',               'Insulin', 'INSULIN', 1,'SC','BD,OD','IU'
    UNION ALL SELECT 'HEPARIN',                 'Heparin Sodium',    NULL,                      'Anticoagulant', 'HEPARIN', 1,'IV,SC','Q6H,Q8H','IU'
    UNION ALL SELECT 'ENOXAPARIN',              'Clexane',           NULL,                      'Anticoagulant (LMWH)', 'HEPARIN', 1,'SC','OD,BD','mg'
    UNION ALL SELECT 'WARFARIN',                'Warfarin Sodium',   NULL,                      'Anticoagulant', 'WARFARIN', 1,'PO','OD','mg'
    UNION ALL SELECT 'POTASSIUM CHLORIDE',      'KCl',               NULL,                      'Electrolyte (IV)', 'POTASSIUM', 1,'IV','PRN,OD','mEq'
    UNION ALL SELECT 'OMEPRAZOLE',              'Risek',             NULL,                      'Proton pump inhibitor', 'PPI', 0,'PO,IV','OD,BD','mg'
    UNION ALL SELECT 'PANTOPRAZOLE',            'Zantac-P',          'Protium',                 'Proton pump inhibitor', 'PPI', 0,'IV,PO','OD,BD','mg'
    UNION ALL SELECT 'ONDANSETRON',             'Zofran',            'Onset',                   'Antiemetic', 'ONDANSETRON', 0,'IV,PO','Q8H,PRN','mg'
    UNION ALL SELECT 'METOCLOPRAMIDE',          'Maxolon',           NULL,                      'Antiemetic / prokinetic', 'METOCLOPRAMIDE',0,'IV,IM,PO','Q8H,TDS','mg'
    UNION ALL SELECT 'DEXAMETHASONE',           'Decadron',          NULL,                      'Corticosteroid', 'STEROID', 0,'IV,IM,PO','Q6H,BD','mg'
    UNION ALL SELECT 'HYDROCORTISONE',          'Solu-Cortef',       NULL,                      'Corticosteroid', 'STEROID', 0,'IV,IM','Q6H,Q8H','mg'
    UNION ALL SELECT 'PREDNISOLONE',            'Deltacortril',      NULL,                      'Corticosteroid', 'STEROID', 0,'PO','OD','mg'
    UNION ALL SELECT 'SALBUTAMOL',              'Ventolin',          NULL,                      'Bronchodilator', 'SALBUTAMOL', 0,'NEB,PO','Q6H,PRN','mg'
    UNION ALL SELECT 'IPRATROPIUM',             'Atrovent',          NULL,                      'Bronchodilator', 'IPRATROPIUM', 0,'NEB','Q6H,QID','mcg'
    UNION ALL SELECT 'FUROSEMIDE',              'Lasix',             NULL,                      'Loop diuretic', 'FUROSEMIDE', 0,'IV,PO','OD,BD','mg'
    UNION ALL SELECT 'CHLORPHENIRAMINE',        'Piriton',           NULL,                      'Antihistamine', 'ANTIHISTAMINE', 0,'PO,IM,IV','TDS,PRN','mg'
    UNION ALL SELECT 'DIAZEPAM',                'Valium',            NULL,                      'Benzodiazepine', 'BENZODIAZEPINE',1,'IV,PO,PR','PRN,Q8H','mg'
    UNION ALL SELECT 'MIDAZOLAM',               'Dormicum',          NULL,                      'Benzodiazepine', 'BENZODIAZEPINE',1,'IV,IM','PRN','mg'
    UNION ALL SELECT 'PHENYTOIN',               'Epanutin',          NULL,                      'Anticonvulsant', 'PHENYTOIN', 1,'IV,PO','Q8H,BD','mg'
    UNION ALL SELECT 'TRANEXAMIC ACID',         'Transamin',         NULL,                      'Antifibrinolytic', 'TRANEXAMIC', 0,'IV,PO','Q8H,TDS','mg'
    UNION ALL SELECT 'VITAMIN K',               'Phytomenadione',    NULL,                      'Vitamin', 'VITAMINK', 0,'IM,IV','OD','mg'
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM ipd_drug_formulary f WHERE f.generic_name = seed.generic_name);

-- -----------------------------------------------------------------------------
-- 8. Permissions.
--
--    The split that enforces "staff may write, only a doctor may approve":
--      IPD_WRITE_MED_ORDER   -> admin + doctor + staff   (create a draft order)
--      IPD_APPROVE_MED_ORDER -> admin + doctor ONLY      (the signature)
--      IPD_ADMINISTER_MED    -> admin + doctor + staff   (tick a dose given)
--      IPD_DISCONTINUE_MED   -> admin + doctor ONLY      (stop a running drug)
--      IPD_VIEW_TREATMENT_SHEET -> all three             (read + print)
--      IPD_MANAGE_FORMULARY  -> admin ONLY
--      IPD_MANAGE_ALLERGIES  -> admin + doctor + staff   (document an allergy)
--
--    STAFF is deliberately absent from APPROVE and DISCONTINUE. That is the
--    whole clinical safety rule of this feature; granting either to STAFF later
--    silently removes the doctor gate.
-- -----------------------------------------------------------------------------
INSERT INTO permissions (`key`, label, category)
SELECT * FROM (
    SELECT 'IPD_VIEW_TREATMENT_SHEET' AS `key`, 'View the IPD treatment sheet'                  AS label, 'admin' AS category
    UNION ALL SELECT 'IPD_WRITE_MED_ORDER',      'Write an IPD medication order (needs doctor approval)', 'admin'
    UNION ALL SELECT 'IPD_APPROVE_MED_ORDER',    'Approve / reject an IPD medication order (doctor)',     'admin'
    UNION ALL SELECT 'IPD_ADMINISTER_MED',       'Mark an IPD medication dose given / held',              'admin'
    UNION ALL SELECT 'IPD_DISCONTINUE_MED',      'Discontinue a running IPD medication order (doctor)',   'admin'
    UNION ALL SELECT 'IPD_MANAGE_FORMULARY',     'Manage the drug formulary',                             'admin'
    UNION ALL SELECT 'IPD_MANAGE_ALLERGIES',     'Record / retire a patient allergy',                     'admin'
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM permissions p WHERE p.`key` = seed.`key`);

-- View / write / administer / record-allergies -> admin + doctor + staff.
INSERT INTO role_permissions (base_role, permission_id)
SELECT r.base_role, p.id
FROM (SELECT 'ADMIN' AS base_role UNION ALL SELECT 'DOCTOR' UNION ALL SELECT 'STAFF') r
JOIN permissions p ON p.`key` IN ('IPD_VIEW_TREATMENT_SHEET','IPD_WRITE_MED_ORDER','IPD_ADMINISTER_MED','IPD_MANAGE_ALLERGIES')
WHERE NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.base_role = r.base_role AND rp.permission_id = p.id);

-- Approve / discontinue -> admin + doctor ONLY. This is the doctor gate.
INSERT INTO role_permissions (base_role, permission_id)
SELECT r.base_role, p.id
FROM (SELECT 'ADMIN' AS base_role UNION ALL SELECT 'DOCTOR') r
JOIN permissions p ON p.`key` IN ('IPD_APPROVE_MED_ORDER','IPD_DISCONTINUE_MED')
WHERE NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.base_role = r.base_role AND rp.permission_id = p.id);

-- Formulary management -> admin only.
INSERT INTO role_permissions (base_role, permission_id)
SELECT r.base_role, p.id
FROM (SELECT 'ADMIN' AS base_role) r
JOIN permissions p ON p.`key` = 'IPD_MANAGE_FORMULARY'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.base_role = r.base_role AND rp.permission_id = p.id);

-- =============================================================================
-- End IPD Treatment Sheet Phase 1 migration.
--
-- Verify with sql/ipd/verify_ipd_treatment_sheet.sql — do NOT assume this ran.
-- =============================================================================
