# Plan 01 — Foundation

| | |
|---|---|
| **Status** | Approved by owner (2026-11-05), including recommendations D1–D6 in §8 |
| **Date** | 2026-11-04 |
| **Roadmap step** | Brief §13 step 1, adjusted by [ADR 0001](../decisions/0001-stack.md) and [ADR 0002](../decisions/0002-product-model.md) |
| **Owner decisions used** | XAMPP config may be edited with backups · dev host `meridian.localhost` · database: XAMPP's MariaDB 10.11 on port 3306 (owner, 2026-11-05; replaces the MySQL 8.4 ZIP) · demo data grows per module |

Foundation builds the platform every later module stands on. It ships **no HR features**. When it's done, you can sign in as any of the 6 demo accounts (with 2FA), see a themed app shell whose menu is filtered by your permissions, edit global settings and the permission matrix, and upload a file safely. Everything is covered by tests and CI, and generated API types connect the frontend to the backend.

---

## 0. Scope

**In scope**

| # | Area | Brief ref |
|---|---|---|
| F0 | Local environment (XAMPP edits, MariaDB 10.11 settings, vhost) | ADR 0001 §3.3 |
| F1 | Repo skeleton: `backend/`, `frontend/`, `openapi/`, `deploy/`, `tools/` | ADR 0001 §3.4 |
| F2 | Platform services: Problem Details errors, JSON logs with request ID, health checks, public IDs, encrypted fields, blind index, audit log, idempotency keys, optimistic concurrency, cursor pagination, rate limits | §3.2, §4, §8.1 |
| F3 | Authentication: login, logout, password reset, TOTP 2FA + recovery codes, lockout, session rotation, idle timeout, 2FA enforcement by role | §4, §8.2 AUTH |
| F4 | Authorisation: access roles, permission catalogue, role → permission matrix, **scope engine** (self / reports / team / all) | §5 |
| F5 | Company settings (singleton) with full validation | §6.1 `org_settings` → `company_settings` (ADR 0002) |
| F6 | File service: presigned upload, magic-byte MIME check, size limits, scan status, photo re-encode, signed download | §4 Uploads |
| F7 | Frontend shell: login and 2FA screens, app layout, permission-filtered navigation, 5 themes, Settings and Access Roles pages, Security (2FA) page | §9 |
| F8 | OpenAPI generated from code (Scramble) → typed TS client; CI drift check | ADR 0001 Q2 |
| F9 | CI (GitHub Actions), Dependabot, security audits | §3.2.12, §4 |
| F10 | Demo seed (Foundation slice) and parity test | §6.3, owner decision |
| F11 | Installer command and deploy skeleton (not run against a server yet) | ADR 0002 §5 |

**Out of scope for Foundation** (later plans): departments, designations, full employee profile, recruitment, projects, tasks, KPI, notifications UI, onboarding wizard UI, server provisioning.

**Minimal people data inside Foundation.** The scope engine (§5.2 of the brief) needs reporting chains and team leads to work and to be tested. Foundation therefore creates a **core** `employees` table (identity + `superior_id`) and `teams` / `team_members`. The People module later extends these with additional migrations (new columns only, nothing is rebuilt). Decision D1 in §8.

---

## 1. System architecture

```
Browser ──► http://meridian.localhost  (Apache, XAMPP, port 80)
              ├── /api/*, /sanctum/*, /healthz, /readyz ─► Laravel (backend/public)
              └── everything else ───────────────────────► Next.js dev server :3000 (proxied, incl. websocket HMR)

Laravel ──► MariaDB 10.11 :3306 (XAMPP, 127.0.0.1 only)
        ├── cache / queue / session: "database" driver in dev (Redis in prod)
        ├── files: "local" disk in dev (S3-compatible in prod), served via signed routes only
        └── mail: "log" driver in dev
```

In production the same shape holds, except Apache serves the static `frontend/out/` directly (ADR 0001 §3.2).

**Sync vs async in Foundation**

| Work | Runs | Why |
|---|---|---|
| Login, 2FA, settings, permissions, presign | Synchronous request | Fast, user is waiting |
| Audit log write | Synchronous, **same transaction** as the change | An audited change must never commit without its audit row |
| File scan + photo re-encode | Queued job (`ProcessUploadedFile`) | Slow; file stays `scan_status = pending` and unusable until clean |
| Password reset email | Queued, dispatched **after commit** | Never send mail for a rolled-back change |

---

## 2. File structure (new)

