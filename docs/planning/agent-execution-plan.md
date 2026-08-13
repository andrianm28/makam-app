# Agent Execution Plan — all sprints

> **Superseded, 09 Aug 2026.** This document describes the pre-Superpowers batch model. New work and retrofits follow `AGENTS.md` §Development methodology. Retained for historical record of Sprint 1-4's actual execution.

**Version:** v0.1
**Date:** 25 Juli 2026
**Purpose:** turn the 51 sprint tasks and 378 Kiro spec tasks into concrete, batched Claude subagent assignments.
**Derived from:** [`sprint-plan.md`](sprint-plan.md) (what to build, in what order) and [`parallelization-analysis.md`](parallelization-analysis.md) (what can be concurrent, and what must not be)

---

## 0. How to use this document

Each sprint is broken into **batches**. A batch is a set of agents that can run **at the same time** because their file sets are disjoint. Batches within a sprint run in sequence; a batch does not start until the previous one has passed its gate.

```
BATCH  = N concurrent agents, disjoint files, one mechanical gate at the end
GATE   = ci/verify-docs.sh (+ the CI pipeline once it runs) — must pass before the next batch
HUMAN  = a review gate from AGENTS.md. Agents PREPARE; a human EXECUTES. Never agentized.
```

**Current position (25 Jul 2026):** Sprint 1 is 8 of 10 done. Sprints 2–5 are 1 of 41. Batches already completed are marked ✅ and kept for the record.

| Sprint | Tasks | Done | Human gates |
|---|---:|---:|---:|
| 1 Foundation | 10 | 8 | 2 |
| 2 Design + infra | 10 | 1 | 3 |
| 3 Tier-0 | 12 | 0 | 2 |
| 4 MVP slices | 10 | 0 | 2 |
| 5 Test + gates | 9 | 0 | 3 |

---

## 1. Global rules — these are not negotiable

Violating any of these is how a fan-out produces more work than it saves.

### 1.1 Never agentize

| Category | Why | Examples here |
|---|---|---|
| Live infrastructure | `makam.co.id` is live; the DB holds real state | S2-T5 DNS, S2-T6 Redis restart, S2-T7 backup execution |
| Anything behind a human gate | Needs judgement, not throughput | all 12 gated tasks |
| Single-writer artefacts | Two writers means a conflict or two sources of truth | `tokens.css`, `StatusIntent`, `app.css`, `composer.json`, CI workflow, canonical enums |
| Product or legal decisions | Not engineering work | marketplace multi-vendor, category codes, CSP, brand primary |

### 1.2 Migration collisions — the concrete mechanism

Concurrent agents writing migrations will collide on filename timestamps and on schema order. Two controls, both mandatory for any batch that touches `database/migrations/`:

1. **Pre-assign a timestamp slot per agent** in the batch brief. Example for a 3-agent batch on 2026-08-03:
   - Agent A → `2026_08_03_100000_*` … `2026_08_03_109999_*`
   - Agent B → `2026_08_03_110000_*` … `2026_08_03_119999_*`
   - Agent C → `2026_08_03_120000_*` … `2026_08_03_129999_*`
2. **Use `isolation: worktree`** so each agent works on its own checkout and cannot see a half-written sibling migration.

Table ownership is already declared normatively in each spec's `design.md` (see `kiro-specs-analysis.md` §5.1 and §5.3). An agent that needs a table it does not own **references it and reports the dependency** — it does not create it.

### 1.3 Reference implementation before fan-out

For any batch producing more than three similar artefacts, one agent builds the **first** one alone and its output becomes the convention brief for the rest. Without this, eight primitives get eight styles. This costs one serial step and saves a rewrite.

### 1.4 Gate before, gate after

`ci/verify-docs.sh` must pass **before** a batch launches (so failures are attributable) and **after** it lands. Once the CI pipeline runs, the same applies to it. A batch whose gate fails is not merged; the failing agent is re-briefed.

### 1.5 Review is the bottleneck, not agents

Agents change the rate of production, not of review. Four concurrent agents is the sweet spot (`parallelization-analysis.md` §2). Twelve human gates at one gate per week is already a twelve-week floor — see **OQ-9**, which matters more than agent count.

---

## 2. Agent task template

