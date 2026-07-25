# Dev Sprint Plan — Makam.co.id

**Version:** v0.1 — **PROPOSED**, awaiting product/engineering sign-off
**Date:** 25 Juli 2026
**Baseline analysed:** commit `05f6f4d` (documentation baseline v0.6) + live `makam-nonprod` stack at `/opt/makam/compose/`
**Inputs:** repository deep-analysis findings (C-2, H-1…H-4, M-1…M-6, L-1…L-6) and [`docs/design/design-system.md`](../design/design-system.md) v0.1
**Governing rules:** [`AGENTS.md`](../../AGENTS.md) · [`ai-agent-dev-stg-execution-checklist.md`](../operations/ai-agent-dev-stg-execution-checklist.md) · [`ci-cd-and-release.md`](../operations/ci-cd-and-release.md) · [`release-gates.md`](../testing/release-gates.md)

---

## 0. Executive summary

### 0.1 Where the project actually is

Documentation is **exceptionally complete** — 27 ADRs, 154 documents, 19 Kiro feature specs, 17 governance gates, 18 feature flags, a full OpenAPI 3.1 contract, and now a verified design system. **Application code is zero.** The single running piece of infrastructure has a silent, permanent defect.

### 0.2 What this plan delivers, stated honestly

**Four sprints (8 calendar weeks) does not deliver MVP acceptance.** [`release-gates.md`](../testing/release-gates.md) contains roughly 80 acceptance checkboxes spanning payments, notifications, marketplace, vendor portal, renewal at 100k-record search performance, MFA, quarantined uploads, transactional outbox, Horizon, audit, backup/PITR, and formal performance certification. Building that from zero code in eight weeks is not achievable by any team size that could be onboarded in that window.

What four sprints **does** deliver, and what this plan commits to:

> **A production-shaped walking skeleton with four honest public entry points, a working CI pipeline, hardened non-production infrastructure, an enforced design system, and one complete vertical slice (FAQ) proving the whole stack end to end.**

That is a genuine, demonstrable, weekly-measurable outcome. Section 8 sets out the honest runway from there to MVP acceptance. Scaling the plan down or compressing it is a product decision, not an engineering one — this document states the cost so that decision is informed.

### 0.3 The single most urgent item

**C-2 is still broken right now.** Re-verified at the time of writing:

```
$ docker exec makam-nonprod-postgres-1 psql -U postgres_admin -d postgres -tAc \
    "select datname from pg_database order by 1;"
postgres
postgres_admin
template0
template1                       ← makam_dev and makam_stg STILL ABSENT

$ docker exec -u postgres makam-nonprod-postgres-1 sh -c \
    'for f in /run/secrets/*; do [ -r "$f" ] && echo readable || echo NOT readable; done'
makam_dev_db_password: NOT readable
makam_stg_db_password: NOT readable
postgres_admin_password: NOT readable
```

Postgres reports `Up 11 hours (healthy)`. Nothing surfaces the fault. Every downstream task — migrations, seeds, tests, features — is blocked behind it, and it will not self-heal on restart.

### 0.4 Sprint sequence at a glance

| Sprint | Weeks | Goal | Primary findings closed |
|---|---|---|---|
| **1 — Foundation** | 1–2 | Code exists, DB exists, CI exists | H-4, C-2, H-2, H-1, M-2, M-3 |
| **2 — Design + infra hardening** | 3–4 | Design system enforced; non-prod trustworthy | M-1, M-4, M-5, H-3, L-4 |
| **3 — MVP vertical slices** | 5–6 | Four public entry points reachable and honest | M-6, L-6, first feature specs |
| **4 — Test, a11y, gate dry-run** | 7–8 | Evidence, not assertions | L-5, release-gate baseline report |

---

## 1. Starting position — verified facts

Everything in this table was executed, not assumed. Re-verify before Sprint 1 begins; the infrastructure rows may drift.

| Fact | Verified value |
|---|---|
| Application code | **0 PHP files.** 154 `.md`, 1 `.yml`, 1 `.yaml`, 1 `.sh`, 1 `.json` |
| Git | 1 commit (`05f6f4d`), 160 tracked files, remote `github.com/andrianm28/makam-app` |
| Untracked | `.claude/`, `ekspektasi-user`, `docs/design/`, `resources/` |
| Host runtimes | PHP **8.5.8**, Composer **2.10.2**, Node **v24.16.0** |
| Host | Ubuntu **22.04.5 LTS**, 2 vCPU, 3.8 GiB RAM (153 MiB free), 9.5 GiB swap, disk 52 % |
| Postgres | **18.4** container, healthy, no published port, network `internal=true` |
| Redis | **8.2.7** container, healthy, `dbsize=0`, **`requirepass` empty** |
| App databases | **`makam_dev` / `makam_stg` DO NOT EXIST** |
| App roles | **Only `postgres_admin`.** No `makam_dev_user` / `makam_stg_user` |
| Extensions | **`pg_trgm` / `unaccent` NOT created** (blocks ADR-0007 trigram search) |
| Root cause | Docker secrets are `0600 uid=1000`; Postgres init runs as uid 999 → `Permission denied` → init aborted → `Skipping initialization` on all 4 restarts since |
| dev/stg endpoints | Placeholders **exited**; `127.0.0.1:8081/8082` → `http=000`; no nginx vhost; `dev./stg.makam.co.id` unresolved |
| Live `makam.co.id` | HTTP 200 — a **static 14 KB `index.html`** + `makam-notify.service` on `:3001`. **Unrelated to this repo.** |
| Baseline lockfile artefacts | **0 of 8 present** (`composer.json`, `composer.lock`, `package.json`, frontend lock, `.nvmrc`, `Dockerfile`, `ci/version-matrix.yml`) |
| Secrets hygiene | **Clean** — 0 hardcoded credentials in repo; host secrets `0600`; PAT in root-only store |
| Design tokens | `resources/css/tokens.css` — **46/46 WCAG AA pairs pass**, verifier exit 0 |

### 1.1 Pre-flight verification performed for this plan

Sprint 1's largest risk was that the pinned baseline might not exist or not co-resolve. **That risk is now closed with evidence.**

```
$ composer show --all laravel/framework   → v13.22.0 available (latest)
$ composer show --all livewire/livewire   → v4.3.3
$ composer show --all filament/filament   → v5.7.3
$ composer show --all laravel/horizon     → v5.48.1
$ npm view tailwindcss dist-tags          → latest 4.3.3
```

**Dependency resolution dry-run** on `php ~8.5.0` + `laravel/framework ^13.0` + `livewire/livewire ^4.0` + `filament/filament ^5.0` + `laravel/horizon ^5.0`:

```
Lock file operations: 109 installs, 0 updates, 0 removals
  - Locking laravel/framework (v13.22.0)
  - Locking livewire/livewire (v4.3.3)
  - Locking filament/filament (v5.7.3)
  - Locking laravel/horizon (v5.48.1)
0 problems reported
```

**Frontend resolution dry-run:** `tailwindcss 4.3.3` + `@tailwindcss/vite 4.3.3` + `vite 7.3.6` + `laravel-vite-plugin 2.1.0` → 34 packages, no conflict.

