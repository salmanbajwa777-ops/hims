-- ============================================================================
-- Vehicles, meter readings and expense sub-categories.   (2026-08-11)
--
-- WHY A NEW COLUMN AND NOT THREE NEW CATEGORIES
--   18 files join expense_categories on expenses.category_id — the P&L, day
--   closing, payouts, the daily-summary cron and the approval emails among
--   them. Splitting "Transport & Fuel" into Fuel / Maintenance / Repairs would
--   silently change what every one of those reports sums, and orphan the
--   history already posted under the existing category. Sub-category is
--   therefore an ADDITIONAL nullable column: every existing row keeps its
--   category_id, and every existing report keeps returning the same number.
--
-- WHY A TABLE AND NOT AN ENUM FOR THE SUB-CATEGORY
--   A value the ENUM does not have stores '' silently and collapses every row
--   onto one key. A table also means adding "Tyres" or "Insurance" later needs
--   no migration at all.
--
-- WHY needs_vehicle IS A FLAG ON THE CATEGORY
--   Same pattern as the existing needs_doctor: the vehicle picker appears
--   because the CATEGORY says so, never because PHP matched the string
--   'Transport & Fuel'. Renaming the category must not break the feature.
--
-- NO STORED PROCEDURE — the Hostinger DB user is denied CREATE ROUTINE
-- (#1044). Flat statements, fully qualified. MySQL has no
-- "ADD COLUMN IF NOT EXISTS", so re-running a section errors with #1060
-- "Duplicate column name" — harmless, it means that section already applied.
-- Run the statements one at a time if some have been applied.
--
-- Run in phpMyAdmin against the hims database.
-- ============================================================================


-- ---- 1. The vehicle register ------------------------------------------------
-- Unlimited rows. Deactivate rather than delete, exactly as expense_categories
-- does, so a vehicle sold next year keeps its cost history intact.
CREATE TABLE IF NOT EXISTS `u402528120_hmis`.vehicles (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(80)  NOT NULL COMMENT 'e.g. Suzuki Bolan',
    registration  VARCHAR(30)  NOT NULL COMMENT 'Number plate, e.g. LES 4471',
    vehicle_type  VARCHAR(40)  NULL     COMMENT 'Ambulance / Bike / Admin car',
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_by_id INT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_vehicle_registration (registration),
    CONSTRAINT fk_vehicles_created_by
        FOREIGN KEY (created_by_id) REFERENCES `u402528120_hmis`.users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---- 2. Expense sub-categories ---------------------------------------------
-- Scoped to a parent category, so "Fuel" under Transport can coexist with a
-- future "Fuel" under a generator category without clashing.
CREATE TABLE IF NOT EXISTS `u402528120_hmis`.expense_subcategories (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    name        VARCHAR(60) NOT NULL,
    -- Fuel is the only sub-category that consumes litres and therefore the only
    -- one that can produce a km/L figure. Data-driven so the form never has to
    -- match on the name 'Fuel'.
    tracks_fuel TINYINT(1) NOT NULL DEFAULT 0,
    sort_order  INT NOT NULL DEFAULT 0,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_cat_sub (category_id, name),
    CONSTRAINT fk_subcat_category
        FOREIGN KEY (category_id) REFERENCES `u402528120_hmis`.expense_categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---- 3. Flag the category as vehicle-tracked --------------------------------
ALTER TABLE `u402528120_hmis`.expense_categories
    ADD COLUMN needs_vehicle TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = posting must name a vehicle and may carry a meter reading';

UPDATE `u402528120_hmis`.expense_categories
   SET needs_vehicle = 1
 WHERE name = 'Transport & Fuel';


-- ---- 4. The expense row -----------------------------------------------------
-- All four columns are NULL for every non-vehicle expense and for every row
-- posted before this migration. Nothing back-fills them: a historic
-- "Transport & Fuel" row has no knowable vehicle or odometer reading, and
-- inventing one would put fiction into a cost-per-km figure.
ALTER TABLE `u402528120_hmis`.expenses
    ADD COLUMN subcategory_id INT NULL
        COMMENT 'expense_subcategories.id — Fuel / Maintenance / Repairs'
        AFTER category_id,
    ADD COLUMN vehicle_id INT NULL
        COMMENT 'vehicles.id — which vehicle this spend belongs to'
        AFTER subcategory_id,
    ADD COLUMN meter_reading INT NULL
        COMMENT 'Odometer in whole km at the time of the spend'
        AFTER vehicle_id,
    ADD COLUMN litres DECIMAL(8,2) NULL
        COMMENT 'Fuel quantity; NULL for maintenance and repairs'
        AFTER meter_reading;

-- Reporting reads (vehicle, date) constantly — every per-vehicle figure is a
-- range scan over one vehicle's rows in meter order.
ALTER TABLE `u402528120_hmis`.expenses
    ADD INDEX idx_expense_vehicle_date (vehicle_id, expense_date);

ALTER TABLE `u402528120_hmis`.expenses
    ADD CONSTRAINT fk_expenses_vehicle
        FOREIGN KEY (vehicle_id) REFERENCES `u402528120_hmis`.vehicles(id)
        ON DELETE SET NULL;

-- ON DELETE RESTRICT by omission: a sub-category with spend against it must be
-- deactivated, not deleted, or the report loses the row's classification.
ALTER TABLE `u402528120_hmis`.expenses
    ADD CONSTRAINT fk_expenses_subcategory
        FOREIGN KEY (subcategory_id) REFERENCES `u402528120_hmis`.expense_subcategories(id);


-- ---- 5. Seed the three sub-categories ---------------------------------------
-- Only Fuel tracks litres. sort_order fixes the button order on the form so it
-- does not drift with alphabetical sorting.
INSERT INTO `u402528120_hmis`.expense_subcategories (category_id, name, tracks_fuel, sort_order)
SELECT ec.id, seed.name, seed.tracks_fuel, seed.sort_order
  FROM (
        SELECT 'Fuel' AS name, 1 AS tracks_fuel, 1 AS sort_order
  UNION ALL SELECT 'Maintenance', 0, 2
  UNION ALL SELECT 'Repairs',     0, 3
       ) AS seed
  JOIN `u402528120_hmis`.expense_categories ec ON ec.name = 'Transport & Fuel'
 WHERE NOT EXISTS (
        SELECT 1 FROM `u402528120_hmis`.expense_subcategories s
         WHERE s.category_id = ec.id AND s.name = seed.name
       );


-- ============================================================================
-- VERIFY (read-only). Fully qualified — querying information_schema switches
-- phpMyAdmin's current-database context, which is what makes a following bare
-- table name fail with "#1109 Unknown table ... in information_schema".
-- ============================================================================

-- Expect 4 rows: subcategory_id/vehicle_id/meter_reading int YES, litres decimal(8,2) YES.
SELECT column_name, column_type, is_nullable
  FROM information_schema.columns
 WHERE table_schema = 'u402528120_hmis' AND table_name = 'expenses'
   AND column_name IN ('subcategory_id', 'vehicle_id', 'meter_reading', 'litres');

-- Expect exactly one row: Transport & Fuel with needs_vehicle = 1.
SELECT id, name, needs_vehicle
  FROM `u402528120_hmis`.expense_categories
 WHERE needs_vehicle = 1;

-- Expect 3 rows: Fuel (tracks_fuel 1), Maintenance 0, Repairs 0.
SELECT s.id, s.name, s.tracks_fuel, s.sort_order, ec.name AS parent
  FROM `u402528120_hmis`.expense_subcategories s
  JOIN `u402528120_hmis`.expense_categories ec ON ec.id = s.category_id
 ORDER BY s.sort_order;

-- Expect both FKs present on expenses.
SELECT constraint_name, column_name, referenced_table_name
  FROM information_schema.key_column_usage
 WHERE table_schema = 'u402528120_hmis' AND table_name = 'expenses'
   AND constraint_name IN ('fk_expenses_vehicle', 'fk_expenses_subcategory');
