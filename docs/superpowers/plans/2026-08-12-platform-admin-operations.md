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

## Scope Decision — RULED

Coordinator ruling, 12 Aug 2026. It corrects two things this lane's own survey got wrong, and both corrections are load-bearing.

- **L7 exists and is actively building the order model.** Worktree `.worktrees/platform-order-orchestration`, branch `lane/l7-order-orchestration` — created after this lane's survey snapshot, which is why the survey reported it absent. It owns `orders`, `quotes`, and the order state machine. **Do not build a rival order model.** AC4/AC9/AC11 admin screens consume L7's schema.
- **L11 owns the vendor schema.** Branch `lane/l11-marketplace-checkout` is building `vendors`, `vendor_users`, `vendor_orders` and related tables. **Do not create a `vendors` table in this lane.** AC3's admin vendor-management UI consumes L11's schema once it exists.
- **Follow the house authorizer idiom**, not a new Laravel policy: a constructor-injected `Contracts\XAuthorizer` whose `authorize()` throws. This overrides the retrofit ticket's "add a policy class" wording and this plan's earlier leaning.
- **`FaqArticleAuthorizationCharacterizationTest`'s four gap tests are rewritten to prove the exploit is now inert — never deleted.** They are the evidence trail; inverting the assertions preserves it, removing them destroys it.
- **Money: convert at the read seam** using the established `Money::fromDecimal(string $amount): int` (verified present at `app/Platform/FinancialLedger/Money.php:39`).

**Verified at planning time:** `git log d9fea9f..lane/l7-order-orchestration` and the same for `lane/l11-marketplace-checkout` both return **empty** — neither lane has committed schema yet. Task ordering below is therefore deliberate: every task that depends only on models that already exist runs first, giving L7 and L11 maximum time to land. The dependent tasks sit last and carry an explicit escalation trigger rather than a guess.

### Two open authorization questions

**A. Which roles may manage FAQ content?** `docs/security/rbac-matrix.md` (v0.2, 31 lines) has **no FAQ or content-management row at all**. The nearest analogue is "Memorial edit/publish → Admin: Moderation". The authorization model for FAQ management is genuinely unspecified, and this lane will not invent one.

Task 1 therefore implements the **most restrictive defensible grant — `ActorRole::ADMIN` only.** This is a refusal to grant rather than an invented model: it cannot over-permit, and widening it later to `restricted_admin` or `operator` is a deliberate, reviewable decision. The PR flags this for human ruling and proposes the corresponding new `rbac-matrix.md` row rather than silently shipping an access model.

**B. The panel gate — RULED, fix it in this lane.** `AdminPanelAccessPolicy::allows()` returns bare `isAuthenticated()`, so any authenticated user reaches `/admin`. The policy's own docblock records that this was always meant to be temporary: *"Tightening this to a real role/scope check is S3-T2/T3 and Batch 3.2 Agent C's job, once `ActorContext::$roles`/`$scopes` are actually populated."* That precondition was met when the identity seam merged, so the placeholder is now a live, exploitable hole.

User ruling, 12 Aug 2026 (explicit): **this lane fixes it**, full rigor, flagged in the PR as an authorization change needing human sign-off. Restrict to `admin`, `restricted_admin`, `operator`, `finance`. The roughly ten existing tests that `actingAs()` a roleless user against `/admin` were exercising the same gap — giving them real role grants is correct test hygiene, not collateral damage. Cross-lane risk was weighed and accepted in choosing this over a separate hotfix lane. **Task 10 is therefore unconditional.**

> **Interaction that makes tests vacuous if missed.** Once the gate is tightened, a roleless user is rejected at the *panel* boundary and never reaches any Resource. Every resource-level denial test written against a roleless user then passes even if its authorizer were deleted — it would be verifying the panel gate, not the resource. So every resource-level denial test in this lane must **also** assert denial for an actor holding a panel-authorized role that lacks rights on that resource (`operator` is the natural choice). Roleless-denied proves the original gap is closed; `operator`-denied proves the resource check does independent work.

### Scope summary

- *Full rigor:* Task 1 (FAQ authorizer), Task 6 (report/export scoping), Task 10 (panel gate, conditional).
- *Light tier:* Tasks 2-5, 7-8.
- *Ledgered, not built:* AC3 vendor entity (L11's), AC4/AC9 order workflows (L7's), AC11 beyond failed-payment, order-dependent parts of AC7. AC7's own **"where data exists"** clause sanctions this.

---

