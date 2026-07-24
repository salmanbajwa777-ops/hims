# RBAC Overhaul — Rollout Order (2026-07-24)

Fixes the permissions issues from the RBAC audit: duplicate admit keys (the "Zoya"
admit-rejection bug), the invisible `|| in_array($role,['ADMIN','MANAGER'])`
second-grant path in every gate, four over-broad keys, and a scattered catalog.

**The one rule: always grant the new/merged key before removing the old path.**
Every step below adds access first and removes it only once the replacement is
seeded — so no receptionist, nurse, or doctor is ever locked out mid-deploy.

## Files in this change set

| File | What it does | Risk |
|------|--------------|------|
| `rbac_overhaul_1_catalog.sql` | Re-asserts every real key into the master `permissions` catalog + adds 3 new split keys. **Additive.** | none |
| `rbac_overhaul_2_grants.sql`  | Grants those keys to the right roles/users, reproducing every hardcoded `in_array` path + admit merge + split-key back-grants. **Additive.** | none |
| `rbac_overhaul_3_categories.sql` | Re-categorizes every key into 5 role-owned buckets (nursing / clinical / reception / financial / admin) so the assign-permissions screen groups them sensibly. **Display only.** | none |
| Code edits (below) | Remove the role hardcodes; switch to the finer split keys; render permission groups in the new category order. | needs the SQL run first |

## Run order (data first, code last)

### 1. Run `rbac_overhaul_1_catalog.sql` in phpMyAdmin
Adds/asserts the catalog. Idempotent. Nobody's access changes.

### 2. Run `rbac_overhaul_2_grants.sql` in phpMyAdmin
Grants every key the code is about to rely on:
- **ADMIN** gets everything (lets us drop the ADMIN branch of every hardcode).
- **MANAGER** gets the billing / vitals / discharge / write-off / admit / expense-approve keys it used to reach only by role.
- **RECEPTIONIST / NURSE** get the log-events / discharge / record-admissions keys they reached by role.
- **Admit merge:** `ADMISSION_ADMIT_PATIENT` back-granted to every role/user that held legacy `RECEPTION_ADMIT_PATIENTS`.
- **Split-key back-grant:** everyone with `RECEPTION_REGISTER_PATIENTS` also gets `RECEPTION_MANAGE_BOOKINGS` + `RECEPTION_EDIT_DOCTOR_TIMINGS`; everyone with `RECEPTION_PROCESS_PAYMENTS` also gets `ADMISSION_FINALIZE_BILL`.

After this runs, users pick up the new grants on their **next page load** —
`refresh_session_permissions()` reloads the session every request, no re-login needed.

**Verify before deploying code** (should return the expected roles, not empty):
```sql
SELECT rp.base_role, p.`key`
FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
WHERE p.`key` IN ('ADMISSION_ADMIT_PATIENT','ADMISSION_FINALIZE_BILL',
                  'RECEPTION_MANAGE_BOOKINGS','RECEPTION_EDIT_DOCTOR_TIMINGS',
                  'FINANCIAL_APPROVE_EXPENSES','NURSING_RECORD_ADMISSIONS')
ORDER BY p.`key`, rp.base_role;
```

### 3. Deploy the code changes
Only after step 2 is verified. The gates now read pure `has_permission()` — no
role fallback. Files touched:
- `admission.php`, `admission_discharge.php`, `admissions.php` — dropped `|| in_array(...)`; admission billing now uses `ADMISSION_FINALIZE_BILL`.
- `bookings.php` — now gated on `RECEPTION_MANAGE_BOOKINGS`.
- `doctor_timings.php` — edit now gated on `RECEPTION_EDIT_DOCTOR_TIMINGS`.
- `patients.php`, `receptionist.php`, `config/admission_actions.php` — dropped the legacy `RECEPTION_ADMIT_PATIENTS` OR-branch; single `ADMISSION_ADMIT_PATIENT` key.

### 4. (Optional, later) Retire the legacy admit key
Once a check shows **no user holds `RECEPTION_ADMIT_PATIENTS` alone**, the row can
be deleted from `permissions` (cascades clean up `role_permissions` /
`user_permission_overrides`). Not required for correctness — nothing reads it
anymore — just catalog hygiene. Check first:
```sql
SELECT u.id, u.name, u.base_role
FROM users u
JOIN user_permission_overrides o ON o.user_id = u.id
JOIN permissions p ON p.id = o.permission_id AND p.`key` = 'RECEPTION_ADMIT_PATIENTS'
WHERE o.granted = 1;   -- any rows = someone was granted it individually; leave it until re-checked
```

## Still open (not in this change set — decide separately)

- **18 dead catalog keys** (`RECEPTION_PRINT_CONSENT`, `CLINICAL_VIEW_*`,
  `FINANCIAL_VIEW_*`, most `ADMIN_*`) are seeded but checked nowhere in code. They
  read as protection the app doesn't apply. Either wire each to its screen or move
  it to a "planned" section of the catalog. Left as-is for now — harmless, just
  misleading in the assign-UI.
- **ACCOUNTANT role** is seeded with financial + payment + settings grants but is
  not one of the five working roles. Decide: map it into the matrix or fold its
  duties into Manager. Until then it silently holds those permissions.
- ~~`permissions.php` save could strand ADMIN~~ **Handled in this change set:**
  saving the ADMIN role now always writes the full catalog regardless of the
  posted checkboxes, so ADMIN can't lock admins out via the assign-UI.