```
/.gitattributes  /.editorconfig  /README.md
/backend                      Laravel 13
  app/
    Domain/
      Access/                 Permission catalogue, RoleMatrix, ScopeResolver (recursive CTE)
      Audit/                  AuditLogger, Auditable trait, redaction rules
      Files/                  FileService, ProcessUploadedFile job, MimeSniffer, ImageNormaliser
      Settings/               CompanySettings model + validation rules
      Shared/                 HasPublicId trait, PublicId generator, BlindIndex, EncryptedCast,
                              CursorPaginator, WorkingDays (stub, real impl in Work module)
    Http/
      Controllers/Api/V1/     Auth*, Me, Settings, AccessRoles, Files, Health
      Middleware/             AssignRequestId, EnforceIdleTimeout, RequireTwoFactorIfEnforced,
                              IdempotencyKey, RequireIfMatch
      Requests/  Resources/
    Exceptions/ProblemDetailsRenderer.php
    Console/Commands/InstallCommand.php     (php artisan meridian:install)
  config/  meridian.php (public-id prefixes, upload limits, key versions)
  database/migrations/  seeders/DemoCompany/  factories/
  routes/api.php
  tests/Unit/  tests/Feature/  tests/Database/
  phpstan.neon  pint.json  pest config
/frontend                     Next.js 16, output: 'export'
  src/app/(auth)/login/  (auth)/two-factor/  (auth)/forgot-password/  (auth)/reset-password/
  src/app/(app)/layout.tsx    shell: sidebar, topbar, theme switcher
  src/app/(app)/dashboard/  profile/security/  system/settings/  system/access-roles/
  src/app/(app)/[...placeholder]  "Coming in <module>" for not-yet-built nav items
  src/lib/api/                generated schema.d.ts + openapi-fetch client + problem-details helper
  src/lib/auth/  src/lib/permissions/  src/theme/ (5 layouts → AntD tokens + CSS vars)
  src/components/             IconButton, Chip, Drawer, Modal(confirm), DataTable (shell only)
  tests/unit/ (Vitest)  tests/e2e/ (Playwright)
/openapi/openapi.yaml         generated, committed
/deploy/deploy.php            Deployer recipe · apache-vhost.conf · supervisor-horizon.conf
/tools/xampp/meridian-vhost.conf         the vhost we add to XAMPP
/.github/workflows/ci.yml  /.github/dependabot.yml
```

---

## 3. Database schema (Foundation tables)

Conventions for every table: `id BIGINT UNSIGNED AUTO_INCREMENT` primary key (never exposed). `public_id CHAR(16) ascii UNIQUE` on exposed tables. `created_at`/`updated_at` stored as `DATETIME(6)` UTC. `created_by`/`updated_by` reference `users.id` (NULL = system). `version INT UNSIGNED` where optimistic concurrency applies. Charset `utf8mb4`, collation `utf8mb4_uca1400_as_ci` (MariaDB 10.11). Strict SQL mode. JSON columns get `JSON_VALID` CHECKs; JSON shape rules are validated in the app (ADR 0001 §4.1).

### 3.1 `company_settings` (singleton)

| Column | Type / constraint | Why |
|---|---|---|
| `id` | `TINYINT UNSIGNED PK`, `CHECK (id = 1)` | Guarantees exactly one row |
| `company_name`, `logo_file_id` | `VARCHAR(150) NOT NULL`, FK → files | Branding per install |
| `timezone` | `VARCHAR(64) NOT NULL DEFAULT 'Asia/Kuala_Lumpur'` | "Today" is computed in this zone |
| `country`, `currency` | `CHAR(2) DEFAULT 'MY'`, `CHAR(3) DEFAULT 'MYR'` | |
| `working_days` | `JSON`, CHECK schema: array of distinct ints 1..7 | Replaces `smallint[]` (ADR 0001 §5.7) |
| `holiday_region` | `VARCHAR(10)` e.g. `MY-14` | |
| `two_fa_enabled`, `two_fa_roles` | `BOOL`, `JSON` (array of role keys) | |
| `session_timeout_minutes` | `SMALLINT CHECK IN (15,30,60,480)` | |
| `default_probation_months` | `TINYINT CHECK 0..6` | |
| `probation_reminder_days`, `pip_default_days` | `CHECK IN (7,14,30)`, `CHECK IN (30,60,90)` | |
| `checkin_every_workdays`, `checkin_mandatory` | `TINYINT CHECK 1..5`, `BOOL` | |
| `extension_approval` | `ENUM('both','pm','sup')` | |
| `auto_late_delay` | `BOOL` | |
| `data_review_month`, `data_review_window_days` | `TINYINT CHECK 1..12`, `CHECK IN (14,30,45)` | |
| `require_resume`, `candidate_retention_months`, `former_employee_policy` | `BOOL`, `CHECK IN (6,12,24)`, `ENUM('interview','choose','direct')` | |
| `kpi_mode`, `kpi_weights` | `ENUM('system','external')`, `JSON` with schema CHECK (exactly 7 keys, ints 0..100) + CHECK sum = 100 | ADR 0001 §5.8 |
| `version`, timestamps, `updated_by` | | ETag / If-Match |

