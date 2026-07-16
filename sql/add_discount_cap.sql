-- Per-person discount authority. Not a boolean permission — see HMIS-PHP-PLAN.md §3 for why
-- this lives as a plain column rather than in permissions/role_permissions.

ALTER TABLE users
    ADD COLUMN max_discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0;