Every brief uses this shape. The H-1/H-3/M-2 batch on 25 Jul 2026 used it and all six agents returned usable work, including two that correctly refused to game a check.

```markdown
You are implementing <SPRINT TASK ID> — <one-line goal> in /home/ubuntu/makam-app.

## You own exactly these files. Touch nothing else:
<explicit list, or explicit glob>
## Explicitly NOT yours:
<files another agent in this batch owns, named so the agent does not open them>

## Spec
Read first: .kiro/specs/<spec>/requirements.md and design.md
Acceptance criteria in scope: AC<n>, AC<m>
Foundations you may CONSUME but must not redefine: <list>
Tables you own: <list>.  Tables you reference only: <list>.
Migration timestamp slot: <YYYY_MM_DD_HHMMSS> to <...>

## Design system (if any UI)
docs/design/design-system.md and resources/css/tokens.css are the single source
of truth. Never hardcode a hex, px, ms, or shadow. Never use a Tailwind
arbitrary value. Implement all ten required UI states (§6) for every screen.
Resolve status through StatusIntent (§3.7) — never match on an enum in a view.

## Constraints
- Additive where possible. Do not restructure files you do not own.
- Do not generate an APP_KEY, a credential, or a secret of any kind.
- Do not touch live infrastructure.
- AGENTS.md: never report PASS for a check you did not execute. Use BLOCKED or
  NOT TESTED. If a gate fails for a reason you believe is wrong, report it —
  do NOT weaken the check to make it green.

## Verify before finishing (run these, report the raw output)
<explicit commands>
bash ci/verify-docs.sh

## Report back
What changed, the verification output, what you did NOT do and why, and any
finding you surfaced but did not fix.
```

The last two lines matter more than they look. On 25 Jul 2026 one agent found a false positive in `ci/verify-docs.sh` GATE 7 and reported it rather than deleting the legend that tripped it; another found a numerical contradiction between two planning documents and reported it rather than picking a number. Both were more valuable than the task they were given.

---

## 3. Sprint 1 — Foundation

**Status: 8 of 10 done.** The C-2 chain and the scaffold are complete.

| Batch | Agents | Scope | Gate |
|---|---:|---|---|
| ✅ 1.1 | serial | S1-T1…T4 C-2 chain | executed 25 Jul, G1 |
| ✅ 1.2 | 6 | S1-T5 H-1 · S1-T9 M-2 · ADR-0028 · ADR-0029 · H-3 · L-4 | doc gates PASS |
| ✅ 1.3 | serial | S1-T6 scaffold · S1-T8 CI | doc gates PASS |
| **1.4** | **1** | ADR-0030 scaffold decision (records the OQ-1 outcome: fresh skeleton, no starter kit) | doc gates |
| **HUMAN** | — | **S1-T7** `.env.dev`/`.env.stg`, APP_KEY generation — **gate G2** | — |

**Batch 1.4 brief:** owns `docs/adr/0030-fresh-laravel-skeleton.md` only. Records why a starter kit was rejected (opinionated auth conflicts with the K1/K2 `IdentityAccessAdapter` boundary and mandatory MFA), what was pinned, and that no install has run. Status **Proposed**.

---

## 4. Sprint 2 — Design system + infra hardening

19 pd. **Four batches plus one prepare-only batch.** The first batch is serial and alone because everything downstream depends on its result.

### Batch 2.1 — 1 agent, serial, blocking

**S2-T1: wire `tokens.css` into Tailwind 4.3 and verify every utility.**

Owns `resources/css/app.css`, `vite.config.js`. This is the batch that **finally tests `design-system.md` §8.2**, which has been asserted-but-unverified since it was written.

Instruction that matters: *"Where the build disagrees with `design-system.md` §8.2, correct the document. Do not work around it in CSS."*

Must prove each of these generates: `max-w-form`, `max-w-prose`, `max-w-content`, `duration-fast`, `z-modal`, `z-header`, `touch-target`, `h-11`, `h-13`, `xs:`, `border-neutral-450`, `ease-standard`, `text-base` with its paired line-height. Verification is `npm ci && npm run build` plus grepping the emitted CSS.

**Gate:** frontend build green in CI. Nothing in Sprint 2 or later proceeds until this passes.

