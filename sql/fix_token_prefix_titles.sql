-- Correct every doctor's token prefix — the stored names carry the "DR" title.
--
-- Names are stored as "DR SALMAN A BAJWA", so the earlier backfills took "D" from the
-- title and produced DR / DH / DR / DS. Two doctors collided on DR, which would have
-- made their queue tokens indistinguishable.
--
-- Correct rule (matches derive_token_prefix() in config/tokens.php): ignore the title,
-- then take the first letter of the FIRST and LAST real name parts.
--
--   DR SALMAN A BAJWA    -> SB   (middle initial "A" is not the surname)
--   DR HIJAB SHAHEEN     -> HS
--   DR RIAZ KHAN         -> RK
--   DR BASHIR UR RAHMAN. -> BR   ("ur" is a connector, trailing "." ignored)
--
-- Set explicitly rather than computed by a string expression: there are four doctors,
-- and the generic SQL that strips titles + particles + punctuation is far harder to
-- verify than the four values it would produce. Matching is on the name so a row is
-- only touched if it really is that doctor.
--
-- Idempotent. Safe to re-run.

UPDATE users SET token_prefix = 'SB'
 WHERE base_role = 'DOCTOR' AND UPPER(name) LIKE '%SALMAN%BAJWA%';

UPDATE users SET token_prefix = 'HS'
 WHERE base_role = 'DOCTOR' AND UPPER(name) LIKE '%HIJAB%SHAHEEN%';

UPDATE users SET token_prefix = 'RK'
 WHERE base_role = 'DOCTOR' AND UPPER(name) LIKE '%RIAZ%KHAN%';

UPDATE users SET token_prefix = 'BR'
 WHERE base_role = 'DOCTOR' AND (UPPER(name) LIKE '%BASHIR%RAHMAN%'
                              OR UPPER(name) LIKE '%BASHIR%REHMAN%');

-- Confirm: expect BR / HS / RK / SB, all distinct.
SELECT name, token_prefix FROM users WHERE base_role = 'DOCTOR' ORDER BY name;

-- Any doctor added later is handled automatically by config/tokens.php, which strips
-- the title and skips particles. This lists anyone still sharing a prefix — they need
-- an admin to set a different one on staff.php. Empty result = nothing to do.
SELECT token_prefix, GROUP_CONCAT(name SEPARATOR ' | ') AS doctors, COUNT(*) AS n
FROM users WHERE base_role = 'DOCTOR' AND token_prefix IS NOT NULL
GROUP BY token_prefix HAVING n > 1;
