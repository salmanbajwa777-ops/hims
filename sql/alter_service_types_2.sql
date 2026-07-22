-- =============================================================================
-- Collapse ER service_type to just 2 values: SERVICE / PROCEDURE
-- Old set was INJECTION_IM/INJECTION_IV/IV_DRIP/OXYGEN/PROCEDURE/OTHER.
-- Everything except PROCEDURE becomes SERVICE. Idempotent + safe to re-run.
-- Run in phpMyAdmin.
-- =============================================================================

-- 1) Widen both ENUMs to include the new values alongside the old ones,
--    so the UPDATE below can remap without violating the column definition.
ALTER TABLE er_services_master
    MODIFY service_type
    ENUM('INJECTION_IM','INJECTION_IV','IV_DRIP','OXYGEN','PROCEDURE','OTHER','SERVICE') NOT NULL;

ALTER TABLE admission_services
    MODIFY service_type
    ENUM('INJECTION_IM','INJECTION_IV','IV_DRIP','OXYGEN','PROCEDURE','OTHER','SERVICE') NOT NULL;

-- 2) Remap every non-PROCEDURE row to SERVICE.
UPDATE er_services_master
    SET service_type = 'SERVICE'
    WHERE service_type IN ('INJECTION_IM','INJECTION_IV','IV_DRIP','OXYGEN','OTHER');

UPDATE admission_services
    SET service_type = 'SERVICE'
    WHERE service_type IN ('INJECTION_IM','INJECTION_IV','IV_DRIP','OXYGEN','OTHER');

-- 3) Narrow the ENUMs to the final 2-value set.
ALTER TABLE er_services_master
    MODIFY service_type ENUM('SERVICE','PROCEDURE') NOT NULL;

ALTER TABLE admission_services
    MODIFY service_type ENUM('SERVICE','PROCEDURE') NOT NULL;
