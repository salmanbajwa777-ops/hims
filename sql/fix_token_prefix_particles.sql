-- Correct token prefixes for names containing a connector particle.
--
-- The backfill in add_token_codes.sql took the first letter of the first TWO words, so
-- "BASHIR UR REHMAN" was stored as BU — "ur" is a connector inside the surname, not a
-- middle name. The correct prefix is BR (first + last name part). Same class of error
-- for "ud", "ul", "bin", "al" and similar.
--
-- config/tokens.php now derives first+last and skips particles, but that fallback only
-- applies when token_prefix is NULL — a stored value always wins. So the rows the old
-- backfill already wrote have to be corrected here.
--
-- Idempotent: re-running changes nothing once the values are right.
--
-- Scope guard: only touches DOCTOR rows whose prefix still equals what the OLD
-- first-two-words rule produced. A prefix an admin has since set by hand differs from
-- that, so this cannot clobber a deliberate override.

UPDATE users
SET token_prefix = UPPER(CONCAT(
        LEFT(TRIM(name), 1),
        LEFT(TRIM(SUBSTRING_INDEX(TRIM(name), ' ', -1)), 1)
    ))
WHERE base_role = 'DOCTOR'
  AND TRIM(name) <> ''
  -- Name has a particle as its second-to-last part ("… UR REHMAN", "… BIN QASIM").
  AND UPPER(SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(name), ' ', -2), ' ', 1))
      IN ('UR','UD','UL','AL','BIN','BINT','IBN','DIN','E','VON','VAN','DE','DA','DEL')
  -- Still holds exactly what the old rule produced (i.e. untouched by an admin).
  AND token_prefix = UPPER(CONCAT(
        LEFT(TRIM(name), 1),
        LEFT(SUBSTRING(TRIM(name), LOCATE(' ', TRIM(name)) + 1), 1)
      ));

-- Confirm. Expect SB / HS / RK / BR — and no duplicates.
SELECT name, token_prefix
FROM users
WHERE base_role = 'DOCTOR'
ORDER BY name;
