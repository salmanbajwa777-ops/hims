# HMIS — Phase 1 Build Spec

**Parent spec:** Unified HMIS Specification (functional) + Premium UI/UX Design Guide (visual).
This document is the actionable Phase 1 plan reconciling both, plus decisions made 2026-07-15.

**Stack:** Next.js, TypeScript, Tailwind CSS, PostgreSQL, Prisma, GitHub Actions → AWS EC2

---

## 1. Phase 1 Scope

**In scope:**
- Login (email/phone + password)
- Forgot password (email link, time-limited token)
- Change password (logged-in user; current + new + confirm — no forced rotation)
- First-time admin setup detection → seeded fallback admin account
- Staff Management (add staff, add doctor — both are `User` records with a `baseRole`)
- Permissions & Roles (dynamic — see §3)
- Special Tasks (ad-hoc staff/doctor task assignment — stretch item, simple CRUD)
- Role-based dashboard routing (redirect shell only; each dashboard's real content is later phases)
- Audit logging framework (write path only; full audit *report* UI is Phase 6)

**Explicitly NOT in Phase 1** (stays in original phase per hmis-spec.md):
- Procedure Master, Rate Master, Tax Config, Expense Categories (Phase 3–4)
- Visit/OPD, vitals, consultation, short-stay, billing (Phase 2, 4, 5)
- QA assessments, patient feedback, settlements (Phase 6)

---

## 2. Auth & Password Flows

### Seeded admin account
- On first deploy / empty `User` table, seed one fallback admin:
  - `email: admin` *(not a real email — a login identifier, since email-link reset needs a real inbox for real users but the seed account must always be recoverable without one)*
  - `password: admin1234` (hashed, bcrypt)
  - `baseRole: ADMIN`
  - Flag `mustChangePassword: true`
- Seed account is a safety net, not the daily admin login — first real admin should create their own account or immediately change this password. UI should nag until `mustChangePassword` is cleared.

### Login
- Standard email/phone + password form per design spec §5.
- On success: route by `baseRole` per §8.1 of the design spec.

### Forgot password
- "Forgot password?" link on login → enter email → if a `User` with that email exists, create `PasswordResetToken` (random token, 1-hour expiry, single-use) and email a reset link.
- Always show the same "if an account exists, we've sent a link" message (don't leak whether the email exists).
- Reset link → new password + confirm → invalidate token → invalidate other active sessions for that user.
- Requires an email-sending service (Resend/SMTP) configured via env var — needed before this flow can go live; until then, seeded admin's `admin1234` + admin-driven manual resets are the fallback.

### Change password (self-service, logged in)
- Current password + new password + confirm new password.
- Clears `mustChangePassword` flag if set.

---

## 3. Dynamic Permission Model

Replaces the narrative-only access rules in the original functional spec. Base roles still exist (`ADMIN, DOCTOR, MANAGER, ACCOUNTANT, NURSE, RECEPTIONIST`) and still carry sane defaults, but permissions are now data, editable by Admin without a code deploy.

**Resolution order for "can user X do action Y":**
1. Look up `UserPermissionOverride` for (user, permission) — if present, it wins (`granted = true/false`).
2. Otherwise fall back to `RolePermission` for (user.baseRole, permission).
3. Otherwise denied.

This matches the design spec §7.3 "Customize Permissions" screen (base role grants + per-user overrides, with a running total like "40 base + 2 custom = 42").

### Schema additions

```prisma
model Permission {
  id          String   @id @default(cuid())
  key         String   @unique // "vitals.record", "audit.view", "staff.manage"
  label       String             // human-readable, shown in UI
  category    String             // "clinical" | "financial" | "admin"
  createdAt   DateTime @default(now())

  rolePermissions RolePermission[]
  overrides       UserPermissionOverride[]
}

model RolePermission {
  id           String     @id @default(cuid())
  baseRole     String     // ADMIN, DOCTOR, MANAGER, ACCOUNTANT, NURSE, RECEPTIONIST
  permissionId String
  permission   Permission @relation(fields: [permissionId], references: [id])

  @@unique([baseRole, permissionId])
}

model UserPermissionOverride {
  id           String     @id @default(cuid())
  userId       String
  user         User       @relation(fields: [userId], references: [id], onDelete: Cascade)
  permissionId String
  permission   Permission @relation(fields: [permissionId], references: [id])
  granted      Boolean    // true = add on top of role, false = revoke from role
  createdAt    DateTime   @default(now())

  @@unique([userId, permissionId])
}

model PasswordResetToken {
  id        String   @id @default(cuid())
  userId    String
  user      User     @relation(fields: [userId], references: [id], onDelete: Cascade)
  token     String   @unique
  expiresAt DateTime
  usedAt    DateTime?
  createdAt DateTime @default(now())
}
```

Also add to `User`:
```prisma
model User {
  // ...existing fields from functional spec...
  mustChangePassword Boolean @default(false)
  permissionOverrides UserPermissionOverride[]
  resetTokens         PasswordResetToken[]
  tasksAssigned       Task[] @relation("TaskAssignedTo")
  tasksCreated        Task[] @relation("TaskCreatedBy")
}
```

### Seeding
- Permission catalog (`Permission` rows) is seeded from a static list in code (grouped by category, matching design spec §7.2's Clinical / Financial / Admin sections).
- `RolePermission` defaults seeded per role, matching the boundaries narratively described in hmis-spec.md §1 (e.g. Doctor gets `patients.view`, `consultation.create`, `consultation.edit_own`, `vitals.view`, `procedures.record`, `commission.view_own` — NOT `financials.view_clinic` or `audit.view`).
- Admin UI (design spec §7.1/§7.2) edits `RolePermission`; Staff Management "Customize Permissions" (§7.3) edits `UserPermissionOverride`.

---

## 4. Special Tasks (new module, not in original spec)

Lightweight ad-hoc task assignment, decoupled from patient visits — e.g. "restock supplies," "follow up with vendor."

```prisma
model Task {
  id           String    @id @default(cuid())
  title        String
  description  String?
  assignedToId String
  assignedTo   User      @relation("TaskAssignedTo", fields: [assignedToId], references: [id])
  createdById  String
  createdBy    User      @relation("TaskCreatedBy", fields: [createdById], references: [id])
  status       String    @default("pending") // pending, in_progress, done, cancelled
  dueDate      DateTime?
  createdAt    DateTime  @default(now())
  updatedAt    DateTime  @updatedAt
}
```

- Admin dashboard quick action: "+ Add Special Task" → pick staff member, title, description, due date.
- Assignee sees their open tasks on their own dashboard (simple list, no dependency on other phases).

---

## 5. Admin UI Sub-Modules (Phase 1)

Per design spec §7.1 sidebar, Phase 1 wires up:
- **Staff Management** — add/edit staff (any non-doctor role), list, "Customize Permissions" modal
- **Doctor Onboarding** — add doctor (creates `User` + empty `DoctorProfile`; document upload and financial terms remain Phase 4 per original plan, but the base profile record is created here so doctors exist in dropdowns early)
- **Permissions & Roles** — role list, per-role permission editor (design spec §7.2)
- **Special Tasks** — assign/track ad-hoc tasks
- **Audit Logs** — write path active from Phase 1 (per hmis-spec.md principle #4); the reporting/browsing UI can be minimal (raw table view) until Phase 6

Deferred sidebar items (Financial Config, Procedure Master, Expense Categories, Reports) render as disabled/"Coming in a later phase" per original phase plan — not built in Phase 1.

---

## 6. Design System Reference

Full visual spec (colors, typography, spacing, component states, login page layout, animations) lives in the design guide this Phase 1 plan implements — see chat history 2026-07-15, "HMIS — PREMIUM UI/UX DESIGN GUIDE". Key tokens:

- Primary: `#1E3A8A` → `#3B82F6` gradient
- Base font: Inter
- Spacing base: 8px
- Border radius: 8px (inputs/buttons), 12px (cards/modals)
- WCAG AA verified combinations listed in design guide §10.1

*(Recommend copying the full design guide content into `hims/docs/design-system.md` verbatim so it lives in-repo rather than only in chat history.)*

---

## 7. Open Items / Decisions Log

- 2026-07-15: Permission model — chose full dynamic (`Permission`/`RolePermission`/`UserPermissionOverride`) over static/cosmetic, so the §7 admin UI is real, not a mockup.
- 2026-07-15: Password reset — email link, with seeded `admin`/`admin1234` as a non-email fallback account for initial access.
- 2026-07-15: Change password — simple current+new, no forced rotation, but `mustChangePassword` forces it once for admin-created accounts (including the seed).
- 2026-07-15: Phase 1 scope confirmed as auth + staff/doctor-as-user + permissions; Procedure Master and other master data stay in their original later phases.
- 2026-07-15: "Special Task" clarified as ad-hoc staff task assignment — new module, added to Phase 1 as a stretch item.