### Batch 2.2 — 1 agent, serial

**S2-T2a: `<x-mk.button>` as the convention reference** (design-system §3.1).

Owns `resources/views/components/mk/button.blade.php` plus a `spinner` partial. Six variants, three sizes, all states including `loading` that keeps the label and width. Its diff becomes the convention brief for Batch 2.3.

### Batch 2.3 — 4 agents, concurrent

**S2-T2b: the remaining eight primitives.** Disjoint files, no migrations, so no worktree needed.

| Agent | Owns | Spec |
|---|---|---|
| A | `field.blade.php`, `card.blade.php` | §3.2, §3.3 |
| B | `modal.blade.php`, `table.blade.php` | §3.4, §3.5 |
| C | `badge.blade.php`, `alert.blade.php` | §3.6, §3.8 |
| D | `stepper.blade.php`, `header.blade.php` | §3.9, §3.10 |

Each brief carries Batch 2.2's button as the convention exemplar. **Agent D must not build `bottomnav`** — design-system §3.11 marks it PROPOSED and NOT APPROVED pending OQ-4; the brief says so explicitly.

**Gate:** doc gates 2 and 3 (no hex, no arbitrary values) plus frontend build.

### Batch 2.4 — 1 agent, serial

**S2-T3: Filament 5 theme + `StatusIntent`.** Owns `resources/css/filament/**`, `app/Providers/Filament/**`, `app/Support/Design/StatusIntent.php`.

`StatusIntent` is mandated single by design-system §3.7 and is now referenced by eight specs — it cannot be split. This batch also resolves **OQ-09**: generate the Filament PHP palette from `tokens.css` rather than hand-editing it, and add a CI check that the two agree.

This is also where **design-system §8.3 finally gets verified** — the least reliable section in the whole spec.

### Batch 2.5 — 1 agent, serial

**S2-T4: add the six design governance gates to CI.** Owns `ci/verify-docs.sh`, `.github/workflows/ci.yml`. Single-writer.

### Batch 2.6 — 3 agents, PREPARE ONLY

The infra tasks are human-gated. Agents produce reviewed artefacts; a human executes them.

| Agent | Prepares | Human gate |
|---|---|---|
| A | nginx vhost for `dev.`/`stg.` + IP allowlist + `noindex`, as a file plus a runbook. **Must include an apex regression check** — `makam.co.id` is live | **G3** DNS + firewall |
| B | Redis `requirepass` config + per-environment prefixes/queues/Horizon namespaces + rollback note | **G4** |
| C | Encrypted daily staging backup script + restore procedure with an evidence template | **G5** |

Every brief ends: *"Do not execute. Do not run docker compose, systemctl, nginx -s reload, or any DNS change. Produce the artefact and the runbook only."*

**Blocked:** Agent C cannot finish without an object storage provider — **OQ-4**.

### Batch 2.7 — 1 agent

**S2-T10: observability** — structured logging config, container/memory/swap/disk monitoring, alert thresholds.

**Sprint 2 shape:** 1 → 1 → 4 → 1 → 1 → 3 (prepare) → 1. Peak concurrency 4.

---

## 5. Sprint 3 — Tier-0 foundations

21.5 pd. **The hardest sprint to parallelize** — identity → scopes → audit → outbox is largely sequential, and two tasks are human-gated.

### Batch 3.1 — 1 agent, serial, blocking

**S3-T1: `ActorContext`.** Owns `app/Platform/IdentityAccess/**`, plus its migration slot. Everything in Tier 0 consumes it, so nothing else starts.

Spec: `platform-identity-and-access` AC1, AC8.

### Batch 3.2 — 3 agents, concurrent, **worktree isolation required**

All three write migrations. Pre-assigned slots, per §1.2.

| Agent | Task | Spec / AC | Owns | Slot |
|---|---|---|---|---|
| A | S3-T8, S3-T9 audit | `platform-audit` AC1, AC2, AC4, AC5 | `app/Platform/Audit/**` | `…_100000` – `…_109999` |
| B | S3-T5, S3-T6, S3-T7 gates | `platform-feature-gate` AC1, AC2, AC5, AC7, AC10 | `app/Platform/FeatureGate/**` | `…_110000` – `…_119999` |
| C | S3-T4 scopes | `platform-identity-and-access` AC5 | `app/Platform/IdentityAccess/Scopes/**` | `…_120000` – `…_129999` |

