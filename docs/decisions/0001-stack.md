# ADR 0001 — Technology stack and hosting

| | |
|---|---|
| **Status** | Accepted (2026-11-04). Open questions in §8 still to be answered. **Amended by [ADR 0002](0002-product-model.md):** per-client, single-company installs. Tenancy (§1 answer 4, §5.1) and every `organization_id` reference below no longer apply. **Amended 2026-11-05 (owner):** the database is **MariaDB 10.11 LTS** in dev and production, replacing MySQL 8.4 (§4.1). |
| **Product** | Meridian Workforce (formerly "Hajimi HR"); see [product vision](../product/vision.md) |
| **Date** | 2026-11-04 |
| **Decider** | Owner (Tang) |
| **Brief sections** | §2 Platform decision gate, §3 Architecture, §4 Security, §14 Open decisions 1 and 2 |

---

## 1. Context

The brief (`HAJIMI-HR-PROJECT.md` §2) asks for a decision record that compares stacks before any code is written. It assumes PostgreSQL 16, Redis, S3 storage and Docker as constants.

The owner has already chosen a stack that fits their skills and existing hosting:

| Layer | Choice |
|---|---|
| Frontend | Next.js + Tailwind CSS + Ant Design |
| Backend | Laravel (PHP 8.3) |
| Database | **MariaDB 10.11 LTS** (not PostgreSQL; MySQL 8 until the 2026-11-05 amendment) |
| Development | XAMPP on Windows, localhost |
| Production | WHM/cPanel on a **VPS or dedicated server with root access** |

Answers confirmed with the owner on 2026-11-04:

1. **Production server:** VPS/dedicated with WHM root. We can install Redis, Supervisor and ClamAV there, and pick MariaDB 10.11 in WHM.
2. **Database:** ~~MySQL 8.0+ in production. Development also moves to MySQL 8.~~ Amended 2026-11-05: **MariaDB 10.11 LTS minimum, in both dev (XAMPP) and production (WHM).** See §4.1.
3. **Next.js runtime:** static export served by Apache. Laravel serves the API. There is no Node process in production.
4. **Tenancy:** ~~launch for a single company, with a multi-tenant schema (`organization_id` everywhere) from day one.~~ Superseded by ADR 0002: one company per installation, no `organization_id`.
5. **Redis:** used in production. Development uses Laravel's `database` cache and queue drivers.

Because this departs from the brief's constants, §5 lists every brief requirement the stack affects and how we meet it. **The owner is approving those deviations together with the stack.**

---

## 2. Options considered

The brief's evaluation criteria applied to the three options it names, plus the owner's choice. Every option is shown with the owner's hosting (cPanel), because that is fixed.

| Criterion | **A′ Laravel + MySQL + Next.js (static)** ✅ | A Laravel + PostgreSQL | B NestJS + Prisma | C Go + sqlc |
|---|---|---|---|---|
| Owner familiarity | **High** (PHP/Laravel, WordPress) | High (PHP), low (Postgres ops on cPanel) | Medium (vanilla JS → TS) | Medium (Go) |
| Time to MVP | **Fastest**: Fortify, Sanctum, Horizon, queues, policies, validation built in | Fast | Medium: assemble auth, RBAC, jobs yourself | Slowest: most hand-written |
| Type safety end to end | Medium: PHP types + OpenAPI-generated TS client | Same | High: TS throughout | High: Go + generated TS |
| Background jobs | **Mature**: Horizon + Redis, retries, batches, dashboards | Same | Good (BullMQ) | Good (River/Asynq) |
| Multi-tenancy | App-layer scopes + composite FKs (no RLS) | Adds Postgres RLS | App layer + Postgres RLS | App layer + Postgres RLS |
| Hiring pool (Malaysia) | **Largest**: PHP/Laravel is common in MY SMEs and agencies | Same | Good | Small |
| Fit with WHM/cPanel | **Native**: Apache + PHP-FPM + MySQL are first-class in cPanel | Postgres is supported but second-class in WHM | Needs a long-running Node app | Needs a custom service |
| Observability | Sentry, JSON logs, Laravel Pulse | Same | Sentry, OTel | Sentry, OTel |
| Cost at 1k users | One VPS | One VPS | One VPS | One VPS |
| Cost at 100k users | Bigger VPS + separate DB server | Same | Same | Same |
| Cost at 1M users | Needs a move off a single cPanel box (see §7) | Same | Same | Same |