| Result | Status |
|---|---|
| Entire pinned baseline exists and co-resolves on PHP 8.5 | **PASS** (dry-run, evidence above) |
| Actual install, build, boot, or migrate | **NOT TESTED** — dry-run only; no `vendor/`, no `node_modules/`, nothing executed |

Note: `technology-baseline.md` pins `tailwindcss:^4.1`, which resolves to **4.3.3**. That satisfies the constraint, but the design system's §8.2 snippets were written against the Tailwind 4 API generally — confirm utility generation on 4.3.x in Sprint 2 (task S2-T1).

---

## 2. Scope reality — what four sprints includes and excludes

### 2.1 In scope

| Area | Sprint 4 end state |
|---|---|
| Laravel 13 skeleton, all 8 baseline artefacts | ✅ Complete, lockfiles committed |
| CI pipeline | ✅ Core stages of `ci-cd-and-release.md` §2 running and blocking merges |
| `makam_dev` + `makam_stg` databases, roles, extensions, isolation | ✅ Created, cross-access negative-tested |
| Non-prod hardening: nginx dev/stg, IP allowlist, `noindex`, Redis auth, backups + restore test | ✅ Done |
| Design system wired and CI-enforced | ✅ Tokens → Tailwind → Filament; contrast gate blocking |
| `<x-mk.*>` primitives | ✅ Buttons, fields, cards, modals, tables, badges, alerts, stepper, header |
| Homepage — 4 service cards, exact order, 5 launch regions | ✅ Complete |
| **FAQ — complete vertical slice** (6 categories, public list/filter/search/detail, admin CMS, publish/unpublish) | ✅ Complete, browser-tested |
| Booking wizard — Steps 1–5 with autosave/resume | ✅ Working, no payment |
| Renewal — city/cemetery selection + search UI with honest empty state | ⚠️ Skeleton (no tariff, no payment) |
| Marketplace — category/product browse from seeded canonical catalog | ⚠️ Skeleton (no cart, no checkout, no vendor portal) |
| Browser tests E2E-HOME, E2E-FAQ; partial E2E-BOOK | ✅ Passing |
| Accessibility: axe, keyboard, 200 % zoom, touch targets | ✅ On delivered screens |

### 2.2 Explicitly NOT in scope for Sprints 1–4

Deferred to Sprint 5+ (§8). Each is listed so nobody assumes it is coming:

Booking Steps 6–9 (customer data, deceased data + upload, payment, confirmation) · document upload + quarantine + malware scanning + signed URLs · **any payment path, online or manual fallback** · quotation/immutable quote versions · order lifecycle state machine · FuneralCase / At-Need / Urgent · Pre-Need interest · marketplace cart/checkout/single-vendor constraint · **vendor portal entirely** · admin operations beyond FAQ CMS · renewal tariff/fee/payment/duplicate prevention · grave registry import + fuzzy search at 100k · transactional outbox · Horizon supervisors + queue priorities · notifications (email/WhatsApp) + notification matrix · MFA + re-authentication + session revocation · audit trail · financial ledger/journal/settlement · certificates and agreements · performance certification (Profiles A–D) · production environment of any kind.

### 2.3 Assumption this plan runs on

> **Assumed team: 1 senior full-stack Laravel/Livewire/Filament developer, working with AI-agent assistance, plus a part-time human reviewer with authority over the §9 gates.** All effort figures are person-days (pd) on that basis. §10 gives calendar sensitivity for other team sizes. If the real team differs, re-baseline §10 before committing to dates — do not compress the sprint contents to fit.

---

## 3. Critical path and dependencies

### 3.1 Two parallel tracks in Sprint 1

The most valuable scheduling insight: **the scaffold does not depend on the database.** `composer install` and `npm ci` need neither Postgres nor Redis. So Sprint 1 runs two tracks concurrently and converges at first migration.

```
TRACK A — INFRASTRUCTURE (blocking, do first, has a human gate)
  S1-T1  fix Docker secret ownership (uid/gid/mode)
    └─▶ S1-T2  align init script to safe parameter binding      [H-2]
          └─▶ S1-T3  create DBs + roles + extensions + isolation [C-2] ⚠ HUMAN GATE
                └─▶ S1-T4  schema-aware healthcheck + smoke      [C-2]
                                                    │
TRACK B — APPLICATION (independent until here)      │
  S1-T6  Laravel 13 scaffold + 8 baseline artefacts  [H-4]
    └─▶ S1-T7  .env.dev / .env.stg, APP_KEY separation
          └─▶ S1-T8  CI skeleton                                 │
                                                    │            │
                            ┌───────────────────────┴────────────┘
                            ▼
                   ★ CONVERGENCE: first `php artisan migrate` succeeds
                            │
                            ├─▶ Sprint 2  design system + infra hardening
                            └─▶ Sprint 3  features (BLOCKED until convergence)
```

### 3.2 Hard ordering rules

1. **No feature work before convergence.** Building features against a non-existent database produces untestable code. This is the rule the plan exists to enforce.
2. **`pg_trgm` / `unaccent` must exist before any renewal/grave-search work** — ADR-0007 depends on them, and they are created in S1-T3.
3. **Design system must be wired (S2-T1) before any screen is built.** Building screens first then retrofitting tokens guarantees hardcoded values.
4. **CI must exist (S1-T8) before feature work** or the §9.5 governance gates are unenforced and drift starts immediately.
5. **Backup + restore test (S2-T6) before any destructive migration** — [`database-backup-and-recovery.md`](../operations/database-backup-and-recovery.md) §4.
6. **Payment work cannot start until `G-PAY-01` status is known** and the FIN-DEC decisions in `release-gates.md` §H are approved. This is why no payment work appears in Sprints 1–4.
7. **H-1 is a documentation fix, not a runtime fix.** The *deployed* compose mounts the volume correctly; the *repo example* does not. Fixing the doc prevents future data loss — it does not repair anything currently running. Do not "fix" the deployed file to match the broken example.

### 3.3 Longest chain

```
S1-T1 → S1-T2 → S1-T3(gate) → S1-T4 → migrate → S2-T1 → S2-T2 → S3-T2(FAQ) → S4-T1(tests) → S4-T5(gate report)
```

Everything else can be scheduled around this chain.

---

## 4. Sprint 1 — Foundation (Weeks 1–2)

### Goal

> From zero code and a broken database to: a Laravel 13 application that boots, connects to a real `makam_dev` database, and passes a CI pipeline — with the silent-failure class of defect made loud.

### Tasks