Agent A's brief carries the one instruction most likely to be skipped: **withhold `UPDATE` and `DELETE` on `audit_events` from the application role at the database level.** An application-level convention will eventually be bypassed; a missing grant will not.

Agent B's brief must state that a misconfigured or unknown gate resolves **closed**, and that closing a gate switches behaviour but never removes a route or a booking step.

### Batch 3.3 — 1 agent, serial

**S3-T10: correlation-id propagation** across request → outbox → queue → provider → notification. Spans every module, so single-writer.

### Batch 3.4 — 1 agent, serial

**S3-T11: minimum outbox.** Owns `app/Platform/Outbox/**`. Needs correlation from 3.3. Deliberately minimum — table, `SKIP LOCKED` claim, routing, retry. Horizon supervisors and bounded replay are Sprint 6.

Test that must exist: **commit succeeds, dispatcher dies, event still publishes on recovery.** That is the whole reason the outbox exists.

### Batch 3.5 — 3 agents, concurrent

**S3-T12: authorization and audit test suites.** Fans out cleanly by surface.

| Agent | Owns |
|---|---|
| A | cross-panel access negatives (`tests/Feature/Authorization/Panel*`) |
| B | cross-record and cross-scope negatives (`tests/Feature/Authorization/Scope*`) |
| C | audit invariants: no committed mutation without its record; `UPDATE`/`DELETE` rejected (`tests/Feature/Audit/*`) |

### HUMAN — S3-T2 MFA, S3-T3 re-authentication

Agents prepare the TOTP enrolment/challenge/recovery flow and the re-authentication middleware. **A human enables mandatory MFA**, because it changes the authentication surface and can lock out the only admin. Brief requirement: enrol and verify a recovery path *before* enforcement.

**Sprint 3 shape:** 1 → 3 (worktree) → 1 → 1 → 3. Peak concurrency 3.

---

## 6. Sprint 4 — MVP vertical slices

27 pd. **Best fan-out in the plan** (81 % parallel).

### Batch 4.1 — 1 agent, serial, blocking

**S4-T1: master data and seeds.** Owns `database/seeders/**`, `app/Support/Catalog/**`.

Every slice consumes this. The critical constraint: **enums derive from the canonical catalogues**, never restated. That means the 5 launch regions, the 12 service codes from `service-catalog.md`, the 9 marketplace product codes from `marketplace-catalog.md`, and the 6 FAQ categories from `faq-catalog.md` each come from one definition. `AGENTS.md` forbids duplicating catalogue data.

**Blocked for marketplace categories:** the catalogue has 9 product codes but **0 category codes**, so `/marketplace/kategori/{categorySlug}` cannot be seeded. Product decision required.

> **Correction, 8 Aug 2026 — the ownership line above is wrong against the code that shipped.** The row is kept as written for the record; this note supersedes its "Owns" clause only. S4-T1 was delivered 26 Jul 2026 and owns **neither** of the two paths named above. Verified directly against the repository today:
>
> | Claim in the row above | What is actually true |
> |---|---|
> | Owns `database/seeders/**` | `database/seeders/` contains exactly one file, Laravel's **untouched stock `DatabaseSeeder.php`**. Nothing in CI, the Dockerfile, or any deployment script runs `php artisan db:seed` — the seed migrations' own doc blocks say so explicitly (`2026_07_26_170400_seed_faq_categories_and_articles.php`: "no CI pipeline or deployment process ever runs `php artisan db:seed`"). Seeder classes were never the delivery mechanism here |
>
> > **Update, 13 Aug 2026:** `App\Support\ExampleData\CemeteryExampleData` now
> > centralizes the example-cemetery data. Migrations still ship it (unchanged
> > delivery mechanism); a seeder (`CemeteryExampleDataSeeder`) makes `db:seed`
> > produce the same data idempotently for anyone who runs it.
> | Owns `app/Support/Catalog/**` | **`app/Support/Catalog/` does not exist.** `app/Support/` holds `CompanyInfo.php`, `ContactInfo.php`, and `Design/` — nothing catalogue-related |
>
> **The real ownership.** Master data ships as **timestamped data migrations** in `database/migrations/`, so it applies through the same `php artisan migrate` that creates the schema and needs no second deployment step: `…_120400_seed_feature_gate_registry.php`, `…_170400_seed_faq_categories_and_articles.php`, `…_180200_seed_marketplace_products_and_variants.php`, `…_180700_seed_service_definitions_from_catalog.php`, `…_190300_seed_cemeteries_and_capability_profiles.php`, plus three later dummy-data backfills (`…_200100`, `…_210000`, `…_220000`). The **closed-list constant classes** live one directory per module under `app/Domain/*/`, not under `app/Support/` — `ServiceCatalog/ServiceCode.php`, `ServiceCatalog/ServiceCategory.php`, `ServiceCatalog/FulfillmentOwner.php`, `Marketplace/ProductCode.php`, `Marketplace/MarketplaceProductCategory.php`, `Faq/FaqCategoryCode.php`, `CemeteryDirectory/LaunchCityCode.php`, `CemeteryCapability/*Mode.php`, and others.
>
> **The constraint the row states is unaffected and was met** — enums still derive from one canonical definition each, which is what `AGENTS.md` §Documentation actually requires; only the two directory paths were wrong. A future batch briefing an agent from this section must state the migration-plus-`app/Domain/` layout, because briefing `database/seeders/**` would send that agent to build a parallel, never-executed seeding path alongside the real one.

