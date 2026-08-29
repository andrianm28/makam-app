# SvelteKit + ElysiaJS + Bun + Drizzle Rewrite — Feasibility Analysis and Migration Plan

- **Date:** 29 August 2026
- **Status:** **Analysis only. Not a proposal, not an approved initiative, not a decision.** No ADR authorizes this stack. Nothing in this document may be treated as authorization to begin work.
- **Author:** AI agent, on request
- **Decision owner:** project owner (human). See §7.
- **Scope:** assess a complete stack rewrite of `makam-app` from Laravel 13 / Filament 5 / Livewire 4 / PHP 8.5 to SvelteKit (frontend) + ElysiaJS (API) + Bun (runtime) + Drizzle ORM (data access), retaining PostgreSQL.

> **Governance note, stated up front.** This target stack is currently **forbidden by this repository's own binding rules**. `AGENTS.md` §Pinned technology and dependencies pins "PHP 8.5, Laravel 13, Livewire 4, Filament 5, PostgreSQL 18, Redis 8.2, Node 24 LTS" and says "Do not introduce Octane, Kubernetes, Redis Cluster, OpenSearch, GraphQL, or **separate SPA** without an approved ADR and measured need." `docs/architecture/technology-baseline.md` §9 names "**separate React/Vue/Svelte frontend**" in its deferred list. `ci/version-matrix.yml` carries `separate-spa-frontend` in a machine-checked `forbidden_without_adr` list asserted by CI's `verify-versions` job. This document is written as decision support for a decision the owner has not made, and does not assume the mandate has changed. §1.4 sets out what would have to change for the mandate to permit this.
>
> Per `AGENTS.md` §Documentation, this document does not duplicate canonical catalogue data. Counts are cited to the file that owns them; paths are given in backticks rather than as links so they read as references, not as a rival index.

---

## 1. Executive summary

### 1.1 The bottom line

A full rewrite is **technically feasible and strategically inadvisable.** My recommendation is **do not rewrite** — and, if the owner wants a SvelteKit frontend anyway, take the bounded Option C in §4.3 (replace the public-facing Livewire surfaces only) rather than the full stack change.

Three findings drive this, in order of weight.

**Finding 1 — the expensive asset is not the code, it is the evidence.** `app/` holds 975 PHP files and roughly 96,000 lines. But `tests/` holds 439 files, **3,212 PHPUnit test methods**, and 71 Playwright browser tests; 338 of those 439 files (77%) require a real PostgreSQL database. `docs/domain/traceability-matrix.md` carries 71 tracked stakeholder expectations of which **66 are `Covered`** — meaning a named, existing, passing test asserts the row's claim, mechanically enforced by `ci/verify-docs.sh` GATE 7. `docs/testing/release-gates.md` carries 64 acceptance checkboxes, 41 ticked. A rewrite does not re-type 96k lines; it **re-earns 3,212 tests, 66 individually-argued coverage claims, 162 migrations already run against live customer data, and 37 ADRs' worth of settled decisions**. That is the real bill, and it is not reduced by the target stack being pleasant to write.

**Finding 2 — the target ORM structurally cannot host this codebase's invariant layer.** This system enforces its most safety-critical rules inside Eloquent's model lifecycle: **44 `booted()` hooks** (41 `saving`, 9 `deleting`, 3 `creating`) that assert closed-list membership, cross-table delete guards, and published-parent immutability; **16 models overriding `update()` / `performUpdate()` / `delete()`** to implement append-only and single-write-path guarantees. `app/Domain/OrderWorkflow/Models/Order.php` is the sharpest case: a two-flag `performUpdate()` guard whose net invariant is that `orders.status` cannot move without a persisted `order_status_events` row already existing, verified by querying the database rather than trusting the passed object. Drizzle ORM is a **SQL-first query builder with no model layer** — no lifecycle hooks, no dirty tracking, no `getOriginal()`, no global scopes. Hooks are an open feature request, not a feature. This is not a porting inconvenience; it is the **removal of the layer where these invariants currently live**, with no equivalent place to put them short of hand-written repository wrappers that nothing forces a caller to use. §3.4 develops this.

**Finding 3 — roughly 60% of the rewrite is Filament replacement, for zero user-visible gain.** `app/Filament/` is 217 files / 22,354 lines producing **~103 distinct admin surfaces** (69 resource pages, 9 custom pages, 14 relation managers, 4 widgets, 6 nested report panels), plus **120 `Action::make()` instances** with confirmation modals and inline form schemas and **105 notification call sites**. Filament supplies the table builder, form builder, infolist builder, filters, pagination, search, sorting, column toggling, modals, and toasts declaratively — `app/Filament/Admin/Resources/BookingOrders/Tables/BookingOrdersTable.php` produces a fully sortable, searchable, filtered, paginated table in **45 lines**. There is no comparable framework in the Svelte ecosystem; the closest option, SvelteAdmin, is a small community project, and the market's answer to "Svelte admin" is overwhelmingly *dashboard templates* (visual shells) rather than CRUD frameworks with authorization composition. Rebuilding ~103 internal screens that no customer sees, to reach parity with what exists today, is the single largest line item and the one with the weakest return.

### 1.2 Cost, at honest precision

I will not give a month figure — I have no visibility into team size, availability, or the owner's tolerance for feature freeze, and a fabricated number would be worse than none. What I can give is **relative scale, anchored to this repository's own delivery record.**

The entire application was built between 25 July and 28 August 2026 — **37 days, 1,037 commits, 124 merged PRs**, from a zero-code baseline, using an AI-agent-heavy parallel workflow. That is an unusually low marginal cost of code, and it is a genuine argument *for* the rewrite being cheaper here than the industry default. But note what that 37 days did **not** include: it did not include running a live payment path with real customer money, and it did not include re-proving 66 traceability rows against a system that already worked.

| Work package | Relative scale | Notes |
|---|---|---|
| Domain + Platform port (`app/Domain` 373 files, `app/Platform` 327 files) | **Large** | ~60k lines. Actions port cleanly in *shape*; the invariant layer does not (§3.4). |
| Filament admin replacement (~103 surfaces) | **Largest single item** | No framework leverage. Zero user-visible value. |
| Public Livewire surfaces (36 components, 43 Blade views) | **Medium** | The one package where SvelteKit is genuinely better. |
| Test suite re-creation (3,212 + 71 tests) | **Large** | Cannot be mechanically translated. §3.6. |
| Data migration + dual-run correctness | **Medium, high-risk** | Schema is kept, so this is smaller than typical — but §5.1. |
| CI/CD, deployment, 13 doc gates | **Medium** | 8 of 13 gates in `ci/verify-docs.sh` are path-coupled to `resources/`/`app/`/`tests/` and fail wholesale on a SvelteKit tree. |
| Governance (ADR supersession, re-evidencing) | **Small in effort, large in latency** | Blocks everything else. §1.4. |