| ID | Task | Findings | Effort | Gate |
|---|---|---|---:|---|
| S1-T1 | Fix Docker secret ownership so uid 999 can read `/run/secrets/*` | C-2 root cause | 0.5 pd | — |
| S1-T2 | Replace deployed init script with the repo's safe parameter-bound version | **H-2** | 0.5 pd | — |
| S1-T3 | Create `makam_dev`/`makam_stg` + roles + `pg_trgm`/`unaccent` + connect isolation | **C-2** | 1 pd | ⚠️ **HUMAN** |
| S1-T4 | Schema-aware healthcheck + `/health/ready` smoke so this cannot fail silently again | **C-2** | 1 pd | — |
| S1-T5 | Fix Postgres volume path in repo compose example | **H-1** | 0.5 pd | — |
| S1-T6 | Laravel 13 scaffold + all 8 baseline artefacts | **H-4** | 4 pd | — |
| S1-T7 | `.env.dev` / `.env.stg` with separated APP_KEY, DB user, Redis prefix, queue, cookie, storage | H-4, M-1 | 1 pd | ⚠️ **HUMAN** |
| S1-T8 | CI pipeline skeleton (GitHub Actions) | H-4 | 2 pd | — |
| S1-T9 | `.gitignore` + `.claude/settings.json` hardening; commit design-system files | **M-2, M-3** | 0.5 pd | — |
| S1-T10 | ADR-0028 (design system) + ADR-0029 (scaffold decisions) | L-6 | 1 pd | — |

**Total: ~12 pd**

### Task detail

**S1-T1 — Docker secret ownership.** Preferred, declarative: service-level long syntax so the fix lives in `compose.yml`.

```yaml
services:
  postgres:
    secrets:
      - source: postgres_admin_password
        target: postgres_admin_password
        uid: "999"
        gid: "999"
        mode: 0400
      - source: makam_dev_db_password
        target: makam_dev_db_password
        uid: "999"
        gid: "999"
        mode: 0400
      # …repeat for makam_stg_db_password
```

> **Verify first:** `uid`/`gid`/`mode` support in the long syntax is documented for Swarm and is honoured by Compose v2 in current versions, but **this has not been tested on this host**. Fallback if unsupported: `chown 999:999` the three host files, keeping mode `0400`. Do **not** `chmod 0444` — that makes a database credential world-readable on the host.

**S1-T3 — Create databases (⚠️ HUMAN GATE).** Two routes:

- **Route A — manual DDL (RECOMMENDED, non-destructive).** The cluster holds no application data, so nothing is at risk. Preserves the existing volume.
- **Route B — delete the volume to re-trigger init.** Destructive. Requires explicit human approval under `AGENTS.md` ("Pause for … destructive database/volume changes") and the execution checklist's required-pause conditions. Only choose this if Route A is somehow blocked.

DDL for Route A, run as `postgres_admin` via `psql -v ON_ERROR_STOP=1` with `--set` bindings (never shell interpolation — that is the H-2 defect):

```sql
-- Per docs/operations/database-backup-and-recovery.md §8: SEPARATE application
-- and migration roles with least privilege. See finding N-1 below.
CREATE ROLE makam_dev_app      LOGIN PASSWORD :'dev_app_password';
CREATE ROLE makam_dev_migrator LOGIN PASSWORD :'dev_mig_password';
CREATE DATABASE makam_dev OWNER makam_dev_migrator;

-- Isolation: revoke the default PUBLIC grant, then grant explicitly.
REVOKE CONNECT ON DATABASE makam_dev FROM PUBLIC;
GRANT  CONNECT ON DATABASE makam_dev TO makam_dev_app, makam_dev_migrator;
-- …mirror all of the above for makam_stg / makam_stg_app / makam_stg_migrator
```

Then, connected to each database:

```sql
CREATE EXTENSION IF NOT EXISTS pg_trgm;    -- ADR-0007 trigram search
CREATE EXTENSION IF NOT EXISTS unaccent;
GRANT USAGE, CREATE ON SCHEMA public TO makam_dev_migrator;
GRANT USAGE           ON SCHEMA public TO makam_dev_app;
```

**Cross-access negative test** (required evidence per the execution checklist): assert `makam_dev_app` **cannot** connect to `makam_stg`, and vice versa. Automate it as a test, not a one-off check.

**S1-T4 — Make silent failure impossible.** This is the task that stops C-2 recurring, and it matters more than the fix itself.

```yaml
healthcheck:
  test: ["CMD-SHELL", "pg_isready -U postgres_admin && psql -U postgres_admin -d makam_dev -tAc \"select 1 from pg_extension where extname='pg_trgm'\" | grep -q 1"]
  interval: 30s
  timeout: 10s
  retries: 5
  start_period: 30s
```

Plus `/health/ready` per `ci-cd-and-release.md` §8, asserting DB reachable, both extensions present, migration table present, Redis reachable — **without exposing secrets**. Fail loudly.

**S1-T6 — Scaffold (H-4).** Deliver all eight artefacts: `composer.json` (`php: ~8.5.0`, `laravel/framework: ^13.0`, `livewire/livewire: ^4.0`, `filament/filament: ^5.0`, `laravel/horizon: ^5.0`), `composer.lock`, `package.json` (`tailwindcss: ^4.1`, `@tailwindcss/vite`, `vite`, `laravel-vite-plugin`), frontend lockfile, `.nvmrc` (`24`), `Dockerfile` (multi-stage, PHP 8.5 FPM, pinned by digest per `technology-baseline.md` §5.3), `ci/version-matrix.yml`.

Modular monolith structure per `overview.md` §5 — establish the module boundaries now, even as empty namespaces. Retrofitting boundaries after features exist never happens.

**Builds run in CI, never on the 2/4 host** (`ci-cd-and-release.md` §10). Resolution is already proven (§1.1); this task is about committing lockfiles and Docker/CI wiring.

**S1-T9 — Hygiene (M-2, M-3).** Add `.claude/` and a decision on `ekspektasi-user` to `.gitignore` — it is the stakeholder expectation document and should arguably be **tracked** as canonical rather than ignored; decide, do not leave it in limbo. Fix the `.claude/settings.json` bypass: `Bash(cat *)` is allowed while `Read(*/.env)` and `Read(*secret*)` are denied, which makes the denials cosmetic. Commit `docs/design/`, `resources/css/tokens.css`, `docs/design/verify-contrast.py`, and this plan.

### Deliverable

Laravel 13 boots; `php artisan migrate` succeeds against `makam_dev`; CI green on a PR; `docker compose config` valid; health endpoints report truthfully; design-system files committed.

### Definition of Done

- [ ] `psql -tAc "select datname from pg_database"` lists `makam_dev` **and** `makam_stg`
- [ ] `pg_trgm` **and** `unaccent` present in both databases
- [ ] Cross-database access negative test passes (automated)
- [ ] `docker exec -u postgres … [ -r /run/secrets/* ]` → readable for all three
- [ ] `php artisan --version` reports Laravel 13.x
- [ ] `php artisan migrate` completes against `makam_dev`
- [ ] `composer validate` passes; `composer.lock` and frontend lockfile committed
- [ ] `npm ci && npm run build` succeeds **in CI**
- [ ] CI blocks merge on failure
- [ ] `/health/live` and `/health/ready` return truthfully and leak no secrets
- [ ] Repo compose example volume path corrected (H-1)
- [ ] Deployed init script uses parameter binding (H-2)
- [ ] `git status` clean; nothing untracked-and-unignored
- [ ] ADR-0028 and ADR-0029 recorded
- [ ] Evidence pack per execution checklist "Evidence required at completion"

### Risks