## File Structure

| Path | Responsibility |
|---|---|
| `app/Domain/Faq/Contracts/FaqAuthorizer.php` | Interface: `authorizeManage()` throws, `canManage()` returns bool |
| `app/Domain/Faq/Authorization/RoleBasedFaqAuthorizer.php` | Role check against `ActorContext` |
| `app/Domain/Faq/Exceptions/FaqActionNotAuthorisedException.php` | Module-specific throw, mirroring `LedgerReadNotAuthorisedException` |
| `app/Domain/CemeteryDirectory/Actions/` | New write Actions — none exist today |
| `app/Domain/Marketplace/Actions/` | New write Actions — directory exists but is empty |
| `app/Filament/Admin/Resources/<Name>/` | One dir per Resource, following the `FaqArticles/` split: `Schemas/`, `Tables/`, `Pages/` |
| `app/Filament/Admin/Pages/` | Dashboard, reports, audit review |

---

## Tasks

Each task ends with `php artisan test` green against the recorded baseline (1812 tests, 2 pre-existing host-only errors) plus `bash ci/verify-docs.sh` all-pass, then a commit. **No task marks an AC `Covered` in the traceability matrix without a real test file on disk** — Gate 7 enforces this.

### Task 1: FAQ authorization (FULL RIGOR)

**Files:**
- Create: `app/Domain/Faq/Contracts/FaqAuthorizer.php`, `app/Domain/Faq/Authorization/RoleBasedFaqAuthorizer.php`, `app/Domain/Faq/Exceptions/FaqActionNotAuthorisedException.php`
- Modify: `app/Filament/Admin/Resources/FaqArticles/Tables/FaqArticlesTable.php:151-228`, `app/Filament/Admin/Resources/FaqArticles/FaqArticleResource.php`, the Faq service provider (binding)
- Modify: `tests/Feature/Filament/Admin/Faq/FaqArticleAuthorizationCharacterizationTest.php` — invert, do not delete

**Interfaces — Produces:**
```php
interface FaqAuthorizer
{
    public function canManage(ActorContext $actor): bool;
    public function authorizeManage(ActorContext $actor): void; // throws FaqActionNotAuthorisedException
}
```

- [ ] **Step 1: Write the failing tests.** Rewrite each of the four existing `test_gap_*` methods to assert denial instead of success, renaming `test_gap_any_authenticated_user_can_publish_an_article` → `test_a_roleless_user_cannot_publish_an_article`, and keep a docblock recording that this test previously pinned the gap (cite `retrofit-backlog.md:88`). Add a positive counterpart per action granting `ActorRole::ADMIN` via `GrantActorRole`, asserting the action still succeeds. **Both directions are required** — a denial-only test suite passes just as well against a resource that denies everyone.
- [ ] **Step 2: Run them and watch them fail.** `php artisan test --filter=FaqArticleAuthorization`. Expected: the four denial tests FAIL (the action still succeeds today). If any denial test passes before the implementation exists, the test is vacuous — fix it before continuing.
- [ ] **Step 3: Implement the authorizer.** `RoleBasedFaqAuthorizer::canManage()` returns `$actor->hasRole(ActorRole::ADMIN)`. `authorizeManage()` calls it and throws `FaqActionNotAuthorisedException` otherwise. Bind the interface in the Faq service provider.
- [ ] **Step 4: Wire both layers on all four actions.** Add `->authorize(fn (): bool => app(FaqAuthorizer::class)->canManage(app(ActorContext::class)))` **and** call `app(FaqAuthorizer::class)->authorizeManage(app(ActorContext::class));` as the first statement inside each `->action()` closure. Both are required: `->authorize()` governs whether the control renders and mounts, the throwing call is the server-side enforcement that holds even if the action is invoked directly. Preserve every existing `->visible()`/`->hidden()` record-state guard — those are orthogonal to authorization and must not be replaced by it.
- [ ] **Step 5: Add resource-level authorization.** Implement `canViewAny()`/`canCreate()`/`canEdit()` on `FaqArticleResource` delegating to the same authorizer, so `ViewAction`/`EditAction` and the list page are covered too, not only the four custom actions.
- [ ] **Step 6: Correct the stale comments.** Remove the "`ActorContext::$roles` is always `[]`" claims from `AdminPanelAccessPolicy` and the characterization test — L5 made them false and they actively mislead.
- [ ] **Step 7: Run the full suite.** `php artisan test`. Expected: baseline + the new tests, no regressions.
- [ ] **Step 8: Commit** as its own reviewable authorization unit, message noting AGENTS.md §Infrastructure-agent execution human review is required and that the role grant is `admin`-only pending the ruling on open question A.