### 3.2 Access

| Table | Columns and constraints | Why |
|---|---|---|
| `access_roles` | `key VARCHAR(20) UNIQUE` (`sysadmin, exec, hr, lead, staff`), `name`, `note`, `locked BOOL` | Seeded; `sysadmin` locked |
| `permissions` | `key VARCHAR(64) UNIQUE`, `group`, `label` | Catalogue defined in code, synced by seeder; the 17 keys of brief §5.3 |
| `role_permissions` | PK `(role_id, permission_id)`, `granted BOOL`, FKs `RESTRICT` | Editable matrix; sysadmin rows ignored (always granted) |

### 3.3 Identity

| Table | Columns and constraints | Why |
|---|---|---|
| `users` | `public_id`, `name`, `email VARCHAR(190) UNIQUE` (lower-cased on write, CHECK), `password` (argon2id), `role_id FK`, `employee_id UNIQUE NULL` (FK added with employees in M4), `two_factor_secret TEXT` (encrypted), `two_factor_recovery_codes TEXT` (encrypted, so they can be shown again after a password confirmation), `two_factor_confirmed_at`, `status ENUM('invited','active','disabled')`, `last_login_at`, `password_changed_at`, `ui_layout ENUM(5 themes) DEFAULT 'indigo'`, `remember_token`, `version`. **M3:** the failed-login count and lock are kept in the cache per email (`LoginThrottle`), not in columns, so unknown emails behave exactly like real ones. | Fortify column names; theme preference stored server-side (brief §9) |
| `sessions` | Laravel default (`user_id` indexed) | Dev DB sessions; lets us revoke a user's sessions |
| `password_reset_tokens` | Laravel default (token hashed) | |
| `personal_access_tokens` | Sanctum default | Ready for future mobile apps |

### 3.4 People core (extended by the People module later)

| Table | Columns and constraints | Why |
|---|---|---|
| `employees` | `public_id`, `employee_no VARCHAR(20) UNIQUE`, `full_name VARCHAR(150)`, `work_email VARCHAR(190) UNIQUE`, `superior_id FK employees NULL ON DELETE RESTRICT`, loop-blocking trigger (self-reference included: MariaDB allows no CHECK on the auto-increment `id`), `status ENUM(8 values from brief)`, `join_date DATE`, `left_at DATE NULL`, `version`, `deleted_at` | Enough for scope rules and demo logins. Cycle prevention by the domain service (`EmployeeHierarchy`) and the trigger (ADR 0001 §5.12). Index on `superior_id` for the recursive CTE. |
| `teams` | `public_id`, `name VARCHAR(100) UNIQUE`, `lead_employee_id FK`, `version` | Lead scope |
| `team_members` | PK `(team_id, employee_id)`, index `(employee_id)` | Rule "lead must be a member" enforced in the domain service + test |

### 3.5 Platform

| Table | Columns and constraints | Why |
|---|---|---|
| `files` | `public_id`, `disk`, `storage_key UNIQUE`, `original_name`, `mime`, `size_bytes`, `sha256 CHAR(64)`, `scan_status ENUM('pending','clean','infected')`, `purpose ENUM('photo','resume','evidence','logo','import')`, `uploaded_by`, `confirmed_at` | Brief §6.1 `files`; `purpose` sets size and MIME limits |
| `audit_log` | `id`, `occurred_at DATETIME(6)`, `actor_user_id NULL` (no FK, so rows survive user deletion), `ip VARBINARY(16)`, `user_agent VARCHAR(255)`, `request_id CHAR(26)`, `action VARCHAR(64)`, `subject_type VARCHAR(64)`, `subject_id BIGINT`, `changes JSON` (before/after with sensitive values redacted). Indexes `(subject_type, subject_id, occurred_at)` and `(actor_user_id, occurred_at)`. Triggers `BEFORE UPDATE` / `BEFORE DELETE` → `SIGNAL`. Written on the app connection in the same transaction as the change (ADR 0001 §5.14, amended 2026-11-05). | Append-only (ADR 0001 §5.14). Not partitioned at MVP (§5.13). |
| `jobs`, `job_batches`, `failed_jobs`, `cache`, `cache_locks` | Laravel defaults | Dev drivers; harmless in prod |

**Migration order:** settings → access → files → users → employees → teams → audit triggers (created by the audit_log migration).