### Batch 4.2 — 6 agents, concurrent, **worktree isolation required**

The **Status** column was appended 8 Aug 2026 — the six rows are otherwise unchanged from the 25 Jul plan and are kept exactly as briefed, so the plan-versus-outcome comparison stays readable. Status values are sourced from [`sprint-plan.md`](sprint-plan.md) §7, which is authoritative for progress (`AGENTS.md`: *"`tasks.md` is planning only; issue tracker owns progress"* — no tracker is named yet, **OQ-8**).

| Agent | Slice | Spec / AC | Consumes | Slot | Status (8 Aug 2026) |
|---|---|---|---|---|---|
| A | **FAQ complete** — public + admin CMS | `public-faq` AC1–AC9, `admin-operations` AC6 | identity, audit, gates | `…_100000` | ✅ **Done** — S4-T2, 26 Jul 2026, CI green, **deployed to dev.makam.co.id** 26 Jul |
| B | Homepage — 4 cards exact order, 9 sections | `public-home-and-navigation` AC1–AC9 | gates, identity | `…_110000` | ✅ **Done** — S4-T3, 26 Jul 2026, CI green, **deployed to dev.makam.co.id** 26 Jul |
| C | Cemetery directory + capability resolver | `cemetery-directory-and-availability` AC1–AC12 | gates, audit, identity | `…_120000` | Not started — S4-T6; master data ready (S4-T1) |
| D | Wizard Steps 1–5 + stepper + autosave | `public-booking-wizard` AC1–AC6, AC11–AC13 | identity, gates, audit | `…_130000` | 🔵 **Implemented, pending merge/CI** — S4-T4, resumed 08 Aug 2026 and reviewed 09 Aug; built on an unmerged branch, booking test surface green locally, **no CI run and not deployed** |
| E | Draft persistence, versioning, server validation | `booking-and-order-orchestration` AC2, AC3 | audit, outbox | `…_140000` | 🔵 **Implemented, pending merge/CI** — S4-T5, built together with S4-T4 as one feature, same branch; **no CI run and not deployed** |
| F | Renewal search skeleton + marketplace browse | `renewal-and-grave-registry` AC1–AC5, AC14; `funeral-marketplace…` AC1–AC3 | gates, audit | `…_150000` | Not started — S4-T7 + S4-T8; catalogue seeded (S4-T1) |

**Build FAQ first if you must serialize any of these.** It is the cheapest complete vertical slice and it proves every layer — Livewire, Filament, design system, migrations, seeds, authorization, tests, CI. Finding a stack problem in FAQ costs 4 pd; finding it three-quarters through the wizard costs far more.

Two briefs carry a specific non-obvious requirement:
- **Agent C:** indicative availability renders `neutral` + `"Perlu konfirmasi"` — **never `success`**. An indicative price styled as success is a false promise.
- **Agent F:** three **distinct** empty states for grave search — no-result, privacy-limited, gate-closed. Collapsing them means a family concludes their relative's grave does not exist.

