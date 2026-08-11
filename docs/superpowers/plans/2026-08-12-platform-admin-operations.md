# Platform Admin Operations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the admin dashboard's authorization hole and build the admin-operations modules that have a real data model behind them, ledgering the rest honestly instead of scaffolding CRUD over tables that do not exist.

**Architecture:** Filament 5 Resources and Pages provide forms/tables only; every write delegates to a Domain/Platform Action that enforces authorization, transactions, state guards, and audit (`.kiro/specs/admin-operations/design.md` §"Admin panel boundaries", and the binding rule in `app/Domain/README.md`). Authorization has two distinct layers that must not be conflated: the **panel gate** (`PanelAccessPolicy` — who reaches `/admin` at all) and **per-resource authorization** (who may act on a given record). This lane fixes the former and introduces the latter.

**Tech Stack:** Laravel 13, Livewire 4, Filament 5, PostgreSQL 18 (SQLite in-memory for the test suite), PHPUnit.

**Spec:** `.kiro/specs/admin-operations/` (requirements.md AC1–AC11, design.md, tasks.md).

---

## Global Constraints

Copied verbatim or by exact citation from the binding sources. Every task's requirements implicitly include this section.

- **Source precedence** — RKS K23–K35 → `docs/product/mvp-scope.md` → approved ADR/specs → approved benchmark extensions (`AGENTS.md` §Source precedence).
- **Never hardcode a design value.** `docs/design/design-system.md` names `resources/css/tokens.css` the SINGLE SOURCE OF TRUTH; §10 reads "NEVER hardcode a value". Arbitrary Tailwind values (`text-[#12545E]`, `p-[13px]`) are prohibited.
- **`ci/verify-docs.sh` Gate 2 scans `app/**/*.php` for 6-digit hex literals.** A hex in a Filament colour map, badge array, or even a doc-block example fails the build. Resolve colours through `App\Support\Design\StatusIntent::filamentColor()` or Filament colour names — never a hex.
- **`ci/verify-docs.sh` Gate 3** bans Tailwind arbitrary values in `*.blade.php` under `resources/` and `app/` unless the bracket contains `var(--`.
- **`ci/verify-docs.sh` Gate 7** requires every `Covered` row in `docs/domain/traceability-matrix.md` to name at least one test path that **exists on disk**. Never mark an AC `Covered` without a real test file.
- **`ci/verify-docs.sh` Gate 4** requires every relative markdown link in `docs/` and `.kiro/` to resolve on disk — including links added by this plan.
- **Resolve every status through the shared `StatusIntent` helper** (`design-system.md` §3.7). Never `match` on a status enum inside a Filament closure. **Always pass `$family` explicitly** — `DIPROSES`, `SELESAI`, and `DIBATALKAN` exist in both the `order_lifecycle` and `vendor_processing` families and resolve differently.
- **`MENUNGGU_VERIFIKASI_PEMBAYARAN` renders `pending`, never `success`** (`design-system.md` §3.7, restated normatively in the spec's `tasks.md`).
- **Every write goes through a Domain/Platform Action, never Eloquent in a Resource** (`app/Domain/README.md`).
- **Sensitive actions require a mandatory non-blank reason** and emit an audit event. The closed list is `App\Platform\Audit\SensitiveActions::ACTIONS`; extend it deliberately, never infer sensitivity from an action name at runtime.
- **Sensitive-action confirmation UI** (`tasks.md`): consequence stated in the modal body, typed reason where mandated, `danger` confirm button, and **never default-focus the destructive button**.
- **Bulk export renders as `secondary`, never `primary`**, never adjacent to a benign action; it is privileged and requires recent re-authentication.
- **Human review is mandatory before authorization, security, financial, privacy, and destructive-migration changes** (`AGENTS.md` §Infrastructure-agent execution). This lane opens a PR and **does not self-merge**. Authorization work is staged as its own reviewable commit and flagged in the PR body.
- **Never report `PASS` for a check that was not executed** — use `BLOCKED` or `NOT TESTED` explicitly (`AGENTS.md` §Infrastructure-agent execution).
- **Never run `composer install` or `npm run build` on this host.** CI owns builds (`CLAUDE.md` §Scope note, `docs/operations/ci-cd-and-release.md` §10). Verification here is `php artisan test` plus `bash ci/verify-docs.sh`; anything else is verified by pushing and reading CI.
- **Do not duplicate canonical catalogue data** across hand-maintained documents (`AGENTS.md` §Documentation). Extend `docs/product/screen-inventory.md`'s existing ADM rows rather than inventing a rival table.
- **Never place restricted data in logs, Pulse, Horizon tags, or error trackers** (`AGENTS.md` §Observability).
- **Admin panel inherits the same tokens as the public site** (`design-system.md` §8.3) — a `DIBAYAR` badge is identical for customer and admin. Do not restyle the panel. Keep the PHP colour array generated from `tokens.css`, never hand-edited (OQ-09).
- **Accessibility:** `size=sm` (36 px, `--mk-control-h-sm`) is the only control permitted below the 44 px floor, and only on pointer devices — never on touch layouts (`tasks.md` §Primitives).

---

## Current State

Established by direct inspection of the tree at `d9fea9f`, not inferred from documentation. Several repository doc blocks are **stale** on exactly these points; where they conflict with this section, the code was re-verified and wins.

### What exists and works

| Seam | Location | Notes |
|---|---|---|
| Admin panel | `app/Providers/Filament/AdminPanelProvider.php` | Panel id `admin`, Resources auto-discovered, Pages registered explicitly via `->pages([...])` |
| One Filament Resource | `app/Filament/Admin/Resources/FaqArticles/` | The house convention: `Schemas/` (form + infolist), `Tables/`, `Pages/` split into separate classes |
| Four admin Pages | `app/Filament/Admin/Pages/` | `FinanceReports`, `InAppNotifications`, `MfaChallenge`, `MfaSettings` |
| Roles | `app/Platform/IdentityAccess/Roles/ActorRole.php` | `final class` of string constants (**not** a PHP enum), declaration order == precedence: `admin`, `restricted_admin`, `finance`, `operator`, `case_manager`, `vendor`, `customer`, `system` |
| Role resolution | `Roles\ActorRoleReader::rolesForActor()` | Reads `actor_role_assignments` where `revoked_at IS NULL`; zero constructor deps by design (a container cycle here previously OOM'd the host) |
| Actor context | `IdentityAccess\ActorContext` | `hasRole()`, `hasScope()`; scoped binding, one instance per request |
| Business-entity scope | `Scopes\ScopeAssignmentReader` + `scope_assignments` | `ScopeEntityType`: `cemetery`, `vendor`, `order`, `case`, `grave`, `business_entity`; `ScopeGrantLevel`: `own`, `assigned`, `read`, `privileged` |
| Audit | `app/Platform/Audit/` | `Audit::record()` / `Audit::wrap()`, `SensitiveActions::ACTIONS` closed list |
| Status → intent | `app/Support/Design/StatusIntent.php` | `intent()`, `icon()`, `label()`, `filamentColor()`; families `order_lifecycle` (13 statuses) and `vendor_processing` (8) |
| Service catalogue | `app/Domain/ServiceCatalog/` | 20 files. Models **and write Actions** both exist — the most complete domain in the repo |
| Payment | `app/Platform/Payment/` | `PaymentIntent`, `PaymentSession`, `ProviderEvent`, `PaymentVerification`, `PaymentReversal` + Actions. **Backend complete, zero Filament UI** |
| Financial ledger | `app/Platform/FinancialLedger/` | `LedgerReport::summary()`, `Money`, and the `FinanceLedgerReadAuthorizer` role+scope precedent |
| FAQ | `app/Domain/Faq/` | Complete: models, versions, 5 write Actions, public + admin surfaces |

### The authorization gap — wider than the ledgered ticket

`app/Platform/IdentityAccess/Panel/AdminPanelAccessPolicy.php:38-41` is, in full:

```php
public function allows(ActorContext $actor): bool
{
    return $actor->isAuthenticated();
}
```

**Any authenticated user reaches `/admin`.** `tests/Feature/Filament/Admin/Faq/FaqArticleAuthorizationCharacterizationTest.php` already pins the consequence: a bare `User` with no role can list, publish, unpublish, and reorder FAQ articles. The four custom row actions in `app/Filament/Admin/Resources/FaqArticles/Tables/FaqArticlesTable.php:151-228` (`publish`, `unpublish`, `moveUp`, `moveDown`) gate only on record state via `->visible()`/`->hidden()` and never call `->authorize()`. No `FaqArticlePolicy` exists.

`docs/planning/retrofit-backlog.md:88` rates this Critical but "not currently exploitable — the role vocabulary a policy would check doesn't exist yet (`ActorContext::$roles` always `[]`)".

> **That premise is now false.** Lane L5 merged `actor_role_assignments` and wired `ActorRoleReader` into `LocalUsersTableIdentityAccessAdapter`; roles resolve for real. A user holding only `customer` is now a distinguishable, lower-privileged principal who can still publish FAQ content. **The gap became genuinely exploitable when L5 merged.** The stale "always `[]`" comments in `AdminPanelAccessPolicy` and in the characterization test must be corrected as part of the fix, not left to mislead the next reader.

### Authorization idiom — a real design decision

There are **zero** Laravel `Illuminate\Auth\Access` policies in this repo. No `AuthServiceProvider` policy map, no `#[UsePolicy]`, no `Gate::policy()`, no `app/Policies/` directory. `Gate` is never imported anywhere in `app/`, and `$this->authorize(...)` is never used.

The established house idiom is a constructor-injected `Contracts\XAuthorizer` interface whose `authorize()` throws a module-specific `…NotAuthorisedException` and returns a scope object — five instances in `app/Platform/FinancialLedger/`.

Filament's `->authorize()` and its Resource authorization hooks integrate with Laravel's Gate. So closing the FAQ gap the way the ledger prescribes ("adding a policy class or `->authorize()` call") introduces the repository's **first** Laravel policy. That is a deliberate deviation from the house idiom, justified by Filament's native integration point, and it must be recorded as such rather than slipped in silently.

### What does not exist at all

Verified by grep, not inferred:

- **No `Order` entity.** `grep -rn "class Order" app/ database/` returns zero matches. No `orders`, `order_items`, or `order_status_history` table in any of the 80 migrations. `app/Domain/OrderWorkflow/Models/` and `Actions/` are empty directories. "Order" survives as vocabulary only: `ScopeEntityType::ORDER`, `StatusIntent`'s 13-status `order_lifecycle` family, and an openapi path.
- **No `Vendor` entity.** No `vendors` table, no model, no `app/Domain/Vendor/`. Vendor identity today is `products.vendor_name` — a nullable free-text column seeded with nine hardcoded dummy names — plus unconstrained `vendor_id` **string** columns (no foreign key) on `vendor_payables` and `payouts`.
- **No city, facility, class, or availability entity.** City is a `string(32)` column validated against the `LaunchCityCode` constant list. Facilities are a `json` column. Class is `cemetery_packages.class_label`, a free string. "Availability" is a *mode* on the capability profile, not a calendar.
- **No write Action for cemeteries or for products/variants.** `app/Domain/Marketplace/Actions/` is empty; `CemeteryCapability`'s only Action is a read/resolve. Every row in `cemeteries` today comes from a seed migration.
- **Nothing is scope-assignable.** `grep -rn "HasScopeAssignments\|ScopeAssignable" app/` outside the Scopes directory returns nothing. Zero models use the global scope.

### Consequence for the acceptance criteria

| AC | Data model status |
|---|---|
| 1 — dashboard modules | Partial — only the modules below that have data |
| 2 — city/cemetery/package/class/service/facility/tariff/map/availability | Cemetery + service-catalogue tables exist; **no city/facility/class/availability entity; no cemetery write Action** |
| 3 — vendor/product/variant/category/service area/vendor status | **No vendor model at all**; products/variants exist but read-only |
| 4 — booking/marketplace/renewal workflows, PIC, audited comms | **No order model at all** |
| 5 — payment/transaction references, manual outgoing payment | Backend complete, **no UI** |
| 6 — FAQ category/article/draft/preview/publish/ordering | **Shipped**, minus category CRUD; authorization is the open Critical |
| 7 — reports on orders/receipts/payouts/vendor performance/renewal | Only `LedgerReport::summary()`. The AC's own **"where data exists"** clause governs |
| 8 — authorization + audit for sensitive actions | Audit seam complete; **authorization is the open Critical** |
| 9 — no bypass of payment/state invariants | Needs order/payment state machines that do not exist |
| 10 — scope export/report to role + business entity | `FinanceLedgerReadAuthorizer` is the working precedent |
| 11 — exception queues | Failed-payment data exists (`provider_events.status`, `reconciliation_exceptions`); **operator response, vendor delay, unmatched renewal have no model** |

### Concurrent lane boundaries

- **L8 `lane/l8-renewal-completion`** — read its plan directly. It adds **no** `SensitiveActions` entries and creates **no** `app/Filament/Admin/**` files. It owns `app/Domain/Renewal/` and new `renewals` / `renewal_quotes` / `renewal_external_markings` migrations. One latent collision: its AC10 "privileged marking action" for out-of-system renewals would land on ADM-060 if it ever becomes a screen; its plan does not place it in Filament today.
- **L7 order orchestration** — no branch, no worktree, no plan doc exists anywhere on disk or in any ref. The order model is unowned in practice.
- **L11 `lane/l11-marketplace-checkout`** — live at `d9fea9f`, nothing committed. A checkout very likely needs the same order model.

`SensitiveActions::ACTIONS` already contains `TARIFF_SOURCE_CHANGE`, `GATE_CHANGE`, and `VENDOR_PAYOUT` — three of the seven sensitive actions named in `design.md`. Quote issuance belongs to L7 and external-renewal marking to L8. **Declare any intended addition to that file before committing it.**

### Test baseline

`php artisan test` at `d9fea9f` in this worktree: **1812 tests, 7015 assertions, 2 errors, 59 skipped.** Both errors are pre-existing and host-only — `EloquentGateRegistrySourceTest` and `HomePageRouteTest` issue `DROP TABLE … CASCADE`, which is PostgreSQL syntax that SQLite rejects. CI runs `DB_CONNECTION: pgsql` against `postgres:18`, where both pass. This matches the baseline L8 independently recorded. **Any additional failure is caused by this lane.**

`vendor/` in this worktree is a hardlink copy (`cp -al`) of the main checkout's, **not a symlink** — verified via `ReflectionClass::getFileName()` that app classes and `vendor/autoload.php` resolve inside the worktree. A symlinked `vendor/` would make Composer resolve `$baseDir` back to the main checkout and silently test the wrong code.

---

## Scope Decision — PENDING COORDINATOR RULING

Two questions were escalated before task decomposition and **must be answered before the task list below is executed**:

- **Q1.** Who creates the `orders` table and model? Three lanes (L7, L9, L11) need it and none owns it. If L7 owns it, this lane defers ADM-040/050/060 and AC4/AC9/AC11 to a follow-up rather than racing to invent a schema.
- **Q2.** Is a `vendors` table in this lane's scope, or does it belong to the not-yet-dispatched vendor-portal lane? Either way it is a new migration against a database already deployed to `dev.makam.co.id`, so it is human-review gated.

A third item was escalated for confirmation rather than decision: fixing `AdminPanelAccessPolicy` is proposed for this lane, but it is a shared file and roughly ten existing test files `actingAs()` a roleless user against `/admin`, so tightening it has cross-lane blast radius.

**Working assumption pending the ruling** (build only what has a real data model; ledger the rest honestly, as AC7's "where data exists" clause already sanctions):

- *Full rigor:* panel gate, `FaqArticlePolicy` + `->authorize()` on the four actions, sensitive-action authorization and audit wiring.
- *Light tier:* ADM-010 cemetery/TPU-TPS, ADM-020 package/service/tariff, ADM-030 product/variant, ADM-070 payment/transaction + manual verification, ADM-090 reports scoped via `FinanceLedgerReadAuthorizer`, ADM-100 audit review, ADM-001 dashboard.
- *Ledgered, not built:* AC3 vendor entity, AC4, AC9, AC11 except failed-payment, and the order-dependent parts of AC7.

---

## Tasks

> Task decomposition follows once Q1/Q2 are ruled on. Writing it before then would bake in an ownership assumption this lane is explicitly not authorised to make.