---

## 4. API endpoints (Foundation)

All under `/api/v1`, JSON, `snake_case`. Errors are RFC 7807 Problem Details with a stable `code`. Public IDs only. Auth is the Sanctum session cookie + CSRF.

| Method & path | Permission / rule | Notes |
|---|---|---|
| `GET /sanctum/csrf-cookie` | public | Sets XSRF cookie |
| `POST /auth/login` | public, 20/min/IP (an office shares one IP) + per-email lockout: 5th failure locks 1 min, doubling to 1 h | Returns `{two_factor: true}` when a code is needed; rotates the session |
| `POST /auth/two-factor-challenge` | pending-login session | TOTP code or recovery code (each recovery code works once) |
| `POST /auth/logout` | authenticated | |
| `POST /auth/forgot-password` · `POST /auth/reset-password` | public, rate-limited | Always 202, so the response doesn't reveal whether an email exists |
| `POST /auth/confirm-password` | authenticated | Required before 2FA changes (valid 15 min) |
| `PUT /auth/password` | authenticated | **Added in M3 (owner, 2026-11-05).** Current + new password; other sessions end; audited |
| `POST /auth/2fa/setup` · `POST /auth/2fa/confirm` · `DELETE /auth/2fa` | self, recently confirmed password | Setup returns QR SVG + secret; disabling is blocked if the role is enforced |
| `GET /auth/2fa/recovery-codes` · `POST /auth/2fa/recovery-codes` | self, recently confirmed password | Regenerate invalidates the old codes |
| `GET /me` | authenticated | User, role, linked employee, `ui_layout`, `two_factor_required` |
| `GET /me/permissions` | authenticated | Permission keys + nav scope flags (e.g. `has_reports`) |
| `PATCH /me/preferences` | self | `ui_layout` |
| `GET /settings` | authenticated (sections filtered by permission) | Returns `ETag`. Everyone reads `company` and `work`; `security` needs system.settings; `employment` system.settings or people.lifecycle; `recruitment` system.settings or candidate.create; `kpi` kpi.configure or kpi.view_all (M4) |
| `PATCH /settings` | `system.settings` for security/work/employment/recruitment fields; `kpi.configure` for `kpi_mode`/`kpi_weights`; needs `If-Match` | 412 on a stale version; audited |
| `GET /access-roles` | `system.settings` | Roles + full matrix |
| `PUT /access-roles/{key}/permissions` | `system.settings`; `sysadmin` → 409 `ROLE_LOCKED` | Audited; invalidates permission cache |
| `POST /files/presign` | authenticated + purpose-specific permission | Returns upload URL + `file_id`; size/MIME limits by purpose |
| `POST /files/{public_id}/complete` | uploader | Verifies size + sha256, queues scan/normalise |
| `GET /files/{public_id}/url` | owner-resource policy | Short-lived signed URL; 409 if not `clean` |
| `GET /healthz` · `GET /readyz` | public (no data) | Liveness / DB + cache + queue reachability |

The brief puts `GET|PATCH /org/settings` at `/settings` here, because there is no org layer (ADR 0002). Lifecycle, people and other endpoints come with their modules.

---

## 5. UI architecture (Foundation)

| Route | Component(s) | Mockup screen |
|---|---|---|
| `/login` | LoginForm (email, password), demo-account quick pick in dev builds only | Login |
| `/two-factor` | OtpForm (6-digit, or recovery code toggle) | 2FA prompt |
| `/forgot-password`, `/reset-password` | simple forms | — (new) |
| `(app)` layout | Sidebar (groups from mockup `NAV`, filtered by `/me/permissions`), Topbar (crumb, title, global actions slot), ThemeSwitcher (5 layouts), user menu | Shell |
| `/dashboard` | Placeholder welcome card | Dashboard (filled by later modules) |
| `/profile/security` | 2FA enable/disable, QR, recovery codes, active sessions note | — (new, needed for 2FA) |
| `/system/settings` | Sections: Security, Work rules, Employment defaults, Recruitment. Same layout as the mockup `pgSettings`. | Global Settings |
| `/system/access-roles` | Role cards + permission matrix with switches; sysadmin column locked | Access Roles |
| other nav items | `ComingSoon` placeholder naming the module | — |