Rough shape: **the rewrite is several times the cost of the original build**, because the original build had no correctness bar to clear other than its own tests, and the rewrite must clear the existing system's behaviour as its specification while a live beta keeps moving.

### 1.3 What I did not find

I looked for, and did not find, a stated technical pain point that the current stack is causing. There is no ADR, finding, backlog row, or incident record complaining about Laravel/Filament/Livewire performance, developer velocity, hiring, or operability. `ADR-0026: Require Performance Evidence Before Scaling Complexity` sets the bar that complexity increases need measured need — and no such measurement exists arguing the current stack is inadequate. `docs/planning/sprint-plan.md` estimated ~16 sprints (~8–9 months solo) to MVP acceptance; most of it shipped inside one month on the current stack.

The real open problems in this repository are **not stack problems**. From `docs/planning/sprint-plan.md` and `docs/testing/release-gates.md`: 23 of 64 release-gate boxes unticked; backup/restore prepared but **never executed** (S2-T7); per-environment credential separation open (S1-T7); production, staging and development all sharing **one host with no PITR and no HA** (ADR-0027 production graduation); PHPStan pinned at **level 2** across 975 files with a stale comment calling the codebase "a scaffold with almost no application code yet"; **zero coverage measurement** (`coverage: none` on all six setup-php invocations). A rewrite addresses none of these and would postpone all of them.

That asymmetry is the core of my recommendation. **The strongest case against the rewrite is not that it would fail — it is that the same effort spent on the current stack closes real, named, currently-open risks.**

### 1.4 What would have to be true for this to proceed

Not opinions — the repository's own mechanical requirements:

1. A new **ADR-0038** explicitly superseding **ADR-0017** (`Pin Supported Technology Baseline`, Accepted 23 July 2026) and formally resolving **ADR-0001** (`Use Laravel Modular Monolith`, still **`Status: Proposed`**) against Laravel. It would also sit against ADR-0018 (Horizon/Redis queues), ADR-0028 (token-driven design system, whose CI gates are Blade-path-coupled), and ADR-0026's evidence bar.
2. `ci/version-matrix.yml` edited to remove `separate-spa-frontend` from `forbidden_without_adr`, and `docs/architecture/technology-baseline.md` §9 amended.
3. **Measured need** — AGENTS.md's words. Today there is no measurement.
4. Human approval. `AGENTS.md` §Infrastructure-agent execution: an AI agent may *prepare* security/authorization/financial/production-affecting changes; "human review is mandatory" before them. A stack rewrite is all four at once.

**One honest counterweight, which cuts the other way.** The Laravel choice is *not* rank-1 authority here. `docs/governance/assumptions-and-gates.md` §4 states plainly: "**Laravel modular monolith is proposed, not mandated by RKS.**" ADR-0001 has never been accepted. So the stack is an assumption of record, not a stakeholder requirement — and an owner who wants to change it is not overriding the customer, only the engineering baseline. That is a materially lower bar than it first appears, and the analysis below should be read with that in mind rather than as a defence of Laravel for its own sake.

A complication worth naming: **RKS K23–K35 is rank-1 authority and is not in this repository.** `docs/planning/ekspektasi-vs-specs.md` records conformance to RKS *content* as `BLOCKED — the RKS source document is not in the repository`. Nobody working from inside this repo can assert a stack change is RKS-compatible. The requirements themselves are recoverable (all 13 K-codes are mapped to owning specs per `docs/planning/kiro-specs-analysis.md`), so a rewrite would inherit a mapped requirement set — but the unverifiability argues for conservatism.

---

## 2. Current system analysis

### 2.1 Scale

Measured in the worktree at commit `0d9a56d`:

| Area | Files | Lines |
|---|---:|---:|
| `app/Domain` (19 bounded contexts) | 373 | 29,856 |
| `app/Platform` (12 modules) | 327 | 30,596 |
| `app/Filament` (2 panels) | 220 | 22,354 |
| `app/Livewire` (36 public components + 7 admin report panels) | 50 | 8,470 |
| `app/Support`, `app/Http`, `app/Providers`, `app/Models` | 29 | 4,801 |
| `resources/views` (Blade) | 102 | 13,869 |
| `database/migrations` | 162 | 14,748 |
| `tests` | 459 | 89,235 |
| `docs` | 220 | 53,928 |

975 PHP files in `app/`. 108 Eloquent models. 50+ named web routes (`routes/web.php` is 684 lines). 41 Blade components under `resources/views/components/` (17 `mk/` primitives, 18 icons, 6 document-vault states).

**This is not a greenfield app.** `docs/product/screen-inventory.md` tracks 80 screens with 61 marked shipped — and that document is stale on the low side (it marks VND-080 vendor profile "not built" while `app/Filament/Vendor/Pages/Profile.php` exists at 230 lines and is registered). `docs/product/mvp-scope.md` §8's out-of-scope list is likewise stale: three of its nine exclusions (Paid Pre-Need, Memorial/QR, Visitation booking) have since shipped and are `Covered`. **The shipped product is materially wider than its own scope document.**

### 2.2 What the product is

An Indonesian cemetery and burial-services platform for Jabodetabek (Jakarta metro), brokering three commercial journeys against municipal TPU/TPS cemeteries and private funeral vendors: **grave booking** (`/pemesanan-makam`, a 9-step wizard), **funeral-services marketplace** (`/marketplace`), and **grave-lease renewal** (`/perpanjangan`), plus FAQ. All routes and labels are Indonesian. Canonical scope lives in `docs/product/mvp-scope.md`; do not re-derive it from this document.

Two product characteristics matter for any rewrite:

- **Money and civic records.** Payment sessions, a double-entry financial ledger, vendor payables and payouts, agreements, and certificates. A correctness defect here is a financial or legal defect, not a UX defect.
- **Honest gated fallbacks.** 17 governance gates (`docs/governance/assumptions-and-gates.md`) and 18 feature flags, each with a *specified and separately tested* closed-state UX. Gates are seeded as deny-by-default database rows with a private constructor so only `fromRecord()` can produce `open: true`, and `tests/Feature/FeatureGate/ClientSideTamperingCannotOpenAGateTest.php` asserts a client cannot open one. This "honest when degraded" discipline is a product requirement, not a nicety, and it is threaded through every surface.

### 2.3 The load-bearing patterns a rewrite must preserve or deliberately drop

These four are what make this codebase what it is. Each is currently enforced by a mechanism the target stack does not have.

**(a) Audit-wrapped single write paths.** `app/Platform/Audit/Audit.php` is a static class (deliberately not a facade, not container-bound). `Audit::wrap()` is precisely `DB::transaction(fn => [mutation, then record])` — the transaction *is* the audit guarantee. `Audit::record()` writes one row and opens no transaction, for callers that must skip the audit row on an idempotent no-op. Real call sites: **90 `wrap`, 53 `record`**. One table backs it, `audit_events`, append-only by application-level override only — and the class docblock is explicit that `AuditEvent::query()->update()` and raw SQL are *not* stopped, with the real fix (a `REVOKE` from a lower-privileged role) parked unexecuted in `app/Platform/Audit/sql/revoke-audit-mutations.sql` behind finding N-1.

