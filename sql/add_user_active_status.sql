-- User active/inactive status.
--
-- The Staff & Doctors page used to derive "Pending first login" from
-- must_change_password, which never reflected an admin decision. Every account
-- is fully active from the moment it's created; the only way a user becomes
-- inactive is an admin deactivating them here. An inactive user cannot log in.
--
-- Safe to run more than once: the column is added only if it doesn't exist yet.

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'is_active'
);

SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER must_change_password',
    'SELECT "is_active already exists" AS note'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Every existing account is active.
UPDATE users SET is_active = 1 WHERE is_active IS NULL;