**Why A′:** fastest MVP, using skills the owner already has, on hosting the owner already runs. The main cost is losing PostgreSQL-only integrity features (RLS, exclusion constraints, partial unique indexes, deferrable constraints, arrays). §5 shows each has a workable MySQL 8 replacement. Losing Postgres RLS is the biggest trade-off; §5.1 covers how we compensate.

**Rejected:**
- **A (Postgres):** better integrity, but WHM/cPanel tooling, backups and phpMyAdmin are MySQL-first. The owner chose MySQL.
- **B and C:** slower to MVP for this owner, and both need a long-running process that cPanel handles poorly.

---

## 3. Decision

### 3.1 Components

| Concern | Choice | Notes |
|---|---|---|
| Backend framework | **Laravel 13**, PHP 8.3+ | The brief says Laravel 11, which is past its security-support window. Laravel 13 needs PHP ^8.3, so production PHP must be 8.3 or newer (Q1). |
| Auth | Laravel **Fortify** (login, password reset, TOTP 2FA, recovery codes) + **Sanctum** SPA cookie auth | Session cookies are HttpOnly, Secure and SameSite=Lax, with CSRF via `/sanctum/csrf-cookie`. Bearer tokens only for future API clients. |
| Password hashing | `argon2id` hashing driver | The production PHP build must include Argon2. Check during server setup. |
| Database | **MariaDB 10.11 LTS** (prod and dev), InnoDB, `utf8mb4_uca1400_as_ci` (case-insensitive, accent-sensitive; Q7) | See §4.1 for dev and §5 for feature mapping. |
| Cache, locks, rate limits | **Redis** in prod (installed via WHM/root); `database` driver in dev | Code uses `Cache::lock`, `RateLimiter` and `Cache::tags` only where the driver supports them. Tags are not supported by the DB driver, so we use versioned keys instead (brief §3.2.8). |
| Queue | **Redis + Horizon** under **Supervisor** in prod; `database` queue in dev | Horizon needs `pcntl`, which doesn't exist on Windows, so dev runs `php artisan queue:work`. |
| Scheduler | One cPanel cron: `* * * * * php artisan schedule:run` | Covers reminders, retention purge, annual review launch and KPI recalculation sweeps. |
| Frontend | **Next.js 16 (App Router) with `output: 'export'`**, **React 19**, **Ant Design 6** components, **Tailwind CSS 4** for layout and utilities | Static HTML/JS served by Apache. All data is fetched client-side with TanStack Query. |
| API contract | **OpenAPI 3.1, generated from Laravel code with Scramble** (`dedoc/scramble`) → committed `openapi/openapi.yaml` → TypeScript client generated with `openapi-typescript` + `openapi-fetch` | Code-first (Q2): the spec is produced from routes, FormRequests and API Resources, so it can't drift from the code. CI regenerates it and fails if the committed file differs. Breaking changes to the spec then break the frontend type-check. |
| Validation | Laravel FormRequests on the server; matching schemas on the client, generated from OpenAPI | The server is always authoritative. |
| Files | Flysystem: **S3-compatible** (Cloudflare R2 or Wasabi) in prod, `local` disk in dev | Presigned uploads, signed URLs, ClamAV scan (installed on the VPS), re-encoding with Intervention Image. |
| Encryption of PII | Laravel encrypter set to **`aes-256-gcm`**, key rotation via `APP_PREVIOUS_KEYS`; separate HMAC key for blind indexes | KMS/Vault isn't available on cPanel. Keys live in `.env` outside the web root, with permissions 0600. See §6. |
| Edge | **Cloudflare** in front of the server (DNS, WAF, CDN, rate limiting, Turnstile for the public apply form) | |
| Errors and logs | Sentry (Laravel + browser SDK), Monolog JSON logs with `request_id`, `org_id`, `user_id` | OpenTelemetry deferred until there is a collector to send to. |
| Health checks | `/healthz` (liveness) and `/readyz` (DB, Redis and queue reachable) | |
| CI | GitHub Actions: Pint + PHPStan (Larastan) + Pest on a **MariaDB 10.11 service container**; ESLint + `tsc` + Vitest for the frontend; migration dry-run | |
| Deploy | **Deployer (PHP)** over SSH: release folders + atomic `current` symlink, `migrate --force`, `horizon:terminate`, frontend `out/` uploaded | Gives zero-downtime deploys on cPanel. Migrations follow expand → migrate → contract. |
| Backups | WHM daily backups + **MariaDB binary logs kept 30 days** for point-in-time recovery, copied off-server to S3 | Quarterly restore drill (brief §3.2.13). |

