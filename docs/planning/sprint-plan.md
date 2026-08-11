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

**Five sprints (10 calendar weeks nominal) does not deliver MVP acceptance.** [`release-gates.md`](../testing/release-gates.md) contains roughly 80 acceptance checkboxes spanning payments, notifications, marketplace, vendor portal, renewal at 100k-record search performance, MFA, quarantined uploads, transactional outbox, Horizon, audit, backup/PITR, and formal performance certification. Building that from zero code in ten weeks is not achievable by any team size that could be onboarded in that window.

What five sprints **does** deliver, and what this plan commits to:

> **A production-shaped walking skeleton with four honest public entry points, a working CI pipeline, hardened non-production infrastructure, an enforced design system, Tier-0 platform foundations (identity + mandatory MFA, feature gates, append-only audit, minimum outbox), and one complete vertical slice (FAQ) proving the whole stack end to end.**

That is a genuine, demonstrable, weekly-measurable outcome. §9 sets out the honest runway from there to MVP acceptance. Scaling the plan down or compressing it is a product decision, not an engineering one — this document states the cost so that decision is informed.

> **Why five and not four.** This plan originally had four sprints. It was written before the eight `platform-*` specs existed, when the cross-cutting foundations were unspecified and therefore invisible — so features appeared to start in Sprint 3. Specifying them made their consumers explicit: `platform-audit` is consumed by 17 of 19 feature specs, `platform-identity-and-access` by 16, `platform-feature-gate` by 12. §3.4 derives the consequence. Foundation implementation now has its own sprint rather than being stubbed inside a feature sprint and torn out later. The honest cost of that alignment is **+21.5 pd and +2 nominal weeks** (§11).

### 0.3 The single most urgent item — ✅ RESOLVED 25 Jul 2026

> **Status update.** C-2 was **executed and verified on 25 Jul 2026**, under recorded human approval for Route A only (manual DDL, non-destructive; the `postgres_data` volume was preserved and Route B was never run). What follows is the original diagnosis, kept for the record. The verified outcome:
>
> - Secret ownership fixed via host `chown 999:999` — the declarative Compose fix proved **unsupported on this host** (see S1-T1).
> - `makam_dev` and `makam_stg` created, owned by `makam_dev_user` / `makam_stg_user`.
> - `pg_trgm 1.6` + `unaccent 1.1` installed in both; `similarity()` confirmed working, so ADR-0007 now has its foundation.
> - Environment isolation verified by **5/5 negative tests** — `dev→stg`, `stg→dev`, and `dev→postgres` all `DENIED`; `PUBLIC` CONNECT revoked on the maintenance databases too.
> - **Schema-aware healthcheck** installed and verified in **both** directions: exit 0 when correct, exit 1 when a database or an extension is missing. `pg_isready` alone can no longer report healthy over a missing schema.
> - Data survived a container recreate, which independently confirms the deployed volume mount is correct (contrast H-1, the repo example).
> - Init script realigned to bound parameters (H-2), so a future volume recreate reproduces this state instead of the original failure.
>
> Rollback path: `/opt/makam/compose/compose.yml.bak.20260725_042315Z` and `postgres-init/01-create-databases.sh.bak.20260725_042315Z`.
>
> **Still open:** finding N-1 — `database-backup-and-recovery.md` §8 wants separate application and migration roles. Only two secrets exist, so one role per environment was created. The split needs two newly provisioned secrets, which is a credential change requiring its own human approval.

**Original diagnosis — C-2 was broken.** As verified at the time of writing:

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

Restructured 25 Jul 2026 from four sprints to five. The eight `platform-*` foundation specs made their consumers explicit, and §3.4 shows that three of them are prerequisites for **every** feature — so foundation implementation earned its own sprint instead of being stubbed inside a feature sprint and torn out later.

| Sprint | Weeks | Goal | Specs implemented | Findings closed |
|---|---|---|---|---|
| **1 — Foundation** | 1–2 | Code exists, DB exists, CI exists | scaffold for all | H-4, **C-2 ✅**, **H-2 ✅**, H-1, M-2, M-3 |
| **2 — Design + infra hardening** | 3–4 | Design system enforced; non-prod trustworthy | design system | M-1, M-4, M-5, H-3, L-4 |
| **3 — Tier-0 foundations** ← *new* | 5–6 | Identity, gates, audit, outbox — nothing ships before these | `platform-identity-and-access`, `platform-feature-gate`, `platform-audit`, `platform-outbox` (min) | — |
| **4 — MVP vertical slices** | 7–8 | Four public entry points reachable and honest | `public-faq`, `public-home-and-navigation`, `cemetery-directory…`, `public-booking-wizard` (1–5), `booking-and-order-orchestration` (draft) | M-6, L-6 |
| **5 — Test, a11y, gate dry-run** | 9–10 | Evidence, not assertions | — | L-5, release-gate baseline report |

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

## 2. Scope reality — what five sprints includes and excludes

### 2.1 In scope

| Area | Sprint 5 end state |
|---|---|
| Laravel 13 skeleton, all 8 baseline artefacts | ✅ Complete, lockfiles committed |
| CI pipeline | ✅ Core stages of `ci-cd-and-release.md` §2 running and blocking merges |
| `makam_dev` + `makam_stg` databases, roles, extensions, isolation | ✅ Created, cross-access negative-tested |
| Non-prod hardening: nginx dev/stg, `noindex`, Redis auth, backups + restore test. **`dev.` access restriction was later reversed by explicit decision — dev is now intentionally public; see ADR-0031.** `stg.` access restriction is unaffected. | ✅ Done |
| Design system wired and CI-enforced | ✅ Tokens → Tailwind → Filament; contrast gate blocking |
| `<x-mk.*>` primitives | ✅ Buttons, fields, cards, modals, tables, badges, alerts, stepper, header |
| Homepage — 4 service cards, exact order, 5 launch regions | ✅ Complete |
| **FAQ — complete vertical slice** (6 categories, public list/filter/search/detail, admin CMS, publish/unpublish) | ✅ Complete, browser-tested |
| Booking wizard — Steps 1–5 with autosave/resume | ✅ Working, no payment |
| Renewal — city/cemetery selection + search UI with honest empty state | ⚠️ Skeleton (no tariff, no payment) |
| Marketplace — category/product browse from seeded canonical catalog | ⚠️ Skeleton (no cart, no checkout, no vendor portal) |
| Browser tests E2E-HOME, E2E-FAQ; partial E2E-BOOK | ✅ Passing |
| Accessibility: axe, keyboard, 200 % zoom, touch targets | ✅ On delivered screens |
| **Tier-0 foundations** — identity + mandatory MFA, feature gates, append-only audit, minimum outbox | ✅ Implemented and tested (Sprint 3) |

### 2.2 Explicitly NOT in scope for Sprints 1–5

Deferred to Sprint 6+ (§9), now stated as **specs** rather than loose features, so the boundary is checkable:

**Foundations deferred:** `platform-notifications` · `platform-document-vault` (blocked on OQ-4) · `platform-payment-adapter` (blocked on OQ-5 / FIN-DEC) · `platform-financial-ledger` (blocked on FIN-DEC) · full `platform-outbox` (Horizon supervisors, bounded replay, 10k-import isolation).

**Feature specs deferred entirely:** `funeral-marketplace-and-vendor-portal` (cart, checkout, **vendor portal**) · `funeral-case-management` · `at-need-booking` · `package-and-service-bundles` · `cemetery-operator-dashboard` · `recurring-care-subscriptions` · `grave-care-fulfillment` · and all five gated specs (`pre-need-contracting`, `plot-inventory-and-reservation`, `certificates-and-agreements`, `visitation-booking`, `memorial-and-qr`).

> **Correction, 09 Aug 2026 (Wave 0 ruling 0a):** the full deferral above of `funeral-marketplace-and-vendor-portal` cart/checkout/vendor-portal to Sprint 11–12 is overridden by `docs/product/mvp-scope.md:35` (`Cart dan checkout`) — a stakeholder MUST IMPLEMENT item per `mvp-scope.md:5` and `AGENTS.md` §Source precedence (mvp-scope ranks above sprint plans; never remove an MVP item because an external gate is closed — implement the documented fallback). Cart/checkout is in MVP scope; the Sprint 11–12 deferral below is a resourcing note, not a scope decision. See `docs/superpowers/plans/2026-08-09-wave0-decisions.md` Task 1.

**Partially deferred:** `public-booking-wizard` Steps 6–9 · `booking-and-order-orchestration` order state machine, quotation, payment guard · `renewal-and-grave-registry` tariff, payment, duplicate-period guard, 10k import, 100k fuzzy-search certification · `admin-operations` beyond the FAQ CMS.

**Also out:** performance certification (Profiles A–D) · production environment of any kind.

### 2.3 Assumption this plan runs on

> **Assumed team: 1 senior full-stack Laravel/Livewire/Filament developer, working with AI-agent assistance, plus a part-time human reviewer with authority over the §10 gates.** All effort figures are person-days (pd) on that basis. §11 gives calendar sensitivity for other team sizes. If the real team differs, re-baseline §11 before committing to dates — do not compress the sprint contents to fit.

### 2.4 Spec ↔ sprint mapping — all 27 specs

Added 25 Jul 2026 when the corpus went from 19 to 27 specs. **Every spec is accounted for.** A spec with no sprint is a planning defect; a sprint task with no spec is scope creep.

**Platform foundations (8)** — implemented in Sprint 3, because nearly every feature consumes them:

| Spec | Sprint | Note |
|---|---|---|
| `platform-identity-and-access` | **3** | Prerequisite for every authenticated surface |
| `platform-feature-gate` | **3** | Prerequisite for every gated fallback and explanatory page |
| `platform-audit` | **3** | Required by 13 feature specs |
| `platform-outbox` | **3** (min) / 6 (full) | Minimum publisher in S3; Horizon supervisors + replay in S6 |
| `platform-notifications` | 6 | Blocks booking Step 9 |
| `platform-document-vault` | 6 | Blocks booking Step 7; **gated on OQ-4 object storage** |
| `platform-payment-adapter` | 8–9 | Blocks Step 8; **gated on OQ-5 / `G-PAY-01`** |
| `platform-financial-ledger` | 8–9 | **Gated on FIN-DEC approvals** |

**MVP-required feature specs (8):**

| Spec | Sprint | Depends on foundations |
|---|---|---|
| `public-home-and-navigation` | **4** | identity (light), feature-gate |
| `public-faq` | **4** | identity, audit, feature-gate |
| `cemetery-directory-and-availability` | **4** | identity, audit, feature-gate |
| `public-booking-wizard` | 4 (Steps 1–5) / 6–7 (6–9) | all eight |
| `booking-and-order-orchestration` | 4 (draft/quote) / 7 | all eight |
| `renewal-and-grave-registry` | 4 (search) / 13 (payment) | identity, audit, feature-gate, document-vault, payment |
| `admin-operations` | 4 (FAQ CMS) / 7 (full) | all eight |
| `funeral-marketplace-and-vendor-portal` | 11–12 | identity, audit, payment, ledger, document-vault, notifications |

**RKS-derived and benchmark-derived (6):** `package-and-service-bundles` S7 · `cemetery-operator-dashboard` S7 · `funeral-case-management` S7 · `at-need-booking` S7 · `recurring-care-subscriptions` S13 · `grave-care-fulfillment` S13.

**Gated / optional (5)** — not MVP acceptance, scheduled only if the gate opens: `pre-need-contracting` (`G-LEGAL-01`) · `plot-inventory-and-reservation` (`G-PLOT-01`) · `certificates-and-agreements` (`G-CERT-01`) · `visitation-booking` (`G-VISIT-01`) · `memorial-and-qr` (`G-MEM-01`).

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
6. **Payment work cannot start until `G-PAY-01` status is known** and the FIN-DEC decisions in `release-gates.md` §H are approved. This is why no payment work appears in Sprints 1–5.
7. **H-1 is a documentation fix, not a runtime fix.** The *deployed* compose mounts the volume correctly; the *repo example* does not. Fixing the doc prevents future data loss — it does not repair anything currently running. Do not "fix" the deployed file to match the broken example.
8. **No feature spec may be implemented before the foundations it consumes** (§3.4). This is the rule that forced Sprint 3 to exist.

### 3.3 Longest chain

```
S1-T1 → S1-T2 → S1-T3(gate) → S1-T4 → migrate → S2-T1 → S2-T2
      → S3-T1(identity) → S3-T3(audit) → S4-T2(FAQ) → S5-T1(tests) → S5-T5(gate report)
```

Everything else can be scheduled around this chain.

### 3.4 Foundation dependency order — derived from the specs

This section is why the plan changed shape on 25 Jul 2026. The original four-sprint sequence had features starting in Sprint 3, because at the time the eight cross-cutting foundations were **unspecified and therefore invisible**. Now that they are specified, their consumers are explicit, and an order falls out of the specs rather than out of preference.

Which foundations each of the 19 feature specs genuinely needs in order to function:

| Foundation | Feature specs that need it | Verdict |
|---|---:|---|
| `platform-identity-and-access` | **19 of 19** | Every spec has an authenticated or record-scoped surface |
| `platform-audit` | **~18 of 19** | Nearly every spec has at least one action requiring an audit record |
| `platform-feature-gate` | **16 of 19** | Every gated capability, mode value, and explanatory page |
| `platform-notifications` | ~12 | Anything that tells a customer, operator, or vendor something |
| `platform-outbox` | ~10 | Anything emitting a domain event |
| `platform-document-vault` | ~10 | Anything with an upload or a private file |
| `platform-payment-adapter` | 6 | Money paths only |
| `platform-financial-ledger` | 5 | Money paths only |

> **These figures are reasoned, not measured — read this before citing them.**
>
> An earlier revision of this section presented keyword-frequency counts as if they were dependency measurements. That was wrong in three compounding ways, and the numbers above replace them:
>
> 1. **File counts were reported as spec counts.** `kiro-specs-analysis.md` §2.2 reported "13 specs" for audit; the underlying `grep -ril` actually matched **13 files across 9 specs**.
> 2. **The counts were then inflated without re-measuring.** This table previously read "17 of 19" for audit — a figure with no measurement behind it. Caught 25 Jul 2026 by a subagent writing ADR-0029, which found the contradiction between this table and three other documents and reported it rather than picking one.
> 3. **Keyword counts are now self-contaminated.** The `## Design system` sections appended to all 19 `tasks.md` on 25 Jul 2026 contain the words *audit*, *authorization*, *pending*, and *feature-gate*. Re-running the old patterns partly measures those additions.
>
> More fundamentally: **keyword frequency was never a valid metric for sequencing.** A spec that needs an admin panel needs identity whether or not it ever writes the word "MFA". Keyword counts were a useful *discovery* heuristic — they are what surfaced the gap — but the ordering has to rest on reasoned dependency, which is what the table now states.
>
> The one keyword fact worth keeping, because it was the discovery signal and is reproducible against the pre-change tree: **`outbox` appeared 0 times in the entire `.kiro/` tree** before these specs existed, despite `AGENTS.md` making the transactional outbox mandatory.
>
> The tiering below is unaffected — the reasoned figures support it more strongly than the keyword counts did.

Which gives four tiers:

```
TIER 0  identity + feature-gate + audit          ← Sprint 3. NOTHING ships before these.
           │
TIER 1  outbox (minimum publisher)               ← Sprint 3, thin slice
           │
TIER 2  notifications + document-vault           ← Sprint 6. Unlocks Step 7 and Step 9.
           │
TIER 3  payment-adapter + financial-ledger       ← Sprint 8–9. Gate-blocked regardless.
```

**The consequence for Sprint 4 features is concrete, not theoretical.** Even the three specs graded READY in `kiro-specs-analysis.md` §7.2 have Tier-0 dependencies:

- `public-faq` needs **identity** (admin CMS is a privileged surface, so mandatory MFA applies), **audit** (publish/unpublish is an audited authorization action), and **feature-gate** (AC7: payment and Urgent content must reflect active gates).
- `public-home-and-navigation` needs **feature-gate** (AC6 explanatory pages, and the truthful Urgent banner).
- `cemetery-directory-and-availability` needs **feature-gate** (capability modes), **audit** (AC9 operator updates), **identity** (operator scope).

So "FAQ first" remains correct as the first *feature*, but it is no longer the first *build*. Tier 0 comes first. Attempting FAQ before it means stubbing auth, gates, and audit — three stubs that would each have to be torn out later, in the one slice whose entire purpose is to prove the stack end to end.

---

## 4. Sprint 1 — Foundation (Weeks 1–2)

### Goal

> From zero code and a broken database to: a Laravel 13 application that boots, connects to a real `makam_dev` database, and passes a CI pipeline — with the silent-failure class of defect made loud.

### Tasks