- **State:** TanStack Query for server state. Mutations invalidate the affected queries. No business rules in the client.
- **Forms:** AntD Form. Server 422 field errors are mapped onto fields. Submit is disabled while a request is in flight, and an idempotency key is sent on create/commit POSTs.
- **Theming:** each of the 5 layouts = an AntD `ConfigProvider` token set + CSS variables + a few structural classes (floating sidebar, icon rail). Saved via `PATCH /me/preferences`.
- **AntD + Tailwind:** AntD's CSS-in-JS runs in a cascade layer, and Tailwind's preflight is ordered so it doesn't override AntD components.
- **Accessibility:** focus traps in drawers and modals, keyboard navigation in the sidebar, `prefers-reduced-motion`, icon buttons always have `aria-label` + tooltip.
- **Mobile:** sidebar becomes off-canvas below 1024 px.

---

## 6. Build sequence and approval gates

Each milestone ends with a short report, then **I ask before committing**. Pushing to GitHub is a separate question each time.

| M | Work | Gate before starting |
|---|---|---|
| M0 | Back up XAMPP config; `tools/xampp/apply-config.ps1` (PHP extensions, proxy modules, `meridian.localhost` vhost, MariaDB 10.11 dev settings); `.gitattributes`, `.editorconfig` | ✅ XAMPP edits approved. The config files need Administrator rights, so **you run the script as Administrator**, then restart Apache and MySQL from the XAMPP panel. |
| M1 | Scaffold Laravel 13 + Next.js 16 and install packages (list in §6.1) | **Ask:** confirm the package list |
| M2 | Platform services (F2) + company settings (F5) + tests | — |
| M3 | Auth + 2FA (F3) + tests | — |
| M4 | Access roles, permissions, scope engine, people core, demo seed + parity test (F4, F10) | — |
| M5 | File service (F6) + tests | — |
| M6 | **M6a** backend changes from the mockup reviews (§9.2), then **M6b** frontend shell, auth screens, My Profile, Settings, Access Roles, System Updates (§9.3) + Vitest + Playwright smoke | Owner approves §9 before M6a starts |
| M7 | OpenAPI generation + TS client (F8); CI + Dependabot (F9) | **Ask:** before the first push to GitHub (CI only runs there) |
| M8 | `meridian:install` command + deploy skeleton (F11) | — |

### 6.1 Packages to install (for your confirmation at M1)

| Side | Runtime | Dev / test |
|---|---|---|
| Backend | `laravel/framework` 13, `laravel/fortify`, `laravel/sanctum`, `dedoc/scramble`, `intervention/image` (gd), `sentry/sentry-laravel` | `pestphp/pest` (+ Laravel plugin), `larastan/larastan`, `laravel/pint` |
| Backend (prod only) | `laravel/horizon`: needs `pcntl`, which doesn't exist on Windows, so it's installed with platform-requirement overrides and only enabled in production | |
| Frontend | `next` 16, `react` 19, `antd` 6, `@ant-design/icons`, `@ant-design/cssinjs`, `@tanstack/react-query`, `openapi-fetch`, `dayjs`, `@sentry/nextjs` | `tailwindcss` 4, `typescript`, `openapi-typescript`, `eslint`, `prettier`, `vitest`, `@testing-library/react`, `@playwright/test` |

Icons: AntD ships `@ant-design/icons`; the mockup used Lucide. Decision D4.

---

## 7. Tests

| Kind | What |
|---|---|
| Unit | Public ID generator (prefix, length, alphabet, collision retry) · encrypted cast round-trip and **decrypt with previous key** after rotation · blind index normalisation (NRIC with/without dashes → same hash) · `kpi_weights` and settings validation · scope resolver (Hafiz → Nadia → Kevin → Grace → Aiman chain; lead scope = team members ∪ their reports; staff with no reports = self only) · superior cycle detection · lockout backoff timing · Problem Details shape |
| Database | Each CHECK on `company_settings` rejects bad values · singleton cannot get a second row · `superior_id = id` rejected · `audit_log` UPDATE/DELETE rejected by trigger · `audit` DB user can't UPDATE |
| Feature (every endpoint) | allowed · forbidden by role (403) · forbidden by scope where relevant · validation failure (422) · unauthenticated (401) · stale `If-Match` (412) · missing CSRF (419). Cross-tenant tests from the brief **don't apply** (ADR 0002). |
| Auth flows | Login rotates the session ID · 6th failed attempt locks with backoff · 2FA-enforced role is forced into setup · recovery code works once · idle timeout signs out · password reset doesn't reveal unknown emails · disabled user can't sign in |
| Seed parity | 6 users with the right roles · 20 employees · 6 teams with leads who are members · the 5-step chain above · Nadia has exactly 2 reports (Mei Ling, Hafiz) · `sysadmin` has no employee |
| Frontend | Vitest: nav filtering per permission set, theme token mapping, Problem Details → form errors. Playwright smoke: each of the 6 demo accounts signs in, sees exactly its allowed menu, opens each allowed page, zero console errors (brief §10.6). |
| CI | All of the above + `pint --test`, `phpstan`, `eslint`, `tsc --noEmit`, `next build`, OpenAPI drift check, `composer audit`, `npm audit --omit=dev` |