### 3.2 Production request flow

```
Browser / embed iframe
      │ HTTPS
Cloudflare (WAF, CDN, rate limit, Turnstile)
      │
Apache on cPanel  ── hr.<domain>
   ├── /            → Next.js static export (out/)   [.htaccess fallback for client-side routes]
   ├── /api/*       → Laravel public/index.php (PHP-FPM)
   ├── /sanctum/*   → Laravel
   └── /apply/*     → Laravel-rendered shell (sets per-org CSP frame-ancestors), loads the static apply bundle
                       │
          ┌────────────┼──────────────┬──────────────┐
       MariaDB 10.11 Redis        Horizon workers   S3 (R2/Wasabi)
     (+ binlog)   (cache/queue)   (Supervisor)      signed URLs
                                      │
                       Email provider · ClickUp/Asana/monday/Google APIs
```

Serving frontend and API from **one origin** means Sanctum cookies work without CORS, and there's a single CSP to maintain.

### 3.3 Development setup (Windows + XAMPP)

| Piece | Setup |
|---|---|
| Laravel | Apache vhost `meridian.localhost` → `backend/public`, PHP 8.3 from XAMPP |
| Database | **XAMPP's MariaDB 10.11 on port 3306**, bound to 127.0.0.1, configured like production (§4.1). (Was MySQL 8.4 on 3307 before the 2026-11-05 amendment.) |
| Next.js | `npm run dev` on `:3000`, reverse-proxied by the `meridian.localhost` vhost (`mod_proxy`), so the browser sees one origin, as in production |
| Queue and cache | `database` drivers; run `php artisan queue:work` in a terminal |
| Files | `local` disk; ClamAV scan replaced by a stub that always returns "clean" in dev |
| Mail | Log driver, or Mailpit if installed |

### 3.4 Repository layout

```
/backend      Laravel app
/frontend     Next.js app (static export)
/openapi      openapi.yaml (+ generated TS client output target)
/docs         decisions/ (ADRs), runbooks/ (deploy, backup restore, breach response)
/deploy       deploy.php (Deployer), Supervisor + Apache vhost templates
```

---

## 4. Environment notes

### 4.1 Dev/prod database parity

XAMPP on this machine ships **MariaDB 10.11.16**. Running different engines in dev and prod would let migrations pass locally and fail on deploy.

~~Decision: install MySQL 8.4 LTS for development (port 3307).~~ **Amended decision (owner, 2026-11-05): MariaDB 10.11 LTS everywhere.**

- **Dev:** XAMPP's MariaDB 10.11 on port 3306, bound to `127.0.0.1`, with the same charset, collation, timezone and SQL mode as production.
- **Production:** MariaDB 10.11 selected in WHM, so dev and production run the same major version. Moving both to 11.4 LTS later is a planned upgrade, not a drift.
- **CI:** runs on a MariaDB 10.11 service container.
- **Why 10.11 and not "any 10.x":**
  - 10.6 and older are past end of support.
  - Only 10.10+ has the accent-sensitive Unicode collation `utf8mb4_uca1400_as_ci` (Q7).
  - 10.11 is supported until February 2028.