| Risk | L | I | Mitigation |
|---|---|---|---|
| Compose `uid`/`gid`/`mode` not honoured on this Docker version | Med | Med | Fallback `chown 999:999`; verify before committing |
| Someone chooses Route B and wipes the volume | Low | High | Human gate; Route A explicitly recommended; no app data exists yet anyway |
| `Dockerfile` PHP 8.5 base image unavailable by digest | Med | Med | Pin to a known-good tag first, add digest once verified |
| Filament 5 install requires config the docs don't cover | Med | Med | Time-boxed spike inside S1-T6; escalate to ADR if the baseline needs changing |
| 2 vCPU / 153 MiB free RAM makes local work painful | High | Low | Builds in CI; keep placeholders stopped; monitor swap |

### Explicitly not in Sprint 1

No features. No screens. No migrations beyond framework defaults. No nginx/DNS changes. No Redis auth (Sprint 2 — it needs a restart and a config plan).

---

## 5. Sprint 2 — Design system enforcement + infra hardening (Weeks 3–4)

### Goal

> Make the design system mechanically unavoidable, and make non-production infrastructure trustworthy enough to build on — reachable, authenticated, backed up, and restore-tested.

### Tasks

| ID | Task | Findings | Effort | Gate |
|---|---|---|---:|---|
| S2-T1 | Wire `tokens.css` → Tailwind 4.3; verify **every** utility in design-system §8.2 generates | design §12 | 2 pd | — |
| S2-T2 | Build `<x-mk.*>` primitives (button, field, card, modal, table, badge, alert, stepper, header) | design §3 | 4 pd | — |
| S2-T3 | Verify Filament 5 theming; implement `StatusIntent`; resolve OQ-09 palette duplication | design §8.3, OQ-09 | 2 pd | — |
| S2-T4 | Add all six design governance gates to CI, incl. `verify-contrast.py` as hard fail | design §9.5 | 1 pd | — |
| S2-T5 | nginx dev/stg vhosts + DNS + IP allowlist + TLS + `noindex` | **M-1** | 2 pd | ⚠️ **HUMAN** |
| S2-T6 | Redis `requirepass` + separate prefixes/namespaces per environment | **M-5** | 1 pd | ⚠️ **HUMAN** |
| S2-T7 | Encrypted daily staging backup to remote object storage + **restore test with evidence** | **M-4** | 2 pd | ⚠️ **HUMAN** |
| S2-T8 | Downgrade the 32 false `Covered` claims in the traceability matrix | **H-3** | 1 pd | — |
| S2-T9 | Align document versions to v0.6; add `docs/design/` + this plan to Kiro steering | **L-4**, design OQ-11 | 0.5 pd | — |
| S2-T10 | Basic observability: structured logs, container/memory/swap/disk monitoring | M-6 prep | 1.5 pd | — |

**Total: ~19 pd** — the heaviest sprint relative to its length. See §10; this is the most likely candidate for a 3-week Sprint 2.

### Task detail

**S2-T1 — Validate the design system's own NOT TESTED list.** `design-system.md` §12 states plainly that no Tailwind build has run. This task closes that. Every utility asserted in §8.2 (`max-w-form`, `duration-fast`, `z-modal`, `h-13`, `xs:`, `border-neutral-450`, `ease-standard`) must be proven to generate on Tailwind **4.3.3**. **Where reality differs, fix `design-system.md`** — do not fix the code to match a wrong document.

**S2-T3 — Filament 5 (the least-verified area).** `design-system.md` §8.3 is explicitly flagged as the least reliable section. Verify the theme path, the `vendor/filament/.../theme.css` import target, `LocalFontProvider`, and `Color::hex()`. Then close **OQ-09**: Filament resolves colours in PHP and cannot read CSS variables, so hex values are currently duplicated. Build the generator + CI diff so `tokens.css` stays the single source of truth.

Implement `StatusIntent` as the one place status → intent resolution happens (design §3.7), shared by public Livewire views and Filament tables. This must exist **before** any status is rendered anywhere.

**S2-T5 — nginx / DNS / allowlist (⚠️ HUMAN GATE).** Touches DNS and firewall — both are required-pause conditions in `AGENTS.md` and the execution checklist. Deliver: `dev.makam.co.id` → `127.0.0.1:8081`, `stg.makam.co.id` → `127.0.0.1:8082`, restart the placeholder containers (or the real app image once it exists), IP allowlist or basic auth for dev, TLS via Certbot, `X-Robots-Tag: noindex` on both.

> **Take care:** `makam.co.id` currently serves a **live** static landing page plus `makam-notify.service` on `:3001`. Adding subdomain vhosts must not disturb it. Back up nginx config first; keep a rollback path; verify the apex still returns 200 afterwards.
>
> **DNS ownership must be confirmed before any record change** — ambiguity is an explicit pause condition.

**S2-T6 — Redis auth (⚠️ HUMAN GATE).** Currently no `requirepass`. Risk is mitigated (internal network, no published port) but it breaches `security-baseline`. Requires a restart, so treat as a change with a rollback plan. Also establish distinct Redis prefixes, queue names, and Horizon namespaces per environment — required by `release-gates.md` §I and impossible to retrofit cleanly once queues carry data.

**S2-T7 — Backup + restore (⚠️ HUMAN GATE).** Per `database-backup-and-recovery.md` §9: staging gets daily encrypted logical backups to **remote** object storage, ≥ 7 days retention. **"A backup is not considered valid until restored"** (§4) — so this task is not done until a restore has been executed and evidence recorded per §5 (version, extensions, migration state, row counts, smoke test, sign-off). Local Docker volumes are explicitly **not** backups.

**S2-T8 — Fix the false coverage claims (H-3).** The traceability matrix asserts `Covered` on 32 items with zero tests in existence. `AGENTS.md`: *"Every traceability item marked `Covered` needs test evidence."* Introduce `Documented` / `Specified` and re-label. This is a small edit with outsized value: it stops the project reporting readiness it does not have — the same failure mode as C-2's silent health.

### Deliverable

Design system enforced by CI. `dev.` and `stg.` reachable, TLS-terminated, access-restricted, `noindex`. Redis authenticated and namespaced. Staging backup running with a **recorded successful restore**. Traceability honest.

### Definition of Done

- [ ] `npm run build` produces CSS using tokens; **zero** hardcoded hex outside `tokens.css`
- [ ] Every §8.2 utility verified generated, or `design-system.md` corrected
- [ ] All nine `<x-mk.*>` primitives exist with their documented states
- [ ] Filament panel renders with brand palette; `StatusIntent` is the sole resolver
- [ ] All six §9.5 CI gates active; `verify-contrast.py` blocks merge
- [ ] `https://dev.makam.co.id` and `https://stg.makam.co.id` return 200, TLS valid, allowlist enforced, `noindex` header present
- [ ] `https://makam.co.id` **still returns 200** (regression check)
- [ ] `redis-cli ping` without auth **fails**; app connects with auth; prefixes distinct per env
- [ ] Backup exists in remote storage; **restore executed**; evidence recorded per §5
- [ ] Traceability contains no unevidenced `Covered`
- [ ] Steering file lists `docs/design/` and this plan
- [ ] Memory/swap/disk/container monitoring active with alert thresholds

### Risks