---

## 8. Edge cases and open decisions

**Edge cases handled in Foundation**
- Two admins edit settings at once → second save gets 412 and must reload.
- Admin removes `system.settings` from their own role → blocked: at least one non-locked role, or sysadmin, must keep it (409).
- 2FA turned on for a role while its users are signed in → they're sent to 2FA setup on their next request.
- Lost authenticator → recovery code; if all are lost, a sysadmin reset is audited.
- Clock drift on phones → TOTP accepts ±1 time step.
- Upload claims `image/png` but bytes are a PDF → rejected by magic-byte check.
- Same file uploaded twice → stored twice, linked by `sha256`, so later dedup is possible.
- Request retried with the same `Idempotency-Key` → original response replayed, no second write.
- Request at 23:59 MYT → "today" derived from the company timezone, not the server's.

**Owner decisions** (all approved as recommended, 2026-11-05)

| # | Question | My recommendation | Needed by |
|---|---|---|---|
| D1 | Create the core `employees` / `teams` tables in Foundation (so the scope engine can be built and tested), extended later by the People module? | Yes | M4 |
| D2 | Transactional outbox (brief §6.1) now, or rely on Laravel's "dispatch after commit" until email notifications matter? After-commit can lose a job if Redis is down at that exact moment; an outbox can't. | After-commit now, outbox in the Work module (when notifications start) | M2 |
| D3 | Unbuilt nav items: show them with a "Coming in <module>" page, or hide them until built? | Show with placeholder, so permissions can be tested end to end | M6 |
| D4 | Icons: AntD icons (native, consistent with components) or Lucide (matches the mockup)? | Lucide via `lucide-react`, to stay faithful to the mockup | M6 |
| D5 | Add a `system.audit.view` permission + a basic audit log viewer now? The brief has no such permission, so adding one is a brief change. | Not now: audit log is written in Foundation; a viewer can come with Hardening | M4 |
| D6 | Demo login quick-pick buttons on the login page in dev builds (like the mockup)? Never shown in production builds. | Yes, dev only | M6 |
| D7 | Passkeys (Fortify installs them)? | **Owner, 2026-11-05: not for now.** Feature off, table and routes removed; can be added later | M3 |
| D8 | Password rules | **Owner, 2026-11-05: 8-128 characters with upper and lower case, a number and a symbol.** Below the OWASP ASVS L2 guidance (12+ characters, breached-password check), accepted by the owner | M3 |
| D9 | Endpoint for a signed-in user to change their password | **Owner, 2026-11-05: yes**, `PUT /auth/password` | M3 |
| D10 | Audit writes vs. a separate audit DB user | **Owner, 2026-11-05: same transaction as the change** (ADR 0001 §5.14 amended) | M2 |

---

## 9. Amendment: mockup reviews (2026-11-05, approved by owner 2026-11-05)

The owner reviewed the HTML mockup three times on 2026-11-05 (`docs/design/meridian-mockup.html`, commits `be80faf`, `6a9004f`, `a92e96f`). M6 is split into **M6a** (backend changes) and **M6b** (screens), in that order (owner decision D11).

### 9.1 Owner decisions