Transaction semantics are **load-bearing and non-uniform**. `app/Domain/Renewal/Actions/MarkRenewalPaidOnline.php` writes two audit rows *inside* its transaction and a third from a `catch` block *outside* it, because a row written inside would roll back with the throw — and it documents that its own `DB::transaction()` is a savepoint under `ProcessWebhookEvent`'s outer transaction. That is not incidental style; it is the difference between having and not having a record of a refused anomaly.

**(b) Append-only and forward-only state** (ADR-0005). 12 dedicated exception classes; 16 models overriding `update()`/`performUpdate()`/`delete()`. `app/Domain/PlotReservation/Models/PlotReservation.php` throws on all three and deliberately leaves `create()` open, because every state transition appends a row. `app/Domain/OrderWorkflow/Models/Order.php` takes the harder route: `orders` rows legitimately change, so `performUpdate()` is conditional on one of two private authorization flags, each set only by a method that first verifies against the **database** that a qualifying `order_status_events` row exists. `$event->exists` is explicitly not consulted — the docblock calls it "a public, caller-writable property, so it is a claim rather than evidence."

Database-level backstops exist but are partial: `order_status_events_paid_once` (partial unique index, exactly-once `DIBAYAR`), `vendor_payables UNIQUE(vendor_id, source_type, source_id)`, a `vendor_orders_single_vendor` constraint trigger, and 5 migrations installing real Postgres triggers for the ledger and document layers. Everywhere else, **the application is the enforcement**.

**(c) Closed-list string columns.** No Postgres enum types anywhere, by explicit convention: `app/Domain/PlotInventory/PlotState.php` states it as "plain string column with application-layer validation, not a Postgres enum type — this codebase's established convention," so extending a list never needs a migration. Two mechanisms coexist: **57 native PHP backed enums**, and ~40 `final class` const-holders with a `KNOWN_*` array plus `isKnown()`/`assertKnown()`. Enforcement is three-layered and **inconsistently applied**: model `saving()` hooks (the primary mechanism), 55 DB `CHECK` constraints (covering only some columns — `grave_plots.plot_state`, `cemeteries.plot_tracking_mode` and `actor_role_assignments.role` have none), and form-level option lists derived from the same constants. A port must decide *per column* whether the database or the application is authoritative, and today the answer differs by table.

Declaration order is semantically load-bearing in at least one place: `ActorRole::KNOWN_ROLES` order **is** privilege precedence, read by `DocumentAccessPolicy::auditRoleFor()`.

**(d) Scope-based multi-tenancy — and it is not where you would expect.** `app/Platform/IdentityAccess/ActorContext.php` is an immutable value object (`identityReference`, `roles`, `scopes`, `lastAuthenticatedAt`), resolved per-request by `ActorContextResolver`, bound **`scoped()` not `singleton()`** — the docblock explains that Horizon workers boot once and process many jobs, so a singleton would leak job 1's actor into job 2. 8 roles, 6 scope entity types, 4 grant levels. `scope_assignments` uses soft revoke (`revoked_at`), never delete, and deliberately has no unique constraint because grants can be re-granted.

The subtlety that matters most: **`ScopeAssignmentGlobalScope` exists, is correct, and has zero domain adopters.** `grep` for `HasScopeAssignments` returns only the four files of the mechanism itself. Live enforcement is instead at the **panel boundary**, via `app/Filament/Vendor/Concerns/ScopesToCurrentVendor.php`, which overrides `Resource::getEloquentQuery()`. The reason is documented and good: `vendor_listings` and `service_areas` are also read by the *public* marketplace where the reader is a guest holding no grant, so a deny-by-default global scope would return zero listings to every visitor.

`getEloquentQuery()` is the chokepoint because it is **the one query every page of a Filament resource derives from** — list table, edit-page route-model resolution, and delete action alike. So `/vendor/produk/{id}/edit` for another vendor's listing is a **404, not an edit form**. Deny-by-default falls out of Laravel compiling `whereIn(col, [])` to an always-false clause. `getEloquentQuery()` is overridden **41 times** across the codebase.

Because it is a per-surface mechanism, it is backstopped by a **reflection test** — `tests/Feature/Filament/Vendor/VendorPanelScopingTest.php` walks `app/Filament/Vendor/**` and fails CI when a class is unscoped. Its docblock: "the structural test is what fails CI when someone adds a seventh, unscoped surface next month."

### 2.4 Structural tests that encode invariants

These deserve separate billing because they do not port; they must be re-derived from scratch against a new class layout.

- `tests/Feature/Filament/Vendor/VendorPanelScopingTest.php` — enumerates the vendor panel by walking the filesystem, fails on an unscoped surface.
- `tests/Feature/FinancialLedger/NoAutomatedPayoutPathTest.php` — asserts by source-tree walk that no automated-payout path **exists in the tree at all**, with four detection signals, and proves its own detector fires against synthetic samples. Its reasoning: "A test that called a payout and asserted no HTTP request went out would only prove the path was not taken on that call."
- `tests/Feature/Filament/Admin/ResourceStaticPropertyTypeContractTest.php` — reflects every admin resource and asserts none narrows an inherited Filament static property type. Written after an incident that "took the whole PHP/frontend/browser CI surface down."
- `tests/Feature/FinancialLedger/JournalAppendOnlyTest.php`, `tests/Feature/FeatureGate/ClientSideTamperingCannotOpenAGateTest.php`, `tests/Feature/Payment/WebhookCredentialRedactionTest.php`.

### 2.5 Testing, CI, and deployment as they stand

**Testing.** PHPUnit 12.5.31, not Pest (zero Pest packages, zero `#[Test]` attributes, zero `it(`/`test(` in PHP). 3,212 `test_` methods across 439 files; 77% use `RefreshDatabase`. Playwright 1.62.1 for E2E (13 specs / 71 tests) with `@axe-core/playwright` for accessibility; **no Dusk**. k6 for load. `phpunit.xml` nominally defaults to SQLite in-memory, but both CI and `docs/operations/local-test-recipe.md` export `DB_CONNECTION=pgsql`, which wins — and the recipe is emphatic: "**SQLite is deliberately not an option here: it masks real Postgres `uuid`-typed column behavior that this app's migrations rely on, so a green SQLite run does not mean the code is verified.**" The suite additionally cannot run on a normal host: it needs a pinned PHP 8.5 container plus Postgres 18 with `pg_trgm` and `unaccent`.

**CI.** `.github/workflows/ci.yml`, 756 lines, 8 jobs. The `php` job gates on `composer validate --strict`, `pint --test`, `phpstan analyse`, `php artisan test` against real Postgres 18 + Redis 8.2, `design:verify-filament-palette`, and `blade:verify-content-survival`. Separately: `docs-gates` (`ci/verify-docs.sh`), `verify-versions`, `frontend`, `contracts`, `security-audit`, `browser-test`, `load-test`, `build-image` (GHCR push + SBOM). **PHPStan level 2. No coverage measurement anywhere.**

