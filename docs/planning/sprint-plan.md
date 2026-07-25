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
| **Tier-0 foundations** — identity + mandatory MFA, feature gates, append-only audit, minimum outbox | ✅ Implemented and tested (Sprint 3) |

### 2.2 Explicitly NOT in scope for Sprints 1–5

Deferred to Sprint 6+ (§9), now stated as **specs** rather than loose features, so the boundary is checkable:

**Foundations deferred:** `platform-notifications` · `platform-document-vault` (blocked on OQ-4) · `platform-payment-adapter` (blocked on OQ-5 / FIN-DEC) · `platform-financial-ledger` (blocked on FIN-DEC) · full `platform-outbox` (Horizon supervisors, bounded replay, 10k-import isolation).

**Feature specs deferred entirely:** `funeral-marketplace-and-vendor-portal` (cart, checkout, **vendor portal**) · `funeral-case-management` · `at-need-booking` · `package-and-service-bundles` · `cemetery-operator-dashboard` · `recurring-care-subscriptions` · `grave-care-fulfillment` · and all five gated specs (`pre-need-contracting`, `plot-inventory-and-reservation`, `certificates-and-agreements`, `visitation-booking`, `memorial-and-qr`).

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
| S1-T10 | ADR-0028 (design system) + ADR-0029 (platform foundation specs) | all | L-6 | 1 pd | — | ⚠️ 2 of 3 done; scaffold ADR still to write |

**Total: ~12 pd · 8 of 10 done (25 Jul 2026)**

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
| S2-T1 | Wire `tokens.css` → Tailwind 4.3; verify **every** utility in design-system §8.2 generates | design system | design §12 | 2 pd | — | ❌ open |
| S2-T2 | Build `<x-mk.*>` primitives (button, field, card, modal, table, badge, alert, stepper, header) | design system | design §3 | 4 pd | — | ❌ open |
| S2-T3 | Verify Filament 5 theming; implement `StatusIntent`; resolve OQ-09 palette duplication | design §3.7 + `booking-and-order-orchestration` | design §8.3, OQ-09 | 2 pd | — | ❌ open |
| S2-T4 | Add all six design governance gates to CI, incl. `verify-contrast.py` as hard fail | design system | design §9.5 | 1 pd | — | ❌ open |
| S2-T5 | nginx dev/stg vhosts + DNS + IP allowlist + TLS + `noindex` | infra | **M-1** | 2 pd | ⚠️ **HUMAN** | ❌ open |
| S2-T6 | Redis `requirepass` + separate prefixes/namespaces per environment | infra, `platform-outbox` prep | **M-5** | 1 pd | ⚠️ **HUMAN** | ❌ open |
| S2-T7 | Encrypted daily staging backup to remote object storage + **restore test with evidence** | infra | **M-4** | 2 pd | ⚠️ **HUMAN** | ❌ **blocked on OQ-4** |
| S2-T8 | Downgrade the 32 false `Covered` claims in the traceability matrix | traceability | **H-3** | 1 pd | — | ❌ open (verified: still 32 `Covered`, 0 tests) |
| S2-T9 | Align document versions to v0.6; register `docs/design/` + `platform-*` in Kiro steering | steering, `docs/specs/README.md` | **L-4**, design OQ-11 | 0.5 pd | — | ⚠️ partial — steering ✅, versions ❌ |
| S2-T10 | Basic observability: structured logs, container/memory/swap/disk monitoring | infra | M-6 prep | 1.5 pd | — | ❌ open |

**Total: ~19 pd** — the heaviest sprint relative to its length. See §11; this is the most likely candidate for a 3-week Sprint 2.

> **Alignment note.** S2-T3 delivers `StatusIntent`, which is the shared status → intent resolver mandated by design-system §3.7 and now referenced by **eight** specs (`booking-and-order-orchestration`, marketplace, admin, case-management, care ×2, plot-inventory, pre-need). It must be built once here, not per feature.

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

## 6. Sprint 3 — Tier-0 foundation implementation (Weeks 5–6)

> **This sprint did not exist in the original plan.** It was inserted on 25 Jul 2026 when the eight `platform-*` specs were authored and their consumers became explicit. §3.4 derives why: `platform-audit` is consumed by 17 of 19 feature specs, `platform-identity-and-access` by 16, `platform-feature-gate` by 12. Nothing ships before them, so they get their own sprint rather than being stubbed inside a feature sprint and torn out later.

### Goal

> Tier 0 exists and is enforced: an authenticated actor with resolved scope, a server-side gate registry with documented fallbacks, an append-only audit that cannot be bypassed, and a minimum outbox publisher — all with tests, before any feature consumes them.

### Tasks