| Risk | L | I | Mitigation |
|---|---|---|---|
| **nginx change breaks live `makam.co.id`** | Med | **High** | Config backup, `nginx -t` before reload, apex regression check, rollback path, human gate |
| DNS ownership unclear | Med | Med | Confirm owner before touching records — explicit pause condition |
| Design-system §8.2/§8.3 wrong in ways needing rework | Med | Med | Time-boxed; correct the document; escalate to ADR if the baseline shifts |
| No remote object storage provisioned | Med | High | Resolve **OQ-4** before Sprint 2 starts; blocks S2-T7 entirely |
| Redis auth breaks Horizon config later | Low | Med | Set prefixes/namespaces now, before queues carry data |
| Sprint 2 is over-committed at 19 pd / 2 weeks | **High** | Med | Extend to 3 weeks, or move S2-T10 and S2-T9 to Sprint 4 |

---

## 6. Sprint 3 — MVP vertical slices (Weeks 5–6)

### Goal

> Four public entry points exist, are reachable, are honest about what is not yet available, and are built entirely from the design system — with FAQ complete end to end to prove the whole stack.

### Sequencing insight

**Build FAQ first, not the booking wizard.** FAQ is the cheapest *complete* vertical slice — public list, filter, search, detail, admin CMS, publish/unpublish, six seeded categories — and it exercises every layer: Livewire, Filament, design system, migrations, seeds, authorization, browser tests, CI. Proving the stack on FAQ costs ~4 pd. Discovering a stack problem three-quarters through the 9-step wizard costs far more.

### Tasks

| ID | Task | Spec | Effort | Gate |
|---|---|---|---:|---|
| S3-T1 | Master data + seeds: 5 launch regions, cemeteries, canonical service catalog, marketplace catalog, FAQ categories | multiple | 3 pd | — |
| S3-T2 | **FAQ complete slice** — public + admin CMS + 6 categories + no draft leakage | `public-faq`, `admin-operations` | 4 pd | — |
| S3-T3 | Homepage — 4 service cards in exact order, hero, 9 sections per IA §3, honest Urgent status | `public-home-and-navigation` | 3 pd | — |
| S3-T4 | Booking wizard shell Steps **1–5** + autosave/resume + draft resume across sessions | `public-booking-wizard` | 5 pd | — |
| S3-T5 | Cemetery directory + availability display with `"Perlu konfirmasi"` | `cemetery-directory-and-availability` | 3 pd | — |
| S3-T6 | Renewal skeleton — city/cemetery selection + search UI + honest empty state | `renewal-and-grave-registry` | 2 pd | — |
| S3-T7 | Marketplace skeleton — category/product browse from seeded catalog | `funeral-marketplace-and-vendor-portal` | 2 pd | — |
| S3-T8 | Gated-fallback mode banners driven by **server-side** mode values | `overview.md` §15 | 1.5 pd | — |
| S3-T9 | Capacity review with all tenants counted; decide upgrade vs split | **M-6** | 1 pd | ⚠️ **HUMAN** |
| S3-T10 | Resolve the 5 open decisions in `assumptions-and-gates.md` §5 that block specs | **L-6** | 1 pd | ⚠️ **HUMAN** |

**Total: ~25.5 pd** — over a 2-week sprint for one developer. See §10.

### Task detail

**S3-T1 — Master data is the real prerequisite.** Every other Sprint 3 task depends on seeded canonical data. `AGENTS.md` is explicit: *"Do not duplicate canonical catalog data in multiple hand-maintained documents or code locations."* Seeds read from one authoritative definition per catalog. `overview.md` §13: *"must not scatter hard-coded variants across Livewire components."*

**S3-T4 — Wizard Steps 1–5 only.** Steps 6–9 are deliberately excluded: Step 7 needs the full quarantine/malware/signed-URL pipeline and Step 8 needs a payment decision that does not exist yet. Steps 1–5 deliver the wizard shell, the 9-step stepper presentation (design §3.9 — still displaying 1–9, since the framing is a product contract), autosave every 10 s while dirty, resume across sessions, anonymous draft with opaque token, back navigation that preserves data, and server-side step validation.

**S3-T8 — Honesty machinery.** `PaymentMode`, `WhatsAppMode`, `PreNeedMode`, `GraveSearchMode` read from the **server** — a front-end flag is explicitly insufficient. This is what lets the app ship truthfully while gates are closed, and it is why the renewal and marketplace skeletons are acceptable deliverables rather than broken promises.

**S3-T9 — Capacity review (⚠️ HUMAN GATE).** The host runs `fund-for-indonesia` (app + `postgres:16` on `:3000`/`:5432`) alongside this stack. 153 MiB RAM was free before any PHP-FPM or Horizon process existed. `performance-and-capacity.md` assumes a dedicated host. Produce a real measurement and a recommendation: upgrade, split environments, or formally accept the limitation.

### Deliverable

Four public entry points live on `dev.`/`stg.`, built from design-system primitives, all required states implemented, honest gated-fallback messaging, FAQ complete and browser-tested.

### Definition of Done

- [ ] Homepage shows exactly four services in stakeholder order (IA §3 nine-section order respected)
- [ ] Five launch regions present and selectable
- [ ] FAQ: six categories, filter, search, detail, CS CTA; **draft articles are not publicly reachable** (test-enforced)
- [ ] Booking Steps 1–5 navigable; back preserves data; autosave verified across a session boundary
- [ ] Cemetery cards show type, name, photo, address, Maps URL, facilities, price **with source**, availability, `"Perlu konfirmasi"` when indicative
- [ ] All ten required UI states implemented on every delivered transactional screen (design §6)
- [ ] Zero hardcoded design values (CI-enforced)
- [ ] Status badges resolve through `StatusIntent` only
- [ ] Gate/mode banners read server-side; verified by toggling the server value
- [ ] Every delivered screen verified at 320 / 360 / 768 / 1024 / 1280 px
- [ ] Capacity review recorded with a decision
- [ ] Traceability updated — `Covered` **only** where a test exists and passes

### Risks

| Risk | L | I | Mitigation |
|---|---|---|---|
| Sprint 3 over-committed at 25.5 pd / 2 weeks | **High** | High | Descope to FAQ + homepage + Steps 1–3, or extend to 3–4 weeks. **Do not descope the state patterns.** |
| Feature work starts before convergence | Med | High | Hard dependency rule §3.2.1; CI gate |
| Canonical catalog gets duplicated into components | Med | High | Code review rule; single-source seeds |
| Open decisions (L-6) block specs mid-sprint | Med | Med | Front-load S3-T10 in week 1 |
| Design system needs changes once real screens exist | Med | Low | Expected; ADR path per design §9.4 |

---

## 7. Sprint 4 — Test, accessibility, gate dry-run (Weeks 7–8)

### Goal

> Convert Sprint 3's assertions into evidence, and produce an honest release-gate status report that says `NOT READY` where that is the truth.

### Tasks