| # | Decision |
|---|---|
| D11 | M6a backend changes first, then M6b screens. |
| D12 | A minimal `departments` table moves into Foundation (name, optional HOD); groups belong to a department. The Organization module adds the rest later. |
| D13 | English / Chinese via our own JSON dictionaries (`en.json`, `zh-CN.json`) and a typed `t()` helper; no i18n library. The user's language is saved on their account. |
| D14 | Demo data follows the mockup: the owner's organisation (CEO, GM, 9 departments, 51 people, groups, team leads, per-person reporting lines) and its six demo logins. **Real names: replace with fictional ones before any client or prospect sees the demo.** |
| D15 | Design option 6 (Atlas layout, Straits colours, Plus Jakarta Sans); one look, no theme switcher. Supersedes the "5 themes" of F7 and `ui_layout`. |
| D16 | Component classes use the `mw-` naming in `docs/design/components.md`. |
| D17 | (2026-11-06, revised 2026-11-08) Interns and juniors can't be team leads. Each designation has a **Can lead a group** flag (`designations.can_lead_team`, off for Intern, Junior 1 and Junior 2 in the demo); `TeamService::addLead` refuses others with 422 `TEAM_LEAD_LEVEL_TOO_LOW`. A flag rather than a level threshold, so reordering the ladder never changes who may lead. |
| D18 | (2026-11-06, revised 2026-11-08) Reporting lines view is a compact card tree: anyone with reports gets a card and branches out; people with no reports are boxed per group under their superior. Each branch is labelled with its group (or department, for a head outside any group). Branches follow department, then group order. Export as PNG or JPG (3×) or a one-page A4 landscape PDF. (The A4 rows-of-3-and-4 layout was tried and dropped.) |
| D19 | (2026-11-08) Designations are a ladder ordered by `level` (0 = lowest, no gaps). The order changes only as a whole: the screen lets HR drag rows, shows the unsaved order, and asks for confirmation before saving (`PUT /designations/order` with every id and If-Match = ladder version). Only unused designations can be deleted; rungs above move down. |
| D20 | (2026-11-08) Employment types are fully managed: add, edit (including rename), delete, with the types each may convert to and an optional fixed end date with a renewal reminder. Deleting a type still in use asks which type to move those employees to, then moves and deletes in one step. At least one type remains. |
| D21 | (2026-11-08) Task tags are fully managed: add (code 1–3 characters, fixed once created), edit, delete. Each tag has its own list of levels (one or more, each with a name and points) instead of a fixed five. At least one tag remains. When the Tasks module exists: deleting a tag in use asks which tag to move tasks to, and tasks on a removed level move to the highest remaining one. |
| D22 | (2026-11-08) Employee numbers follow a company format: `{seq}` (required) plus optional `{yyyy}`, `{yy}`, `{dept}`, letters, digits and `- _ / .`; padding 3–8 digits; next number. Changing the format affects new employees only; a System Admin can instead renumber everyone (join-date order, old numbers kept in the audit log). Issued numbers skip any already taken. |
| D23 | (2026-11-08) Tasks, projects and meetings each offer List, Grid and Cards views with search, filters and sorting. Mockup now; the list APIs take matching filter and sort parameters when those modules are built. |

### 9.2 M6a: backend changes