- **What MariaDB changes in §5:**
  - `JSON` is an alias for `LONGTEXT` with a `JSON_VALID` check.
  - `JSON_SCHEMA_VALID` only exists from 11.1, so JSON shape rules (5.7, 5.8) are enforced in the application layer, with simple `JSON_LENGTH`/`JSON_EXTRACT` CHECKs where they fit.
  - Everything else in §5 works the same on MariaDB 10.11:
    - generated columns with unique indexes;
    - CHECK constraints;
    - `SIGNAL` triggers;
    - `WITH RECURSIVE`;
    - `DATETIME(6)`;
    - `SELECT … FOR UPDATE`;
    - binary logs.

### 4.2 Versions found on this machine (2026-11-04)

PHP 8.3.36 · Apache 2.4.65 · MariaDB 10.11.16 (XAMPP) · Node 24.22.1 · npm 11.19.1 · Composer 2.10.3 · Redis: not installed · Docker: not installed.

---

## 5. Deviations from the brief and how we meet each requirement

The brief (§2, §3, §6) assumes PostgreSQL. Each Postgres-only feature, with its MySQL 8 replacement. **Since the 2026-11-05 amendment the engine is MariaDB 10.11:** every row below applies to MariaDB as written, except 5.7, 5.8 and 5.9, which carry a MariaDB note.