| ID | Task | Findings | Effort | Gate |
|---|---|---|---:|---|
| S4-T1 | Browser suites E2E-HOME + E2E-FAQ complete; E2E-BOOK for Steps 1–5 | test-strategy §2 | 4 pd | — |
| S4-T2 | Accessibility: axe-core in the suite, keyboard walkthroughs, focus order, 200 % zoom, 320 px reflow, touch targets | design §7.7 | 3 pd | — |
| S4-T3 | Authorization + query-scope tests for delivered surfaces; cross-panel access negative tests | test-strategy §7 | 2 pd | — |
| S4-T4 | Lighthouse/weight budget measurement vs design §4.6; record actuals | design §4.6 | 1 pd | — |
| S4-T5 | **Release-gate dry run** → `READY` / `READY WITH BLOCKERS` / `NOT READY` report | release-gates | 2 pd | ⚠️ **HUMAN** |
| S4-T6 | Rollback rehearsal + expand/contract migration compatibility test | ci-cd §4, §7 | 2 pd | ⚠️ **HUMAN** |
| S4-T7 | Resolve remaining design open questions (OQ-01 brand, OQ-04 bottom nav, OQ-05 icons, OQ-06 copy) | design §11 | 1 pd | ⚠️ **HUMAN** |
| S4-T8 | Docs: `CLAUDE.md`↔`AGENTS.md`, screen-inventory update, remaining L-4 | **L-5, L-4** | 1 pd | — |
| S4-T9 | Sprint 5+ backlog groomed into the issue tracker | — | 1 pd | — |

**Total: ~17 pd**

### Task detail

**S4-T5 — The most important deliverable of this sprint.** A structured pass over every `release-gates.md` checkbox with one of three states and **no fourth option**: `PASS` (with evidence), `BLOCKED` (with reason), `NOT TESTED`. `AGENTS.md`: *"Never report `PASS` for a check that was not executed."* The expected honest outcome is **`NOT READY`**, with most of sections C–H marked `NOT TESTED` because they are out of scope (§2.2). That report is the deliverable — it is what makes the remaining runway visible and fundable.

**S4-T6 — Rehearse rollback before needing it.** `ci-cd-and-release.md` §4 mandates expand/contract and §7 defines rollback actions. Rehearse on staging: deploy, migrate, roll the artefact back, confirm the schema stays forward-compatible. Requires a human gate — it touches migrations and deployment.

**S4-T7 — Unblock the design system.** **OQ-01 (is Petrol teal the accepted brand primary?)** must be settled here. If a green primary is mandated, `success` must move and all 46 contrast pairs need re-verification — cheap now, expensive after 40 screens exist. **OQ-04 (bottom nav)** is a navigation contract needing product approval, not a style choice.

### Deliverable

Green browser suites for delivered scope. Accessibility evidence. Measured performance actuals. A signed, honest release-gate report. Rehearsed rollback. A groomed Sprint 5+ backlog.

### Definition of Done

- [ ] E2E-HOME and E2E-FAQ pass on desktop **and** mobile viewports
- [ ] E2E-BOOK passes for Steps 1–5 including autosave/resume and back navigation
- [ ] axe-core reports zero critical/serious violations on delivered screens
- [ ] Keyboard-only completion of every delivered journey; focus visible and correctly ordered; modal focus returns to trigger
- [ ] 200 % zoom and 320 px reflow verified with no horizontal scroll
- [ ] Authorization tests cover every delivered surface; cross-panel negative tests pass
- [ ] Weight budget actuals recorded against design §4.6 targets (pass **or** documented exception)
- [ ] Release-gate report published with every item `PASS` / `BLOCKED` / `NOT TESTED` — **no unevidenced `PASS`**
- [ ] Rollback rehearsal executed with recorded outcome
- [ ] OQ-01 and OQ-04 decided and recorded
- [ ] Traceability reflects only tests that exist and pass
- [ ] Sprint 5+ backlog in the issue tracker (`AGENTS.md`: *"`tasks.md` is planning only; issue tracker owns progress"*)

### Risks

| Risk | L | I | Mitigation |
|---|---|---|---|
| A11y findings force design-system changes | Med | Med | Expected; budget rework; ADR path |
| Weight budget missed on 2 vCPU host | Med | Low | Record as exception; note host is not production evidence |
| Stakeholders read "Sprint 4 done" as "MVP done" | **High** | **High** | §0.2 and §2.2 exist for this; lead the report with scope |
| OQ-01 reverses the palette | Low | Med | Re-run `verify-contrast.py`; ~1 pd if decided in Sprint 4, far more later |

---

## 8. Beyond Sprint 4 — honest runway to MVP acceptance

Outline only, for planning visibility. **Not estimated at the same confidence as Sprints 1–4** — these depend on gate decisions, provider availability, and financial approvals outside engineering control.

| Sprint | Theme | Notes |
|---|---|---|
| 5–6 | Booking Steps 6–9 · document upload + quarantine + malware + signed URLs · quotation + immutable versions | Privacy + security gates throughout |
| 7 | Order lifecycle state machine · FuneralCase / At-Need / Urgent · admin order operations | Forward-only states, append-only audit |
| 8–9 | **Payment** — manual fallback first, then online if `G-PAY-01` opens | Blocked on FIN-DEC approvals; heavy human gates |
| 10 | Transactional outbox · Horizon supervisors + queue priorities · notification matrix (email; WhatsApp only if `G-WA-01`) | |
| 11–12 | Marketplace cart/checkout/single-vendor · **vendor portal** (9 screens) | |
| 13 | Renewal tariff/fee/payment/duplicate prevention · grave registry import · fuzzy search at 100k with `pg_trgm` | Perf target < 500 ms |
| 14 | MFA · re-authentication · session revocation · audit trail · RBAC across all panels | |
| 15 | Certificates and agreements · versioned documents | |
| 16 | Performance certification Profiles A–D · production environment · managed Postgres + PITR | **Requires a provider decision — see N-3** |
| 17 | Full release-gate pass · UAT · production readiness review | |

**Rough total to MVP acceptance: ~17 sprints ≈ 8–9 months with the assumed 1-developer team, or ~4–5 months with 2–3 developers.** Treat as an order-of-magnitude planning figure, **not a commitment**. The dominant uncertainties are the payment gate, the production database provider, and whether the vendor portal can be phased.

---

## 9. Human review gates — consolidated

`AGENTS.md`: *"AI agents may prepare migrations and deployment changes but human review is mandatory before security, authorization, financial, privacy, destructive migration, DNS, firewall, or production-affecting changes."*

An agent may **prepare and propose** every item below. **None may be executed without recorded human approval.**

| Gate | Task | Category | Why |
|---|---|---|---|
| **G1** | S1-T3 | Destructive DB | Creating databases/roles; Route B would delete a volume |
| **G2** | S1-T7 | Secrets | APP_KEY, DB credentials, per-env separation |
| **G3** | S2-T5 | **DNS + firewall** | DNS records, IP allowlist, TLS; **risks the live `makam.co.id`** |
| **G4** | S2-T6 | Security config | Redis auth; requires restart |
| **G5** | S2-T7 | DB + storage | Backup credentials, remote storage, restore execution |
| **G6** | S3-T9 | Infrastructure | Capacity decision: upgrade / split / accept |
| **G7** | S3-T10 | Product/legal | Reservation TTL, certificate authority, public data minimums, consent |
| **G8** | S4-T5 | Release | Gate report sign-off |
| **G9** | S4-T6 | Migration + deploy | Rollback rehearsal, expand/contract |
| **G10** | S4-T7 | Product/brand | Brand primary (OQ-01), navigation contract (OQ-04) |