`ci/verify-docs.sh` is 333 lines / 13 gates and is the mechanism that "makes parallel agent fan-out safe." **Eight of the thirteen are path-coupled** to `resources/`, `app/`, `.kiro/specs/`, or `tests/`-rooted evidence paths and would fail wholesale on a SvelteKit tree — gate 7's evidence regex is `(tests|resources/tests)/[A-Za-z0-9_./-]+\.(php|ts|js)` and would not recognise a SvelteKit test path without editing the gate.

**Deployment.** One image, one host. `ghcr.io/andrianm28/makam-app`, digest-pinned, nginx + php-fpm inside the container, ~7–10 Compose services on a single `yiemvm` box (Ubuntu 24.04.4, 8 vCPU, 31 GB), host nginx with Let's Encrypt TLS. Horizon capped at two staging processes. Five scheduled commands including `outbox:publish` every minute (`FOR UPDATE SKIP LOCKED`) and a `spine:watchdog`. **CI does not deploy** — `build-image` pushes and stops; deployment is a manual runbook-driven `docker compose` operation, and `docs/operations/runbooks/deploy-production.md` is still marked "Prepared, not executed."

**And, decisively for risk: production, staging and development are the same host** (ADR-0027 production graduation, 25 Aug 2026), with **no PITR and no HA** — "a host failure is real production downtime with manual recovery (RTO measured in hours)". There is a **live public beta with real customer bookings**. A rewrite is a live-system migration under a single-host, no-PITR topology, not a greenfield build.

### 2.6 Design system