### Task 2: ADM-020 service catalogue and price/tariff admin (light) — AC2

Write Actions already exist (`DefineServicePackage`, `PublishServicePackageVersion`, `ReviseServicePackageVersion`, `RecordServiceDefinitionPriceVersion`), so this is Resource wiring over a complete domain — the best first light-tier task.

- [ ] Resource for `ServicePackage` + `ServicePackageVersion` + `ServicePackageItem`, delegating every write to the existing Actions. Never touch Eloquent directly.
- [ ] Price/tariff screen over `PriceVersion`. `PRICE_VERSION_RECORDED` and `SERVICE_DEFINITION_PRICE_VERSION_RECORDED` are both `SensitiveActions`-listed, so the form **must** capture a mandatory non-blank reason and the confirm modal must state the consequence and not default-focus the destructive button.
- [ ] `price_versions.amount` is `decimal`; convert with `Money::fromDecimal()` at the read seam before comparing against any ledger figure.
- [ ] Authorization via an injected authorizer, same idiom as Task 1. Tests cover authorized success and unauthorized denial.

### Task 3: ADM-010 cemetery / TPU-TPS admin (light) — AC2

- [ ] Create write Actions under `app/Domain/CemeteryDirectory/Actions/` — **none exist**; every `cemeteries` row today comes from a seed migration.
- [ ] Resource over `Cemetery` (UUID pk) plus `CemeteryCapabilityProfile` and `CemeteryPackage`. Closed lists (`CemeteryType`, `CemeteryPublicationStatus`, `LaunchCityCode`, the capability modes) drive every select — never a free-text field.
- [ ] City, facility, class and availability have **no entity**; manage them as the columns they are (`city` string against `LaunchCityCode`, `facilities` json, `class_label` string) and record in the ledger that AC2's "city/facility/class/availability management" is columns-not-entities.

### Task 4: ADM-030 product and variant admin (light) — AC3 partial

- [ ] Create write Actions under `app/Domain/Marketplace/Actions/` — the directory exists but is empty.
- [ ] Resource over `Product` and `ProductVariant`. `products.base_price_idr` is a **minor-unit integer**, unlike the catalogue's decimals — do not mix them.
- [ ] **Vendor management is explicitly out of scope here** (L11 owns the schema). Do not create a `vendors` table, and do not build CRUD over `products.vendor_name`, which is dummy free text.

### Task 5: ADM-070 payment and transaction views (light) — AC5

- [ ] Read-only Resources/Pages over `PaymentIntent`, `PaymentSession`, `ProviderEvent`, `PaymentVerification`, `PaymentReversal`. Backend is complete with zero UI today.
- [ ] Manual outgoing-payment recording with proof, delegating to the existing `VerifyManualPayment` / payout Actions. `PAYMENT_MANUAL_VERIFICATION` and `VENDOR_PAYOUT` are `SensitiveActions`-listed → mandatory reason.
- [ ] `MENUNGGU_VERIFIKASI_PEMBAYARAN` renders `pending`, **never** `success`. Resolve through `StatusIntent::filamentColor($state, StatusIntent::FAMILY_ORDER_LIFECYCLE)` — always pass the family explicitly.
- [ ] Coordinate with the live `fix/payment-controller-authorization` hotfix worktree before touching payment authorization.

### Task 6: ADM-090 reports and export scoping (FULL RIGOR) — AC7, AC10

- [ ] Reports over `LedgerReport::summary()`, scoped through the existing `FinanceLedgerReadAuthorizer` (requires `ActorRole::FINANCE` **and** a `business_entity` scope grant at `privileged`). This is the working precedent — reuse it, do not write a parallel one.
- [ ] AC10: an out-of-scope record must return an explanatory state and **must not reveal whether the record exists** (design-system §6.4). Test this explicitly.
- [ ] Bulk export renders `secondary`, never adjacent to a benign action, and requires recent re-authentication.
- [ ] Financial totals reconcile to journal references, not to mutable order status (design.md §Reporting).

### Task 7: ADM-100 audit and sensitive-action review (light) — AC8

- [ ] Read-only Resource over `AuditEvent`. Append-only — no edit or delete path.
- [ ] Filter by action, actor, and date. Confirm no restricted data is rendered into logs or error trackers.