### Required pause conditions — agent must stop and ask

Per the execution checklist, regardless of sprint: SSH fallback unverified before disabling password login · destructive migration or volume deletion · firewall change that could remove current access · DNS/certificate ownership ambiguity · missing secret or provider account · any request involving production data or credentials · incompatibility requiring a baseline architecture change.

### Not delegable to an agent at all

Production access (prohibited outright) · secret **values** in chat or logs (prohibited) · financial gate activation · legal/privacy decisions (G7) · brand decisions (G10).

---

## 10. Estimates

### 10.1 Per sprint

| Sprint | Effort | Nominal | Realistic (1 dev @ ~4.5 productive pd/week) | Verdict |
|---|---:|---|---|---|
| 1 — Foundation | **12 pd** | 2 weeks | ~2.5 weeks | Tight but achievable |
| 2 — Design + infra | **19 pd** | 2 weeks | ~4 weeks | **Over-committed** |
| 3 — MVP slices | **25.5 pd** | 2 weeks | ~5.5 weeks | **Significantly over-committed** |
| 4 — Test + gates | **17 pd** | 2 weeks | ~3.5 weeks | Over-committed |
| **Total** | **~73.5 pd** | **8 weeks** | **~15.5 weeks** | |

### 10.2 Calendar sensitivity

| Team | Calendar for ~73.5 pd | Note |
|---|---|---|
| 1 senior dev + AI assist | **~15–16 weeks** | The assumed baseline |
| 2 senior devs | ~8–9 weeks | Sprints 2–3 parallelise reasonably well |
| 3 devs | ~6–7 weeks | Coordination overhead starts to bite; Sprint 1 does not parallelise |

**Sprint 1 barely parallelises** — Track A (§3.1) is a serial chain with a human gate in the middle. Adding people helps from Sprint 2 onward, not before.

### 10.3 Honest reading

> The requested 4-sprint / 8-week structure is delivered above as specified. At the assumed team size the same **content** realistically needs **~15–16 weeks**. Two options, both legitimate: keep 8 weeks and descope (drop S3-T5/T6/T7 and S4-T3/T4, deliver FAQ + homepage + Steps 1–3 only), or keep the content and plan ~15 weeks. **Do not keep both the scope and the dates** — that is how the false-`Covered` failure mode (H-3) recurs at the schedule level.
>
> Effort figures are **estimates**, not measurements. No task here has been executed except the §1.1 preflight.

---

## 11. Risk register — cross-sprint

| ID | Risk | L | I | Mitigation | Owner |
|---|---|---|---|---|---|
| R-1 | **nginx work breaks live `makam.co.id`** | Med | **High** | Config backup, `nginx -t`, apex regression test, rollback, G3 | Human + agent |
| R-2 | Silent-failure class recurs elsewhere | Med | **High** | S1-T4 pattern: assert *function*, not liveness, in every healthcheck | Dev |
| R-3 | Host capacity insufficient once PHP-FPM + Horizon run | **High** | Med | S3-T9 measurement + decision; builds stay in CI | G6 |
| R-4 | Payment gate `G-PAY-01` stays closed indefinitely | Med | **High** | Manual fallback is the designed path; no payment work in 1–4 | Product |
| R-5 | Production managed Postgres provider undecided (N-3) | **High** | **High** | Decide by Sprint 4; blocks all production planning | Product |
| R-6 | No object storage → blocks backups **and** uploads | Med | High | **OQ-4 — resolve before Sprint 2** | Product |
| R-7 | Scope creep from 19 specs into Sprints 1–4 | **High** | Med | §2.2 exclusion list is binding | PM |
| R-8 | Design system diverges once real screens land | Med | Med | CI gates + ADR path (design §9.4) | Dev |
| R-9 | Single-developer bus factor | Med | High | ADRs, this plan, and the evidence packs are the mitigation | PM |
| R-10 | 2/4 host mistaken for production capacity evidence | Med | High | `release-gates.md` §I forbids it; restate in every report | Dev |
| R-11 | Documentation drifts from code once code exists | **High** | Med | `AGENTS.md` requires doc updates with behaviour changes; add to PR template | Dev |

### New findings surfaced while planning

These were not in the original analysis and need tracking:

| ID | Finding | Sev | Where |
|---|---|---|---|
| **N-1** | The init script creates **one** role per environment, but `database-backup-and-recovery.md` §8 requires **separate application and migration roles** with least privilege. Fixed in the S1-T3 DDL. | Medium | S1-T3 |
| **N-2** | **No Content-Security-Policy** is defined anywhere in `security-baseline.md`. Cheap to add before the scaffold hardens; expensive to retrofit around Livewire/Alpine. | Medium | design OQ-10 → Sprint 1/2 |
| **N-3** | ADR-0021 requires **managed** PostgreSQL with PITR for production; today's setup is a self-hosted Docker container with no backups. **No provider has been chosen.** Blocks all production planning. | **High** | R-5, Sprint 16 |

---

## 12. OPEN QUESTIONS

Each is a real fork. The stated default applies until decided.

| ID | Question | Default | Blocks | Decide by |
|---|---|---|---|---|
| **OQ-1** | **Fresh `laravel/laravel` skeleton, or a starter kit?** A Livewire starter kit brings opinionated auth scaffolding that may conflict with the K1/K2 `IdentityAccessAdapter` boundary and the mandatory-MFA model. | **Fresh skeleton** + explicit module structure per `overview.md` §5 | S1-T6 | Sprint 1 day 1 |
| **OQ-2** | **Octane?** `AGENTS.md` forbids it without an approved ADR **and** measured need. | **No.** Not in scope. Do not add. | — | Settled |
| **OQ-3** | **Where does CI run?** No pipeline exists. Repo is on GitHub; builds must happen off the 2/4 host. Hosted runners cost money; a self-hosted runner on this host would violate the build-off-host rule. | GitHub Actions, hosted runners | S1-T8 | Sprint 1 |
| **OQ-4** | **S3-compatible object storage — which provider?** Needed for staging backups (S2-T7) **and** later document uploads. Local MinIO on the 2/4 host is explicitly forbidden. | **Undecided — blocks S2-T7** | S2-T7, R-6 | **Before Sprint 2** |
| **OQ-5** | **Is `G-PAY-01` open, and are the FIN-DEC decisions approved?** Determines whether payment is online or manual-fallback-only. | Manual fallback only; no payment work in 1–4 | Sprint 8–9 | Before Sprint 8 |
| **OQ-6** | **Production managed PostgreSQL provider?** (N-3) ADR-0021 requires managed + PITR. | **Undecided — blocks production planning** | Sprint 16 | Before Sprint 10 |
| **OQ-7** | **Malware scanner for production?** Always-on ClamAV is forbidden on the 2/4 host; dev may use a deterministic mock. Production needs a real fail-closed scanner. | Mock in dev; production undecided | Sprint 5–6 | Before Sprint 5 |
| **OQ-8** | **Which issue tracker?** `AGENTS.md` says `tasks.md` is planning only and the tracker owns progress — but no tracker is named. | Undecided | S4-T9 | Sprint 1 |
| **OQ-9** | **Who is the human reviewer for the §9 gates,** and what is their availability? Ten gates across four sprints with a part-time reviewer is a real bottleneck. | Undecided | All gates | **Before Sprint 1** |
| **OQ-10** | **Is `ekspektasi-user` canonical?** It is the raw stakeholder workflow, currently untracked and un-ignored. Track it as canonical, or ignore it explicitly. | Track it | S1-T9 | Sprint 1 |
| **OQ-11** | **Content-Security-Policy** (N-2) — define now or defer? Deferring means retrofitting around Livewire/Alpine. | Define in Sprint 1–2 | design OQ-10 | Sprint 2 |
| **OQ-12** | **Is the 8-week calendar fixed, or is the scope fixed?** §10.3 — both cannot hold. | Undecided — **needs an explicit answer** | Everything | **Before Sprint 1** |