| Change | Detail |
|---|---|
| Session timeout options | 15, 30, 60, **120, 240** minutes (480 removed): CHECK constraint, validation, default stays 30. |
| Departments (D12) | `departments`: `public_id`, `name` UNIQUE, `head_employee_id` NULL (at most one HOD; any designation). `employees.department_id`, `teams.department_id`. CEO and GM sit in a "Management" department that the org chart shows above the others. |
| Team leads | A group has **zero, one or several** team leads: new `team_leads (team_id, employee_id)`; `teams.lead_employee_id` is dropped. A team lead must be a member. |
| Reporting lines | Stay per person (`employees.superior_id`), never derived from the team lead; loops refused by the service and the trigger. |
| Scope | Team leads see their groups' members and everyone below them; an HOD sees their whole department; everyone sees the people below them in the reporting lines; sysadmin, exec, HR see everyone. |
| Display name and pictures | `users.display_name` (others see its first letter as the avatar), `users.avatar_file_id` (personal picture, shown to the user only). The official photo (`employees.photo_file_id`, set by HR, used in org chart and HR views) arrives with the People module. |
| Language | `users.locale` (`en`, `zh-CN`), `PATCH /me/preferences` accepts it. `ui_layout` is removed (D15). |
| Two 2FA devices | New `two_factor_devices` (`user_id`, `name`, encrypted secret, `confirmed_at`, `last_used_at`), **at most 2 per user**. Sign-in accepts a code from any confirmed device; recovery codes stay per user. Endpoints: `GET/POST /auth/2fa/devices`, `POST /auth/2fa/devices/{id}/confirm`, `DELETE /auth/2fa/devices/{id}` (the last device can't be removed while 2FA is required for the role). Replaces Fortify's single-secret setup endpoints. |
| Forced 2FA setup | Unchanged rule: when 2FA is required for the role and the user has no device, only 2FA setup, `/me` and logout are allowed. |
| Version | `GET /api/v1/version` (signed in): semantic version, build date and commit, from `config/meridian.php` (set by the deploy). The update log table and the cron deploy script come with M8. |
| Demo seed (D14) | Seeder, parity tests and demo logins switched to the mockup's organisation. |

### 9.4 Job catalogues and employee numbers (2026-11-08, owner: "Prefix + 3 setup modules")

| Area | Backend |
|---|---|
| Tables | `designations` (name, track, level unique, can_lead_team, salary band, KPI target), `employment_types` + `employment_type_conversions`, `task_tags` + `task_tag_levels`; `employees.designation_id`, `employees.employment_type_id`; `company_settings.employee_no_format / _digits / _next` (migration `2026_11_08_100000_create_job_catalogues`). |
| API | `GET /designations` (ETag = ladder version; salary bands only with people.salary.view), `POST`, `PATCH /{id}`, `DELETE /{id}`, `PUT /designations/order`; `GET/POST/PATCH/DELETE /employment-types` (DELETE takes `move_to`); `GET/POST/PATCH/DELETE /task-tags` (levels sent as a whole list); `GET /employee-numbers` (people.edit), `POST /employee-numbers/renumber` (system.settings, If-Match = settings version). Format changes for new hires go through `PATCH /settings` (new `employee_numbers` section). |
| Permissions | Reads: any signed-in app user. Designations and employment types: org.structure.manage. Task tags: org.task_tags.manage. |
| Errors | `DESIGNATION_IN_USE`, `LADDER_INCOMPLETE` (422), stale ladder 412, `EMPLOYMENT_TYPE_IN_USE`, `LAST_EMPLOYMENT_TYPE`, `MOVE_TO_SAME_TYPE`, `LAST_TASK_TAG`, `TAG_NEEDS_A_LEVEL`, `EMPLOYEE_NO_FORMAT_INVALID`, `EMPLOYEE_NO_NOT_UNIQUE`, `TEAM_LEAD_LEVEL_TOO_LOW`. |
| Tests | `tests/Feature/Catalogue/*`, `tests/Feature/People/EmployeeNumbersTest.php`, seed parity for the demo catalogues. |
| Not yet | Task/project/meeting list APIs (D23) and tag-move-on-delete (D21) come with the Tasks and Projects modules. A fresh install starts with empty catalogues until the installer (M8) seeds defaults. |

### 9.3 M6b: screens (Next.js static export)

| Area | Screens and behaviour |
|---|---|
| Sign-in | Login (fits the window; demo accounts scroll; dev builds only, D6), 2FA code, forgot / reset password, language switch, version. |
| Shell | Sidebar labelled by default, collapsible to an icon rail (remembered); profile card (picture + display name + role) opens My Profile; version line; top bar with page title, **ⓘ user guide** (right slide-in panel), search placeholder, EN / 中文 switch; themed scrollbars. |
| Dashboard | Greeting band and placeholder tiles (filled by later modules). |
| My Profile | Overview (display name, own picture, employment details read-only), Security (change password with live rules; 2FA devices: add with QR, remove, recovery codes), Preferences (language). Personal information arrives with the People module. |
| System | Global Settings (labelled 2FA toggle, timeouts above, other sections per permission), Access Roles (matrix), System Updates (version now; update log in M8). |
| Other menu items | "Coming in <module>" page (D3). |
| Tests | Vitest for the dictionary, menu filtering and Problem Details mapping; Playwright: each demo account signs in, sees exactly its menu, opens each allowed page with no console errors; screenshots of login, a failed attempt and the dashboard. |

**M6b status (2026-11-10):** built. Notes: the sign-in page shows the frontend build version (the API version endpoint needs sign-in); the menu is decided from permissions, including the "My People" label; External Partners appears as a placeholder for exec and HR (D25); My Profile has no Personal information tab until the People module. Dev needs a queue worker (`php artisan queue:work`) running as the same Windows account as Apache, or picture scans can't finish.

The milestones after M6 are unchanged: M7 (OpenAPI, CI; ask before the first push), M8 (installer, deploy, update log).

### 9.5 External partners (2026-11-06, owner approved the mockup 2026-11-06)

Third-party Marketing and Design people take part in tasks and projects without joining the organisation. Mockup only; the backend is built with the Tasks and Projects modules, not in Foundation. Walk-through: `docs/design/partner-journeys.pdf`.

| # | Decision |
|---|---|
| D24 | Partners sign in as **guests**: a fixed, locked **Guest** access role (Dashboard, My Profile, My Tasks, Projects, Meetings only). Guests are not employees: no org chart, reporting lines, payroll, leave or KPI. |
| D25 | A partner is a **vendor company** (name, service, internal owner) with its **people** (name, email, status Invited / Active / Disabled). Managed on an **External Partners** page under Organization (exec and HR). Disabled guests can't sign in. User Accounts listing for guests: not wanted (owner). |
| D26 | Guests are added to individual projects and see only those projects, their own tasks, and meetings they're invited to. Their tasks carry an **EXT** tag and score **0 points**; difficulty levels are hidden when a guest is the assignee. |
| D27 | Partners ask for client data (statistics, briefs) by raising a **request task** (type, details, requester) on a project; staff answer by **sharing files** on the task. |
| D28 | Partners deliver work by **Submit for review** with files; the project manager **approves the deliverable**. A **Request changes** step is not in the mockup yet and is still to be designed. |