| ID | Task | Spec | Findings | Effort | Gate | Status |
|---|---|---|---|---:|---|---|
| S1-T1 | Fix Docker secret ownership so uid 999 can read `/run/secrets/*` | infra | C-2 root cause | 0.5 pd | — | ✅ done |
| S1-T2 | Replace deployed init script with the repo's safe parameter-bound version | infra | **H-2** | 0.5 pd | — | ✅ done |
| S1-T3 | Create `makam_dev`/`makam_stg` + roles + `pg_trgm`/`unaccent` + connect isolation | infra | **C-2** | 1 pd | ⚠️ **HUMAN** | ✅ done (G1, Route A) |
| S1-T4 | Schema-aware healthcheck + `/health/ready` smoke so this cannot fail silently again | infra | **C-2** | 1 pd | — | ✅ done |
| S1-T5 | Fix Postgres volume path in repo compose example | docs | **H-1** | 0.5 pd | — | ✅ done (subagent, 25 Jul) |
| S1-T6 | Laravel 13 scaffold + all 8 baseline artefacts; establish `overview.md` §5 module namespaces | all | **H-4** | 4 pd | — | ✅ done 25 Jul — see note below |
| S1-T7 | `.env.dev` / `.env.stg` with separated APP_KEY, DB user, Redis prefix, queue, cookie, storage | `platform-identity-and-access` | H-4, M-1 | 1 pd | ⚠️ **HUMAN** | ❌ open |
| S1-T8 | CI pipeline skeleton (GitHub Actions) | all | H-4 | 2 pd | — | ✅ done — `.github/workflows/ci.yml` + `ci/verify-docs.sh` |
| S1-T9 | `.gitignore` + `.claude/settings.json` hardening; commit design-system files | repo | **M-2, M-3** | 0.5 pd | — | ✅ done (M-2 by subagent; `.gitignore` fixed for scaffold) |
| S1-T10 | ADR-0028 (design system) + ADR-0029 (platform foundation specs) + ADR-0030 (scaffold) | all | L-6 | 1 pd | — | ✅ done — 3 of 3 |

**Total: ~12 pd · 9 of 10 done (25 Jul 2026)**

Remaining: **S1-T7** (`.env.dev`/`.env.stg`, APP_KEY generation — behind human gate **G2**, deliberately untouched) and the scaffold half of **S1-T10**.

> **S1-T6 scaffold — what was and was not done.** The authentic `laravel/laravel` skeleton was used per the OQ-1 documented default (fresh skeleton, no starter kit), giving `laravel/framework ^13.8`. All eight `technology-baseline.md` §5 artefacts now exist. `php` was tightened from the skeleton's `^8.3` to the baseline `~8.5.0`, and Livewire 4, Filament 5, and Horizon 5 were added.
>
> Lockfiles were generated by **resolution only** — `composer update --no-install` and `npm install --package-lock-only` — so no `vendor/` or `node_modules/` was downloaded on this host. That is deliberate: `ci-cd-and-release.md` §10 forbids heavy builds on the 2 vCPU / 4 GB combined host. Resolved: `laravel/framework v13.22.0`, `livewire/livewire v4.3.3`, `filament/filament v5.7.3`, `laravel/horizon v5.48.1`, `tailwindcss 4.3.3`, `vite 7.3.6`. Composer reported **0 security advisories**.
>
> **NOT TESTED:** the application has never booted. `composer install`, `npm ci`, `npm run build`, `php artisan`, and `docker build` have all **not been run** — the first real execution is the CI pipeline's job. Laravel's default `welcome.blade.php` was **deleted** rather than suppressed, because it hardcodes colours and Tailwind arbitrary values that the token gates reject, and because `AGENTS.md` requires the homepage to present exactly four services in a fixed order — a placeholder at `/` would be a false product claim. The two default `ExampleTest` stubs were deleted for the same reason: they would have satisfied the "test evidence exists" condition in doc gate 7 without evidencing anything.

> **Alignment note.** S1-T6 must create the module namespaces from `overview.md` §5 *including the eight `platform-*` boundaries*, even as empty directories. Retrofitting module boundaries after features exist does not happen in practice.

### Task detail

**S1-T1 — Docker secret ownership. ✅ EXECUTED 25 Jul 2026.**

The declarative fix (`secrets` long syntax with `uid`/`gid`/`mode`) was tried first and **does not work on this host.** Docker Compose parses the keys, then discards them:

```
$ docker compose up -d --force-recreate postgres
warning  secrets `uid`, `gid` and `mode` are not supported, they will be ignored
```

Readability by uid 999 was still `NOT readable` afterwards. **The host-ownership fallback is therefore the actual fix, not the backup plan:**

```bash
sudo chown 999:999 /opt/makam/compose/secrets/*.txt
sudo chmod 0400   /opt/makam/compose/secrets/*.txt
```

Verified immediately after: all three `READABLE ✅` by uid 999. The change is reflected **live in the running container** — Compose file-secrets are bind-mounted, so no recreate is needed for ownership alone.

Do **not** `chmod 0444` — that makes a database credential world-readable on the host. Ownership must move to uid 999; the mode stays `0400`.

`compose.yml` now carries a `SECRET OWNERSHIP` comment recording this, because the requirement is invisible from the file otherwise and will silently break again if the secret files are ever restored or regenerated.

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

| ID | Task | Spec | Findings | Effort | Gate | Status |
|---|---|---|---|---:|---|---|
| S2-T1 | Wire `tokens.css` → Tailwind 4.3; verify **every** utility in design-system §8.2 generates | design system | design §12 | 2 pd | — | ✅ done 25 Jul (CI-enforced) |
| S2-T2 | Build `<x-mk.*>` primitives (button, field, card, modal, table, badge, alert, stepper, header) | design system | design §3 | 4 pd | — | ✅ done 25 Jul (all 9 built, reviewed, merged) |
| S2-T3 | Verify Filament 5 theming; implement `StatusIntent`; resolve OQ-09 palette duplication | design §3.7 + `booking-and-order-orchestration` | design §8.3, OQ-09 | 2 pd | — | ✅ done 25 Jul (Batch 2.4) — panel boots in CI against real Filament 5.7.3, `StatusIntent` 26 tests green, OQ-09 resolved via generator |
| S2-T4 | Add all six design governance gates to CI, incl. `verify-contrast.py` as hard fail | design system | design §9.5 | 1 pd | — | ✅ done 25 Jul (Batch 2.5) — gates 1–3 pre-existing, 4/5 added as verify-docs.sh GATE 11/12, 6 wired into ci.yml's `php` job |
| S2-T5 | nginx dev/stg vhosts + DNS + IP allowlist + TLS + `noindex` | infra | **M-1** | 2 pd | ⚠️ **HUMAN** | ⚠️ **dev done 25 Jul (allowlist later removed by decision, ADR-0031); stg vhost + runbook prepared 25 Jul (Batch 2.6A) — still blocked on DNS confirmation, not deployed** |
| S2-T6 | Redis `requirepass` + separate prefixes/namespaces per environment | infra, `platform-outbox` prep | **M-5** | 1 pd | ⚠️ **HUMAN** | ⚠️ **prepared 25 Jul (Batch 2.6B) — runbook + compose snippet ready; not applied, needs a live restart human gate G4** |
| S2-T7 | Encrypted daily staging backup to remote object storage + **restore test with evidence** | infra | **M-4** | 2 pd | ⚠️ **HUMAN** | ⚠️ **prepared 25 Jul (Batch 2.6C) — script + restore runbook ready; still blocked on OQ-4 (no object storage provider chosen)** |
| S2-T8 | Downgrade the 32 false `Covered` claims in the traceability matrix | traceability | **H-3** | 1 pd | — | ✅ done (commit f82b3c7, six-agent doc batch) — all 31 items read `Specified`; `ci/verify-docs.sh` GATE 7 has enforced `Covered=0` all session. This status line itself was the only stale part. |
| S2-T9 | Align document versions to v0.6; register `docs/design/` + `platform-*` in Kiro steering | steering, `docs/specs/README.md` | **L-4**, design OQ-11 | 0.5 pd | — | ✅ done — steering registers `docs/design/design-system.md` + `tokens.css`; `docs/specs/README.md` §"Document versioning convention" (recorded for L-4) formalizes that each document keeps its own version and a per-document version below the v0.6 package baseline is expected, not a defect — so "align to v0.6" meant documenting that rule, not mass-editing every header. Checked before acting: bumping all 26 sub-v0.6 documents' headers would have been the wrong fix. |
| S2-T10 | Basic observability: structured logs, container/memory/swap/disk monitoring | infra | M-6 prep | 1.5 pd | — | ✅ done 25 Jul (Batch 2.7) — JSON log channel, `observability.md`, `monitoring-check.sh` verified against the live host |

**Total: ~19 pd** — the heaviest sprint relative to its length. See §11; this is the most likely candidate for a 3-week Sprint 2.

> **Alignment note.** S2-T3 delivers `StatusIntent`, which is the shared status → intent resolver mandated by design-system §3.7 and now referenced by **eight** specs (`booking-and-order-orchestration`, marketplace, admin, case-management, care ×2, plot-inventory, pre-need). It must be built once here, not per feature.

### Task detail

**S2-T1 — Validate the design system's own NOT TESTED list.** `design-system.md` §12 states plainly that no Tailwind build has run. This task closes that. Every utility asserted in §8.2 (`max-w-form`, `duration-fast`, `z-modal`, `h-13`, `xs:`, `border-neutral-450`, `ease-standard`) must be proven to generate on Tailwind **4.3.3**. **Where reality differs, fix `design-system.md`** — do not fix the code to match a wrong document.

**S2-T3 — Filament 5 (the least-verified area).** `design-system.md` §8.3 is explicitly flagged as the least reliable section. Verify the theme path, the `vendor/filament/.../theme.css` import target, `LocalFontProvider`, and `Color::hex()`. Then close **OQ-09**: Filament resolves colours in PHP and cannot read CSS variables, so hex values are currently duplicated. Build the generator + CI diff so `tokens.css` stays the single source of truth.

Implement `StatusIntent` as the one place status → intent resolution happens (design §3.7), shared by public Livewire views and Filament tables. This must exist **before** any status is rendered anywhere.

**S2-T5 — nginx / DNS / allowlist (⚠️ HUMAN GATE).** Touches DNS and firewall — both are required-pause conditions in `AGENTS.md` and the execution checklist. Deliver: `dev.makam.co.id` → `127.0.0.1:8081`, `stg.makam.co.id` → `127.0.0.1:8082`, restart the placeholder containers (or the real app image once it exists), IP allowlist or basic auth for dev, TLS via Certbot, `X-Robots-Tag: noindex` on both.
>
> **Updated 25 Jul 2026.** Basic auth was delivered for dev as scoped above, then explicitly reversed the same day by user decision — dev is now intentionally public, `noindex` only. See [ADR-0031](../adr/0031-make-dev-environment-public.md). `stg`'s allowlist/auth requirement is unaffected by this reversal.

> **Take care:** `makam.co.id` currently serves a **live** static landing page plus `makam-notify.service` on `:3001`. Adding subdomain vhosts must not disturb it. Back up nginx config first; keep a rollback path; verify the apex still returns 200 afterwards.
>
> **DNS ownership must be confirmed before any record change** — ambiguity is an explicit pause condition.

**S2-T6 — Redis auth (⚠️ HUMAN GATE).** Currently no `requirepass`. Risk is mitigated (internal network, no published port) but it breaches `security-baseline`. Requires a restart, so treat as a change with a rollback plan. Also establish distinct Redis prefixes, queue names, and Horizon namespaces per environment — required by `release-gates.md` §I and impossible to retrofit cleanly once queues carry data.

**S2-T7 — Backup + restore (⚠️ HUMAN GATE).** Per `database-backup-and-recovery.md` §9: staging gets daily encrypted logical backups to **remote** object storage, ≥ 7 days retention. **"A backup is not considered valid until restored"** (§4) — so this task is not done until a restore has been executed and evidence recorded per §5 (version, extensions, migration state, row counts, smoke test, sign-off). Local Docker volumes are explicitly **not** backups.

**S2-T8 — Fix the false coverage claims (H-3).** The traceability matrix asserts `Covered` on 32 items with zero tests in existence. `AGENTS.md`: *"Every traceability item marked `Covered` needs test evidence."* Introduce `Documented` / `Specified` and re-label. This is a small edit with outsized value: it stops the project reporting readiness it does not have — the same failure mode as C-2's silent health.

### Deliverable

Design system enforced by CI. `dev.` and `stg.` reachable, TLS-terminated, `noindex`. `stg.` access-restricted; `dev.` intentionally public by decision (ADR-0031). Redis authenticated and namespaced. Staging backup running with a **recorded successful restore**. Traceability honest.

### Definition of Done

- [ ] `npm run build` produces CSS using tokens; **zero** hardcoded hex outside `tokens.css`
- [ ] Every §8.2 utility verified generated, or `design-system.md` corrected
- [ ] All nine `<x-mk.*>` primitives exist with their documented states
- [ ] Filament panel renders with brand palette; `StatusIntent` is the sole resolver
- [ ] All six §9.5 CI gates active; `verify-contrast.py` blocks merge
- [ ] `https://dev.makam.co.id` and `https://stg.makam.co.id` return 200, TLS valid, `noindex` header present; `stg.` allowlist enforced, `dev.` intentionally unrestricted (ADR-0031)
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

## 6. Sprint 3 — Tier-0 foundation implementation (Weeks 5–6)

> **This sprint did not exist in the original plan.** It was inserted on 25 Jul 2026 when the eight `platform-*` specs were authored and their consumers became explicit. §3.4 derives why: `platform-audit` is consumed by 17 of 19 feature specs, `platform-identity-and-access` by 16, `platform-feature-gate` by 12. Nothing ships before them, so they get their own sprint rather than being stubbed inside a feature sprint and torn out later.

### Goal

> Tier 0 exists and is enforced: an authenticated actor with resolved scope, a server-side gate registry with documented fallbacks, an append-only audit that cannot be bypassed, and a minimum outbox publisher — all with tests, before any feature consumes them.

### Tasks