---

## 13. NOT TESTED / assumptions

Per `AGENTS.md`: *"Never report `PASS` for a check that was not executed; use `BLOCKED` or `NOT TESTED` explicitly."*

### Verified — executed, with evidence in this document

| Item | Result |
|---|---|
| Current infrastructure state (§1) | **PASS** — commands and output recorded |
| C-2 still broken at time of writing | **CONFIRMED** — DB list and secret-readability output in §0.3 |
| Pinned baseline exists: Laravel 13.22.0, Livewire 4.3.3, Filament 5.7.3, Horizon 5.48.1, Tailwind 4.3.3 | **PASS** — registry queries in §1.1 |
| Composer resolution on PHP 8.5: 109 packages, 0 problems | **PASS** — dry-run output in §1.1 |
| Frontend resolution: Tailwind 4.3.3 + Vite 7.3.6, no conflict | **PASS** — dry-run output in §1.1 |
| Design tokens: 46/46 WCAG AA pairs | **PASS** — `verify-contrast.py` exit 0 |

### NOT TESTED

| Item | Status |
|---|---|
| Actual `composer install` / `npm ci` (dry-run only; no `vendor/`, no `node_modules/`) | **NOT TESTED** |
| Laravel 13 boot, `artisan`, migrations | **NOT TESTED** — no application exists |
| Compose `uid`/`gid`/`mode` secret support on this Docker version | **NOT TESTED** — S1-T1 has a fallback for this reason |
| The S1-T3 DDL | **NOT EXECUTED** — written, not run; behind human gate G1 |
| Tailwind 4.3 generation of design-system §8.2 utilities | **NOT TESTED** — S2-T1 exists to close this |
| Filament 5 theming (design §8.3) | **NOT VERIFIED** — least-reliable area; S2-T3 |
| PHP 8.5 Docker base image availability by digest | **NOT VERIFIED** |
| Whether nginx subdomain vhosts can be added without disturbing live `makam.co.id` | **NOT TESTED** — R-1, gate G3 |
| Restore-from-backup viability | **NOT TESTED** — no backup exists |
| Host capacity with PHP-FPM + Horizon running | **NOT MEASURED** — S3-T9 |
| All effort estimates | **ESTIMATES, NOT MEASUREMENTS** |
| Sprint 5–17 outline (§8) | **INDICATIVE ONLY** — not planned at Sprint 1–4 confidence |
| `makam-notify.service` internals and its interaction with new vhosts | **BLOCKED** — unit file is root-only, `Permission denied` |
| Root cron | **BLOCKED** — `ubuntu` not in `/etc/cron.allow` |

### Assumptions this plan rests on

1. Team is 1 senior full-stack dev + AI assist + part-time human reviewer (§2.3).
2. The `AGENTS.md` baseline stands — no Octane, Kubernetes, SPA, GraphQL, Redis Cluster.
3. The stakeholder MVP in `mvp-scope.md` is unchanged and no item may be silently dropped.
4. The 2/4 host remains non-production only and is never cited as production capacity evidence.
5. No production data or credentials ever reach this host.
6. A human reviewer is available for the ten §9 gates without becoming the bottleneck (**OQ-9**).

---

## Appendix A — Finding → sprint traceability

| Finding | Severity | Sprint | Task | Notes |
|---|---|---|---|---|
| **C-1/C-2** | Critical | 1 | S1-T1, T2, T3, T4 | Root cause + fix + permanent detection |
| **H-1** | High | 1 | S1-T5 | Repo **example** volume path; deployed file is already correct |
| **H-2** | High | 1 | S1-T2 | Restore safe parameter binding |
| **H-3** | High | 2 | S2-T8 | 32 false `Covered` → `Documented` |
| **H-4** | High | 1 | S1-T6 | Scaffold + 8 artefacts — **the master blocker** |
| **H-5** | High | 1–2 | S1-T5, S2-T9 | Repo↔deployed drift; add drift detection |
| **M-1** | Medium | 2 | S2-T5 | nginx + DNS + allowlist ⚠️ G3 |
| **M-2** | Medium | 1 | S1-T9 | `.claude` permission bypass |
| **M-3** | Medium | 1 | S1-T9 | gitignore / untracked files |
| **M-4** | Medium | 2 | S2-T7 | Backup + **restore test** ⚠️ G5 |
| **M-5** | Medium | 2 | S2-T6 | Redis auth ⚠️ G4 |
| **M-6** | Medium | 3 | S3-T9 | Capacity review ⚠️ G6 |
| **L-1** | Low | 2 | S2-T6 | `pg_hba` trust (container-local only) |
| **L-2** | Low | 1 | S1-T9 | `compose.yml` mode 0660 |
| **L-3** | Low | 2 | S2-T5 | `git-askpass` sudo path + hardcoded username |
| **L-4** | Low | 2, 4 | S2-T9, S4-T8 | Version alignment to v0.6 |
| **L-5** | Low | 4 | S4-T8 | `CLAUDE.md` ↔ `AGENTS.md` |
| **L-6** | Low | 3 | S3-T10 | 5 open decisions ⚠️ G7 |
| **N-1** | Medium | 1 | S1-T3 | Separate app/migration roles — **new** |
| **N-2** | Medium | 1–2 | OQ-11 | No CSP defined — **new** |
| **N-3** | High | 4+ | OQ-6 | No managed Postgres provider — **new** |

## Appendix B — Weekly measurable progress

Because "every week has measurable progress" was an explicit goal:

| Week | Demonstrable at week's end |
|---|---|
| 1 | `makam_dev`/`makam_stg` exist with extensions; secrets readable by uid 999; healthcheck asserts schema |
| 2 | Laravel 13 boots; `migrate` succeeds; CI green on a PR; lockfiles committed |
| 3 | Tailwind build emits token-driven CSS; contrast gate blocking; first `<x-mk.*>` primitives rendering |
| 4 | `dev.`/`stg.` reachable over TLS with allowlist + `noindex`; Redis authenticated; restore evidence recorded |
| 5 | Seeds loaded; **FAQ complete** — public + admin CMS, browser-tested |
| 6 | Homepage 4 cards + booking Steps 1–5 with working autosave/resume |
| 7 | E2E-HOME/FAQ/BOOK(1–5) green; axe clean on delivered screens |
| 8 | Release-gate report published; rollback rehearsed; Sprint 5+ backlog groomed |