> **What the outcome says about this batch, 8 Aug 2026.** The batch above was planned as six concurrent worktree agents. It was not run that way. `sprint-plan.md` §7's "Execution methodology" describes what actually happened from S4-T2 onward — *"a background agent drafts a batch … then every batch is reviewed line-by-line against the spec and the real installed framework source before commit"* — i.e. one drafting agent at a time with a review gate between batches, not six in parallel. Two observations worth keeping:
>
> 1. **The two slices that completed are exactly the two this section told you to build first.** "Build FAQ first if you must serialize any of these" was followed, and A then B landed CI-green and deployed. That advice held up.
> 2. **D and E were treated as one unit of work, not two agents** — `sprint-plan.md` §7 records S4-T5 as *"built together with S4-T4, same feature."* That is a correction to this table's shape, not just its status: `public-booking-wizard` AC11–AC13 (D) and `booking-and-order-orchestration` AC2–AC3 (E) are precisely the overlapping autosave/step-validation criteria that the wizard-versus-orchestration Boundary exists to split (`kiro-specs-analysis.md` §5.4). Briefing them as two concurrent agents with disjoint file sets assumed a separation the specs had not yet made concrete. Anyone re-planning this batch should brief D+E as one agent, or land the Boundary note first.
>
> Neither observation invalidates the six-agent shape for A/B/C/F; it has simply never been exercised, which is what §11 already says about every batch in this document.

### HUMAN — S4-T9 capacity review (G6), S4-T10 five open decisions (G7)

**Sprint 4 shape:** 1 → 6 (worktree). Peak concurrency 6, but see §1.5 — six diffs land on one reviewer.

> **Correction, 8 Aug 2026 (updated 9 Aug).** Planned shape retained above for the record; **actual shape so far is 1 → 1 → 1** (S4-T1, then S4-T2, then S4-T3, each a serial batch with a review gate between), with S4-T4/T5 paused and S4-T6/T7/T8 not started. S4-T4/T5 were subsequently resumed as **one** serial batch (08–09 Aug), which does not change the observed peak. Peak concurrency observed in Sprint 4 is **1**, not 6. The §1.5 prediction — that review, not agent count, is the real constraint — is so far the thing this sprint actually demonstrates.

---

## 7. Sprint 5 — Test, accessibility, gate dry-run

17 pd.

### Batch 5.1 — 5 agents, concurrent

**S5-T1: browser suites**, one per journey per `test-strategy.md` §2. Files are disjoint by suite.

| Agent | Suite |
|---|---|
| A | E2E-HOME — four menu labels and order, desktop + mobile nav, gate explanatory state |
| B | E2E-FAQ — six categories, filter, search, detail, **no draft leakage** |
| C | E2E-BOOK Steps 1–5 — progress, back/forward, autosave/resume, five regions |
| D | E2E-REN — search, no-result, manual assistance, tariff source + timestamp |
| E | E2E-MKT — category/product coverage, canonical codes |

### Batch 5.2 — 3 agents, concurrent

**S5-T2: accessibility.** axe-core in the suite, keyboard walkthroughs, focus order, 200 % zoom, 320 px reflow, 44 px targets. Split by screen group: public / wizard / admin+vendor.

### Batch 5.3 — 2 agents, concurrent

- Agent A — S5-T3 authorization and query-scope tests for delivered surfaces
- Agent B — S5-T4 Lighthouse and weight-budget measurement against design-system §4.6, recording **actuals** rather than restating targets

### Batch 5.4 — 1 agent

**S5-T8: documentation reconciliation.** Update `screen-inventory.md` with the states actually delivered, and mark traceability `Covered` **only** where a test exists and passes. This is the batch that finally earns the word `Covered`.

### HUMAN — S5-T5 release-gate report (G8), S5-T6 rollback rehearsal (G9), S5-T7 brand + nav decisions (G10)

The gate report's expected honest outcome is **`NOT READY`**, with most of `release-gates.md` §§C–H marked `NOT TESTED` because they are out of scope. That report is the deliverable — it is what makes the remaining runway visible.

**Sprint 5 shape:** 5 → 3 → 2 → 1. Peak concurrency 5.

