# Plan 01 — Foundation

| | |
|---|---|
| **Status** | Approved by owner (2026-11-05), including recommendations D1–D6 in §8 |
| **Date** | 2026-11-04 |
| **Roadmap step** | Brief §13 step 1, adjusted by [ADR 0001](../decisions/0001-stack.md) and [ADR 0002](../decisions/0002-product-model.md) |
| **Owner decisions used** | XAMPP config may be edited with backups · dev host `meridian.localhost` · MySQL 8.4 from the ZIP archive on port 3307, no Windows service · demo data grows per module |

Foundation builds the platform every later module stands on. It ships **no HR features**. When it's done, you can sign in as any of the 6 demo accounts (with 2FA), see a themed app shell whose menu is filtered by your permissions, edit global settings and the permission matrix, and upload a file safely. Everything is covered by tests and CI, and generated API types connect the frontend to the backend.

---

## 0. Scope

**In scope**

| # | Area | Brief ref |
|---|---|---|
| F0 | Local environment (XAMPP edits, MySQL 8.4, vhost) | ADR 0001 §3.3 |
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

Laravel ──► MySQL 8.4 :3307 (C:\mysql84, data in C:\mysql84\data)
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
/tools/mysql/start.ps1 stop.ps1 my.ini   dev MySQL 8.4 helpers (data dir lives outside the repo)
/tools/xampp/meridian-vhost.conf         the vhost we add to XAMPP
/.github/workflows/ci.yml  /.github/dependabot.yml
```

---

## 3. Database schema (Foundation tables)

Conventions for every table: `id BIGINT UNSIGNED AUTO_INCREMENT` primary key (never exposed). `public_id CHAR(16) ascii UNIQUE` on exposed tables. `created_at`/`updated_at` stored as `DATETIME(6)` UTC. `created_by`/`updated_by` reference `users.id` (NULL = system). `version INT UNSIGNED` where optimistic concurrency applies. Charset `utf8mb4`, collation `utf8mb4_0900_as_ci`. Strict SQL mode.

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
| `users` | `public_id`, `email VARCHAR(190) UNIQUE` (lower-cased on write), `password` (argon2id), `role_id FK`, `employee_id UNIQUE NULL FK`, `two_factor_secret TEXT` (encrypted), `two_factor_recovery_codes TEXT` (encrypted, hashed codes), `two_factor_confirmed_at`, `status ENUM('invited','active','disabled')`, `failed_login_count SMALLINT`, `locked_until DATETIME(6)`, `last_login_at`, `ui_layout ENUM(5 themes) DEFAULT 'indigo'`, `remember_token` | Fortify column names; theme preference stored server-side (brief §9) |
| `sessions` | Laravel default (`user_id` indexed) | Dev DB sessions; lets us revoke a user's sessions |
| `password_reset_tokens` | Laravel default (token hashed) | |
| `personal_access_tokens` | Sanctum default | Ready for future mobile apps |

### 3.4 People core (extended by the People module later)

| Table | Columns and constraints | Why |
|---|---|---|
| `employees` | `public_id`, `employee_no VARCHAR(20) UNIQUE`, `full_name VARCHAR(150)`, `work_email VARCHAR(190) UNIQUE`, `superior_id FK employees NULL ON DELETE RESTRICT`, `CHECK (superior_id <> id)`, `status ENUM(8 values from brief)`, `join_date DATE`, `left_at DATE NULL`, `version`, `deleted_at` | Enough for scope rules and demo logins. Cycle prevention by the domain service (ADR 0001 §5.12). Index on `superior_id` for the recursive CTE. |
| `teams` | `public_id`, `name VARCHAR(100) UNIQUE`, `lead_employee_id FK`, `version` | Lead scope |
| `team_members` | PK `(team_id, employee_id)`, index `(employee_id)` | Rule "lead must be a member" enforced in the domain service + test |

### 3.5 Platform

| Table | Columns and constraints | Why |
|---|---|---|
| `files` | `public_id`, `disk`, `storage_key UNIQUE`, `original_name`, `mime`, `size_bytes`, `sha256 CHAR(64)`, `scan_status ENUM('pending','clean','infected')`, `purpose ENUM('photo','resume','evidence','logo','import')`, `uploaded_by`, `confirmed_at` | Brief §6.1 `files`; `purpose` sets size and MIME limits |
| `audit_log` | `id`, `occurred_at DATETIME(6)`, `actor_user_id NULL` (no FK, so rows survive user deletion), `ip VARBINARY(16)`, `user_agent VARCHAR(255)`, `request_id CHAR(26)`, `action VARCHAR(64)`, `subject_type VARCHAR(64)`, `subject_id BIGINT`, `changes JSON` (before/after with sensitive values redacted). Indexes `(subject_type, subject_id, occurred_at)` and `(actor_user_id, occurred_at)`. Triggers `BEFORE UPDATE` / `BEFORE DELETE` → `SIGNAL`. Written through a separate `audit` DB user with `INSERT, SELECT` only. | Append-only (ADR 0001 §5.14). Not partitioned at MVP (§5.13). |
| `jobs`, `job_batches`, `failed_jobs`, `cache`, `cache_locks` | Laravel defaults | Dev drivers; harmless in prod |

**Migration order:** settings → access → files → users → employees → teams → audit triggers + DB users (via a privileged migration step run only by the installer).

---

## 4. API endpoints (Foundation)

All under `/api/v1`, JSON, `snake_case`. Errors are RFC 7807 Problem Details with a stable `code`. Public IDs only. Auth is the Sanctum session cookie + CSRF.

| Method & path | Permission / rule | Notes |
|---|---|---|
| `GET /sanctum/csrf-cookie` | public | Sets XSRF cookie |
| `POST /auth/login` | public, 5/min/IP + per-email lockout | Returns `{two_factor: true}` when a code is needed; rotates the session |
| `POST /auth/two-factor-challenge` | pending-login session | TOTP code or recovery code (each recovery code works once) |
| `POST /auth/logout` | authenticated | |
| `POST /auth/forgot-password` · `POST /auth/reset-password` | public, rate-limited | Always 202, so the response doesn't reveal whether an email exists |
| `POST /auth/confirm-password` | authenticated | Required before 2FA changes |
| `POST /auth/2fa/setup` · `POST /auth/2fa/confirm` · `DELETE /auth/2fa` | self, recently confirmed password | Setup returns QR SVG + secret; disabling is blocked if the role is enforced |
| `GET /auth/2fa/recovery-codes` · `POST /auth/2fa/recovery-codes` | self, recently confirmed password | Regenerate invalidates the old codes |
| `GET /me` | authenticated | User, role, linked employee, `ui_layout`, `two_factor_required` |
| `GET /me/permissions` | authenticated | Permission keys + nav scope flags (e.g. `has_reports`) |
| `PATCH /me/preferences` | self | `ui_layout` |
| `GET /settings` | authenticated (sections filtered by permission) | Returns `ETag` |
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
| M0 | Back up and edit XAMPP config; add `meridian.localhost` vhost; MySQL 8.4 ZIP to `C:\mysql84` on port 3307 with start/stop scripts; `.gitattributes`, `.editorconfig` | ✅ XAMPP edits approved. **Ask before the ~250 MB MySQL download.** You restart Apache from the XAMPP panel. |
| M1 | Scaffold Laravel 13 + Next.js 16 and install packages (list in §6.1) | **Ask:** confirm the package list |
| M2 | Platform services (F2) + company settings (F5) + tests | — |
| M3 | Auth + 2FA (F3) + tests | — |
| M4 | Access roles, permissions, scope engine, people core, demo seed + parity test (F4, F10) | — |
| M5 | File service (F6) + tests | — |
| M6 | Frontend shell, auth screens, Settings, Access Roles, Security page (F7) + Vitest + Playwright smoke | — |
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
