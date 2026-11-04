# Meridian Workforce — Product Vision

| | |
|---|---|
| **Product name** | Meridian Workforce (working name; trademark and domain checks pending, see §9) |
| **Status** | Approved by owner (2026-11-04). Open items in §9 still to resolve. |
| **Date** | 2026-11-04 |
| **Owner** | Tang Edgetech |
| **Related** | [ADR 0001 — Stack](../decisions/0001-stack.md), [ADR 0002 — Product model](../decisions/0002-product-model.md), original brief `HAJIMI-HR-PROJECT.md` |

---

## 1. Purpose

Meridian Workforce is one system for a company's **people, project delivery and performance**. It replaces the spreadsheets, chat threads and disconnected tools most SMEs use today. Leaders see where every project stands and who to talk to. Employees see what is expected of them. Performance is judged on **recorded facts, not impressions**.

It is a **reusable product** that can be installed for any company. It is not built for one organisation.

## 2. Problems it solves

| Today (Excel / Google Sheets / chat) | With Meridian Workforce |
|---|---|
| Project status lives in someone's head or an outdated sheet | Live phase, progress, blockers and delays per project |
| Managers chase people for updates | Mandatory check-ins at a set interval; a missed check-in is visible |
| "Who's holding this up?" is guesswork or blame | Every delay is traced: who caused it, who was affected, why, and how many days were lost |
| Deadlines slip silently | Extension requests need approval; late completion is recorded automatically |
| Performance reviews depend on memory and favouritism | A KPI score built from delivery, timeliness, quality, check-ins, meetings and ratings |
| Salary and NRIC sit in shared sheets anyone can copy | Encrypted fields, role-based visibility and an audit trail of every access-sensitive change |
| Hiring tracked in email threads | Vacancies, a public apply form, multi-round interviews, then one-click conversion to employee |

## 3. Goals

1. **Replace manual tracking.** A proper system of record instead of Excel and Google Sheets, with validated data that cannot drift into an inconsistent state.
2. **Project visibility for every level.** C-level and managers can see accurate status and go straight to the right person. Employees can see their own tasks, dependencies and who they are waiting on.
3. **Delivery monitoring that helps work finish.** During each task the system records progress, speed (time taken vs planned), accuracy (rework), issues and blockers, extensions and delays. The aim is smoother completion, not surveillance.
4. **Fair individual performance monitoring with "positive whistleblowing".** Good work gets surfaced and credited; problems get raised safely (§5).
5. **Privacy and confidentiality by design.** Sensitive personal and pay data is protected inside the system, not only at the server level (§6).

## 4. Users and roles

| Role | Typical person | Sees |
|---|---|---|
| System Admin | Vendor / implementation team | Everything; every action audited |
| Executive | CEO, CTO, COO | Whole company, including salary |
| HR | HR manager / executive | All people and recruitment data, including salary and NRIC |
| Team Lead / Manager | Department or team head | Their teams and everyone under them in the reporting chain |
| Employee | Everyone else | Themselves, plus juniors they supervise |

Access role (what you can do) is separate from designation (career level). A senior who supervises juniors can approve their extensions without being a team lead.

## 5. Positive whistleblowing

Four parts, so that recognition and accountability are both **fair and fact-based**:

| Part | What it does | Release |
|---|---|---|
| **Fact-based visibility** | Delivery, on-time rate, check-in compliance, delays caused, rework and meeting attendance are recorded automatically and shown to the right people. Credit and accountability come from data, not gossip. Delays can be disputed before they count. | **MVP** (comes from the Work and KPI modules) |
| **Peer recognition (kudos)** | Anyone can publicly thank a colleague for specific help, e.g. "unblocked my API in an hour". Shown on a recognition wall and profile; optionally a small, capped KPI input. | Phase 2: "Recognition & Voice" |
| **Upward feedback** | Employees give structured feedback on superiors and PMs (clarity, support, fairness). Aggregated and anonymised below a minimum number of responses. | Phase 2 |
| **Confidential concern reporting** | Staff raise concerns (misconduct, workload, safety, conflict of interest) confidentially or anonymously. Each report becomes a case with restricted access, a status, and protection against retaliation. | Phase 2 |

## 6. Privacy and confidentiality

Server security (firewall, TLS, backups, 2FA) is the baseline. On top of it, **inside** the system:

| Data | Protection |
|---|---|
| NRIC / passport numbers | Encrypted at rest. Searchable only through a one-way blind index. Masked (`••••••••5524`) for anyone without permission. |
| Basic salary | Encrypted. Visible only to Executive and HR (and the employee, for their own). |
| **Increment and promotion amounts** | Encrypted inside the employment history, not only on the employee record. Old and new salary and percentage are hidden from anyone who can't see salary. |
| Bank details, address, emergency contacts | Encrypted; visible to HR and the person themselves. |
| Candidate data | PDPA consent recorded on every application; auto-deleted after the retention period. |
| Confidential reports (Phase 2) | Restricted to a named case-handler group; reporter identity hidden or never collected. |
| Every sensitive change | Written to an append-only audit log (who, what, when, from where), with sensitive values redacted in the log itself. |
| Exports | Only available to permitted roles, logged, and protected against spreadsheet formula injection. |

Visibility is enforced by the server for every request. It is never just hidden in the UI.

## 7. Modules

### 7.1 MVP

| Module | Highlights | Goals served |
|---|---|---|
| Organization | Departments, designations (career ladder with salary bands and KPI targets), employment types, teams and leads, task tags and points | 1 |
| People | Employee records, reporting chains, org chart, lifecycle (probation, confirmation, promotion, increment, contract/intern conversion, PIP), annual data review, CSV import | 1, 4, 5 |
| Recruitment | Vacancies, public/embeddable apply form, talent pool, multi-round interviews, former-employee detection, convert to employee | 1, 5 |
| Work | Projects with 5 phases, prerequisites, tasks (tag + level = points), dependencies, mandatory check-ins, extension approvals, delay and blocker trace, rework, meetings with minutes and action items | 2, 3 |
| KPI | 7-part weighted score, grades A–D, quarterly periods with locking, imports from ClickUp/Asana/monday/sheets, export to Excel/Sheets | 3, 4 |
| Self-service | Dashboard, My Profile, My Tasks, approvals inbox, notifications | 2 |
| System | Roles and permissions, global settings, integrations, company branding, themes | 1, 5 |

### 7.2 Future modules (planning only)

| Module | Scope notes |
|---|---|
| **Attendance (hybrid-ready)** | Clock in/out from web or mobile; work location per day (office / home / client site); optional geofence or IP rules for office days; schedules and shifts; **amendment requests** (forgot to clock, wrong location) with approval and audit. |
| **Leave** | Leave types (annual, sick, hospitalisation, maternity, paternity, unpaid, replacement…). **Entitlement policies by designation**, plus employment type and years of service, with a **statutory floor** so no policy can grant less than the Employment Act minimum (figures to be confirmed with HR/legal before building). Carry-forward, pro-rating for new joiners, approval route, and a team leave calendar. |
| **Calendar** | Public holidays (federal + state), company holidays, replacement holidays, special working days and other adjustments. Feeds the single working-days function used by tasks, KPI, leave and attendance. |
| **Mobile apps (Android + iOS)** | Built on the same API: check-ins, task updates, approvals, attendance clock-in, leave requests, notifications (push). Token-based login with device management. |
| **Recognition & Voice** | Kudos, upward feedback, confidential reporting (§5). |
| **Extensibility** | Custom fields per entity, configurable approval workflows, webhooks, outgoing integrations (payroll, accounting), report builder, multi-language (English / Bahasa Malaysia). |

Design rules that keep these possible without rework:
- All business logic lives behind the versioned API (`/api/v1`). The web app and future mobile apps are both just clients.
- One shared working-days function, so attendance, leave and tasks agree on what counts as a working day.
- Approval routing (superior / PM / HR) built as a reusable component, not copied per feature.
- Every feature reads its rules from settings, never hard-coded to one company.

## 8. Product model

- **Per-client installation, one company per installation** (see ADR 0002). Each client gets their own copy of the system and their own database, on their server or one we host.
- Company name, logo, colours, working week, holiday region, probation and KPI rules are **settings**, configured in an onboarding wizard on first install.
- The mockup's company ("Hajimi Technologies", 20 employees, 3 projects) becomes the **demo company**. It's used for sales demos and automated tests, never shipped as a client's data.

## 9. Open items

| # | Item |
|---|---|
| V1 | Confirm the name: trademark search (MyIPO for Malaysia, plus target markets) and domain availability. "Meridian" is a common word used by many companies, so the full mark "Meridian Workforce" must be checked. |
| V2 | Licensing and upgrades for per-client installs: licence keys? How clients receive updates? Support access policy (vendor System Admin accounts on client installs, always audited). See ADR 0002 §5. |
| V3 | Statutory leave minimums to encode as the floor for the Leave module, to be confirmed with an HR/legal source. |
| V4 | Kudos: should it affect KPI at all, and if so, by how much (capped)? |
| V5 | Confidential reporting: who handles cases at a small company where HR may be involved in the issue? An external handler? |
| V6 | Languages for launch: English only, or English + Bahasa Malaysia? |