| # | Brief requirement | PostgreSQL feature | MySQL 8 replacement | Residual risk |
|---|---|---|---|---|
| 5.1 | Tenant isolation enforced in 3 places (§3.2.1) | **Row-Level Security** with `app.org_id` | (a) A global `TenantScope` on every tenant model via a `BelongsToOrganization` trait, which also fills in `organization_id` on create. (b) **Composite foreign keys** `(organization_id, x_id) → parent(organization_id, id)`, with `UNIQUE (organization_id, id)` on every parent, so the DB can't link rows across tenants. (c) A required cross-tenant test (must return 404) for every endpoint. (d) A lint/arch test that fails if a tenant model lacks the trait, or if raw `DB::table()` is used on tenant tables outside approved repositories. | Medium: a raw query that skips the scope is caught only by tests and review, not the DB. That's acceptable while there's one tenant; review again before onboarding a second org. |
| 5.2 | One active application per person per vacancy (§6.1 candidates) | Partial unique index `WHERE stage NOT IN (...)` | A **generated column** `active_key = IF(stage IN ('Hired','Disqualified'), NULL, 1)` plus `UNIQUE (organization_id, id_no_bidx, vacancy_key, active_key)`. MySQL allows several NULLs in a unique index, so closed applications don't collide. `vacancy_key` is a generated column that turns a NULL vacancy (talent pool) into a fixed value. | Low |
| 5.3 | Interview round unique unless No show | Partial unique | Same pattern: `round_key = IF(result = 'No show', NULL, round)` and `UNIQUE (candidate_id, round_key)` | Low |
| 5.4 | One Pending extension per task | Partial unique | `pending_key = IF(status = 'Pending', 1, NULL)` and `UNIQUE (task_id, pending_key)` | Low |
| 5.5 | KPI periods must not overlap per org | `EXCLUDE` with a date range | `BEFORE INSERT/UPDATE` trigger that `SIGNAL`s if an overlapping period exists. The service layer also takes a `SELECT … FOR UPDATE` lock on the org's periods before writing. | Low (periods are written rarely, by HR only) |
| 5.6 | Designation reorder swaps levels in one transaction | Deferrable unique constraint | Inside one transaction, move the affected rows to a temporary offset (`level + 1000`), then write the final levels. `UNIQUE (organization_id, level)` stays enforced the whole time. | None |
| 5.7 | `points int[5]`, `working_days smallint[]`, `two_fa_roles text[]`, `embed_allowed_domains text[]`, `recovery_codes_hash text[]` | Arrays | `JSON` columns with `CHECK (JSON_SCHEMA_VALID(...))` (MySQL 8.0.17+), e.g. exactly 5 non-negative integers for points. **MariaDB 10.11:** `JSON_VALID` + `JSON_LENGTH` CHECKs in the DB; element types and ranges validated in the FormRequest/domain layer. | Low |
| 5.8 | `kpi_weights` keys, 0..100 and sum = 100 | CHECK on JSONB | `JSON_SCHEMA_VALID` for keys and ranges, plus a CHECK on the sum using `JSON_EXTRACT`. Also validated in the FormRequest. **MariaDB 10.11:** keys and ranges checked in the FormRequest; the DB keeps the sum CHECK via `JSON_EXTRACT`. | Low |
| 5.9 | `citext` emails, `UNIQUE (org, lower(name))` | citext, functional index | Case-insensitive, accent-sensitive collation (`utf8mb4_0900_as_ci`) gives case-insensitive uniqueness directly, while "José" and "Jose" stay distinct (Q7). Emails are lower-cased on write as well. **MariaDB 10.11:** the equivalent collation is `utf8mb4_uca1400_as_ci`. | Low |
| 5.10 | `timestamptz` stored in UTC | timestamptz | `DATETIME(6)` with the connection timezone forced to `+00:00`. Laravel casts to UTC; org timezone conversion is done in the app (brief §3.2.4). We avoid `TIMESTAMP` because it overflows in 2038. | Low |
| 5.11 | UUIDv7 primary keys (§3.2.3) | `uuid` type | **Owner decision (Q3): integer keys inside, prefixed public IDs outside.** Every primary and foreign key is `BIGINT UNSIGNED AUTO_INCREMENT`, starting at 1, and never appears in URLs or API responses. Each exposed table also has a `public_id` column: a type prefix plus 8 random characters (e.g. `emp_7Kq2xP9a`, `cand_…`, `task_…`), `CHAR(16) CHARACTER SET ascii`, `UNIQUE`, generated on create with a retry on collision. The API and URLs use `public_id` only; Laravel route model binding resolves by it. Business codes from the brief (`HJ-0001`, `APP-2026-0031`, `P001`, `K01`) stay as they are, `UNIQUE (organization_id, code)`. Cursor pagination uses `(created_at, id)` internally and encodes the cursor so raw IDs stay hidden. | Low: integer IDs never leave the server, so they can't be enumerated. Prefixes and lengths are fixed in the Foundation step. |
| 5.12 | Superior chain acyclic; dependency graph acyclic | Trigger with recursive CTE | Checked in the domain service with a recursive CTE under row locks (MySQL 8 supports `WITH RECURSIVE`). A DB trigger backstop will be added **if a spike confirms** MySQL lets a trigger read its own table in this case. | Medium until the spike is done |
| 5.13 | Partition `activity_log`, `audit_log`, `task_checkins`, `notifications` by month | Declarative partitioning | MySQL **does not allow foreign keys on partitioned tables**, and the partition key must be part of every unique key. **MVP: no partitioning.** Add time-based indexes and a retention/archive job. Partition the FK-free log tables (`audit_log`, `activity_log`, `notifications`) only when row counts call for it. `task_checkins` keeps its FKs. | Low at MVP scale |
| 5.14 | Append-only `audit_log` (DB role INSERT only) | Role grants | ~~A separate DB user and Laravel connection `audit` with `INSERT, SELECT` only~~ **Amended 2026-11-05 (owner):** audit rows are written on the app connection **in the same transaction as the change**, so a change can never be saved without its audit row and a rolled-back change leaves none. A separate DB user would mean a separate connection, which breaks that guarantee. `BEFORE UPDATE/DELETE` triggers that `SIGNAL` stop every DB user, the app included, from editing or deleting rows. A read-only DB user may still be added later for an audit viewer. | Low: an account with DDL rights could drop the triggers, so DDL stays limited to deploys. |
| 5.15 | Read replicas for dashboards | Streaming replicas | **Not at MVP** (single server). A `read` connection is configured but points at the primary, so adding a replica later is a config change. | None now |
| 5.16 | Docker for dev and prod | Docker | **Not used.** Dev runs on XAMPP + MariaDB 10.11; prod on cPanel with MariaDB 10.11. Reproducibility comes from pinned versions, lockfiles, and CI on MariaDB 10.11. | Medium: environment drift. Mitigated by §4.1 and CI. |
| 5.17 | KMS/Vault for encryption keys | — | Keys in `.env` outside the web root (0600), backed up encrypted in the owner's password manager. Key version prefix stored with each ciphertext so keys can rotate. | Medium: a server compromise exposes the keys. Revisit if hosting moves. |
| 5.18 | Server-side PNG org chart export with headless Chromium | — | **MVP: client-side export** (html-to-image, as in the mockup). Server-side Browsershot/Chromium later, only if large orgs need it. | Low |
| 5.19 | Next.js SSR/SPA (brief §3.1) | — | **Static export.** No SSR, middleware, API routes or `next/image` optimisation. Dynamic pages (`/employees/[id]`) use client-side routing with an Apache rewrite fallback to the app shell. Auth is enforced by the API, never by the frontend. | Low: this is an internal console, so SEO doesn't matter. The public apply page is served via Laravel (§3.2). |