### Task 8: ADM-001 dashboard and failed-payment exception queue (light) — AC1, AC11 partial

- [ ] Dashboard widgets for the modules that exist. Empty state per widget hides rather than renders an empty shell (§6.2); loading uses skeleton tiles (§6.1).
- [ ] Failed-payment exception queue from `provider_events.status` and `reconciliation_exceptions` — `danger` intent.
- [ ] The other three AC11 queues (missing operator response, vendor delay, unmatched renewal) have no data model. Render nothing and ledger them; **do not fabricate data to fill a widget.**

### Task 9: Documentation and traceability

- [ ] Extend the existing ADM rows in `docs/product/screen-inventory.md` §B using §A's established annotation convention. Do not invent a rival table.
- [ ] Update `docs/domain/traceability-matrix.md` — only mark `Covered` where a real test path exists (Gate 7 enforces).
- [ ] Update `.kiro/specs/admin-operations/tasks.md` progress honestly, and add ledger rows to `docs/planning/retrofit-backlog.md` for every deferred AC with its owning lane.
- [ ] Propose the missing FAQ/content row for `docs/security/rbac-matrix.md` (open question A) rather than leaving the model undocumented.

### Task 10: Panel gate (FULL RIGOR) — runs immediately after Task 1

Approved by explicit user ruling; see open question B. Sequenced second, not last, because every Resource added by Tasks 2-8 inherits its protection and its test conventions.

**Files:**
- Modify: `app/Platform/IdentityAccess/Panel/AdminPanelAccessPolicy.php`
- Modify: `tests/Unit/Platform/IdentityAccess/Panel/AdminPanelAccessPolicyTest.php`, `tests/Feature/IdentityAccess/UserCanAccessPanelTest.php`, `tests/Feature/IdentityAccess/AdminPanelHttpAccessTest.php`, the four `tests/Feature/IdentityAccess/Mfa/*` page tests, `tests/Feature/FinancialLedger/FinanceReportsPageTest.php`, `tests/Feature/Notification/InAppNotificationListPageTest.php`, and the three `tests/Feature/Filament/Admin/Faq/*` files

- [ ] **Step 1: Write the failing test.** In `AdminPanelAccessPolicyTest`, assert a `customer`-role actor and a roleless actor are both denied, and that each of `admin`, `restricted_admin`, `operator`, `finance` is allowed. Add an HTTP-level test in `AdminPanelHttpAccessTest` asserting a `customer` gets 403 at `/admin`.
- [ ] **Step 2: Run and watch it fail.** Expected: the denial assertions fail, because `allows()` currently returns `true` for anyone authenticated.
- [ ] **Step 3: Implement.** `allows()` returns `$actor->isAuthenticated()` **and** holds one of `ActorRole::ADMIN`, `RESTRICTED_ADMIN`, `OPERATOR`, `FINANCE`. Keep the authenticated check — a guest must never pass on role absence alone. Replace the stale docblock with what the policy now does.
- [ ] **Step 4: Repair the affected tests.** Each test that `actingAs()` a roleless user against `/admin` gets a real role grant via `GrantActorRole` (mandatory non-blank reason — `ROLE_GRANT` is `SensitiveActions`-listed). Grant the *least* role that makes each test's intent true: MFA and notification page tests want "some panel user" → `operator`; finance reports → `finance`; FAQ management → `admin`. **Do not blanket-grant `admin`** — that would erase the distinctions Task 1 established.
- [ ] **Step 5: Verify Task 1's denial tests did not become vacuous.** The `operator`-denied FAQ tests must still fail for the right reason — resource authorization, not panel rejection. Temporarily stub `RoleBasedFaqAuthorizer::canManage()` to `return true;`, confirm those tests FAIL, then revert the stub. Record the output in the report. If they pass with the authorizer neutered, the test is measuring the wrong thing and must be fixed.
- [ ] **Step 6: Full suite + `bash ci/verify-docs.sh`.**
- [ ] **Step 7: Commit** as its own authorization unit, flagged for human review per `AGENTS.md` §Infrastructure-agent execution.

---

## Verification

- `php artisan test` — must match baseline plus new tests; any other failure is this lane's.
- `bash ci/verify-docs.sh` — all 13 gates.
- **Real PostgreSQL 18** verification for anything schema-touching, against `makam-nonprod-postgres-1`. SQLite-only runs are not sufficient evidence for a migration.
- Never report `PASS` for a check not executed — `BLOCKED` or `NOT TESTED` explicitly.
