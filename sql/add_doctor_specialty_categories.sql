-- Expand users.specialty from GENERAL/DENTAL to the five doctor categories
-- (approved 2026-07-24): General, Pediatrician, ENT Consultant, Dental Surgeon,
-- Pediatric Surgeon.
--
-- Invoice-logo rule is UNCHANGED: only DENTAL prints the tooth logo; every other
-- category (including the new ones) falls through to the general logo. See
-- views/invoice_print_partial.php, admission_invoice.php, refund_print_partial.php
-- ($bill['doctor_specialty'] === 'DENTAL' ? 'logo-dental.png' : 'logo-general.png').
--
-- Enum values kept UPPERCASE/underscore to match the existing 'GENERAL'/'DENTAL'
-- convention; existing rows keep their current value untouched.

ALTER TABLE users
    MODIFY COLUMN specialty
        ENUM('GENERAL','PEDIATRICIAN','ENT','DENTAL','PEDIATRIC_SURGEON')
        NOT NULL DEFAULT 'GENERAL';