---

## 6. Consequences

**Positive**
- The owner can build, debug and run every layer with skills they already have.
- cPanel/WHM handles TLS, backups, mail, PHP-FPM and MariaDB administration out of the box.
- A static frontend means no Node runtime in production, so there are fewer processes to keep alive.
- Laravel's first-party packages cover auth, 2FA, queues, rate limiting, encryption with rotation, and policies.

**Negative / accepted risks**
- No database-enforced tenant isolation (no RLS); isolation relies on app scopes, composite FKs and tests (§5.1).
- Some invariants move from the database into triggers or the domain layer (§5.5, §5.12).
- A single server is a single point of failure until the DB and app are split (§7).
- No Docker means some dev/prod drift risk.

---

## 7. Scaling path (when a single cPanel server isn't enough)

The app servers are stateless (sessions, cache and queues in Redis; files in S3), so scaling is mostly about moving infrastructure, not rewriting code:

1. **About 1k users:** one VPS (app + MariaDB + Redis + workers).
2. **About 100k users:** move MariaDB (and Redis) to their own server or a managed service, add a read replica for dashboards, and run workers on a separate box.
3. **About 1M users:** move off cPanel to a load balancer with N app nodes, managed MariaDB (or MySQL-compatible) with replicas, managed Redis, and a CDN for the static frontend. Consider PostgreSQL again at this point if RLS is needed for many tenants.

Actual costs depend on provider quotes. Get quotes before step 2.

---

## 8. Open questions (need owner answers before the affected work)

| # | Question | Blocks |
|---|---|---|
| Q1 | ✅ **Answered 2026-11-04:** latest majors: Laravel 13, Next.js 16, React 19, Ant Design 6, Tailwind 4. Exact versions are pinned by the lockfiles at scaffold time. | Scaffold |
| Q2 | ✅ **Answered 2026-11-04:** code-first. The spec is generated from Laravel with Scramble, with a CI staleness check, and the TS client is generated from it. | Foundation |
| Q3 | ✅ **Answered 2026-11-04:** `BIGINT` auto-increment keys internally, plus a prefixed 8-character random `public_id` exposed in the API and URLs. Replaces UUIDv7 (see §5.11). | First migration |
| Q4 | Production domain(s): e.g. `hr.<client-domain>` per client install and the public apply form? | Deploy, CSP, Sanctum config |
| Q5 | Object storage provider: Cloudflare R2, Wasabi, or AWS S3 (Malaysia region `ap-southeast-5`) for data residency? | File service |
| Q6 | Email provider (e.g. Amazon SES, Postmark, Mailgun, or cPanel SMTP)? | Notifications |
| Q7 | ✅ **Answered 2026-11-04:** accent-sensitive, case-insensitive: `utf8mb4_0900_as_ci` as the database default. On MariaDB 10.11 (2026-11-05): `utf8mb4_uca1400_as_ci`. | First migration |
| Q8 | Remaining brief §14 items: working vs calendar days (3), delay confirmation (4), mid-period promotion KPI target (5), payroll (7), data residency (8) | Work and KPI modules |

---

## 9. Approval

- [x] Stack (§3) approved (owner, 2026-11-04)
- [x] Deviations from the brief (§5) accepted (owner, 2026-11-04)
- [ ] Open questions Q1–Q8 answered or scheduled

Once approved, the next step is **Roadmap step 1 — Foundation** (brief §13): repo skeleton, CI, auth + 2FA, tenancy scope + composite FKs, RBAC/scope engine, audit log, file service, and seed data with the parity test.
