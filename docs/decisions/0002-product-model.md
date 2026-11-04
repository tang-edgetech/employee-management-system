# ADR 0002 — Product model: per-client, single-company installation

| | |
|---|---|
| **Status** | Accepted (2026-11-04) |
| **Date** | 2026-11-04 |
| **Decider** | Owner (Tang Edgetech) |
| **Amends** | [ADR 0001](0001-stack.md) §1 (answer 4) and §5.1, plus every `organization_id` reference in ADR 0001 §5 |
| **Brief sections overridden** | §3.2.1 (multi-tenant from day one), §3.2.3 (`UNIQUE (organization_id, code)`), §6 (every table has `organization_id`, composite FKs) |

---

## 1. Context

The product is no longer an internal tool for one company. It is **Meridian Workforce**, a reusable product sold to many companies (see [product vision](../product/vision.md)).

The owner chose on 2026-11-04:

1. **Per-client installation.** Each client gets their own copy of the application and their own database. No shared multi-tenant server.
2. **One company per installation.** A group with subsidiaries would use separate installations.
3. The mockup's "Hajimi Technologies" data becomes **demo and test data** only.

## 2. Decision

- **No tenancy layer.** Tables don't carry `organization_id`. There is no tenant scope, no tenant-aware composite foreign keys, and no cross-tenant tests.
- The brief's `organizations` + `org_settings` tables collapse into one **`company_settings`** singleton (a single row, enforced by a fixed primary key and a CHECK constraint). It holds the company name, logo, timezone (default `Asia/Kuala_Lumpur`), country, currency, working week, holiday region, and all the settings from brief §6.1.
- Business codes are unique per installation: `UNIQUE (code)` instead of `UNIQUE (organization_id, code)`.
- Unique indexes in ADR 0001 §5.2–5.4 drop their `organization_id` column. The generated-column technique stays.
- Data isolation between clients is **physical**: separate database, separate `.env` and encryption keys, and preferably a separate cPanel account per client.

## 3. Why

| | Per-client, single-company (chosen) | Multi-tenant SaaS |
|---|---|---|
| Data separation | Strongest: a bug can't leak one client's data to another | Depends on application code (MySQL has no RLS) |
| Sales fit | Clients who want their own server or their own hosting | Small clients who want low monthly pricing |
| Schema and code | Simpler: no `organization_id`, no tenant scope | Every query and table is tenant-aware |
| Upgrades | Many copies to upgrade | One upgrade for everyone |
| Infra cost per client | Higher | Lower |

The owner accepted the higher operations effort for stronger separation and a simpler codebase.

## 4. Consequences

**Positive**
- Removes ADR 0001's biggest residual risk (§5.1, no database-enforced tenant isolation). Isolation is now physical.
- Simpler schema, queries, seeds and tests.
- Each client can be on their own server, region and backup schedule, which helps with data-residency requests.

**Negative / accepted**
- Moving to multi-tenant SaaS later means a significant refactor: adding `organization_id` and scoping across the codebase.
- A holding company with subsidiaries needs several installs, with no group-level reporting across them.
- Every release must be deployed to every client, so the release process (§5) must be reliable and repeatable.

## 5. What a per-client product needs (to design during Foundation)

| Concern | Approach |
|---|---|
| Installation | A one-command installer (`php artisan meridian:install`): checks requirements (PHP 8.3+, MySQL 8.4, extensions), runs migrations, creates the first admin, and starts the **onboarding wizard** (company name, logo, working week, holiday region, departments, designations). |
| Releases | Semantic versions (`v1.4.2`) with a changelog. Each client install records its version. Migrations must run cleanly when a client skips versions (e.g. 1.2 → 1.5). |
| Deployment | The same Deployer script, with one host entry per client. Clients that host it themselves get a release archive plus the upgrade command. |
| Configuration | Everything client-specific is in `company_settings` (in the DB) or `.env` (secrets). No client names in code. |
| Branding | Company name, logo and accent colour from settings; the 5 themes stay available. |
| Vendor support access | System Admin is the vendor's role. Accounts on a client install are created only with client consent, can be time-limited, and every action is audited. |
| Monitoring | One Sentry project, with each install tagged by an `INSTALL_ID` so errors are traceable per client. |
| Backups | Per client, as in ADR 0001 (daily backups + binlog PITR), copied off-server. |
| Licensing | Open question (vision V2). |

## 6. Approval

- [x] Per-client, single-company model approved (owner, 2026-11-04)
- [x] Overrides of brief §3.2.1, §3.2.3 and §6 (`organization_id`) accepted (owner, 2026-11-04)