`docs/design/design-system.md` is 1,727 lines across 14 sections; `resources/css/tokens.css` holds **251 custom-property declarations** in a two-layer model (140 Layer-1 primitives inside Tailwind 4's `@theme`, 111 Layer-2 semantic `--mk-*` tokens). There is **no `tailwind.config.js`** — Tailwind 4.1 CSS-first. Governance is strict: a five-rank precedence order where `tokens.css` owns every design value, nine MUSTs and thirteen MUST NOTs, a 12-item Definition of Done per UI change, an ADR required to change a token, and **seven blocking CI gates**.

`app/Support/Design/StatusIntent.php` (428 lines) is the single permitted place to resolve a domain status string into a presentation triple (intent, icon identifier, Indonesian label) — 6 intents, 8 families, 51 status mappings, with a family-collision algorithm that logs and falls back to neutral rather than throwing, "because an unmapped status must not crash a table render." Two normative invariants are baked in as comments: `MENUNGGU_VERIFIKASI_PEMBAYARAN` is `pending`, "Never `success`"; and "`DIBAYAR` ≠ `SELESAI` — Paid does not mean completed."

**A note that cuts in the rewrite's favour:** all Filament branding was deliberately reverted on 26 Aug 2026. The panels render stock Filament today, and `app/Support/Design/generated/FilamentPalette.php` has no remaining consumer. So a rewrite of the admin panels inherits **no brand debt** — but equally gets **zero design reuse**.

---

## 3. Target stack analysis

### 3.1 SvelteKit — genuinely good, and aimed at the smaller half of this system

SvelteKit is a strong fit for the **public-facing surfaces**, which is where this system's most interactive code lives. `app/Livewire/Public/Booking/BookingWizard.php` is 1,188 PHP lines against a 1,498-line Blade view — **~2,700 lines for one screen**, a 9-step wizard with server-persisted draft state, optimistic-concurrency version checks, deterministic idempotency keys, and per-step autosave. That is exactly the shape of thing a component framework with real client-side state handles more naturally than a server-round-trip model.

One concrete Livewire primitive has **no SvelteKit equivalent** and must be replaced by explicit code: `#[Locked]`. The wizard marks `$draftId`, `$completedSteps`, `$currentStep` and `$version` as `#[Locked]` precisely because the draft id doubles as an anonymous resume token — a client that could set it arbitrarily could point at any draft. In SvelteKit every one of those becomes a hand-written server-side authorization check, and forgetting one is a horizontal-access vulnerability. That is a fair trade, not a blocker, but it must be budgeted and tested for.

The build toolchain overlap is real and worth stating: **Vite 7.3.6 and Tailwind 4.3.3 are already dependencies here**. The frontend build layer a SvelteKit rewrite needs is largely already in place, and `tokens.css` — being plain CSS custom properties with no `tailwind.config.js` — **ports to a Svelte app essentially unchanged**. Of everything in this analysis, the design token layer is the cleanest migration. The 17 `<x-mk.*>` Blade primitives map one-to-one onto Svelte components, and `StatusIntent.php` is a pure static function table that transliterates to TypeScript almost mechanically — and would be *better* in TypeScript, since its 6 intents and 8 families would become a discriminated union the compiler checks.

### 3.2 ElysiaJS + Bun — fast, small, and a one-way door

Elysia is a Bun-native HTTP framework with excellent end-to-end type safety (Eden), and the performance headroom is not in question — benchmarks in the 290k–500k req/s range against Express's 15k–25k. That performance is also **irrelevant to this system's actual constraints**, which are a single 8 vCPU host, a shared Postgres, and Horizon capped at two staging processes. No measurement in this repository suggests request throughput is a limiting factor.

Two properties matter more than speed:

- **Elysia requires Bun, with no stable path to running on Node in production.** That is a one-way door. If Bun later proves unsuitable for some workload, the framework does not come with you.
- **The ecosystem is small** compared to Express/Fastify, and far smaller than Laravel's. This is where the gap gets expensive, because Laravel is not supplying "a router" here — it is supplying, in production use in this codebase: the queue system and Horizon (ADR-0018, five scheduled commands, an outbox publisher using `FOR UPDATE SKIP LOCKED`), the scheduler, migrations, mail transports, session auth, validation, the service container whose **`scoped()` binding semantics the actor-context correctness depends on**, and Pulse/Sentry integration. Each of those becomes either a library selection or a hand-build, and each carries its own correctness surface.

Bun's built-in test runner is Jest-compatible and fast, which is a genuine plus for replacing 3,212 tests — but see §3.6 on why the test count is the wrong thing to focus on.

### 3.3 Drizzle ORM — the decisive technical gap

Drizzle is a well-designed, SQL-first, type-safe query builder. Its design philosophy is explicitly *not* to be an active-record ORM. For most projects that is a virtue. For **this** project it removes the layer where the safety-critical invariants live.

What Drizzle does **not** have, mapped to what this codebase actually uses:

| Eloquent mechanism | Usage here | Drizzle equivalent |
|---|---:|---|
| `booted()` + `saving`/`deleting`/`creating` hooks | **44 models** (41/9/3 hooks) | **None.** Open feature request; third-party wrappers only. |
| `getOriginal()` / dirty tracking | ≥3 models depend on it | **None.** |
| `update()`/`performUpdate()`/`delete()` override | **16 models** | **None** — no model layer to override. |
| Global scopes | 1 (built, unadopted) | **None.** |
| `protected function casts()` | **93 models** | Column-level type mapping — **maps well.** |
| Query scopes (`scope*`) | 26, all simple predicates | Plain helper functions — **maps trivially.** |
| Accessors/mutators | **0** | Not needed. |
| Relations (88 `belongsTo`, 41 `hasMany`, 3 `hasOne`, 2 `morphMany`, 1 `belongsToMany`, 1 `morphTo`, 0 `hasManyThrough`) | Shallow graph | Drizzle relational queries — **maps well**, except the 3 polymorphic relations which have no clean equivalent. |
| `lockForUpdate()` | The concurrency primitive throughout | `for('update')` — **supported.** |

**The good news is real:** zero accessors/mutators, zero `boot()`, one unused global scope, a shallow `belongsTo`/`hasMany`-dominated relation graph, and casts that are almost entirely `immutable_datetime`/`array`/`integer`/`string`/`boolean`. Roughly 70% of the ORM surface ports cleanly, and the resulting TypeScript would be better typed than PHPStan level 2 currently achieves.

**The bad news is where the risk concentrates.** Three worked examples of what has no home in Drizzle:

1. `app/Domain/PlotInventory/Models/GravePlot.php` — a `deleting` hook that refuses deletion unless `plot_state` is available **and** queries `plot_reservations` for existing history. There is no foreign key doing this. In Drizzle, deleting a plot is `db.delete(gravePlots).where(...)`, and nothing intervenes.
2. `app/Domain/ServiceCatalog/Models/ServicePackageItem.php` — refuses save or delete if **either** the incoming *or* the original owning version is published, using `$item->exists` and `$item->getOriginal('service_package_version_id')`. The docblock explains that checking only the incoming id is one-directional and insufficient: it would still permit moving an item *out of* a published version. Drizzle has no original-attribute snapshot; reproducing this requires reading the prior row inside the same transaction, by hand, at every call site.
3. `app/Domain/OrderWorkflow/Models/Order.php` — the two-flag `performUpdate()` guard. Its guarantee is that `orders.status` is unreachable except behind a persisted status event, and `paid_via`/`paid_source_ref` are unreachable except behind a persisted `DIBAYAR` event. This depends entirely on Eloquent routing `save()` on a persisted instance through `performUpdate()`.

The honest mitigation is a **hand-written repository layer** wrapping Drizzle, where every write goes through a function that performs these checks. That is a legitimate architecture. But note precisely what changes: today the guarantee is **structural** — `$order->update()` *throws*, from anywhere, including from code written next year by someone who never read the docblock. With repositories the guarantee becomes **conventional** — it holds only as long as every caller uses the repository, enforced by review and by a lint rule someone must write. The `NoAutomatedPayoutPathTest` pattern (assert the forbidden path is not in the tree) is the right tool to recover some of this, and it is the single most transferable idea from the current codebase.

Also to be decided per-column: since Drizzle has no model hooks, the 40-odd `KNOWN_*` const-list assertions currently running in `saving()` hooks must move either into the DB (adding `CHECK` constraints to the columns that lack them — a schema change on live data) or into Zod/TypeBox validation at the API boundary. The latter is more idiomatic and would be genuinely better-typed; but it moves enforcement *outward*, so a background job or migration writing directly bypasses it, where the model hook did not.

### 3.4 The Filament gap

This is the largest and least interesting work package, and it deserves to be stated plainly: **there is no SvelteKit Filament.**

What Filament supplies declaratively today, in aggregate across `app/Filament/**`: 242 `TextColumn`, 176 `TextEntry`, 133 `->sortable`, 120 `Action::make`, 105 `Notification::make`, 96 `TextInput`, 73 `->searchable`, 46 `Section`, 43 `Select`, 40 `Textarea`, 37 `->defaultSort`, 22 `Filter` + 17 `SelectFilter` + 4 `TernaryFilter`, 14 `RepeatableEntry`, 11 `DatePicker`, 11 `FileUpload`, 8 `->toggleable`. Sampling two resources in full suggests roughly **35% of those 22,354 lines is declarative schema** (~7,800 lines) that Filament renders for free — and that 7,800 lines expands substantially when hand-built, because each declarative feature becomes a component plus a server load function plus URL-state synchronisation plus the ten mandatory UI states.

That last point is the multiplier people forget. `docs/design/design-system.md` §6 makes **ten UI states mandatory for every transactional screen** (loading, empty, validation error, authorization failure, provider unavailable, duplicate/retry-safe result, success, pending, support escape hatch, responsive mobile), and §9.3 makes them a Definition-of-Done checkbox verified at five breakpoints. Filament supplies most of them for admin screens by default. A hand-built admin supplies none of them by default.

Two mitigating facts, in fairness:

- **Zero bulk actions and zero export/import actions exist today.** The rewrite does not inherit that class of work.
- **Zero brand customisation exists today** (reverted 26 Aug). Parity with stock Filament is a lower bar than parity with a themed admin.

And one aggravating fact: `getAuthorizationResponse()` is overridden in **29 files purely to neutralise Filament's "no policy means allow" default**. A rewrite escapes that specific defect — but only by replacing it with the SvelteKit equivalent, which is that **every `+page.server.ts` and `+server.ts` builds its own query and there is no `getEloquentQuery()` chokepoint to hang scoping on**. The current design has exactly one place per resource where scoping must be right. A SvelteKit rewrite has one place per route. That is a strictly worse ratio, and it is the same class of bug the vendor-scoping lane was created to close.

### 3.5 What the ecosystem actually offers for admin

The Svelte admin market is dominated by **dashboard templates** — visual shells with pre-built layouts (Flowbite Svelte Admin Dashboard, SvelteForge Admin, SvelterApp). These solve the CSS, not the CRUD-with-authorization problem. The nearest real CRUD framework, **SvelteAdmin**, generates list/detail/edit views from model config with filtering, pagination and sorting — genuinely useful, and genuinely a small community project rather than a Filament-scale ecosystem with a plugin market, a security track record, and a paid support channel. Adopting it would trade a mature dependency for an immature one at the exact layer that handles the back office for a money-handling system.

Realistically the choice is: **build a bespoke admin** (most likely, most expensive, most control) or **keep Filament** (Option C, §4.3).

### 3.6 Testing parity

Bun's test runner and Playwright would cover the mechanics well — and Playwright already exists here, so the 13 E2E specs are the **one test asset that ports largely intact**, since they drive the browser against URLs and user-visible behaviour rather than against PHP classes. That is a genuine and underrated asset: 71 browser tests already encode the public journeys in a stack-agnostic form.

The 3,212 PHP tests do not port. More importantly, **counting them mis-frames the problem**. The valuable thing is not 3,212 assertions; it is that 66 traceability rows each name a test that a reviewer read against the row's claim, and `ci/verify-docs.sh` GATE 7 fails the build if a `Covered` row names a path that does not exist. The gate's own file documents an earlier broken version of itself that "became permanently true and the gate passed unconditionally — it would have accepted all 31 rows marked `Covered` with nothing whatsoever behind them." Re-earning `Covered` status honestly means re-doing the human reading, row by row, not regenerating assertions.

Two further parity requirements: the suite must run against **real Postgres 18 with `pg_trgm` and `unaccent`** (SQLite is rejected as invalid verification, and the same trap exists in the JS ecosystem with in-memory substitutes); and `AGENTS.md` §Testing requires browser tests covering "all four homepage routes, nine booking steps, marketplace flow, renewal flow, FAQ, admin, and vendor" — which `docs/domain/traceability-matrix.md` §1 admits is **still not satisfied today**. A rewrite would inherit that open obligation, not discharge it.

---

## 4. Migration strategy options

### 4.1 Option A — Big-bang full rewrite

Build the whole system on the new stack, cut over once.

**For:** one architecture, no dual-run complexity, no integration seams, no period of maintaining two codebases. Given this repo's demonstrated 37-day delivery velocity, less absurd here than it would be elsewhere.

**Against:** the cutover lands on a **live production system holding real customer bookings and real money, on a single host with no PITR and no HA**. Rollback after cutover means rolling back the database too, and `docs/operations/ci-cd-and-release.md` states "financial/audit history is never deleted" and "production rollback must not depend on destructive `down()` migrations". There is no rehearsal environment — dev, staging and production are the same box. Feature work freezes for the whole build, or the target chases a moving specification.

**Verdict: reject.** Not because big-bang rewrites are always wrong, but because this one has no safe rollback and no rehearsal environment.

### 4.2 Option B — Strangler-fig, full migration, module by module

Put a reverse proxy in front, migrate one bounded context at a time, retire Laravel progressively.

**For:** incremental, each step independently reversible, no single cutover moment, keeps shipping features throughout. The standard right answer for large migrations.

**Against, and these are specific to this system rather than generic:**

- **The audit invariant would be split across two runtimes writing one `audit_events` table.** `Audit::wrap` is a database transaction; a Laravel action and a Bun action cannot share one. Any workflow spanning both stacks loses the atomicity that is the whole guarantee.
- **`app/Platform/` is not a set of independent modules — it is one backbone every domain depends on.** IdentityAccess, Audit, FeatureGate, Outbox, Payment, FinancialLedger. Migrating any domain context means either migrating the backbone first (which is most of the risk, up front, with no user-visible progress) or calling back into Laravel over HTTP for actor context and audit writes on every operation (which reintroduces the atomicity problem and adds latency to every write).
- **Host capacity.** One 8 vCPU / 31 GB box currently runs ~10 containers with Horizon capped at two processes and per-service `mem_limit` values as tight as 256 MB. Running both stacks concurrently for months needs headroom nobody has measured, and ADR-0027 already accepts this host as a single point of failure.
- Two ORMs against one schema, with the Laravel side's invariants enforced in models the Bun side does not go through.

**Verdict: feasible, but the platform backbone makes it far less incremental than strangler-fig usually is.** Rank third.

### 4.3 Option C — Public surfaces only: SvelteKit frontend, Laravel API, Filament admin stays

Replace `app/Livewire/Public/**` and its Blade views with a SvelteKit app consuming a JSON API served by Laravel. Keep `app/Domain`, `app/Platform`, `app/Filament`, Eloquent, and the entire test suite.

**For:**

- Targets the **one place SvelteKit is genuinely better** — the 36 public components, above all the 2,700-line booking wizard.
- **Preserves every load-bearing invariant unchanged.** Audit wrapping, append-only guards, closed-list hooks, `getEloquentQuery()` scoping, all 44 `booted()` hooks: untouched.
- **Preserves ~103 admin surfaces and the entire Filament investment** — the largest cost item in Options A and B disappears entirely.
- **Preserves the test suite.** Domain and Platform tests (87 + 59 + others) keep passing unchanged. The 63 Filament and 55 Livewire feature tests are the affected slice; the 71 Playwright tests, driving URLs, largely survive.
- Reuses `tokens.css` directly and Vite/Tailwind as already configured.
- **Reversible.** If the SvelteKit frontend does not work out, the Blade/Livewire surfaces are still in git and the API remains useful.
- Much narrower governance ask: still requires an ADR (it is a "separate SPA"), but does not supersede ADR-0017's runtime baseline, ADR-0018, or ADR-0001.

**Against:**

- Delivers **none** of the stated Bun/Elysia/Drizzle goals. If the owner's actual motivation is "get off PHP", this does not do that — it adds TypeScript alongside PHP and increases the number of stacks in play from one to two.
- Two languages, two toolchains, two deployment artifacts on a host already at the edge of its capacity.
- The public surfaces are also where the **honest-fallback discipline** is most visible; every gated-fallback UX and each of the ten mandatory states must be re-implemented and re-tested for 36 components.
- `docs/domain/traceability-matrix.md` rows covering public journeys need new evidence paths, and `ci/verify-docs.sh` GATE 7's regex needs widening to accept them.

**Verdict: the best option *if* something is going to be rewritten.** Bounded, reversible, value-aligned.

### 4.4 Option D — Do not rewrite; invest the same effort in the current stack

**For:** every open risk in §1.3 is addressable directly and none requires a stack change. Raise PHPStan from level 2 (a genuine, cheap, large win on 975 files). Add coverage measurement. Execute the backup/restore procedure that has never run. Close the 23 open release-gate boxes. Separate per-environment credentials (S1-T7). Address the single-host/no-PITR posture. Adopt the `ScopeAssignmentGlobalScope` that was built and never used, or delete it. Extend `CHECK` constraints to the closed-list columns that lack them.

**Against:** does nothing for whatever motivated the question. If the owner's concern is PHP hiring, PHP as a long-term bet, or personal preference for the TypeScript ecosystem, Option D does not address it — and those are legitimate concerns that a technical analysis cannot adjudicate. See §7.

**Verdict: the baseline any rewrite option must beat.** I do not think A or B beats it. C might, depending on §7's answers.

### 4.5 Summary

| | A: Big-bang | B: Strangler-fig | C: Public surfaces only | D: No rewrite |
|---|---|---|---|---|
| Achieves the stated stack goal | Fully | Fully | No | No |
| Preserves audit/append-only invariants | Rebuild all | Split across runtimes | **Untouched** | Untouched |
| Filament (~103 surfaces) | Rebuild | Rebuild | **Kept** | Kept |
| Test suite (3,212 + 71) | Re-earn all | Re-earn progressively | **Mostly kept** | Kept |
| Reversibility | Very low | Moderate | **High** | N/A |
| Risk to live beta / customer money | **Severe** | Moderate–high | Low–moderate | None |
| Governance ask | ADR-0038 superseding 0017, 0001, 0018, 0028 | Same | ADR for SPA only | None |
| **Recommendation** | Reject | Third | **If rewriting, this** | **Recommended** |

---

## 5. Risk assessment

Risks specific to this system, roughly in descending severity.

**5.1 — Reimplementing the ScopeAssignment authorization model incorrectly.** *Severity: critical.* A defect here is cross-tenant data exposure. The current design has one chokepoint per resource (`getEloquentQuery()`, overridden 41 times) plus a reflection test that fails CI on an unscoped surface. SvelteKit has one chokepoint **per route**, and no framework method to reflect over. This repository has already shipped and fixed a real bug of exactly this class — `docs/planning/retrofit-backlog.md` records draft-cemetery grave records reachable by anyone holding the cemetery UUID, found in the GraveRegistry retrofit. The deny-by-default behaviour also relies on a Laravel detail (`whereIn(col, [])` compiling to always-false); the equivalent in a hand-written query is a naked `if` that returns everything when someone forgets it. **Mitigation:** re-derive the reflective enforcement test first, before any surface is built.

**5.2 — Losing the invariant layer to a convention layer.** *Severity: critical.* §3.3. 44 `booted()` hooks and 16 write-guard overrides become repository functions that callers may bypass. **Mitigation:** the `NoAutomatedPayoutPathTest` pattern — assert by source-tree walk that direct `db.update(...)` on guarded tables does not appear outside the repository module. Write that test before the first guarded table is ported.

**5.3 — Re-introducing exactly the bugs Phases A–D just fixed.** *Severity: high.* The 29 `getAuthorizationResponse()` overrides, the two-layer action authorization (render gate *plus* re-check as the first statement of the action closure, because "the button was not rendered" is explicitly not a security property), the `#[Locked]` wizard properties, the `MetadataAllowlist` and blank-reason gates, `ActorContextResolver` being `scoped()` not `singleton()`. Each of these is a fix for a real defect, recorded in a docblock a rewrite author may never read. `docs/planning/retrofit-backlog.md` records roughly 2 Critical / 74 Important / 100+ Minor findings disposed across the retrofit program. **A rewrite starts that ledger at zero and must re-find them.**

**5.4 — Audit-trail correctness during dual-run.** *Severity: high (Option B only).* `Audit::wrap` is a transaction. Two runtimes cannot share one. Any cross-stack workflow silently loses atomicity between the mutation and its audit row — and the failure mode is a missing audit record, which is invisible until someone needs it.

**5.5 — Cutover under a single-host, no-PITR production.** *Severity: high.* Production, staging and development share one box. No PITR, no HA, RTO in hours, backup/restore **never executed**. There is no environment in which to rehearse a cutover. This is arguably a reason to fix the deployment posture *regardless* of the rewrite decision.

**5.6 — Money-path correctness.** *Severity: high.* `MarkRenewalPaidOnline` alone depends on savepoint nesting under an outer webhook transaction, on writing one audit row outside the transaction so it survives a rollback, and on driver-specific duplicate detection matching a Postgres index name. `PlaceMarketplaceOrder` constructs an **explicit guest actor** rather than resolving from the container, because the container-scoped context resolves to the real customer and the payable authorizer throws for any authenticated actor. These are the kind of details that read as noise to a porter and are load-bearing.

**5.7 — Design-system governance discontinuity.** *Severity: medium.* Seven blocking CI gates and 8 of 13 `verify-docs.sh` gates are path-coupled to `resources/`/`app/`/Blade. They fail wholesale on a SvelteKit tree, and the tempting response — disable them for the duration — removes the mechanism that "makes parallel agent fan-out safe" during exactly the period of maximum churn. **Mitigation:** port the gates before the code, not after.

**5.8 — Data migration.** *Severity: lower than typical, but non-zero.* PostgreSQL is retained and the schema is unchanged, so this is a code migration, not a data migration — genuinely the least of the risks. The residual exposure is the append-only and audit tables: any dual-write or backfill touching `audit_events`, `journal_entries`, `order_status_events` or `plot_reservations` must preserve exact ordering and immutability, and the 5 migrations that installed real Postgres triggers must keep working against a client that no longer goes through Eloquent.

**5.9 — Ecosystem and one-way-door risk.** *Severity: medium.* Elysia requires Bun with no stable Node path. Drizzle's missing hooks are an open feature request, meaning the gap may close — or may not. Bun's ecosystem is younger than Node's, which is younger than PHP's, for a system that must run financial workflows for years.

**5.10 — Opportunity cost.** *Severity: high, and the easiest to under-weight.* Everything in §1.3 stays open for the duration.

---

## 6. Phased plan, **if** the decision is to proceed

Presented at strategic level per the brief — this is a feasibility plan, not an execution-ready SDD plan. **It presumes an approved ADR-0038 exists; without one, Phase 0 is where this stops.** It is written for **Option C**, since that is what I recommend if anything proceeds; §6.6 notes what changes for Option B.

### Phase 0 — Decision and authorization (blocking; no code)
- Answer §7's questions. Without them the rest is guesswork.
- Produce the "measured need" AGENTS.md requires: a written statement of the problem the rewrite solves, with evidence.
- Draft ADR-0038; obtain human approval. Amend `ci/version-matrix.yml` and `technology-baseline.md` §9.
- **Exit criterion:** an approved ADR. Nothing before this is authorized.

### Phase 1 — De-risking spike (timeboxed, throwaway, no production impact)
Answer the questions that decide whether Option C is even worth doing, on the smallest real surface. Suggested target: the **renewal journey**, because it is 5 steps rather than 9, it exercises fuzzy grave search (`pg_trgm`/`unaccent`), it touches money, and it is smaller than booking.
- Stand up SvelteKit consuming a Laravel JSON API for those 5 steps.
- Prove: `tokens.css` renders unchanged; the ten mandatory states are reachable; `StatusIntent` transliterates to TypeScript; Playwright specs pass against the new frontend with minimal edits; session auth works across the boundary; `#[Locked]`-equivalent server-side authorization holds for the anonymous resume-token pattern.
- **Exit criterion:** a written go/no-go with evidence. A no-go here is a *success* — it is the cheapest possible answer.

### Phase 2 — Seams and gates before surfaces
- Define the JSON API contract for public surfaces; extend `docs/contracts/openapi.yaml` (already 27 paths, already CI-validated) rather than inventing a parallel contract.
- Port the CI gates **first**: widen `verify-docs.sh` GATE 7's evidence regex, re-express gates 2/3/11/12 against Svelte files, decide the fate of `design:verify-filament-palette` (already orphaned).
- Establish the SvelteKit test harness against **real Postgres**, not an in-memory substitute.
- Re-derive the reflective authorization test (§5.1) for SvelteKit routes before any route is built.
- **Exit criterion:** gates green on an empty SvelteKit app.

### Phase 3 — Migrate public surfaces, journey by journey
Order by ascending risk, not by size: FAQ / Help / Legal / Home → Cemetery directory → Renewal → Marketplace → **Booking wizard last** (it is the largest, most stateful, and most consequential). Account area and Memorial/QR fold in where convenient.
- One journey per PR, per `AGENTS.md` §Development methodology, in a `.worktrees/` worktree, two-tier review.
- Each journey: all ten mandatory states, gated-fallback UX, Playwright coverage, traceability rows re-pointed to new evidence paths.
- Run old and new behind the reverse proxy per journey so each is independently revertible.
- **Exit criterion per journey:** its traceability rows are `Covered` against new evidence, and GATE 7 passes.

### Phase 4 — Retire the Livewire public layer
Delete `app/Livewire/Public/**` and its Blade views only after every journey is migrated and observed in production. Keep `app/Filament/**`, `app/Domain/**`, `app/Platform/**` and the PHP test suite. Update `docs/product/screen-inventory.md` and `docs/design/design-system.md` §8.4.

### Phase 5 — Reassess
With the public surfaces on SvelteKit and Filament still on Laravel, revisit whether the admin should follow. **The right time to decide that is with the Phase 3 evidence in hand, not now.** It may well be that the answer is no, permanently, and that is a perfectly good end state.

### 6.6 If Option B (full strangler-fig) is chosen instead
The order inverts and gets much harder. Phase 2 must first port the **platform backbone** — IdentityAccess, Audit, Outbox, FeatureGate — because no domain context can migrate without it, and the audit-atomicity problem (§5.4) must be solved explicitly before the first cross-stack workflow. Budget the backbone as its own multi-phase program delivering no user-visible value, and settle the atomicity question in writing before any of it starts.

---

## 7. Open questions for the human decision-maker

These are genuinely outside what code analysis can answer, and the recommendation in §1 could change on several of them.

**7.1 What problem is this solving?** The most important question, and I found no evidence in the repository. If the answer is a measured technical pain point, I would like to see the measurement — it may point somewhere other than a rewrite. If the answer is preference for the TypeScript ecosystem, or a long-term bet against PHP, or hiring, those are legitimate and this analysis cannot adjudicate them; but they should be stated plainly, because they lead to different options than a performance or velocity problem would.

**7.2 Team composition and TypeScript/Bun experience.** I have no visibility. The current system was built by an AI-agent-heavy workflow at ~28 commits/day. Does that capacity transfer to a stack with less training-data density and less framework scaffolding? Filament and Livewire supply a great deal of structure that agents follow reliably; a bespoke SvelteKit admin supplies none.

**7.3 Is the Filament admin UX a requirement, or an artifact?** This single answer moves the cost more than any other. If the ~103 admin surfaces must be preserved as-is, Options A and B are dominated by that work. If the admin could be **redesigned and reduced** — a plausible outcome, since it was built by generation rather than by demand, and has zero bulk actions and no export/import — the calculus shifts. Ask: how many of the 103 surfaces does anyone actually use weekly?

**7.4 Timeline, budget, and feature-freeze tolerance.** How long can feature development pause? There is a live public beta with real customers. If the answer is "it cannot", Option A is out on that ground alone and Option B's dual-run cost rises.

**7.5 Is the single-host/no-PITR posture acceptable during a migration?** ADR-0027 accepted it for the current system. Rewriting under it is a different bet. My view: **fix this first regardless of the rewrite decision**, since backup/restore has never been executed even once.

**7.6 What happens to the invariant layer?** §3.3 is the crux. Is the owner comfortable moving from structural guarantees (`$order->update()` throws from anywhere) to conventional ones (guarantees hold as long as callers use the repository)? For a system with append-only financial and audit records, this is a governance question about acceptable risk, not a technical preference.

**7.7 Does the RKS document constrain the stack?** Rank-1 authority, not in the repository, unreadable from here. Only the owner can consult it. If it names a technology baseline, that settles the question in whichever direction it points.

**7.8 Would a partial answer satisfy the underlying motivation?** If Option C — SvelteKit for the public surfaces, Laravel and Filament retained behind it — would satisfy what prompted the question, it is available at a fraction of the risk, and it is reversible. If it would not, that tells us the motivation is about the backend runtime, and §7.1 should be answered explicitly before anything proceeds.

---

## 8. Recommendation

**Do not undertake a full rewrite to SvelteKit + ElysiaJS + Bun + Drizzle.**

The system is a mature, evidence-governed, money-handling platform with 3,212 tests, 66 test-backed traceability rows, 162 migrations applied to live customer data, and 37 ADRs of settled decisions, running a live public beta on a single host with no PITR and no HA. Its most safety-critical invariants are enforced by Eloquent mechanisms — model lifecycle hooks, write-path overrides, and the `getEloquentQuery()` chokepoint — that **Drizzle structurally cannot host**, converting structural guarantees into conventional ones at exactly the layer handling money and audit records. Roughly 60% of the work is replacing Filament with a bespoke admin, delivering nothing a customer will ever see. And no stated technical pain point motivates it, while several real, named risks sit open and would be postponed by it.

**If something is to be rewritten, do Option C:** replace the public-facing Livewire surfaces with SvelteKit consuming a Laravel JSON API, and keep the domain layer, the platform backbone, Filament, and the test suite. That targets the one area where SvelteKit is genuinely better, preserves every load-bearing invariant, reuses `tokens.css` essentially unchanged, is reversible, and asks far less of governance. Gate it behind the Phase 1 spike, and treat a no-go there as a cheap success.

**Before either, answer §7.1.** If the motivating problem turns out to be something the current stack causes, this analysis should be revisited with that evidence in hand — it may well point somewhere neither this document nor the original question anticipated.

---

## 9. Sources

Target-stack claims in §3 were checked against public sources on 29 August 2026 rather than recalled; they should be re-verified at decision time, since all four projects move quickly.

- [Drizzle ORM — Pre & Post Save Signals (feature discussion)](https://github.com/drizzle-team/drizzle-orm/discussions/3787)
- [Drizzle ORM — Hooks like Lucid ORM's (feature request)](https://github.com/drizzle-team/drizzle-orm/issues/2266)
- [Drizzle ORM documentation](https://orm.drizzle.team/)
- [Elysia.js + Bun: The Complete Guide (2026)](https://stacknotice.com/blog/elysiajs-bun-complete-guide-2026)
- [Is Bun production ready in 2026? A practical assessment](https://dev.to/last9/is-bun-production-ready-in-2026-a-practical-assessment-181h)
- [ElysiaJS — Deploy to Production](https://elysiajs.com/patterns/deploy)
- [SvelteForge Admin (SvelteKit 2 / Svelte 5 / Drizzle admin dashboard)](https://github.com/ColorlibHQ/svelteforge-admin)
- [Best Svelte admin dashboard templates 2026](https://adminlte.io/blog/svelte-admin-dashboard-templates/)

All in-repository figures were measured in the worktree `/home/ubuntu/makam-app/.worktrees/sveltekit-rewrite-plan` at commit `0d9a56d`.