| ID | Task | Spec | Effort | Gate |
|---|---|---|---:|---|
| S3-T1 | `ActorContext` resolved once per request; session guard for public and each panel | `platform-identity-and-access` AC1, AC8 | 2 pd | — |
| S3-T2 | TOTP enrolment/challenge/recovery; **mandatory MFA for all privileged roles** | `platform-identity-and-access` AC2, AC6 | 3 pd | ⚠️ **HUMAN** |
| S3-T3 | Re-authentication middleware for the six sensitive action classes | `platform-identity-and-access` AC3 | 1 pd | ⚠️ **HUMAN** |
| S3-T4 | Scope assignment model + mandatory query scopes per `rbac-matrix.md` | `platform-identity-and-access` AC5 | 2 pd | — |
| S3-T5 | Gate + flag registry from `assumptions-and-gates.md`; server evaluation, deny-by-default | `platform-feature-gate` AC1, AC2, AC10 | 2 pd | — |
| S3-T6 | Expose `PaymentMode` / `WhatsAppMode` / `PreNeedMode` / `GraveSearchMode` as mode values | `platform-feature-gate` AC7 | 1 pd | — |
| S3-T7 | Gate-closed explanatory-page pattern (design §6.4) + `intent=info` banner (design §6.9) | `platform-feature-gate` AC5 + design §6 | 1 pd | — |
| S3-T8 | `audit_events` with **database-level** append-only grants; single `Audit::record()` API | `platform-audit` AC1, AC2 | 2 pd | — |
| S3-T9 | Mutation+audit wrapper so the pair cannot be separated; metadata allowlist | `platform-audit` AC4, AC5 | 1.5 pd | — |
| S3-T10 | Correlation-id propagation: request → outbox → queue → provider → notification | `platform-audit` AC10 + `platform-outbox` AC13 | 1 pd | — |
| S3-T11 | Minimum outbox: `outbox_events` table, `SKIP LOCKED` publisher, queue routing | `platform-outbox` AC1, AC5, AC8 | 3 pd | — |
| S3-T12 | Authorization + audit test suite: cross-panel, cross-record, cross-scope negatives | all four Tier-0 specs | 2 pd | — |

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

### Tasks

| ID | Task | Spec | Consumes | Effort | Gate |
|---|---|---|---|---:|---|
| S4-T1 | Master data + seeds from the canonical catalogues; **enums derive from the catalogue, incl. the 9 marketplace product codes** | `cemetery-directory…`, `package-and-service-bundles`, `funeral-marketplace…` | audit | 3 pd | — |
| S4-T2 | **FAQ complete slice** — public + admin CMS + 6 categories + no draft leakage | `public-faq` AC1–AC9, `admin-operations` AC6 | identity, audit, feature-gate | 4 pd | — |
| S4-T3 | Homepage — 4 service cards exact order, 9 sections per IA §3, honest Urgent status | `public-home-and-navigation` AC1–AC9 | feature-gate, identity | 3 pd | — |
| S4-T4 | Booking wizard shell Steps **1–5** + autosave/resume across sessions | `public-booking-wizard` AC1–AC6, AC11–AC13 | identity, feature-gate, audit | 5 pd | — |
| S4-T5 | Draft persistence, versioning, idempotent save, server-side step validation | `booking-and-order-orchestration` AC2, AC3 | audit, outbox | 3 pd | — |
| S4-T6 | Cemetery directory + capability resolver + `"Perlu konfirmasi"` labelling | `cemetery-directory-and-availability` AC1–AC12 | feature-gate, audit, identity | 3 pd | — |
| S4-T7 | Renewal skeleton — city/cemetery selection + fuzzy search UI + **three distinct empty states** | `renewal-and-grave-registry` AC1–AC5, AC14 | feature-gate, audit | 2 pd | — |
| S4-T8 | Marketplace skeleton — category/product browse from seeded catalogue | `funeral-marketplace-and-vendor-portal` AC1–AC3 | identity, audit | 2 pd | — |
| S4-T9 | Capacity review with all tenants counted; decide upgrade vs split | infra | — | 1 pd | ⚠️ **HUMAN** |
| S4-T10 | Resolve the 5 open decisions in `assumptions-and-gates.md` §5 that block specs | **L-6** | — | 1 pd | ⚠️ **HUMAN** |

**Total: ~27 pd** — over a 2-week sprint for one developer. See §11.

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
| **10** | `funeral-case-management` · `at-need-booking` · `cemetery-operator-dashboard` | — | Sprint 7 |
| **11–12** | `funeral-marketplace-and-vendor-portal` — cart, single-vendor checkout, **vendor portal (9 screens)** | — | Sprint 8–9 |
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
| R-3 | Host capacity insufficient once PHP-FPM + Horizon run | **High** | Med | S3-T9 measurement + decision; builds stay in CI | G6 |
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
| **N-1** | The init script creates **one** role per environment, but `database-backup-and-recovery.md` §8 requires **separate application and migration roles** with least privilege. Fixed in the S1-T3 DDL. | Medium | S1-T3 |
| **N-2** | **No Content-Security-Policy** is defined anywhere in `security-baseline.md`. Cheap to add before the scaffold hardens; expensive to retrofit around Livewire/Alpine. | Medium | design OQ-10 → Sprint 1/2 |
| **N-3** | ADR-0021 requires **managed** PostgreSQL with PITR for production; today's setup is a self-hosted Docker container with no backups. **No provider has been chosen.** Blocks all production planning. | **High** | R-5, Sprint 16 |

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
| **L-6** | Low | 4 | S4-T10 | 5 open decisions ⚠️ G7 |
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
| 4 | 2 | `dev.`/`stg.` reachable over TLS with allowlist + `noindex`; Redis authenticated; restore evidence recorded |
| 5 | **3** | A privileged user must enrol TOTP to reach a panel; cross-scope access denied by test |
| 6 | **3** | A closed gate returns an explanatory page; a mutation without its audit record fails a test; commit-then-crash still publishes its outbox event |
| 7 | 4 | Seeds loaded from canonical catalogues; **FAQ complete** — public + admin CMS, browser-tested |
| 8 | 4 | Homepage 4 cards in exact order + booking Steps 1–5 with working autosave/resume |
| 9 | 5 | E2E-HOME/FAQ/BOOK(1–5) green; axe clean on delivered screens |
| 10 | 5 | Release-gate report published; rollback rehearsed; Sprint 6+ backlog groomed |