---

## 8. Sprints 6–16 — outline

Not planned at the same confidence. Agent counts are indicative.

| Sprint | Specs | Agents | Blocked on |
|---|---|---:|---|
| 6 | `platform-notifications`, `platform-document-vault`, `platform-outbox` (full) | 3 | **OQ-4** storage, **OQ-7** scanner |
| 7 | wizard Steps 6–9, orchestration (full), `package-and-service-bundles`, `admin-operations` | 4 | Sprint 6 |
| 8–9 | `platform-payment-adapter`, `platform-financial-ledger` | 2 + heavy gates | **OQ-5**, **FIN-DEC** |
| 10 | `funeral-case-management`, `at-need-booking`, `cemetery-operator-dashboard` | 3 | Sprint 7 |
| 11–12 | `funeral-marketplace-and-vendor-portal` full + vendor portal (9 screens) | 4 | Sprint 8–9 |
| 13 | renewal full, `recurring-care-subscriptions`, `grave-care-fulfillment` | 3 | Sprint 8–9 |
| 14 | `certificates-and-agreements` | 2 | `G-CERT-01` |
| 15 | performance certification, production environment | 2 | **N-3** provider |
| 16 | full release-gate pass, UAT | 1 + human | everything |

Payment and ledger get the **lowest** agent count despite being large. Almost every task there is behind a human gate or a financial approval, so throughput is not the constraint.

---

## 9. Wall-clock estimate

From `parallelization-analysis.md` §2, at 52 % parallelizable and four effective tracks:

| | 1 agent | 4 agents |
|---|---|---|
| Sprints 1–5 | ~20.5 weeks | **~12.5 weeks** |

Peak concurrency by sprint: 1 → 4 → 3 → 6 → 5. Sprint 4 is the only one that justifies six, and even there the constraint moves to review.

**If reviewer capacity is one gate per week, twelve gates is a twelve-week floor regardless of agent count.** Agent planning past four tracks is optimising the wrong variable until **OQ-9** is answered.

---

## 10. What must stay human

| Item | Sprint | Why |
|---|---|---|
| APP_KEY, `.env.dev`/`.env.stg` (G2) | 1 | Credential creation |
| DNS, firewall, IP allowlist (G3) | 2 | Live `makam.co.id` at risk |
| Redis auth (G4) | 2 | Restart of a live service |
| Backup credentials + restore execution (G5) | 2 | Credential + data |
| **Mandatory MFA enforcement** | 3 | Can lock out the only admin |
| **Re-authentication enforcement** | 3 | Changes the authentication surface |
| Capacity decision (G6) | 4 | Infrastructure spend |
| Five open product/legal decisions (G7) | 4 | Not engineering |
| Release-gate sign-off (G8) | 5 | Accountability |
| Rollback rehearsal (G9) | 5 | Migration + deployment |
| Brand primary, navigation contract (G10) | 5 | Product |
| Marketplace multi-vendor contradiction | — | Scope decision |
| Marketplace category codes | — | Canonical data |
| CSP definition (OQ-11) | — | Security decision |
| Managed Postgres provider (N-3) | 15 | Procurement |

---

## 11. NOT TESTED

- **This plan has never been executed at feature scale.** The only batch actually run is the six-agent documentation batch on 25 Jul 2026 (`parallelization-analysis.md` §7). Everything from Batch 2.1 onward is projected.
- **Agent counts and batch boundaries are estimates** derived from file-contention reasoning, not from observed collisions. The migration-slot mechanism in §1.2 is a design, not a proven control — it has never been exercised by concurrent agents.
- **The 12.5-week figure inherits every caveat** in `parallelization-analysis.md` §11, including the illustrative one-gate-per-week reviewer assumption. Actual reviewer availability is unknown.
- **Batch 2.1 and 2.4 may invalidate parts of this plan.** They are the first real test of `design-system.md` §8.2 and §8.3; if the Tailwind or Filament wiring differs from what the document asserts, downstream briefs change. That is expected, and the correct response is to fix the document.
- **No task here has been briefed to an agent.** The template in §2 is proven; the specific briefs are not written.
- Effort figures come from `sprint-plan.md` §11 and remain **developer** person-days, not agent-days (§1.5).