| ID | Task | Spec | Effort | Gate | Status |
|---|---|---|---:|---|---|
| S3-T1 | `ActorContext` resolved once per request; session guard for public and each panel | `platform-identity-and-access` AC1, AC8 | 2 pd | — | ✅ done 26 Jul (Batch 3.1) — `ActorContext`/`ActorContextResolver`/`IdentityAccessAdapter` interface + MVP local-users adapter, `actor_sessions` table, `/admin` panel access policy. Roles/scopes deliberately empty (no owning table yet — flagged, not invented); MFA/re-auth/revoke-all explicitly deferred to later tasks |
| S3-T2 | TOTP enrolment/challenge/recovery; **mandatory MFA for all privileged roles** | `platform-identity-and-access` AC2, AC6 | 3 pd | ⚠️ **HUMAN** | ⚠️ **partial (mechanism prepared, not enabled)**, done 26 Jul (Batch 3.6) — `app/Platform/IdentityAccess/Mfa/**`: pure-PHP TOTP (RFC 6238/4226, no new Composer dependency — verified against both RFCs' official test vectors), replay-safe `verify()` (a consumed time-step is never re-accepted even if still mathematically valid), `mfa_enrolments`/`mfa_recovery_codes` (bcrypt-hashed, one-time)/`mfa_challenges` tables, enrolment/challenge/recovery services, rate limiting (5/60s via `RateLimiter`), full `Audit::record()` integration with a dedicated adversarial test proving no secret/code ever reaches `audit_events.metadata`. `ActorContext::$mfaState` now reports real enrolment status. **Not done, by design — this is the human gate**: nothing enforces MFA anywhere; `AdminPanelAccessPolicy` and every login/panel-access flow are untouched; mandatory MFA for privileged roles (this row's own bolded requirement) needs both the still-missing local roles table (see S3-T1's own flagged gap) and a human decision to enable enforcement |
| S3-T3 | Re-authentication middleware for the six sensitive action classes | `platform-identity-and-access` AC3 | 1 pd | ⚠️ **HUMAN** | ⚠️ **partial (mechanism prepared, not enabled)**, done 26 Jul (Batch 3.7) — `config/reauthentication.php` (`freshness_seconds`, env-overridable via `REAUTHENTICATION_FRESHNESS_SECONDS`, default 900s/15min — a judgement call, documented in the config file itself, since neither AC3 nor `docs/security/authentication-and-mfa.md` §5 names a number); `reauthentication_events` table (own append-only log, deliberately separate from Batch 3.6's `mfa_challenges` — a re-authentication CHALLENGE event, not an MFA verification attempt) + `ReauthenticationEvent` model; `App\Http\Middleware\RequireRecentAuthentication` (compares `ActorContext::$lastAuthenticatedAt` against the config window; a null timestamp is always treated as STALE, never as "never expires"; preserves `url.intended` via Laravel's own `Authenticate`-middleware convention; redirects to a route name supplied as a middleware parameter — `RequireRecentAuthentication::class.':reason,routeName'` — rather than guessing or registering one, since no real challenge route exists anywhere in this repo yet); `Reauthentication\ReauthenticationService` (`challenge()`/`satisfy()`, same dual-write `Audit::record()` pattern as `Mfa\MfaChallengeService`, reusing `Mfa\MfaRateLimiter` under its own `'reauthentication-challenge'` context — throttles WRITE volume, since a challenge is raised automatically on every stale request rather than a deliberate user action like an MFA code submission; the redirect/security behaviour itself is never gated by rate-limit state). Tests under `tests/Feature/IdentityAccess/Reauthentication/**`: fresh actor passes through untouched; null and stale timestamps both redirect and set `url.intended`; challenge and satisfy both write their `reauthentication_events` + `audit_events` pair; the config value change (900s → 60s at runtime) genuinely flips middleware behaviour for the same underlying timestamp; rate limiter blocks the 6th write in a window and `satisfy()` clears it; no secret/credential-shaped metadata key ever appears. **NOT TESTED** (no Postgres on this host per `CLAUDE.md` §10 — verified with `php -l` only; real `php artisan test` run happens in CI). **Not done, by design — this is the human gate**: the middleware is not appended to `bootstrap/app.php`'s `web` group, not added to `AdminPanelProvider.php`'s middleware array, and not attached to any route — a test proves it is absent from the real `web` group. **Genuinely unbuilt**, same honesty gap S3-T2 already flagged: no real challenge UI/controller exists (no login/password-confirmation form anywhere in this repo), and none of the six sensitive-action classes (`docs/security/authentication-and-mfa.md` §5) have a real controller/route yet, so this can only be proven against a fixture route in tests, never end-to-end against a real sensitive action. Whether a future challenge controller routes an enrolled actor to `Mfa\MfaChallengeService` versus a password re-entry form is documented as that controller's own decision (`ReauthenticationService`'s class-level doc block), not decided by this batch | **Correction, 09 Aug 2026 (retrofit): both mechanisms now have real callers — narrower than this row's original bolded requirements, deliberately.** Retrofitted per `AGENTS.md` §Development methodology via its own `brainstorming` pass (`docs/superpowers/specs/2026-08-09-mfa-reauthentication-integration-design.md`) and `docs/superpowers/plans/2026-08-09-mfa-reauthentication-integration.md`, PR [#7](https://github.com/andrianm28/makam-app/pull/7), CI run [`31299824997`](https://github.com/andrianm28/makam-app/actions/runs/31299824997) (all 8 jobs green on PostgreSQL 18, 901/901 tests). Ships: voluntary self-service TOTP MFA for any `/admin` user (`MfaSettings`/`MfaChallenge` Filament pages, `EnforceMfaChallenge` middleware enforcing a login-time challenge once enrolled) and `RequireRecentAuthentication`'s first real attachment anywhere in this repo (`DisableMfaController`, gating MFA disable via a fresh `MfaChallengeService` challenge, not password re-entry). **Does NOT close either row's original bolded requirement**: "mandatory MFA for all privileged roles" and "the six sensitive action classes" both stay explicitly out of scope — blocked on a role model that still does not exist (`ActorContext::$roles` is still always `[]`) and on those six action classes still having no real controller anywhere in the repo, per this retrofit's own design doc Non-goals. `docs/planning/retrofit-backlog.md` §1 item 2 records the full disposition. One real CI-caught bug during this retrofit (a Laravel `scoped()`-container-binding testing artifact, not a production defect — full diagnosis in the retrofit's own commit history) was found, correctly diagnosed, and fixed before merge. |
| S3-T4 | Scope assignment model + mandatory query scopes per `rbac-matrix.md` | `platform-identity-and-access` AC5 | 2 pd | — | ✅ done 26 Jul (Batch 3.2 Agent C) — Eloquent global scope, closed-by-default (empty `whereIn` on zero grants). `ActorContext::$scopes` wiring is a flagged one-line follow-up in the already-merged adapter, not this batch's to make |
| S3-T5 | Gate + flag registry from `assumptions-and-gates.md`; server evaluation, deny-by-default | `platform-feature-gate` AC1, AC2, AC10 | 2 pd | — | ✅ done 26 Jul (Batch 3.2 Agent B) — 17 gates + 18 flags seeded as real rows; deny-by-default structurally enforced (private constructor, only `fromRecord()` can produce `open: true`) |
| S3-T6 | Expose `PaymentMode` / `WhatsAppMode` / `PreNeedMode` / `GraveSearchMode` as mode values | `platform-feature-gate` AC7 | 1 pd | — | ✅ done 26 Jul (Batch 3.2 Agent B) — backed enums via `ModeResolver` |
| S3-T7 | Gate-closed explanatory-page pattern (design §6.4) + `intent=info` banner (design §6.9) | `platform-feature-gate` AC5 + design §6 | 1 pd | — | ✅ done 26 Jul (Batch 3.2 Agent B) — `<x-mk.gate-closed-banner>` extends `<x-mk.alert>`, `<x-mk.gate-closed-page>` reuses §6.2's empty-state recipe; independently verified against the real (not stale) `alert.blade.php` prop signature |
| S3-T8 | `audit_events` with **database-level** append-only grants; single `Audit::record()` API | `platform-audit` AC1, AC2 | 2 pd | — | ⚠️ **partial**, done 26 Jul (Batch 3.2 Agent A) — schema + `Audit::record()`/`wrap()` shipped; **database-level** grant still blocked on N-1 (no distinct app/migration Postgres role to revoke from). App-level guard on `AuditEvent` is real but documented as bypassable via `AuditEvent::query()->update()` — a test proves the bypass rather than hiding it |
| S3-T9 | Mutation+audit wrapper so the pair cannot be separated; metadata allowlist | `platform-audit` AC4, AC5 | 1.5 pd | — | ✅ done 26 Jul (Batch 3.2 Agent A) — `Audit::wrap()` in one `DB::transaction()`, tested both failure directions (mutation throws, audit write throws); 7-action sensitive-reason list, 4-key metadata allowlist |
| S3-T10 | Correlation-id propagation: request → outbox → queue → provider → notification | `platform-audit` AC10 + `platform-outbox` AC13 | 1 pd | — | ⚠️ **partial**, done 26 Jul (Batch 3.3) — `app/Platform/Correlation/**` (`CorrelationId` value object, `CorrelationContext` scoped() holder, `CarriesCorrelationId` job trait) + `AssignCorrelationId` middleware wired into both the `web` group and the `/admin` panel. Request-boundary origin and the reusable propagation mechanism are real and tested. Outbox/queue-job/provider/notification propagation is a **prepared mechanism only, not proven end-to-end** — see N-10: none of those consumer classes exist in this repo yet |
| S3-T11 | Minimum outbox: `outbox_events` table, `SKIP LOCKED` publisher, queue routing | `platform-outbox` AC1, AC5, AC8 | 3 pd | — | ⚠️ **partial**, done 26 Jul (Batch 3.4) — `outbox_events` table with reconciled column names (see finding N-11), `Outbox::record()` write API (AC7 payload denylist via `PayloadClassification`; first real consumer of N-10's `trace_id` mechanism), `OutboxPublisher`'s real `SELECT ... FOR UPDATE SKIP LOCKED` claim loop with bounded-backoff retry (requires a real Postgres connection — throws a clear error on SQLite rather than a cryptic syntax error), and `OutboxQueueRouter`'s event-name → queue routing table (3 catalogue events mapped from `queue-and-outbox.md` §2's prose; everything else falls back to `default`). The execution plan's one required test — "commit succeeds, dispatcher dies, event still publishes on recovery" — is real, against real Postgres, in `tests/Feature/Outbox/OutboxRecoveryTest.php`. **Partial** because: AC1 is proved only against a `tests/Fixtures/` aggregate, not a real domain mutation (none exists yet — same honesty gap N-9/S3-T10 already established); AC5's atomic-claim proof is sequential-only — this suite's `RefreshDatabase`-per-test transaction wrapping means a genuinely separate second database session cannot see this test's uncommitted fixture rows, so true cross-session `SKIP LOCKED` contention is not provable inside this harness (see `OutboxPublisherClaimTest`'s own doc block); AC8's routing correctness is proved but starvation PREVENTION under load needs Sprint 6's Horizon supervisor pools, not built here; AC9 (10k-import load test), AC11 (bounded replay), and AC12 (observability/alerting) are explicitly out of scope per the execution plan and finding N-8. **Correction, 09 Aug 2026 (retrofit):** the module's first two **real producers** are now wired — `StartBookingDraft` and `SaveBookingDraftStep` in `app/Domain/Booking/Actions/**`, both called by the already-routed `app/Livewire/Public/Booking/BookingWizard.php` — each emitting to the outbox inside the Action's pre-existing `DB::transaction()` (see `docs/superpowers/plans/2026-08-09-retrofit-outbox.md` for the full decision). **Gap 1 of that row is now CLOSED:** AC1 is proved end-to-end against a real domain mutation with a real caller for the first time — `tests/Feature/Domain/Booking/Actions/BookingDraftOutboxTest.php` (rollback + commit directions) and `tests/Feature/Outbox/OutboxBookingDraftPublicationTest.php` (a real producer's row is claimed and `PublishOutboxEventJob` pushed), complementing the fixture-based `OutboxTransactionTest`. **Gaps 2–4 remain open, unchanged:** (2) AC5's atomic-claim proof stays sequential-only — this suite's `RefreshDatabase`-per-test transaction wrapping cannot expose a genuinely separate database session, documented in `OutboxPublisherClaimTest`'s own doc block; (3) AC8's routing correctness is still proved only at the unit level, with starvation prevention under load deferred to Sprint 6's Horizon supervisor pools; (4) AC9 (10k-import load test), AC11 (bounded replay), AC12 (observability/alerting) remain out of scope per the execution plan and finding N-8. **Two gaps disclosed at plan time and ledgered, not fixed:** the two new event names (`booking.draft_started.v1`, `booking.draft_step_saved.v1`) are uncatalogued — the catalogue's only booking row, `booking.draft_submitted.v2`, is a Step 9 submission event and Step 9 is unbuilt (`BookingWizardStep::LAST_IMPLEMENTED` is 5), so the genuinely critical event remains unproducible today (recorded as finding N-17 below); and neither new event is "critical" in AC1's sense — the remaining distance to a critical-event proof belongs to whichever spec builds Step 9 submission. This retrofit closes **one** of the row's four gaps, not all four. PR [#14](https://github.com/andrianm28/makam-app/pull/14), CI run [`31313063102`](https://github.com/andrianm28/makam-app/actions/runs/31313063102) (all 9 jobs green on PostgreSQL 18 — first run red: a new test hardcoded a non-existent user id and hit a real FK violation, fixed, re-run green). |
| S3-T12 | Authorization + audit test suite: cross-panel, cross-record, cross-scope negatives | all four Tier-0 specs | 2 pd | — | ⚠️ **partial**, done 26 Jul (Batches 3.1–3.5a landed most of this incidentally while building S3-T1/T4/T8/T9/T10/T11; Batch 3.5b closed the two real remaining gaps a coverage audit found) — **already covered before Batch 3.5b, not restated here:** panel-access wiring at the `User::canAccessPanel()`/`AdminPanelAccessPolicy` level (`tests/Feature/IdentityAccess/UserCanAccessPanelTest.php`, `tests/Unit/Platform/IdentityAccess/Panel/AdminPanelAccessPolicyTest.php`); single-row cross-scope negatives, revoke, cross-actor leakage, cross-entity-type leakage, and the deliberate `withoutGlobalScope()` escape hatch (`tests/Feature/IdentityAccess/Scopes/ScopeAssignmentGlobalScopeTest.php`, 8 pre-existing tests); full audit-invariant coverage — append-only + the documented `AuditEvent::query()->update()` bypass gap, required fields, sensitive-reason enforcement both directions, mutation+audit pairing both failure directions, metadata allowlist (`tests/Feature/Audit/AuditRecordTest.php`, `AuditWrapTransactionTest.php`, `AuditEventAppendOnlyTest.php`); feature-gate deny-by-default, misconfigured-closed, client-tampering resistance, caching semantics, and (Batch 3.5a) real Audit+Outbox integration on gate activation (`tests/Feature/FeatureGate/**`, 7 files). Re-reviewed all of the above for this batch specifically to check for gaps before adding anything new — found none worth new tests in `tests/Feature/Audit/**` or `tests/Feature/FeatureGate/**`. **Added by Batch 3.5b:** (1) `tests/Feature/IdentityAccess/AdminPanelHttpAccessTest.php` — the one real HTTP-level `/admin` access test that was missing; every prior test mocked `Filament\Panel` or checked the policy in isolation. Guest `GET /admin` asserts a real 302 redirect to the `filament.admin.auth.login` route; authenticated `GET /admin` (two independent users) asserts a real 200 — both traced against an actually-installed `filament/filament` `v5.7.3` (the exact version this repo's `composer.lock` pins) found on this host in a sibling project, not guessed; the test file's own doc block records the full source trail. States plainly that this proves today's coarse "authenticated = allowed" boundary only — no role check exists yet (S3-T2/T3). (2) One new test method on the existing scope-assignment file, `test_a_listing_query_returns_exactly_the_granted_rows_out_of_a_mixed_set` — every existing test in that file checked one row at a time via `->find()`; this one grants 2 of 3 rows and asserts a plain `ScopedTestModel::all()` listing returns exactly the 2 granted rows, proving the global scope's `whereIn()` constraint holds across a real multi-row result set, not just single lookups. **Still genuinely open, not this batch's to close:** requirements.md's Negative criterion "No cross-scope read reachable by changing an identifier in a URL" has no real HTTP-level test anywhere, because no real domain resource/controller/route reads a record by a URL-supplied identifier exists in this repo yet — `routes/web.php` declares zero routes, `app/Filament/{Admin,Operator,Vendor}/` are `.gitkeep`-only scaffolds, and `app/Domain/**` is entirely empty `Actions/`/`Models/` scaffolding (confirmed by grep, 26 Jul 2026). This is a structural fact about the current repo state, not a coverage gap this batch could close without inventing a fake route/controller nobody asked for and this batch does not own — re-open this specific check once the first real Resource/controller with a route-bound identifier exists. |

**Total: ~21.5 pd**

### Task detail

**S3-T2 / S3-T3 — MFA and re-authentication are human-gated** because they change the authentication surface. `AGENTS.md` requires human review before security and authorization changes. An agent may prepare them; enabling mandatory MFA on a live panel is a human decision.

**S3-T8 — enforce append-only in the database, not the application.** Withhold `UPDATE`/`DELETE` on `audit_events` from the application role; grant them to the migration role only. An application-level convention will eventually be bypassed; a missing grant will not.

**S3-T9 — the wrapper is the point.** `AGENTS.md` requires audit for 13 specs' worth of actions. A helper that performs mutation and audit as one unit is what makes "a committed state change with no audit record is a defect" enforceable rather than aspirational.

**S3-T11 — minimum, deliberately.** Table, atomic claim, routing, retry. Horizon supervisors, bounded replay, and the 10k-import isolation test come in Sprint 6 with `platform-notifications`, because nothing produces meaningful events yet.

### Deliverable

Tier 0 implemented and tested. A privileged user must enrol MFA. A gated route returns an explanatory page. No state change can commit without its audit record. An event written inside a transaction is published exactly once.

### Definition of Done

- [ ] `ActorContext` is the single source consumers read for identity, roles, scopes
- [ ] Every privileged role requires enrolled MFA; unenrolled access is refused
- [ ] Re-authentication enforced on all six sensitive action classes
- [ ] Query scopes mandatory; cross-scope read impossible by changing a URL identifier
- [ ] All 17 gates and 18 flags modelled; unknown or misconfigured gate resolves **closed**
- [ ] Four mode values readable from the server; client tampering cannot open a gate
- [ ] `UPDATE`/`DELETE` on `audit_events` rejected for the application role
- [ ] A committed mutation without an audit record fails a test
- [ ] Correlation id survives request → outbox → queue
- [ ] Outbox: commit-then-crash still publishes; concurrent publishers never double-publish
- [ ] Authorization negative-test suite green

### Risks

| Risk | L | I | Mitigation |
|---|---|---|---|
| K1/K2 contract unseen; identity spec may not match reality | **High** | **High** | Time-boxed spike in S3-T1 before building; escalate to ADR if the baseline shifts |
| Mandatory MFA locks out the only admin | Med | High | Enrol and verify recovery before enforcing; human gate |
| Foundation sprint feels like "no visible progress" | **High** | Med | §Appendix B gives a demonstrable outcome each week; the alternative is three stubs torn out in Sprint 4 |
| Scope creep into full outbox/notifications | Med | Med | Tier 1 is explicitly minimum; Tier 2 is Sprint 6 |

### Explicitly not in Sprint 3

No notifications, no document vault, no payment, no ledger, no features, no screens beyond the gate-explanatory page and the auth/MFA forms.

---

## 7. Sprint 4 — MVP vertical slices (Weeks 7–8)

### Goal

> Four public entry points exist, are reachable, are honest about what is not yet available, and are built entirely from the design system on top of Tier 0 — with FAQ complete end to end to prove the whole stack.

### Sequencing insight

**Build FAQ first, not the booking wizard.** FAQ is the cheapest *complete* vertical slice — public list, filter, search, detail, admin CMS, publish/unpublish, six seeded categories — and it exercises every layer: Livewire, Filament, design system, migrations, seeds, authorization, browser tests, CI. Proving the stack on FAQ costs ~4 pd. Discovering a stack problem three-quarters through the 9-step wizard costs far more.

### Execution methodology (S4-T2 onward)

Starting with S4-T2 (public FAQ), implementation batches in this sprint are built using Claude Code's multi-agent orchestration: a background agent drafts a batch (a coherent slice — e.g. "backend/domain," "admin Filament resource," "public Livewire pages") against the relevant spec, then every batch is reviewed line-by-line against the spec and the real installed framework source before commit — not trusted on the agent's self-report. Real bugs found in review are fixed directly (schema mistakes, test assertions that assumed a pristine database instead of the real seeded state, misdiagnosed root causes) rather than only flagged. CI (`.github/workflows/ci.yml`) is the verification oracle for every batch — a batch is not considered done until its own CI run is green, not just until local checks pass, since this host cannot run `composer install`/`npm run build` itself (`CLAUDE.md`, `docs/operations/ci-cd-and-release.md` §10). Findings worth a future reader's attention (root causes, scope boundaries, deliberate omissions) are recorded in this document's finding list (Appendix A) the same way earlier sprints' findings were, whether or not the agent producing the batch is the one who wrote them up.

**Correction, 09 Aug 2026: superseded going forward.** The batch-fan-out model described above shipped Sprint 3/4 (~17.5k LOC across `app/Domain/**` and `app/Platform/**`) with no committed plan doc, no RED-GREEN trail, single-pass review at most (occasionally followed by a CI-driven fix commit, never an independent second reviewer), no worktree isolation, and self-reported "CI green"/"done" status for most rows in this table — real CI runs exist for essentially every batch, but this document does not cite the run ID for any of them except where noted. It is superseded by the Superpowers SDD methodology now recorded in `AGENTS.md` §Development methodology, proven once on the booking wizard (S4-T4/S4-T5, PR #2) and now being retrofitted across already-shipped modules per `docs/superpowers/plans/*-retrofit-*.md`. Rows above are left as originally written — this correction does not retroactively claim they meet the new standard; each module's real status is tracked in its own retrofit finding, added to Appendix A as it completes.

### Tasks

| ID | Task | Spec | Consumes | Effort | Gate | Status |
|---|---|---|---|---:|---|---|
| S4-T1 | Master data + seeds from the canonical catalogues; **enums derive from the catalogue, incl. the 9 marketplace product codes** | `cemetery-directory…`, `package-and-service-bundles`, `funeral-marketplace…` | audit | 3 pd | — | ✅ Done (26 Jul 2026) — master data/seeds only; full AC coverage for each spec is S4-T6/S4-T7/S4-T8's job. **Deployed to dev.makam.co.id 26 Jul 2026** — see Deployments log below. **Retrofitted per `AGENTS.md` §Development methodology** via `docs/superpowers/plans/2026-08-09-retrofit-servicecatalog.md` (the `package-and-service-bundles` half of this row; the `funeral-marketplace` half is a separate back-to-back unit in `retrofit-backlog.md` §1 item 7): two-tier Superpowers SDD review (3 task-scoped reviewers — domain, schema, tests — plus 1 whole-module review) found 0 Critical, 26 Important, 21 Minor; 18 Important + 4 Minor ride-alongs closed in a bounded 4-round fix wave with non-vacuous regression tests, verified by a scoped re-review. 3 Important (F4b `cascadeOnDelete()` FK, F11 missing closed-list CHECK constraints, F12 missing partial unique index on the current price version) and the `PRICE_VERSION_RECORDED` `SensitiveActions` question ledgered pending human ruling — each is a migration against a table already deployed to `dev.makam.co.id` (gated by `AGENTS.md` §Infrastructure-agent execution) or an `app/Platform/**` decision. 3 doc corrections to `.kiro/specs/package-and-service-bundles/tasks.md:7/:8/:9` (Ruling B — AC5/AC6/AC8 overclaims corrected). Full disposition: `docs/planning/retrofit-backlog.md` §2. PR [#13](https://github.com/andrianm28/makam-app/pull/13), CI run [`31312252851`](https://github.com/andrianm28/makam-app/actions/runs/31312252851) (all 7 jobs green on PostgreSQL 18 — first run red on an order-dependent assertion in the new revise-copy test, fixed, re-run green) |
| S4-T2 | **FAQ complete slice** — public + admin CMS + 6 categories + no draft leakage | `public-faq` AC1–AC9, `admin-operations` AC6 | identity, audit, feature-gate | 4 pd | — | ✅ Done (26 Jul 2026), CI green. **Deployed to dev.makam.co.id 26 Jul 2026**. **Correction, 09 Aug 2026 (retrofit):** this row cites no CI run ID, unlike later rows (S4-T4, S4-T6) — searched for a real 26 Jul 2026 run to backfill and could not recover one with confidence; left uncited rather than guessed. **Retrofitted per `AGENTS.md` §Development methodology** via `docs/superpowers/plans/2026-08-09-retrofit-faq.md`: two-tier Superpowers SDD review (5 task-scoped reviewers — this module has both a write-lifecycle and a full admin CRUD surface, one more slice than the pilot — plus 1 whole-module review) found 1 Critical, 13 Important, 19 Minor. 11 findings closed in a bounded fix wave with real regression tests, verified clean by a scoped re-review; 1 Critical (no `FaqArticlePolicy`, four custom Filament actions bypass authorization entirely) and 3 Important (a `(category_id, sort_order)` DB uniqueness constraint, `cascadeOnDelete()` silently destroying append-only version history, and versioning-on-edit-of-a-published-article) ledgered pending human ruling — each is either an authorization change or a migration against a table already deployed to `dev.makam.co.id`, both gated by `AGENTS.md` §Infrastructure-agent execution. AC7's enforcement (editorial review, not a code guard — free-text content cannot be validated against a gate) is now recorded for the first time in `design.md`'s new "Open decisions" section. Full disposition: `docs/planning/retrofit-backlog.md` §2. PR [#9](https://github.com/andrianm28/makam-app/pull/9), CI run [`31302714214`](https://github.com/andrianm28/makam-app/actions/runs/31302714214) (all 8 jobs green on PostgreSQL 18, first attempt, no fix cycle needed). |
| S4-T3 | Homepage — 4 service cards exact order, 9 sections per IA §3, honest Urgent status | `public-home-and-navigation` AC1–AC9 | feature-gate, identity | 3 pd | — | ✅ Done (26 Jul 2026), CI green. **Deployed to dev.makam.co.id 26 Jul 2026** |
| S4-T4 | Booking wizard shell Steps **1–5** + autosave/resume across sessions | `public-booking-wizard` AC1–AC6, AC11–AC13 | identity, feature-gate, audit | 5 pd | — | ✅ Done (09 Aug 2026) — resumed 08 Aug after the 26 Jul pause. Built on branch `booking-wizard-steps-1-5`, whole-branch reviewed, review findings fixed and re-reviewed clean, merged via PR #2, CI green on PostgreSQL (run `31289236303`, all 7 jobs). **Deployed to dev.makam.co.id 09 Aug 2026** — see Deployments log below. AC13's "unskippable" half and the real idempotency/version wiring landed only in the final fix wave; see the plan's own AC13 correction note. Several findings deliberately parked, not blocking this row: FeatureGate integration in Step 3, AC3/AC5 field completeness, draft-ownership scoping — see PR #2 body |
| S4-T5 | Draft persistence, versioning, idempotent save, server-side step validation | `booking-and-order-orchestration` AC2, AC3 | audit, outbox | 3 pd | — | ✅ Done (09 Aug 2026) — built together with S4-T4, same feature, same branch. `booking_drafts` + `StartBookingDraft`/`SaveBookingDraftStep` with derived idempotency keys, optimistic versioning, and server-side step sequencing. **Deployed to dev.makam.co.id 09 Aug 2026** — see Deployments log below |
| S4-T6 | Cemetery directory + capability resolver + `"Perlu konfirmasi"` labelling | `cemetery-directory-and-availability` AC1–AC12 | feature-gate, audit, identity | 3 pd | — | ✅ Done (08 Aug 2026, agent team), CI green — run [`31248602859`](https://github.com/andrianm28/makam-app/actions/runs/31248602859), commit `a150a3b`. **Partial against the row's own AC1–AC12 claim:** AC1–AC5, AC11, AC12 shipped at `/cemeteries` + `/cemeteries/{slug}`. AC6–AC9 did **not** — no plot-source adapter, no AC8 staleness monitoring, and no AC9 operator write-scoping/audit (no write-side capability Action exists; that belongs to `admin-operations`). Directory/map benchmarking is **NOT TESTED**. Shipped route vocabulary is `/cemeteries…` per `openapi.yaml`, not `information-architecture.md`'s route tree. **Correction, 09 Aug 2026 (retrofit finding): the "feature-gate, audit, identity" dependency claim in this row is inaccurate as shipped.** `grep -rn "FeatureGate\|Audit::\|IdentityAccess" app/Livewire/Public/Directory app/Domain/CemeteryDirectory app/Domain/CemeteryCapability` finds one hit, a docblock analogy (`PublicCapabilityProjection.php:37`), not a real integration. The shipped module is a fully public, unauthenticated, read-only surface with no write path, so there is currently nothing to gate, audit, or authenticate against those three Platform modules — expected, since AC9's write path (the piece that would need them) is out of scope until `admin-operations` builds it. **Retrofitted per `AGENTS.md` §Development methodology** via `docs/superpowers/plans/2026-08-09-retrofit-cemetery-directory-capability.md`: two-tier Superpowers SDD review (4 task-scoped reviewers + 1 whole-module review) found 0 Critical, 11 Important, 14 Minor; 10 Important findings closed in a bounded fix wave with real regression tests (one caught as vacuous by the scoped re-review and corrected before merge — see the plan's Task 4 report), 1 Important (a database-level closed-list constraint) and 1 further finding (migrating the directory's filter chips to `<x-mk.filter-chip>`) ledgered pending human/design ruling rather than merged unreviewed. AC6–AC9, AC10 (admin management), benchmarking, 3 UI states (§6.6/§6.8/§6.9), and the 44px/focus-ring accessibility checks each got an explicit disposition rather than silent carry-forward — see `docs/planning/retrofit-backlog.md` §2. PR [#5](https://github.com/andrianm28/makam-app/pull/5), CI run [`31293135398`](https://github.com/andrianm28/makam-app/actions/runs/31293135398) (all 8 jobs green on PostgreSQL 18, including the module's new/fixed tests). |
| S4-T7 | Renewal skeleton — city/cemetery selection + fuzzy search UI + **three distinct empty states** | `renewal-and-grave-registry` AC1–AC5, AC14 | feature-gate, audit | 2 pd | — | ✅ Done (08 Aug 2026, agent team), CI green — run `31248602859`, commit `a150a3b`. AC1–AC3, AC5, AC14 shipped at `/perpanjangan` + `/perpanjangan/cari`, and the three empty states did **not** collapse — no-result, privacy-limited, and gate-closed are held apart by assertions written as denials (the §7 note below flagged this as the defect to avoid). **Partial:** only journey steps 1–3 have screens; steps 4–6 (fee, payment, confirmation) are Sprint 13. **AC4 (< 500 ms at 100k records) is NOT TESTED and not passing** — nothing measures latency, no 100k-row fixture exists. **Correction, 09 Aug 2026:** `GraveRegistry` (the grave-search half of this row) retrofitted per `AGENTS.md` §Development methodology — see `docs/planning/retrofit-backlog.md` §1 item 4 and §2. 0 Critical, 7 Important, 17 Minor found; 7 Important + 2 ride-alongs fixed in-wave, including a real privacy gap (draft-cemetery records were reachable as full open-mode results by anyone holding the cemetery's UUID — fixed by scoping the query to published cemeteries, human-reviewed and approved before merge). AC4 stays NOT TESTED, ledgered as backlog, not closed by this retrofit. PR and CI run recorded here once merged. **Second correction, 09 Aug 2026 (`Renewal` retrofit):** this row is shared, and `Renewal` — the non-`GraveRegistry` half (`RenewalJourneyStep`, `RenewalStart`, `/perpanjangan`) — was retrofitted separately per `AGENTS.md` §Development methodology via `docs/superpowers/plans/2026-08-09-retrofit-renewal.md`: two-tier Superpowers SDD review (3 task-scoped reviewers + 1 whole-module review) found 0 Critical, 8 Important (7 fix-wave / 1 ledger-only), 19 Minor (2 fixed as ride-alongs, 1 closed for free by this row's drafted wording, 16 parked verbatim). **The row's `audit` dependency claim is unsubstantiated as shipped** — the same defect class already corrected on the S4-T6 row above. `grep -rni "audit" app/Domain/Renewal app/Livewire/Public/Renewal resources/views/livewire/public/renewal tests/Unit/Domain/Renewal tests/Feature/Livewire/Public/Renewal` returns nothing (exit 1): the shipped renewal surface is public, unauthenticated and read-only, with no write path to audit. **`feature-gate` in the same cell is substantiated and stands** — `ModeResolver::graveSearchMode()` is called server-side on both screens (`RenewalStart.php:186`, `GraveSearch.php:228`), grep exit 0. Also unrecorded until now: **AC16 shipped** on this row (the closed-gate banner that never removes the step, `RenewalStartTest::test_the_closed_data_gate_renders_an_honest_banner_without_removing_the_step`), though the row's Spec cell lists only AC1–AC5 and AC14. AC4 stays **NOT TESTED**, unchanged. AC6–AC11, AC13 and AC15 remain unbuilt and Sprint 13-owned; `app/Domain/Renewal/{Actions,Models}/` are still `.gitkeep`-only and no migration creates `renewals`, `renewal_quotes`, `renewal_external_markings` or `reminder_deliveries` — verified, not assumed. Full disposition: `docs/planning/retrofit-backlog.md` §2. PR [#15](https://github.com/andrianm28/makam-app/pull/15), CI run [`31316000732`](https://github.com/andrianm28/makam-app/actions/runs/31316000732) (all 7 jobs green on PostgreSQL 18 — the branch's first green frontend run after folding the pre-existing §8.2 ci.yml assertion fix, `c42e973`, into this PR; PHP test job green, PHPUnit still not runnable on the 2/4 host) |
| S4-T8 | Marketplace skeleton — category/product browse from seeded catalogue | `funeral-marketplace-and-vendor-portal` AC1–AC3 | identity, audit | 2 pd | — | ✅ Done (08 Aug 2026, agent team), CI green — run `31248602859`, commit `a150a3b`. AC1 and browse shipped at `/marketplace` + `/marketplace/produk/{productCode}`; browse-only is test-enforced (no cart/checkout affordance, no callable Livewire action). **Partial:** AC2 cannot be completed as specified — `products`/`product_variants` have no schedule, service-area, delivery-fee, or stock/availability column. AC3's cart→checkout→payment→vendor sequence is Sprint 11–12 as planned. `/marketplace/kategori/{categorySlug}` stays deliberately **unregistered and BLOCKED** pending a product decision — `marketplace-catalog.md` defines 9 product codes and 0 category codes, and no slug was invented; filtering uses an internal `?kategori=` key instead **Correction, 09 Aug 2026 (retrofit):** `Marketplace` retrofitted per `AGENTS.md` §Development methodology — full disposition in `docs/planning/retrofit-backlog.md` §1 item 7 and §2. Two-tier review (3 task-scoped slices + 1 whole-module) found 1 Critical, 9 Important, 9 Minor; the bounded fix wave (W-1…W-6, one commit each) closed every wave finding with non-vacuous regression tests (W-1 Critical: invented vendor names + prices rendered bare on a public page — fixed at the presentation seam with the established fabricated-data marker), the D-1…D-5 documentation corrections were applied in Task 5, and the scoped re-review confirmed no new breakage. AC2's true gap is **five** missing columns, not four (adds `evidence requirement` — `tasks.md`/`screen-inventory.md`/`traceability-matrix.md` corrected to match). The `{productSlug}` IA drift was corrected to `{productCode}` (`information-architecture.md:13`). The dead `MarketplaceComingSoon` stub was **ledgered, not deleted** — one of three orphaned ComingSoon stubs (`BookingWizardComingSoon`, `MarketplaceComingSoon`, `RenewalComingSoon`) sharing `coming-soon.blade.php`, scheduled as one future combined-removal PR. PR [#16](https://github.com/andrianm28/makam-app/pull/16), CI run [`31317976326`](https://github.com/andrianm28/makam-app/actions/runs/31317976326) (all 8 checks green on PostgreSQL 18 — first run red: a Pint style issue in the W-3 seed test, fixed, re-run green) |
| S4-T9 | Capacity review with all tenants counted; decide upgrade vs split | infra | — | 1 pd | ⚠️ **HUMAN** | Not started — requires the user, not an agent. **New evidence 09 Aug 2026:** `dev-web` was killed by host-wide OOM during a routine deploy (213Mi free of 3.8Gi at the time, 1.4Gi swapped) — see the Deployments log's `09 Aug 2026` row for the full incident. Top memory consumers were AI agent tooling processes, not the `makam-nonprod` stack itself. This is no longer a projection; it is an observed outage. **Wave 0 (09 Aug 2026) baseline measured:** `free -m` at 09 Aug 21:58 WIB → total 3911 Mi, used 2954 Mi, free 247 Mi, buff/cache 709 Mi, **available 697 Mi**, swap 9678 Mi (2539 used / 7139 free). Top RSS processes were AI-agent tooling (opencode ~1.0 GiB, claude+daemon+pty ~0.5 GiB combined, 2× kirocrew ~0.49 GiB combined, kiro mcp-* 5× ~0.43 GiB combined) — the `makam-nonprod` containers were not the top consumers, consistent with the earlier incident note. **Wave 1 execution budget set at 4 concurrent worktrees with staggered `build-image`/test runs** — this is a scheduling bound, NOT a pass on ADR-0027 exit criteria. `free -m` must be re-checked before each wave boundary and the decision (upgrade vs split vs accept) still requires the human; this row stays ⚠️ **HUMAN** pending that decision. Baseline recorded in `docs/superpowers/plans/2026-08-09-wave0-decisions.md` Task 4 |
| S4-T10 | Resolve the open decisions in `assumptions-and-gates.md` §5 that block specs — **12 items now**, not 5; the list grew after this row was written (verified 08 Aug 2026 by counting §5 directly) | **L-6** | — | 1 pd | ⚠️ **HUMAN** | Not started — requires the user, not an agent |

**Total: ~27 pd** — over a 2-week sprint for one developer. See §11.

### Deployments log

Per `docs/operations/ci-cd-and-release.md` §5/§10 ("promotion: commit -> CI artifact -> development -> smoke"). This is the first real deployment to the combined dev/staging host — `dev-web` had been running a placeholder until 25 Jul 2026, and even that first real image (25 Jul) was never migrated: `php artisan migrate:status` showed only Laravel's own 3 default migrations applied, none of Sprint 3 or Sprint 4's real schema.

| Date | Commit | Image (GHCR) | What it includes | Migrations applied | Verified |
|---|---|---|---|---|---|
| 26 Jul 2026 | `8f7c7ee1b4fd7003eafc587da6dfbcb2bc899d34` (`docs/design-system-and-planning`) | `ghcr.io/andrianm28/makam-app@sha256:bfcf6077901f65eb59527ac6a9bfc8d0c39b63a01484752e4497fa26d9e8b259` (tag `sha-8f7c7ee1b4fd`) | Sprint 3 (Tier-0: identity/MFA, audit, feature-gate, outbox) + Sprint 4 S4-T1 (master data), S4-T2 (FAQ), S4-T3 (homepage) | 33 pending migrations run (`2026_07_26_100000` through `2026_07_26_200000`) — all pure `create_table`/seed, zero destructive operations, zero rows deleted | `https://dev.makam.co.id/` (200, real homepage content), `/faq` (200, real seeded articles), `/pemesanan-makam`\|`/marketplace`\|`/perpanjangan` (200, honest stubs, not 404), `/admin` (302 to login), `/up` (200), `X-Robots-Tag: noindex` still present |
| 08 Aug 2026 | `dc374330401ac...` (`docs/design-system-and-planning`) | `ghcr.io/andrianm28/makam-app@sha256:95ca283adb0ff655cdffd12c4cb0dd63e1508ae6ea14b8b3d24c06a63af04c90` (tag `sha-dc374330401a`) | Batch 0: 16 icon components (closing N-15-class gaps for every icon `StatusIntent` and the primitives reference), `<x-mk.stepper>`'s optional `labels` prop, PUB-060 `/bantuan` (a real defect fix — the persistent header link 404d on every page, see `.kiro/specs/help-centre-missing-route/`), the `home-page.blade.php` `tel:` link fix, plus the `composer.lock` advisory fix (`guzzlehttp/guzzle` 7.15.2, `league/commonmark` 2.9.0) that this deployment itself needed — see below | 3 migrations that had been **pending since before this session**, unrelated to Batch 0, run alongside the redeploy: `2026_07_26_200100_add_dummy_vendor_pricing_and_photo_to_products`, `2026_07_26_210000_backfill_dummy_map_price_and_photo_for_seeded_cemeteries`, `2026_07_26_220000_seed_service_definition_dummy_operational_data` — all additive (`update()`/`insert()`/new columns) in `up()`, verified by reading each file before running; zero destructive operations | `https://dev.makam.co.id/` (200), `/bantuan` (200, real HelpCentre content — was 404 before this deploy), `/faq`\|`/pemesanan-makam`\|`/marketplace`\|`/perpanjangan`\|`/privasi`\|`/syarat-ketentuan` (200), `/admin` (302), `/up` (200), `X-Robots-Tag: noindex` present (checked at the real `https://dev.makam.co.id` edge — nginx adds this header, not the app; an internal `curl 127.0.0.1:8081` check missed it and was corrected), apex `https://makam.co.id/` still 200 (regression check) |
| 08 Aug 2026 (later) | `70885c79224faedff8d41a0a450897762e7f289e` (`docs/design-system-and-planning`) | `ghcr.io/andrianm28/makam-app@sha256:7daafedb8a3da55dbddd81959e3164309e052812f519eb60c67273cce65cdd59` (tag `sha-70885c79224f`) | Sprint 4 **S4-T6** (cemetery directory, `/cemeteries` + `/cemeteries/{cemeterySlug}`), **S4-T7** (renewal skeleton, `/perpanjangan` + `/perpanjangan/cari`), **S4-T8** (marketplace browse, `/marketplace` + `/marketplace/produk/{productCode}`) — built by an agent team, merged (`CemeteryPublicQuery` unifying the directory/renewal read path), kiro-spec bookkeeping synced (traceability-matrix v0.6, `screen-inventory.md`), and CI gate 13 added (Blade content-survival check, `design-system.md` §9.5). Full accounting of what shipped/didn't per AC: this table's own three `tasks.md` entries and traceability-matrix.md finding T-J | 2 new migrations, both additive: `2026_08_08_100000_create_grave_records_table` (new table + `pg_trgm` GIN index, `restrictOnDelete` on `cemetery_id` so a cemetery delete cannot silently take a burial registry with it) and `2026_08_08_100010_seed_example_grave_records` (14 fictional `Contoh...`-prefixed rows spanning all three AC14 access modes plus one row against the deliberately-unpublished draft cemetery as a negative fixture) — both read in full before running; zero destructive operations. Row counts before/after: 10 cemeteries → 10 (unchanged), 23 faq_articles → 23 (unchanged), 40 migrations → 42, 0 grave_records → 14 (all 14 seed rows found their cemetery — none skipped) | `https://dev.makam.co.id/` (200), `/cemeteries` (200, all five launch cities present), `/cemeteries/{slug}` (200, real detail page for a real seeded slug), `/perpanjangan`\|`/perpanjangan/cari` (200, real renewal + grave-search content), `/marketplace` (200, real category content) \| `/marketplace/produk/{code}` (200, checked against a real code scraped from the rendered page, not guessed), `/bantuan`\|`/faq`\|`/privasi`\|`/syarat-ketentuan`\|`/pemesanan-makam` (200), `/admin` (302), `/up` (200), `X-Robots-Tag: noindex, nofollow, noarchive, nosnippet` present at the real edge. **No recreation anomaly this time** — `docker compose up -d dev-web` recreated only `dev-web`; postgres and redis stayed `Running`/`Healthy` throughout, unlike the unresolved anomaly noted on the previous deploy below |

| 09 Aug 2026 | `69bda1cc63293e4f36872ab4c3c2f9fa39a6c50e` (merge commit, `docs/design-system-and-planning`, PR #2 "Booking wizard: Steps 1-5 (resume Sprint 4 S4-T4/S4-T5)") | `ghcr.io/andrianm28/makam-app@sha256:f37fe0edba6a4a3fb0bafa26904d334501aa755c059a2e84f7634d55d99f8a20` (tag `sha-9b9f95fe1980`) | Sprint 4 **S4-T4/S4-T5** — the public booking wizard (`/pemesanan-makam`), Steps 1-5 of 9, plus its domain-side draft persistence. Built via `superpowers:subagent-driven-development` (12 tasks), whole-branch reviewed (2 Critical + 6 Important + 10 Minor found), one fix wave addressing 13 findings, one scoped re-review confirming all addressed with no new Critical/Important breakage. Merged via PR #2 after CI ran green on PostgreSQL for the first time for this code (run `31289236303`, all 7 jobs). Image promoted unrebuilt — the merge was fast-forward-eligible, so the merge commit's tree is identical to what CI verified. Several findings deliberately parked, not fixed: FeatureGate integration in Step 3 (Pre-Need/Urgent offered without reading `PreNeedMode`/`UrgentMode`), AC3/AC5 field completeness, draft-ownership scoping once `user_id` is set, missing support links/skeleton/step-5 fallback, no retention story — full reasoning in PR #2's body and the plan's own SDD ledger | 1 new migration, additive: `2026_08_08_130000_create_booking_drafts_table` (new table, two `nullOnDelete` FKs to `cemeteries`/`cemetery_packages`) — read before running, zero destructive operations. Row counts before/after: 10 cemeteries → 10 (unchanged), 42 migrations → 43 | `https://dev.makam.co.id/` (200), `/pemesanan-makam` (200, real wizard — stub copy absent), `/faq`\|`/marketplace`\|`/perpanjangan` (200, unaffected), `/admin` (302), `/up` (200), `X-Robots-Tag: noindex, nofollow, noarchive, nosnippet` present. **Incident during this deploy, self-resolved within minutes:** shortly after initial verification passed, `dev-web` was killed by the kernel (`SIGKILL`, exit 137; `docker`'s own `OOMKilled` flag reported `false`, consistent with a host-wide OOM event outside that one container's 512m cgroup limit, not an app-level fault) — the live user testing the deploy in a browser saw a 502. Host memory at the time: 3.8Gi total, 213Mi free, 1.4Gi swapped. `ps aux --sort=-%mem` showed the top consumers were AI agent tooling processes (two `claude` sessions, `hermes-agent`, `kirocrew`, `herdr`), not the `makam-nonprod` stack. `docker compose up -d dev-web` restored service in under a minute (`postgres`/`redis` cycled too, both came back healthy with the named volume intact — no data loss, row counts re-verified identical). **Root cause not fixed, only worked around**: this is the capacity condition `S4-T9` (⚠️ HUMAN gate, still "Not started" per that row above) exists to review — ADR-0027's exit criteria ("steady memory above 80%, sustained swap") were already satisfied before this deploy even ran. Left unresolved deliberately; not an agent's call to act on shared host processes it doesn't own |

| 09 Aug 2026 (later) | `5ba0f016bc0e427ed6dfac861b1c93c180ffe474` (trunk `docs/design-system-and-planning` — PRs #13 ServiceCatalog retrofit, #14 Outbox retrofit, #16 Marketplace retrofit, #15 Renewal retrofit all merged) | `ghcr.io/andrianm28/makam-app@sha256:cdf5d3a5321c704cb9a648eedcec224eab72aa8ae7d0865cd8ccdbd4ada8daa7` (tag `sha-5ba0f016bc0e`, build-image job in CI run [`31318708145`](https://github.com/andrianm28/makam-app/actions/runs/31318708145)) | Track A retrofit wave, Batch 2 — the four modules retrofitted per `AGENTS.md` §Development methodology via Superpowers SDD plans (`2026-08-09-retrofit-{servicecatalog,outbox,marketplace,renewal}.md`): domain logic extracted to Actions/Services, two-tier review (task-scoped + whole-module) findings fixed in bounded fix waves with regression tests, dispositions in `docs/planning/retrofit-backlog.md` §1/§2. Ledgered pending human ruling (not deployed-active): ServiceCatalog F4b/F11/F12/S-Q3/F15, Marketplace mvp-scope precedence, Renewal L1/L2 — see §2. No public-facing behavior changes; a defect fix riding along (ci.yml §8.2, PR #15) only affects CI, not runtime | **Zero migrations to run** — `migrate:status` showed all 43 migrations `Ran`; the 4 existing migration files touched by retrofits (`create_products_table`, `create_service_definitions_table`, `create_service_package_versions_table`, `create_price_versions_table`) were comment-only doc-block corrections (findings D-3, F10, M6, F9) with zero DDL. Backup of pre-change `compose.yml` kept at `compose.yml.bak.20260809_144149Z-pre-5ba0f01-retrofit-deploy` | `https://dev.makam.co.id/` (200), `/faq`\|`/pemesanan-makam`\|`/marketplace`\|`/perpanjangan`\|`/bantuan`\|`/privasi`\|`/syarat-ketentuan`\|`/cemeteries`\|`/up` (200), `/admin` (302), `X-Robots-Tag: noindex, nofollow, noarchive, nosnippet` present at the real edge, apex `https://makam.co.id/` (200, regression check). `docker compose up -d dev-web` recreated **only** `dev-web`; `postgres`/`redis` stayed `Running`/`Healthy` throughout — no recurrence of the 09 Aug earlier-deploy anomaly. Container `healthy` on the new image; zero `ERROR`/`Exception` lines in container logs. Deploy performed from the host directly (no SSH) — `.env.dev` (the handoff #3 blocker) was already `ubuntu:ubuntu`-owned |

CI's own "Build and push image" job (`build-image`, `.github/workflows/ci.yml`) failed once on this commit with a transient `registry-1.docker.io` timeout (GitHub-runner-side network issue, not a code defect) — re-run (`gh run rerun`) succeeded cleanly on retry.

**Why `build-image` had not run since 26 Jul.** `Dependency audit` (`composer audit --locked`) failed on every commit for 12 days once 8 advisories against `guzzlehttp/guzzle` and `league/commonmark` were reported 3–6 Aug 2026 — after `composer.lock` was last touched (25 Jul scaffold). `build-image` requires `security-audit` to pass, so no new image existed for anything committed after 26 Jul. Fixed by a resolution-only `composer update --no-install` (both packages are transitive, pulled in by `laravel/framework`'s already-permissive `^7.8.2`/`^2.8.1` constraints, so no other lockfile changes were needed) — explicitly authorized by the user before touching this, per `AGENTS.md`'s human-review-before-security-changes rule.

**Anomaly during this deploy, resolved.** `docker compose up -d dev-web` unexpectedly recreated `postgres` and `redis` alongside `dev-web`, not just the one service named on the command line. Verified immediately before proceeding further: `makam-nonprod_postgres_data` remained the same named volume (not recreated), row counts matched known values before and after (10 cemeteries, 23 FAQ articles, 37 migration rows), and all three containers reported `healthy`. No data loss occurred — a container recreate against an existing named volume is non-destructive by itself — but the cause of the wider recreate was not root-caused in this session and is worth investigating before the next deploy: possibly a compose project config-hash change from this being the first `docker compose` invocation of this session to successfully read `.env.dev` via `sudo -n` (prior partial/failed attempts in this same session may have left a different resolved config cached).

Not deployed: S4-T4/S4-T5 (booking wizard) were paused/uncommitted from 26 Jul at the time of this entry. **They were resumed and built 08–09 Aug 2026 and are now implemented but unmerged** — still not deployed, and still absent from every deployment log entry below; left as written here, per this file's own append-correction convention. **S4-T6/S4-T7/S4-T8 deployed in the following entry** (08 Aug 2026, later the same day) — the "in progress via an agent team, not yet committed" note above described this table's own prior state, not current state; left as written rather than rewritten, per this file's own append-correction convention.

**Correction, 09 Aug 2026:** S4-T4/S4-T5 are no longer "unmerged" — merged via PR #2 and deployed the same day; see the `09 Aug 2026` row above. Left the two paragraphs above as written rather than rewritten, per this file's own stated convention.

> **Alignment note.** The old S3-T8 "gated-fallback mode banners" is **gone from this sprint** — it moved to S3-T7 in the Tier-0 sprint, where the gate registry that drives it lives. Features consume the banner; they do not each reimplement it.
>
> S4-T7 must implement **three distinct empty states** (no-result · privacy-limited · gate-closed), per `renewal-and-grave-registry/tasks.md`. Collapsing them into one message is the defect the spec calls out.

### Task detail

**S4-T1 — Master data is the real prerequisite.** Every other Sprint 3 task depends on seeded canonical data. `AGENTS.md` is explicit: *"Do not duplicate canonical catalog data in multiple hand-maintained documents or code locations."* Seeds read from one authoritative definition per catalog. `overview.md` §13: *"must not scatter hard-coded variants across Livewire components."*

**S4-T4 — Wizard Steps 1–5 only.** Steps 6–9 are deliberately excluded: Step 7 needs the full quarantine/malware/signed-URL pipeline and Step 8 needs a payment decision that does not exist yet. Steps 1–5 deliver the wizard shell, the 9-step stepper presentation (design §3.9 — still displaying 1–9, since the framing is a product contract), autosave every 10 s while dirty, resume across sessions, anonymous draft with opaque token, back navigation that preserves data, and server-side step validation.

**Honesty machinery is inherited, not rebuilt.** The four server-side mode values and the `intent=info` fallback banner were delivered in Sprint 3 (S3-T6, S3-T7) because the gate registry that drives them lives there. Every feature in this sprint **consumes** them: renewal reads `GraveSearchMode`, the wizard reads `PaymentMode` and `PreNeedMode`, homepage reads the Urgent gate. That inheritance is what makes the renewal and marketplace skeletons acceptable deliverables rather than broken promises — they are honest about what is closed, using one shared mechanism.

**S4-T8 — Marketplace skeleton, catalogue-driven.** Browse only: categories and products read from the seeded canonical catalogue with the nine `FLOWER_*` / `GRAVESTONE_*` / `GRAVE_CARE_*` codes. No cart, no checkout, no vendor portal — those need payment and ledger (Tier 3) and are Sprint 11–12.

**S4-T9 — Capacity review (⚠️ HUMAN GATE).** The host runs `fund-for-indonesia` (app + `postgres:16` on `:3000`/`:5432`) alongside this stack. 153 MiB RAM was free before any PHP-FPM or Horizon process existed. `performance-and-capacity.md` assumes a dedicated host. Produce a real measurement and a recommendation: upgrade, split environments, or formally accept the limitation.

### Deliverable

Four public entry points live on `dev.`/`stg.`, built from design-system primitives, all required states implemented, honest gated-fallback messaging, FAQ complete and browser-tested.

### Definition of Done

- [x] Homepage shows exactly four services in stakeholder order (IA §3 nine-section order respected) — S4-T3, CI green
- [ ] Five launch regions present and selectable — `LaunchCityCode` seeded/tested (S4-T1); real UI selection is S4-T4's Step 1
- [x] FAQ: six categories, filter, search, detail, CS CTA; **draft articles are not publicly reachable** (test-enforced) — S4-T2, CI green
- [x] Booking Steps 1–5 navigable; back preserves data; autosave verified across a session boundary — merged, CI-verified on PostgreSQL, and deployed 09 Aug 2026 (S4-T4/S4-T5); see the Deployments log
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
| Open decisions (L-6) block specs mid-sprint | Med | Med | Front-load S4-T10 in week 1 |
| Design system needs changes once real screens exist | Med | Low | Expected; ADR path per design §9.4 |

---

## 8. Sprint 5 — Test, accessibility, gate dry-run (Weeks 9–10)

### Goal

> Convert Sprint 3's assertions into evidence, and produce an honest release-gate status report that says `NOT READY` where that is the truth.

### Tasks

| ID | Task | Findings | Effort | Gate |
|---|---|---|---:|---|
| S5-T1 | Browser suites E2E-HOME + E2E-FAQ complete; E2E-BOOK for Steps 1–5 | test-strategy §2 | 4 pd | — |
| S5-T2 | Accessibility: axe-core in the suite, keyboard walkthroughs, focus order, 200 % zoom, 320 px reflow, touch targets | design §7.7 | 3 pd | — |
| S5-T3 | Authorization + query-scope tests for delivered surfaces; cross-panel access negative tests | test-strategy §7 | 2 pd | — |
| S5-T4 | Lighthouse/weight budget measurement vs design §4.6; record actuals | design §4.6 | 1 pd | — |
| S5-T5 | **Release-gate dry run** → `READY` / `READY WITH BLOCKERS` / `NOT READY` report | release-gates | 2 pd | ⚠️ **HUMAN** |
| S5-T6 | Rollback rehearsal + expand/contract migration compatibility test | ci-cd §4, §7 | 2 pd | ⚠️ **HUMAN** |
| S5-T7 | Resolve remaining design open questions (OQ-01 brand, OQ-04 bottom nav, OQ-05 icons, OQ-06 copy) | design §11 | 1 pd | ⚠️ **HUMAN** |
| S5-T8 | Docs: `CLAUDE.md`↔`AGENTS.md`, screen-inventory update, remaining L-4 | **L-5, L-4** | 1 pd | — |
| S5-T9 | Sprint 5+ backlog groomed into the issue tracker | — | 1 pd | — |

**Total: ~17 pd**

### Task detail

**S5-T5 — The most important deliverable of this sprint.** A structured pass over every `release-gates.md` checkbox with one of three states and **no fourth option**: `PASS` (with evidence), `BLOCKED` (with reason), `NOT TESTED`. `AGENTS.md`: *"Never report `PASS` for a check that was not executed."* The expected honest outcome is **`NOT READY`**, with most of sections C–H marked `NOT TESTED` because they are out of scope (§2.2). That report is the deliverable — it is what makes the remaining runway visible and fundable.

**S5-T6 — Rehearse rollback before needing it.** `ci-cd-and-release.md` §4 mandates expand/contract and §7 defines rollback actions. Rehearse on staging: deploy, migrate, roll the artefact back, confirm the schema stays forward-compatible. Requires a human gate — it touches migrations and deployment.

**S5-T7 — Unblock the design system.** **OQ-01 (is Petrol teal the accepted brand primary?)** must be settled here. If a green primary is mandated, `success` must move and all 46 contrast pairs need re-verification — cheap now, expensive after 40 screens exist. **OQ-04 (bottom nav)** is a navigation contract needing product approval, not a style choice.

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
| Stakeholders read "Sprint 5 done" as "MVP done" | **High** | **High** | §0.2 and §2.2 exist for this; lead the report with scope |
| OQ-01 reverses the palette | Low | Med | Re-run `verify-contrast.py`; ~1 pd if decided in Sprint 5, far more later |

---

## 9. Beyond Sprint 5 — honest runway to MVP acceptance

Outline only, for planning visibility. **Not estimated at the same confidence as Sprints 1–5** — these depend on gate decisions, provider availability, and financial approvals outside engineering control.

Now expressed as **specs**, in the dependency order §3.4 derives, so the sequence is checkable rather than thematic. Note that MFA and audit have **moved forward** to Sprint 3 — the old outline scheduled them at Sprint 14, which was wrong: nothing authenticated can ship without them.

| Sprint | Specs implemented | Tier | Blocked on |
|---|---|---|---|
| **6** | `platform-notifications` (full) · `platform-document-vault` · `platform-outbox` (Horizon, replay, 10k isolation) | 2 | **OQ-4** object storage · **OQ-7** scanner |
| **7** | `public-booking-wizard` Steps 6–9 · `booking-and-order-orchestration` (order state machine, quotation, immutable versions) · `package-and-service-bundles` · `admin-operations` (full) | — | Sprint 6 |
| **8–9** | `platform-payment-adapter` · `platform-financial-ledger` — manual fallback first, then online if `G-PAY-01` opens | 3 | **OQ-5** · **FIN-DEC** approvals · heavy human gates |

> **Correction, 11 Aug 2026 (`platform-payment-adapter`, lane `lane/l3-payment-adapter`, Tasks 1-8 of 10):** `G-PAY-01` and the FIN-DEC approvals in `release-gates.md` §H remain **closed/ungranted** — this row's gating is unchanged. Build progress within the deny-only guard (`GuardPaymentSession` fails closed on every one of its six conditions except the server-resolved mode check, so no `payment_sessions` row can exist): a durable, signature-verified webhook receiver; a self-contained manual-fallback submission/verification slice (`payment_verifications`); a self-contained refund/chargeback recording slice (`payment_reversals`); and a ledger seam contract test against L4's `Journal` interface. All decoupled from the blocked session/journal/provider path by design — none constitutes a working paid path. **Sandbox-exercised evidence: NONE.** Task 8's live SumoPod sandbox smoke test (real API key, real webhook secret, `payment_link_url` resolution) is explicitly **NOT TESTED** — it requires a human or ops-provisioned dev-host run outside an agent session (no outbound `PaymentProvider` integration exists to exercise, and the agent session's own permission system correctly refuses to read the sandbox credentials). See `.kiro/specs/platform-payment-adapter/tasks.md`'s NOT TESTED section for the full, itemized disposition.
| **10** | `funeral-case-management` · `at-need-booking` · `cemetery-operator-dashboard` | — | Sprint 7 |
| **11–12** | `funeral-marketplace-and-vendor-portal` — cart, single-vendor checkout, **vendor portal (9 screens)** | — | Sprint 8–9 |

> **Correction, 09 Aug 2026 (Wave 0 ruling 0a):** the marketplace cart/checkout half of this row is in **MVP scope** per `docs/product/mvp-scope.md:35` (`Cart dan checkout`), overriding the Sprint 11–12 deferral (see the §2.2 correction above and `docs/superpowers/plans/2026-08-09-wave0-decisions.md` Task 1). This row's remainder — the **vendor portal (9 screens)** — stays here. Cart/checkout build work is pulled forward and gated on its own Tier 3 foundations (payment-adapter L3, financial-ledger L4); its second Marketplace retrofit review must not start until the precedence ruling is applied and cart/checkout actually exist.
| **13** | `renewal-and-grave-registry` (tariff, payment, duplicate-period guard, 10k import, 100k fuzzy search) · `recurring-care-subscriptions` · `grave-care-fulfillment` | — | Sprint 8–9 · perf target < 500 ms |
| **14** | `certificates-and-agreements` | — | `G-CERT-01` |
| **15** | Performance certification Profiles A–D · production environment · managed Postgres + PITR | — | **N-3** provider decision |
| **16** | Full release-gate pass · UAT · production readiness review | — | everything above |
| *if gates open* | `pre-need-contracting` · `plot-inventory-and-reservation` · `visitation-booking` · `memorial-and-qr` | — | `G-LEGAL-01` · `G-PLOT-01` · `G-VISIT-01` · `G-MEM-01` — **not MVP acceptance** |

**Rough total to MVP acceptance: ~16 sprints ≈ 8–9 months with the assumed 1-developer team, or ~4–5 months with 2–3 developers.** Order-of-magnitude planning figure, **not a commitment**. Dominant uncertainties: the payment gate, the production database provider, and whether the vendor portal can be phased.

All 27 specs appear either here or in Sprints 1–5 (§2.4). None is unscheduled.

---

## 10. Human review gates — consolidated

`AGENTS.md`: *"AI agents may prepare migrations and deployment changes but human review is mandatory before security, authorization, financial, privacy, destructive migration, DNS, firewall, or production-affecting changes."*

An agent may **prepare and propose** every item below. **None may be executed without recorded human approval.**

| Gate | Task | Category | Why |
|---|---|---|---|
| **G1** | S1-T3 | Destructive DB | Creating databases/roles; Route B would delete a volume |
| **G2** | S1-T7 | Secrets | APP_KEY, DB credentials, per-env separation |
| **G3** | S2-T5 | **DNS + firewall** | DNS records, IP allowlist, TLS; **risks the live `makam.co.id`** |
| **G4** | S2-T6 | Security config | Redis auth; requires restart |
| **G5** | S2-T7 | DB + storage | Backup credentials, remote storage, restore execution |
| **G6** | S4-T9 | Infrastructure | Capacity decision: upgrade / split / accept |
| **G7** | S4-T10 | Product/legal | Reservation TTL, certificate authority, public data minimums, consent, and 8 more — see §5, now 12 items total |
| **G8** | S5-T5 | Release | Gate report sign-off (release-gate dry run) |
| **G9** | S5-T6 | Migration + deploy | Rollback rehearsal, expand/contract |
| **G10** | S5-T7 | Product/brand | Brand primary (OQ-01), navigation contract (OQ-04), plus OQ-05/OQ-06 |

### Required pause conditions — agent must stop and ask

Per the execution checklist, regardless of sprint: SSH fallback unverified before disabling password login · destructive migration or volume deletion · firewall change that could remove current access · DNS/certificate ownership ambiguity · missing secret or provider account · any request involving production data or credentials · incompatibility requiring a baseline architecture change.

### Not delegable to an agent at all

Production access (prohibited outright) · secret **values** in chat or logs (prohibited) · financial gate activation · legal/privacy decisions (G7) · brand decisions (G10).

---

## 11. Estimates

### 11.1 Per sprint

Re-baselined 25 Jul 2026 for five sprints. The added Tier-0 sprint is **new work that was previously invisible**, not a re-estimate of existing work — the original plan simply had nowhere for identity, gates, and audit to be built.

| Sprint | Effort | Nominal | Realistic (1 dev @ ~4.5 productive pd/week) | Verdict |
|---|---:|---|---|---|
| 1 — Foundation | **12 pd** (4 tasks ✅ done) | 2 weeks | ~2.5 weeks | Tight but achievable |
| 2 — Design + infra | **19 pd** | 2 weeks | ~4 weeks | **Over-committed** |
| 3 — Tier-0 foundations | **21.5 pd** | 2 weeks | ~4.5 weeks | **Over-committed** |
| 4 — MVP slices | **27 pd** | 2 weeks | ~6 weeks | **Significantly over-committed** |
| 5 — Test + gates | **17 pd** | 2 weeks | ~3.5 weeks | Over-committed |
| **Total** | **~96.5 pd** | **10 weeks** | **~20.5 weeks** | |

Not counted above because it is **already done**: authoring the eight foundation specs and repairing the cross-spec conflicts (25 Jul 2026). Had that been planned rather than discovered, it would have been ~4 pd of specification work in Sprint 0.

### 11.2 Calendar sensitivity

| Team | Calendar for ~96.5 pd | Note |
|---|---|---|
| 1 senior dev + AI assist | **~20–21 weeks** | The assumed baseline |
| 2 senior devs | ~11–12 weeks | Sprints 2–4 parallelise reasonably well |
| 3 devs | ~8–9 weeks | Coordination overhead bites; Sprints 1 and 3 parallelise poorly |

**Sprints 1 and 3 barely parallelise.** Sprint 1's Track A (§3.1) is a serial chain with a human gate in the middle. Sprint 3 is Tier 0, where identity → scopes → audit → outbox is largely sequential and every piece is a prerequisite for the next. Adding people helps most in Sprints 2 and 4.

### 11.3 Honest reading

> **The four-sprint / eight-week shape is no longer achievable, and pretending otherwise would repeat the failure this plan exists to prevent.** Tier-0 foundations are not optional and were not in the original estimate. At the assumed team size the aligned content needs **~20 weeks**, not 8.
>
> Three legitimate options:
>
> 1. **Keep the content, plan ~20 weeks** (1 dev) or ~11–12 weeks (2 devs). Recommended.
> 2. **Keep 10 weeks and descope Sprint 4** to FAQ + homepage only, deferring the wizard and the directory. Tier 0 cannot be descoped.
> 3. **Add people from Sprint 2**, accepting that Sprints 1 and 3 stay serial.
>
> What is **not** legitimate: keeping both the scope and the dates. That is how the false-`Covered` failure mode (H-3) recurs at the schedule level — and it is the same shape as C-2 reporting `healthy` over a missing schema.
>
> Effort figures are **estimates**, not measurements. No task here has been executed except the §1.1 preflight.

---

## 12. Risk register — cross-sprint

| ID | Risk | L | I | Mitigation | Owner |
|---|---|---|---|---|---|
| R-1 | **nginx work breaks live `makam.co.id`** | Med | **High** | Config backup, `nginx -t`, apex regression test, rollback, G3 | Human + agent |
| R-2 | Silent-failure class recurs elsewhere | Med | **High** | S1-T4 pattern: assert *function*, not liveness, in every healthcheck | Dev |
| R-3 | Host capacity insufficient once PHP-FPM + Horizon run | **High** | Med | S4-T9 measurement + decision; builds stay in CI | G6 |
| R-4 | Payment gate `G-PAY-01` stays closed indefinitely | Med | **High** | Manual fallback is the designed path; no payment work in 1–4 | Product |
| R-5 | Production managed Postgres provider undecided (N-3) | **High** | **High** | Decide by Sprint 5; blocks all production planning | Product |
| R-6 | No object storage → blocks backups **and** uploads | Med | High | **OQ-4 — resolve before Sprint 2** | Product |
| R-7 | Scope creep from 27 specs into Sprints 1–5 | **High** | Med | §2.2 exclusion list is binding | PM |
| R-8 | Design system diverges once real screens land | Med | Med | CI gates + ADR path (design §9.4) | Dev |
| R-9 | Single-developer bus factor | Med | High | ADRs, this plan, and the evidence packs are the mitigation | PM |
| R-10 | 2/4 host mistaken for production capacity evidence | Med | High | `release-gates.md` §I forbids it; restate in every report | Dev |
| R-11 | Documentation drifts from code once code exists | **High** | Med | `AGENTS.md` requires doc updates with behaviour changes; add to PR template | Dev |

### New findings surfaced while planning

These were not in the original analysis and need tracking:

| ID | Finding | Sev | Where |
|---|---|---|---|
| **N-1** | The init script creates **one** role per environment, but `database-backup-and-recovery.md` §8 requires **separate application and migration roles** with least privilege. **Still open** (contradicts an earlier version of this row that said "Fixed in the S1-T3 DDL" — the actual `postgres-init/01-create-databases.sh` script's own TODO comment confirms only one role per environment exists; surfaced again by the Batch 2.6C backup-prep agent 25 Jul 2026, since `backup-staging.sh` had to be built against the single-role pattern that's actually live). The split needs two newly provisioned secrets — a credential change requiring human approval, same as line 43 above already said. | Medium | S1-T3 |
| **N-2** | **No Content-Security-Policy** is defined anywhere in `security-baseline.md`. Cheap to add before the scaffold hardens; expensive to retrofit around Livewire/Alpine. | Medium | design OQ-10 → Sprint 1/2 |
| **N-3** | ADR-0021 requires **managed** PostgreSQL with PITR for production; today's setup is a self-hosted Docker container with no backups. **No provider has been chosen.** Blocks all production planning. | **High** | R-5, Sprint 16 |
| **N-4** | **A container attached only to an `internal: true` network silently ignores its `ports:` mapping.** The deployed compose declared `127.0.0.1:8081:80` for both placeholders while `docker port` returned nothing — so dev and staging were unreachable for reasons unrelated to nginx. The repo example always had a second `egress` bridge network; the deployed file had dropped it. Fixed 25 Jul 2026 by restoring `egress` and attaching both placeholders to `[backend, egress]`. **The real `dev-web`/`stg-web` containers need the same pair.** | **High** | S2-T5, drift H-5 |
| **N-5** | Placeholder HTML was mode `0660` owned by uid 1000, but nginx inside the container runs as uid 101, so it could not read the file — a 403 waiting to happen even once the port worked. Fixed to `0644`. Generalises: **any bind-mounted asset must be readable by the container's runtime uid, not just by the host owner.** Same class of defect as C-2. | Medium | S2-T5 |
| **N-6** | `sudo mkdir` on this host produces `drwxr-x---` (root umask 077), so an ACME webroot created that way is untraversable by `www-data` and returns 404. Certbot creates its own directories correctly during issuance, but a pre-existing restrictive directory would break **renewal** silently, 89 days later. Fixed to `0755` and verified the challenge path returns 200 without auth. | Medium | S2-T5 |
| **N-7** | Two canonical documents define **different, incompatible** Pre-Need status enums. [`order-lifecycle.md`](../domain/order-lifecycle.md) §5: `INTEREST_REGISTERED -> CONTACTED -> CLOSED`. [`.kiro/specs/pre-need-contracting/design.md`](../../.kiro/specs/pre-need-contracting/design.md): `INTEREST, CONSULTING, PROPOSED, RESERVED, CONTRACT_PENDING, ACTIVE_PAYMENT, SETTLED, CERTIFIED, ACTIVATED, CANCELLED, DEFAULTED` (itself marked "final approval required before implementation" — provisional). Different vocabulary entirely, not a subset/superset relationship. Verified directly against both files 25 Jul 2026 (Batch 2.4, S2-T3) while building `StatusIntent` — surfaced there because assigning either list an intent/icon would have meant silently picking a winner between two canonical sources, which is a product decision, not an implementation one. Neither document has been edited; this needs a human to reconcile which one (if either, as written) governs. | Medium | pre-need-contracting, StatusIntent extension |
| **N-8** | Adding Kiro's `_Requirements: N_` task-traceability annotations across all 27 specs (25 Jul 2026, EARS conversion) surfaced acceptance criteria with **no corresponding top-level task**, and a small number of tasks with no clean matching criterion — pre-existing coverage gaps in each spec's own `tasks.md`, made visible by the annotation exercise, not introduced by it. Not fixed (adding tasks/criteria is a spec-authoring decision, out of scope for a reformatting pass): `certificates-and-agreements` AC8 (manual/external certificate reference); `funeral-case-management` (waiver handling only defined in the untouched Negative-criteria section, not a numbered AC); `funeral-marketplace-and-vendor-portal` AC10, AC12, AC15; `memorial-and-qr` AC7, AC8; `platform-identity-and-access` AC11, AC12; `platform-notifications` (queue-isolation task has no matching AC); `platform-outbox` AC12 (depth/age/lag observability — no alerting task exists anywhere in this repo, consistent with M-6/S2-T10 being prep-only); `platform-payment-adapter` AC11, AC14; `plot-inventory-and-reservation` AC1, AC9; `pre-need-contracting` AC5, AC7, AC8 (likely intentional — paid-flow implementation is explicitly deferred until `G-LEGAL-01`, per that spec's own Status line). | Low | multiple specs, `docs/planning/kiro-specs-analysis.md` follow-up |
| **N-9** | Batch 3.2's three concurrent worktree agents (audit, feature-gate, scopes — 26 Jul 2026) each correctly stayed inside their own file boundary, which means three small cross-cutting integration seams were left explicitly flagged rather than silently wired: (1) `ActorContext::$scopes` is still always `[]` — `ScopeAssignmentResolver::scopeStringsForActor()` exists and produces the right shape, but populating it means editing the already-merged `LocalUsersTableIdentityAccessAdapter`, outside Batch 3.2's scope; (2) `scope_assignments` has no append-only/tamper-evidence treatment of its own, even though a grant/revoke is a security-relevant mutation of the same class `audit_events` protects; ~~(3) `GateActivationRecorder`'s activation write path documents the exact `Audit::record()` call shape it wants but does not call it, since `Audit` didn't exist yet when Agent B started.~~ **Item (3) resolved 26 Jul 2026 (Batch 3.5):** `GateActivationRecorder::record()` now calls both `Audit::record()` (AC12) and `Outbox::record()` (AC9, blocked at the time on S3-T11, which landed in Batch 3.4) inside its existing `DB::transaction()`, alongside the `feature_gates` update and `gate_activations` insert — all four writes commit or roll back together. `GATE_CHANGE` was already declared on `SensitiveActions::ACTIONS`, so no change was needed there. Tests added to `tests/Feature/FeatureGate/GateActivationRecorderTest.php` proving the success shape and both failure-rolls-back-everything directions. Items (1) and (2) remain open. See finding **N-12** for a new gap surfaced while closing this one (no catalogued event name for a gate state change). | Low | `app/Platform/IdentityAccess/Adapters`, `app/Platform/IdentityAccess/Scopes`, ~~`app/Platform/FeatureGate/GateActivationRecorder.php`~~ (item 3 only) |
| **N-10** | S3-T10 (Batch 3.3, 26 Jul 2026) built the correlation-id **mechanism** — `CorrelationId` value object, `CorrelationContext` (`scoped()` holder, reset between Horizon jobs the same way `ActorContextResolver` is), `AssignCorrelationId` middleware (request-boundary origin, wired into both `web` and `/admin`), and a `CarriesCorrelationId` trait for a future `ShouldQueue` job to adopt — but it cannot be proven propagating into outbox events, queue jobs, provider calls, or notifications **end-to-end**, because none of those consumer classes exist anywhere in this repo yet: `app/Platform/Outbox/` is still an empty `.gitkeep` scaffold (S3-T11 builds the table/publisher next), zero `ShouldQueue` job classes exist, zero Notification classes exist, and zero outbound provider-call HTTP client code exists. Each piece is proved in isolation against test-only fixtures instead (mirroring N-9/Batch 3.2's `ScopedTestModel` precedent) — see `tests/Feature/Correlation/**` and `tests/Fixtures/CorrelatedTestJob.php`. **What S3-T11's outbox agent needs to do to consume this:** when constructing each `outbox_events` row, set its `correlation_id` column to `app(\App\Platform\Correlation\CorrelationContext::class)->current()?->value` (a plain nullable string; `null` if nothing was bound, which should not normally happen once `AssignCorrelationId` runs on every request/panel route, but the column must tolerate it) — read at the point of insert, inside the same transaction as the triggering mutation, exactly like `Audit::record()`'s existing `$correlationId` parameter already expects to receive it. **Correction (finding N-11, Batch 3.4, 26 Jul 2026):** the paragraph above says "set its `correlation_id` column" — that was written from `.kiro/specs/platform-outbox/design.md`'s paraphrase, before Batch 3.4 reconciled it against `queue-and-outbox.md`/`outbox-event-contract.md`/`event-catalog.md`. The actual column S3-T11 built is `trace_id`, not `correlation_id`. Sourced the exact same way described above (`app(CorrelationContext::class)->current()?->value`, read at the point of insert, inside the same transaction as the triggering mutation) — only the column name changes, not the mechanism. See N-11 and `2026_07_26_140000_create_outbox_events_table.php`'s own doc block for the full reconciliation. | Medium | `app/Platform/Correlation/**`, `app/Http/Middleware/AssignCorrelationId.php`, blocks full AC10/AC13 proof until S3-T11 (and later, real queue job/provider/notification classes) exist |
| **N-11** | S3-T11 (Batch 3.4, 26 Jul 2026) had to reconcile a column-name conflict `.kiro/specs/platform-outbox/design.md` introduces against `requirements.md`'s own cited authority chain, for the SAME `outbox_events` table. `design.md`: `event_type`, `correlation_id`, `claimed_at`, `claimed_by`, `published_at`, `attempts`. `docs/architecture/queue-and-outbox.md` §5 "Minimum outbox schema" + `docs/contracts/outbox-event-contract.md` — both named on `requirements.md`'s own "Authority" line, `design.md` is not — plus `event-catalog.md`'s own header line (independently confirms `trace_id`): `event_name`, `trace_id`, `attempt_count`, `locked_at`, `dispatched_at`. Resolved in favour of the second set: `requirements.md`'s authority chain does not name `design.md` at all, and two independently-cited documents agree with each other against it. `design.md`'s paraphrase is superseded on column NAMES only — its operational behaviour description ("a stale claim is reclaimed after a timeout") is not part of the conflict and `OutboxPublisher` follows it as-is. Full reasoning recorded in `2026_07_26_140000_create_outbox_events_table.php`'s own doc block. This also corrects finding N-10 above (see the addendum on that row), which told future readers to expect a column literally named `correlation_id` — written from `design.md` before this reconciliation. Two related judgement calls recorded in the same migration doc block: (1) no `actor_type`/`actor_id` columns — `queue-and-outbox.md` §5's cited "minimum" schema doesn't list them either, so the published envelope's `actor` key is emitted with null values rather than fabricating storage the cited schema doesn't have; (2) `available_at` WAS added (the batch brief flagged it as an open choice) because the cited schema lists it explicitly and AC6's bounded-backoff retry needs a real column to schedule reclaim eligibility against. | Medium | `app/Platform/Outbox/**`, `2026_07_26_140000_create_outbox_events_table.php`, corrects N-10 |
| **N-12** | Closing N-9 item (3) (Batch 3.5, 26 Jul 2026 — `GateActivationRecorder` now calls `Outbox::record()` for real) surfaced that `docs/contracts/event-catalog.md` has **no entry for a gate-state-change event**. Checked directly against the catalogue's own event table: 25 events listed, closest analogues are `cemetery.capability_changed.v1` and other domain-specific "X changed/confirmed" events, but nothing named anything like `feature_gate.state_changed` or `gate.activated`. `platform-outbox` AC3 ("use event types from `event-catalog.md` and SHALL NOT restate the catalogue") means this gap is not this class's to resolve unilaterally by inventing a catalogue entry. Resolution taken: `GateActivationRecorder` still writes the `outbox_events` row (AC9's actual requirement — "emit an outbox event," not "use a catalogued name"), using the clearly-provisional name `feature_gate.state_changed.v1`, following the catalogue's own `noun.verb_past_tense.vN` convention even though it is not itself a catalogued entry. **What still needs to happen:** whoever owns `event-catalog.md` should add a real `feature_gate.state_changed` (or a deliberately chosen alternative name) row, with Producer = `FeatureGate`/`GateActivationRecorder` and Main consumers = whichever projections/notifications AC9 anticipates ("dependent projections and notifications react") — none of which exist yet in this repo. Until that lands, `feature_gate.state_changed.v1` is a real, uncatalogued event type in production data, not a placeholder that disappears on its own. **Status 8 Aug 2026 — STILL OPEN, deliberately not fixed.** Both halves re-verified directly today: `GateActivationRecorder.php` still writes `eventName: 'feature_gate.state_changed.v1'` (line 174, and `tests/Feature/FeatureGate/GateActivationRecorderTest.php:144` still asserts that exact string), and `event-catalog.md` still lists 25 events with no gate row — a case-insensitive search for "gate" across that file returns only its header paragraph and the `quote.accepted.v1` row's "Payment gate" consumer cell, neither of which is an entry for this event. Not resolved here because adding a catalogue row is a documentation-ownership decision and `event-catalog.md` is not this batch's file — the same boundary N-7, N-13 and this finding's own original entry already applied. **One correction to how this gap gets described:** the risk is that uncatalogued events accumulate in real data, and it is worth being exact about how far that has actually gone. Queried the live dev database on this host 8 Aug 2026 (`psql -U postgres_admin -d makam_dev`): `outbox_events` holds **0 rows** and `gate_activations` holds **0 rows**. So the table is live — the 26 Jul deployment ran `create_outbox_events` along with the other 32 pending migrations — but no uncatalogued event has been written yet, because no gate has actually been activated on a deployed environment. The accumulation starts with the **first real gate activation**, not with the deployment that already happened. That is the window this finding still has: fixing the name costs nothing today and costs a data migration over live rows afterwards, since renaming later means either rewriting accumulated `event_name` values or tolerating two names for one concept in the same table. `makam_stg` was **NOT TESTED** (the query was blocked on this host; `stg-web` is still the placeholder container, so it is unlikely ever to have been migrated, but that is inference, not evidence). | Medium | `docs/contracts/event-catalog.md`, `app/Platform/FeatureGate/GateActivationRecorder.php` |
| **N-13** | S4-T2 (`public-faq` backend/domain batch, 26 Jul 2026) needed a schema-owning module for three tables `public-faq`'s own `design.md` names explicitly (`faq_categories`, `faq_articles`, `faq_article_versions`) — `app/Domain/README.md`'s rule is one directory per module boundary listed in `docs/architecture/overview.md` §5. Checked directly against that table: 23 modules listed (`IdentityAccessAdapter` through `FeatureGate`), and **none of them is `Faq`** — a genuine gap between the module-boundary table and a spec that clearly needs to own tables, not a naming mismatch (no existing row's "Responsibility" text covers FAQ content either). Resolution taken: created `app/Domain/Faq/{Models,Actions}` anyway, following the established one-directory-per-module convention despite the missing table row — the alternatives (cramming these three tables into an unrelated existing module's directory, e.g. `NotificationAdapter` or `CemeteryDirectory`, or skipping the directory convention entirely for this one spec) are both worse than the gap this creates, and `app/Domain/README.md` itself already anticipates modules being added over time ("Created empty during the Sprint 1 scaffold... module boundaries are never successfully retrofitted after features exist"). **What still needs to happen:** whoever owns `docs/architecture/overview.md` next should add a `Faq` row to §5's module table (suggested Responsibility text: "Public FAQ categories, articles, versioning, and publish lifecycle"), so the table and the actual `app/Domain/` tree agree again. Not resolved here — adding a row to a document this batch does not own is a documentation-ownership decision, not an implementation one, the same reasoning N-7's Pre-Need status-enum gap and N-12's event-catalogue gap already applied to their own out-of-scope document edits. **Correction, 09 Aug 2026 (retrofit-faq): closed.** `docs/architecture/overview.md:94` now has the row: `| Faq | Public FAQ categories, articles, versioning, and publish lifecycle |` — the exact suggested text this finding proposed. Verified directly; the table and the `app/Domain/` tree agree again. | Medium | `docs/architecture/overview.md` §5, `app/Domain/Faq/**`, `app/Domain/README.md` |
| **N-14** | S4-T2's public FAQ Livewire batch (26 Jul 2026) hit two CI failures that looked Livewire-specific (`ErrorException: Undefined variable $loading` on every `<x-mk.button>` call site, then — after those call sites were hand-written as a workaround — a separate `ParseError: syntax error, unexpected token "]"` in a compiled `card.blade.php`) and initially chased a Livewire-rendering-context theory (`ExtendBlade`, `DeterministicBladeKeys`, `SupportMorphAwareBladeCompilation`) through Livewire's real installed source on the sibling `platform-galang-dana-app` host. That theory was wrong. **Confirmed root cause, reproduced deterministically against the real installed Blade compiler** (`Illuminate\View\Compilers\BladeCompiler::compileString()`, `laravel/framework` v13.22.0): `compileString()` calls `storeUncompiledBlocks()` — which extracts `@php ... @endphp` blocks into raw placeholders — **before** `compileComments()` strips `{{-- --}}` comments. Its regex, `preg_replace_callback('/(?<!@)@php(.*?)@endphp/s', ...)`, is a plain non-greedy text scan with no awareness of comment boundaries. Seven `mk.*` primitives' leading `{{-- --}}` doc comments contained the literal substring `@php` as prose (e.g. card.blade.php: "classes composed once in a single `@php` block"; button.blade.php: "built once in `@php`, merged..."). Since that prose `@php` appears *before* the file's real `@php` directive, the regex matches starting there instead, and non-greedily swallows everything up to the real `@endphp` — including the comment's own closing `--}}`, the real `@props([...])` declaration, and the entire real `@php` block — into one opaque raw-PHP placeholder. Two consequences, both reproduced and confirmed by directly running the affected files through `Blade::compileString()` + `php -l`: (1) with the comment's closing `--}}` gone, the still-open `{{--` no longer terminates, so *later* prose mentions of `<x-mk.button>`/`<x-mk.card>` (used throughout this codebase as backtick-free doc references) survive uncommented into `compileComponentTags()` and get compiled as real, unclosed component tags, corrupting the file — this is the literal mechanism behind the `unexpected token "]"` ParseError; (2) because `@props([...])` itself gets swallowed into the inert placeholder, it never compiles, so every declared prop (including `loading`) is genuinely undefined at render time — this is the actual, confirmed explanation for the original `Undefined variable $loading` error too; Batch 3.2's nested-named-slots diagnosis and this batch's own earlier Livewire-rendering-context theory were both misdiagnoses of the same underlying bug. **Fix applied**: reworded the seven affected doc comments (`modal.blade.php`, `button.blade.php`, `table.blade.php`, `card.blade.php`, `field.blade.php`, `header.blade.php`, `stepper.blade.php`) so their prose no longer contains the literal substring `@php` (e.g. "one `@php` block" → "one PHP block"); also reworded two stray `<x-mk.button>` mentions inside `card.blade.php`'s and `field.blade.php`'s own real `@php` blocks (their `//` comments, which are never touched by `compileComments()` at all since that method only strips `{{-- --}}` syntax) so they read `button.blade.php` instead. Verified by compiling all twelve `mk.*` primitives plus `layouts/app.blade.php`, `livewire/public/faq/index.blade.php`, and `livewire/public/faq/article-detail.blade.php` through the real installed Blade compiler on the sibling host and confirming clean `php -l` output for every one (previously: `card.blade.php` alone reproduced the exact `unexpected token "]"` at line 30, byte-for-byte matching the real CI failure). A full repository sweep (`grep` every `{{-- --}}` comment across `resources/views/**/*.blade.php` for a literal `@php`/`@endphp` substring) confirms zero remaining instances. The hand-written `<a>`/`<button>` markup at the FAQ views' and `<x-mk.header>`'s call sites was **not** reverted back to `<x-mk.button>` in this fix — it is still correct, functioning code, and reverting it is optional future cleanup, not required now that the actual compiler bug is fixed. `table.blade.php`'s one real `<x-mk.button>` usage (still unreachable — no Livewire view uses `<x-mk.table>` yet) is no longer at risk from this bug either, since `table.blade.php`'s own doc comment was part of this fix. **What still needs to happen**: nothing blocking — this is resolved. Optionally, a future batch may revert the hand-written button markup back to `<x-mk.button>` now that it is safe to do so, and should keep this exact class of bug in mind for any *new* `mk.*` primitive: never write the literal substring `@php` (or `@endphp`) as prose inside a `{{-- --}}` comment that precedes the file's own real `@php` block. | High (resolved) | `resources/views/components/mk/{modal,button,table,card,field,header,stepper}.blade.php` |
| **N-15** | Fixing N-14 (above) unblocked real HTTP renders of full Livewire pages for the first time, which immediately surfaced design-system.md **OQ-05** ("Which icon set?", still open) as a real CI failure rather than a documented open question: `InvalidArgumentException: Unable to locate a class or view for component [icon.bars-3]`. Seven `mk.*` primitives already call `<x-dynamic-component :component="'icon.' . $icon">` (button, badge, alert, field, stepper, gate-closed-page, and — critically — `header.blade.php`'s hamburger button, which passes the literal `component="icon.bars-3"` **unconditionally**, not behind a caller-supplied prop), but no `resources/views/components/icon/**` directory has ever existed in this repo — the whole icon set was always future work, and every other `icon.*` reference is conditional on an `$icon`/`$iconTrailing` prop that no current caller actually passes, so `<x-mk.header>` — rendered on every single page via `layouts/app.blade.php` — was the first and only one blocking real page renders. **Fix applied, deliberately scoped to exactly this one icon, not the whole set**: added `resources/views/components/icon/bars-3.blade.php`, built to OQ-05's own documented assumed default ("Outline, 1.5 px" — design-system.md §9.1), using the real, unmodified Heroicons v2 outline "Bars3Icon" glyph (MIT-licensed, 24x24 viewBox, `stroke-width="1.5"`), not a custom drawing. Verified by rendering `<x-mk.header>` and `layouts/app.blade.php` through the real installed Blade/Laravel view engine on the sibling host and confirming the output contains a real `<svg>` (component resolves and renders, not just compiles). **What still needs to happen**: OQ-05 itself remains open — resolving it for real means choosing (or confirming) an actual icon library/licence and building out every icon every `mk.*` primitive references (`x-mark`, `alert-circle`, `check`, and whichever icons `button`/`badge`/`gate-closed-page` callers eventually pass), not just this one. Do that as its own batch once OQ-05 is actually decided, not piecemeal per CI failure. | Medium | `resources/views/components/icon/bars-3.blade.php`, `docs/design/design-system.md` OQ-05 |
| **N-16** | `docs/contracts/openapi.yaml` defines **27 paths and zero of them are implemented**. Verified directly 8 Aug 2026: the `paths:` block holds exactly 27 entries (`/cemeteries` through `/orders/{orderId}/confirmation`); **`routes/api.php` does not exist at all** — `routes/` contains only Laravel's stock `console.php` and `web.php`; `routes/web.php` registers ten public Livewire page routes (`/`, three coming-soon stubs, three FAQ routes, two legal pages) and nothing else; `app/Http/` holds only `Controllers` and `Middleware` with no API controller, and no `Route::apiResource`, `prefix('api')`, or `/api/` string exists anywhere under `app/` or `bootstrap/`. **This is a gap, not a defect.** The contract labels itself `version: 0.3.0-draft` with the description "Draft application contract", and its single `servers:` entry is the placeholder `https://api.example.invalid/v1` — so nothing in this repo claims those endpoints are live, no client consumes them, and the MVP as scoped in §2.1 is server-rendered Livewire, not an HTTP API. A contract-first project is entitled to an unimplemented contract. What makes it worth tracking is the **missing binding between the two**, in three parts. (1) **CI's check proves far less than its name suggests.** The `.github/workflows/ci.yml` step named "Validate OpenAPI 3.1 structure" asserts only that the `openapi` key starts with `3.1` and that `paths` is non-empty, then prints the path and schema counts. It never compares one path against a route, a controller, a request shape, or a response shape. `ci/verify-docs.sh` does not reference `openapi.yaml` anywhere. So a green pipeline reports "OpenAPI validated" when the strongest claim actually established is "this YAML parses and has at least one path." (2) **No sprint owns the contract.** §2.4 and Appendix A2 map all 27 specs to sprints, and §2.2 lists what is deferred — but neither names `openapi.yaml`, and no task in §§4–8 says "implement the API contract." There is therefore no scheduled point at which contract and implementation are reconciled. (3) `AGENTS.md` §Documentation requires the API contract to be updated when behaviour changes, which is a rule with no mechanical enforcement behind it — Sprint 4 shipped FAQ, homepage and master data without touching `openapi.yaml`, and nothing detected that, correctly or otherwise. **What still needs to happen:** first, record the contract's actual status — either "contract-first, implementation deferred to Sprint N" or "reference contract for an external integration this application will never serve" — in the file's own `description` or in §2.2, because those two readings imply completely different future work and the file currently supports both; second, once that decision exists and any path is genuinely implemented, add a real contract-versus-route check to CI (at minimum: every documented path plus method resolves to a registered route) so the gate stops overstating what it proves; third, until then, do not read a green "Validate OpenAPI 3.1 structure" job as evidence that any API exists. Not **High**: nothing is broken today, no consumer is affected, and no document promises these endpoints. Not **Low**: the contract has no owner, no sprint, and a CI step whose name implies assurance it does not deliver — which is exactly the shape of R-11 (documentation drifts from code once code exists) with a green check mark on top of it. | Medium | `docs/contracts/openapi.yaml`, `.github/workflows/ci.yml` (OpenAPI step), absent `routes/api.php`, R-11 |
| **N-17** | No catalogued event name exists for a booking-draft **lifecycle** event. `docs/contracts/event-catalog.md`'s only booking row is `booking.draft_submitted.v2` (producer: Booking), which is a *submission* event belonging to Step 9 — and Step 9 does not exist: `BookingWizardStep::LAST_IMPLEMENTED` is 5 and `SaveBookingDraftStep` throws `InvalidArgumentException` for any step above it, so the catalogued booking event is genuinely unproducible today. The 09 Aug 2026 outbox retrofit's two new producers (`StartBookingDraft`, `SaveBookingDraftStep`) therefore had no catalogue name to emit — the same disclosed-gap pattern finding N-12 already established when `GateActivationRecorder` needed `feature_gate.state_changed.v1`. Resolution taken, following N-12's precedent (AC3 constrains the outbox module's own general behaviour and forbids it from unilaterally inventing catalogue entries; it does not forbid a producer from emitting an event): the two rows are written with clearly-provisional names derived from the audit action names those same two Actions already write (`BOOKING_DRAFT_STARTED`, `BOOKING_DRAFT_STEP_SAVED`) so they translate existing vocabulary rather than invent new — `booking.draft_started.v1` and `booking.draft_step_saved.v1`, both falling back to the `default` queue per `OutboxQueueRouter`. **What still needs to happen:** whoever owns `docs/contracts/event-catalog.md` next should either add the two rows (or the right lifecycle-event vocabulary) or rule that draft-lifecycle events do not belong in the catalogue — one of the two, not silence. Not resolved here: editing that document is a documentation-ownership decision, the same reasoning N-12 and N-13 already applied to their own out-of-scope document edits. | Medium | `docs/contracts/event-catalog.md`, `app/Domain/Booking/Actions/StartBookingDraft.php`, `app/Domain/Booking/Actions/SaveBookingDraftStep.php` |

### The three infra findings are now mechanical

`AGENTS.md` requires evidence, not assertion. N-4, N-5 and N-6 were each silent, and recording them in prose does not stop them recurring — so on 25 Jul 2026 they became gates in [`ci/verify-infra.sh`](../../ci/verify-infra.sh), the operations companion to `ci/verify-docs.sh`. It is read-only by design: a fix belongs in `compose.yml`, never in the checker.

| Gate | Catches | Origin |
|---|---|---|
| I1 | compose does not parse | — |
| **I2** | a service publishing a port from an internal-only network | **N-4** |
| **I3** | a declared port that is not actually listening | **N-4** |
| **I4** | a bind mount unreadable by the container runtime uid | **N-5** |
| I5 | `makam_dev`/`makam_stg` or their extensions missing | **C-2** |
| I6 | dev↔stg isolation broken | C-2 follow-up |
| I7 | Postgres or Redis reachable from the host | security-baseline |
| I8 | secret files not owned by uid 999 | C-2 root cause |
| I9 | `dev.makam.co.id` missing `noindex`, or unexpectedly *not* public | dev-staging §5 (updated, ADR-0031), release-gates §I |
| **I10** | ACME path behind auth — renewal would fail silently | **N-6** |
| I11 | the live apex broken by an nginx change | S2-T5 regression |

**Each gate was negative-tested, not just observed passing.** A gate that has never failed has never been tested — that assumption misled this project twice already (doc gate 7 twice). Verified 25 Jul 2026: removing `egress` from a compose copy fails I2 for both placeholders; `chmod 0600` on the placeholder HTML fails I4; `chown 1000` on a secret fails I8. State was restored and the full suite returns green.

Two gates remain **NOT negative-tested**: I5 (would require dropping a database) and I10 (would require reloading nginx without the auth bypass). Both are asserted from their positive result only.

**Certificate renewal IS verified.** `certbot renew --cert-name dev.makam.co.id --dry-run` completed on 25 Jul 2026 with *"Congratulations, all simulated renewals succeeded"* and exit 0. It had twice exceeded a 2-minute foreground timeout and was reported as unverified in the preceding commit; that reservation is now withdrawn. The full ACME round trip works through basic auth, which was the risk gate I10 exists to guard. **Basic auth was removed from `dev.makam.co.id` later the same day (ADR-0031)** — GATE I10 remains valid and unchanged: with no auth at all, the ACME path is trivially reachable, so the condition I10 guards against cannot occur on dev while ADR-0031 stands.

---

## 13. OPEN QUESTIONS

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
| **OQ-9** | **Who is the human reviewer for the §10 gates,** and what is their availability? Ten gates across five sprints with a part-time reviewer is a real bottleneck. | Undecided | All gates | **Before Sprint 1** |
| **OQ-10** | **Is `ekspektasi-user` canonical?** It is the raw stakeholder workflow, currently untracked and un-ignored. Track it as canonical, or ignore it explicitly. | Track it | S1-T9 | Sprint 1 |
| **OQ-11** | **Content-Security-Policy** (N-2) — define now or defer? Deferring means retrofitting around Livewire/Alpine. | Define in Sprint 1–2 | design OQ-10 | Sprint 2 |
| **OQ-12** | **Is the 8-week calendar fixed, or is the scope fixed?** §10.3 — both cannot hold. | Undecided — **needs an explicit answer** | Everything | **Before Sprint 1** |

---

## 14. NOT TESTED / assumptions

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
| **M-6** | Medium | 4 | S4-T9 | Capacity review ⚠️ G6 |
| **L-1** | Low | 2 | S2-T6 | `pg_hba` trust (container-local only) |
| **L-2** | Low | 1 | S1-T9 | `compose.yml` mode 0660 |
| **L-3** | Low | 2 | S2-T5 | `git-askpass` sudo path + hardcoded username |
| **L-4** | Low | 2, 5 | S2-T9, S5-T8 | Version alignment to v0.6 |
| **L-5** | Low | 5 | S5-T8 | `CLAUDE.md` ↔ `AGENTS.md` |
| **L-6** | Low | 4 | S4-T10 | 12 open decisions (was 5; list grew) ⚠️ G7 |
| **N-1** | Medium | 1 | S1-T3 | Separate app/migration roles — **new**; needs 2 provisioned secrets |
| **N-2** | Medium | 1–2 | OQ-11 | No CSP defined — **new** |
| **N-3** | High | 15 | OQ-6 | No managed Postgres provider — **new** |

## Appendix A2 — Spec → sprint traceability (all 27)

Companion to §2.4. Every spec has a sprint; every sprint task in §§4–8 names its spec.

| Sprint | Specs |
|---|---|
| 1 | scaffold + module namespaces for all 27 |
| 2 | design system (`design-system.md` + `tokens.css`) |
| **3** | `platform-identity-and-access` · `platform-feature-gate` · `platform-audit` · `platform-outbox` (min) |
| **4** | `public-faq` · `public-home-and-navigation` · `cemetery-directory-and-availability` · `public-booking-wizard` (1–5) · `booking-and-order-orchestration` (draft) · `admin-operations` (FAQ CMS) · `funeral-marketplace-and-vendor-portal` (browse) · `renewal-and-grave-registry` (search) · `package-and-service-bundles` (seeds) |
| 5 | none — verification of Sprint 4 output |
| 6 | `platform-notifications` · `platform-document-vault` · `platform-outbox` (full) |
| 7 | `public-booking-wizard` (6–9) · `booking-and-order-orchestration` (full) · `package-and-service-bundles` · `admin-operations` (full) |
| 8–9 | `platform-payment-adapter` · `platform-financial-ledger` |
| 10 | `funeral-case-management` · `at-need-booking` · `cemetery-operator-dashboard` |
| 11–12 | `funeral-marketplace-and-vendor-portal` (full + vendor portal) |
| 13 | `renewal-and-grave-registry` (full) · `recurring-care-subscriptions` · `grave-care-fulfillment` |
| 14 | `certificates-and-agreements` |
| gate-dependent | `pre-need-contracting` · `plot-inventory-and-reservation` · `visitation-booking` · `memorial-and-qr` |

## Appendix B — Weekly measurable progress

Because "every week has measurable progress" was an explicit goal. Sprint 3 is the sprint most at risk of feeling like no progress, so its weeks are given concrete demonstrables.

| Week | Sprint | Demonstrable at week's end |
|---|---|---|
| 1 | 1 | ✅ `makam_dev`/`makam_stg` exist with `pg_trgm`; secrets readable by uid 999; healthcheck asserts schema *(done 25 Jul 2026)* |
| 2 | 1 | Laravel 13 boots; `migrate` succeeds; CI green on a PR; lockfiles committed |
| 3 | 2 | Tailwind build emits token-driven CSS; contrast gate blocking merges; first `<x-mk.*>` primitives rendering |
| 4 | 2 | `dev.`/`stg.` reachable over TLS with `noindex` (`stg.` allowlisted, `dev.` intentionally public per ADR-0031); Redis authenticated; restore evidence recorded |
| 5 | **3** | A privileged user must enrol TOTP to reach a panel; cross-scope access denied by test |
| 6 | **3** | A closed gate returns an explanatory page; a mutation without its audit record fails a test; commit-then-crash still publishes its outbox event |
| 7 | 4 | Seeds loaded from canonical catalogues; **FAQ complete** — public + admin CMS, browser-tested |
| 8 | 4 | Homepage 4 cards in exact order + booking Steps 1–5 with working autosave/resume |
| 9 | 5 | E2E-HOME/FAQ/BOOK(1–5) green; axe clean on delivered screens |
| 10 | 5 | Release-gate report published; rollback rehearsed; Sprint 6+ backlog groomed |
