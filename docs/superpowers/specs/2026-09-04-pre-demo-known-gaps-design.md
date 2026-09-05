# Pre-Demo Known-Gap Closure — Design

## Context

The user asked for a full, deep UAT sweep across all 9 roles and every major journey, to make sure the platform is precise and bug-free for a live product demo on the real `makam.co.id` beta host, including a deep visual/UI-UX pass. That request was too large for one spec and was decomposed into 4 sub-projects (recorded in conversation, not a separate doc): known-gap closure (this spec), payment + notification end-to-end verification, a full role-by-role journey sweep, and a visual/UX + accessibility audit. This spec covers the first: closing the small number of concrete, already-flagged functional/coverage gaps on record in `docs/testing/release-gates.md` and `docs/domain/traceability-matrix.md`, verified against the real current code before scoping (two of the six originally-flagged items turned out already closed — see below).

Verification pass findings (all confirmed against real code/tests, not doc claims):

- **CARE-SUB-02** (cycle-scheduler skip-when-not-due) — already closed. `docs/testing/release-gates.md`'s own 25 Aug 2026 note says the real gap was only that `traceability-matrix.md` cited the wrong test file; the actual code (`app/Domain/CareSubscription/CycleScheduler.php:32-34`) and test (`tests/Feature/Domain/CareSubscription/CycleSchedulerTest.php:85-94`) both exist and pass. No work needed.
- **Aggregate-tier cemetery plot-block guard** — already closed via PR #215 (29 Aug 2026, commit `ca434723`). `app/Domain/PlotInventory/Actions/CreateCemeteryBlock.php:86-91` has the guard. `traceability-matrix.md`'s v0.21 note claiming this is still open is itself stale. Doc-only fix needed (§5).
- **CARE-SUB-06** (`CreateMakeGood` has no live UI path) — confirmed still open, and turned out larger than documented: `ServiceComplaint` has **zero UI anywhere**, admin or customer. Complaints can be filed via `FileComplaint` but staff have no way to see, triage, or act on one. User chose the full fix: a real admin resource with a resolve/dismiss lifecycle, not just a bare "create make-good" button. This is the substantial piece of this spec (§1).
- **Marketplace product-detail schema gap** — narrower than documented. The 5 fields the traceability matrix's PUB-021 row said were "missing, needing a migration" already exist — not on `products`/`product_variants`, but on `vendor_listings` (`availability_mode`, `stock_quantity`, `production_lead_time_days`, `cancellation_policy`, `evidence_requirement`) and `service_areas` (`area_code`, `delivery_fee_minor`), both already vendor-editable via Filament forms, and delivery fee already powers real checkout math (`PlaceMarketplaceOrder.php:145,167`). The only real gap: the public product page never displays availability/stock/evidence/cancellation-policy to a shopper, even though the data it needs (`$listing`) is already resolved server-side. No migration needed (§2).
- **No `/akun` E2E browser suite** — confirmed still open, real gap (§3).
- **Booking-wizard loading-state inconsistency** — confirmed still open, but the fix is different from what `release-gates.md` implies. `<x-mk.button>`'s `:loading` prop (`resources/views/components/mk/button.blade.php:28`) is a plain server-rendered Blade boolean, not Alpine-bound — it cannot reflect a live, in-flight Livewire request. The wizard's real, already-working solution for that is a separate `wire:loading` spinner span paired with `wire:loading.attr="disabled"` on the button, already used correctly at 4 of 13 action-button call sites. The fix is extending that existing pattern to the other 9, not switching to `:loading` (§4).

## Global constraints

- `declare(strict_types=1);` on every new/modified PHP file.
- New domain Actions follow this codebase's established `Audit::wrap()` pattern exactly (see `FileComplaint`/`CreateMakeGood` for the shape: `mutation` closure, `action`, `subject`, `outcome`, `actorRef`, `actorRole`, `source`, `correlationId`).
- The new Filament resource mirrors `WorkOrdersResource`'s real file layout and authorization pattern (`MasterDataAdminAuthorizerContract`) exactly — this is an existing, established convention, not something to redesign.
- Every status transition on `ServiceComplaint` goes through a real domain Action — never a raw Eloquent `update()` from Filament code, matching this codebase's domain-layer discipline everywhere else.
- Real Postgres 18 (never SQLite) for every test in this plan, via the pinned CI image, matching this session's established practice.
- `vendor/bin/pint --test` and `vendor/bin/phpstan analyse --no-progress --memory-limit=1G` (no file-path arguments) must stay clean throughout.
- `bash ci/verify-docs.sh` must stay clean for any task touching `docs/`.

## 1. Service complaints admin resource

### The real gap this closes

`ServiceComplaint` (`app/Domain/VendorFulfillment/Models/ServiceComplaint.php`) has columns `work_order_id`, `customer_id`, `complaint_text`, `status` (`ComplaintStatus`: `Open`/`Investigating`/`Resolved`/`Dismissed`), `resolution_notes`, `resolved_at`, `filed_at`. It has **no relationship methods** — anything joining to `WorkOrder` or the filing customer must query separately. `FileComplaint` (`app/Domain/VendorFulfillment/Actions/FileComplaint.php`) is the only thing that writes to this table today, always creating an `Open` row, called from exactly one place: `CareHistoryPage::fileComplaint()` (customer-facing). **No domain Action exists to transition a complaint's status** — grepped `app/Domain/VendorFulfillment/Actions/` for `Investigate`/`Resolve`/`Dismiss`, zero hits. **No Filament resource, page, or table references `service_complaints` or `ServiceComplaint` anywhere** — confirmed by grep across `app/Filament/`. `WorkOrdersTable.php`'s "Komplain" references are a *different*, unrelated enum case (`WorkOrderStatus::Complaint`, a work-order-level status), not this table.

Do not confuse this with the marketplace's separate `VendorProcessingStatus::KOMPLAIN` flag (`app/Livewire/Public/Marketplace/OrderTracking.php`) — that's a vendor-order status flag on a totally different model, unrelated to `service_complaints`. Out of scope for this spec.

### New domain Actions (mirror `FileComplaint`'s exact shape)

- **`StartInvestigatingComplaint(ServiceComplaint $complaint): ServiceComplaint`** — `Open → Investigating` only; throws (a new `InvalidComplaintTransitionException` or reuses an existing domain exception pattern — implementer's call, follow whatever exception-naming convention `VendorFulfillment` already uses elsewhere) if the complaint isn't currently `Open`. Records `VendorFulfillmentAuditActions::COMPLAINT_INVESTIGATING` (new audit-action constant, add it next to `COMPLAINT_FILED`/`MAKE_GOOD_CREATED` in `VendorFulfillmentAuditActions`). New outbox event `care.complaint_investigating.v1`.
- **`ResolveComplaint(ServiceComplaint $complaint, string $resolutionNotes, bool $createMakeGood, ?string $makeGoodNotes = null): ServiceComplaint`** — `Open|Investigating → Resolved`. Sets `resolution_notes`, `resolved_at = now()`. When `$createMakeGood` is true, calls `CreateMakeGood` against the complaint's `WorkOrder` (looked up via `work_order_id`) inside the **same** `Audit::wrap()` mutation closure as the status update, so both writes commit or roll back together, and stores the resulting `MakeGoodOrder`'s id on the complaint (see schema change below). Records `VendorFulfillmentAuditActions::COMPLAINT_RESOLVED`. New outbox event `care.complaint_resolved.v1` (includes `make_good_order_id` in its payload when set, else `null`).
- **`DismissComplaint(ServiceComplaint $complaint, string $reason): ServiceComplaint`** — `Open|Investigating → Dismissed`. `$reason` stored in `resolution_notes` (reuse the same column — a dismissal reason and a resolution note are the same kind of "why this complaint is closed" text; don't add a second column for this). `resolved_at = now()`. Records `VendorFulfillmentAuditActions::COMPLAINT_DISMISSED`. New outbox event `care.complaint_dismissed.v1`.

All three throw if called on a complaint not in an allowed source state (fail closed — mirrors this codebase's `OrderIsGuardedException`-style discipline elsewhere, e.g. the order-workflow write guard the demo-seed-data work already had to route around).

### Schema change: link a resolved complaint to its make-good

Add nullable `make_good_order_id` (uuid, `restrictOnDelete` FK to `make_good_orders.id`) to `service_complaints`. This is the one real domain-model gap the research surfaced: today, filing a complaint and creating a make-good are two Actions that both happen to key off the same `WorkOrder`, with **no queryable link** recording "this make-good exists because of this complaint." A migration adding the column is the correct fix — not a workaround, a genuinely missing relationship. Only `ResolveComplaint` ever writes this column.

### `CreateMakeGood`'s audit-attribution gap

`CreateMakeGood`'s `Audit::wrap()` call hardcodes `actorRole: 'system'`, `source: AuditSource::Job` (`CreateMakeGood.php:79-81`) — correct today, since its only real caller is unattended. Called from `ResolveComplaint` (itself called from a real admin action), the audit trail should show the actual admin who resolved the complaint, not `system`. Fix: add optional parameters to `CreateMakeGood::__invoke()` — `?string $actorRole = null, ?AuditSource $source = null, ?string $actorRef = null` — defaulting to the exact current hardcoded values (`'system'`, `AuditSource::Job`, `null`) when omitted, so every existing call site (`CareSubscriptionExampleData`'s demo-seed generator, `ComplaintFlowTest`, any other caller) needs zero changes. `ResolveComplaint` is the only caller that passes real values, sourced from the Filament action's current `ActorContext`.

### Filament resource — mirror `WorkOrdersResource`'s exact file layout

```
app/Filament/Admin/Resources/ServiceComplaints/
  ServiceComplaintsResource.php
  Pages/ListServiceComplaints.php
  Pages/ViewServiceComplaint.php
  Actions/StartInvestigatingAction.php
  Actions/ResolveComplaintAction.php
  Actions/DismissComplaintAction.php
  Tables/ServiceComplaintsTable.php
  Schemas/ServiceComplaintInfolist.php
```

No create/edit form (complaints are only ever created via `FileComplaint`, from the customer-facing flow) — matches `WorkOrdersResource`'s own read-only-plus-actions shape exactly (no `Schemas/*Form.php`, `getPages()` only registers `'index'`/`'view'`).

- `canAccess()`/`getAuthorizationResponse()`: both call `MasterDataAdminAuthorizerContract::authorize(app(ActorContext::class))`, identical to `WorkOrdersResource.php:63-83`. Real authorized roles (confirmed against the live implementation, not assumed): `ADMIN`, `RESTRICTED_ADMIN`, `OPERATOR`, `FINANCE` (`MasterDataAdminAuthorizer.php:39-44`).
- `ServiceComplaintsTable`: columns for status (badge, colored per `ComplaintStatus`), `complaint_text` (truncated), `filed_at`, `resolved_at`, and a resolved-work-order reference. Add `ServiceComplaint::workOrder(): BelongsTo` to the model — it is a real, missing relationship this resource needs, and every other Eloquent read in this table should go through it rather than a manual `WorkOrder::find()` per row. Filter by `status`.
- `ServiceComplaintInfolist`: full complaint text, filing customer, linked `WorkOrder`/care-plan context, resolution notes, and (when set) a link to the resulting `MakeGoodOrder`. Add `ServiceComplaint::customer(): BelongsTo` (`User::class, 'customer_id'`) to the model for the same reason as `workOrder()` above. **Corrected 4 Sep 2026 (this note's original claim about `customer_id` was stale when written):** the original text here said the migration declared `customer_id` as `foreignUuid` with no FK, while the model cast it `integer` — a real mismatch this spec found during research. That defect was already fixed 13 days before this spec was written, by `database/migrations/2026_08_22_100000_fix_customer_and_uploader_identity_columns.php`, which redeclared `customer_id` as `foreignId('customer_id')->constrained('users')` — a real bigint column with a real FK to `users.id`, matching the model's `integer` cast. This spec's research was working from stale information; there was no live defect left for this task to avoid fixing.
- Three page actions: **Mulai Investigasi** (visible only when `status === Open`, calls `StartInvestigatingComplaint`), **Selesaikan** (visible when `status ∈ {Open, Investigating}`, a form modal collecting `resolution_notes` + a "Buat pesanan perbaikan (make-good)?" toggle + conditional `makeGoodNotes` field, calls `ResolveComplaint`), **Tolak** (visible when `status ∈ {Open, Investigating}`, a form modal collecting a required reason, calls `DismissComplaint`). All three use Filament's standard action-confirmation + audited-mutation pattern already established elsewhere in this admin panel (e.g. `ReplaceVendorAction` in the same `WorkOrders` directory is a good reference for the shape).

### Testing

- Domain-layer tests for all 3 new Actions (state-machine correctness: allowed/disallowed transitions, the `ResolveComplaint`+`CreateMakeGood` same-transaction guarantee, audit/outbox events) — real Postgres, following `ComplaintFlowTest.php`'s existing style exactly (it already proves the `FileComplaint`→`CreateMakeGood` chain works; these new tests extend that same file or a sibling file, not duplicate it).
- A Filament resource test proving `canAccess()` genuinely denies a `CUSTOMER`/`VENDOR`/`CEMETERY_OPERATOR` actor and allows the 4 authorized roles (matches the existing test pattern for `WorkOrdersResource` or `SubscriptionsResource` — read whichever exists and follow it).
- A feature test walking the full UI path: file a complaint (existing `FileComplaint` call), load it in the new resource, resolve it with make-good, assert the `MakeGoodOrder` was created and linked, assert the audit trail shows the real admin actor (not `system`).

## 2. Marketplace listing details surfaced to shoppers

`ProductDetail::render()` (`app/Livewire/Public/Marketplace/ProductDetail.php:225-259`) already resolves a `?VendorListing $listing` and passes it to the view. `product-detail.blade.php`'s own header comment already documents this exact gap (lines 10-32) — the four fields (`availability_mode`, `stock_quantity`, `evidence_requirement`, `cancellation_policy`) live on `$listing` and are simply never rendered. This is a pure Blade-view addition:

- `availability_mode` (`STOCKED`/`MADE_TO_ORDER`/`SCHEDULED`, via `VendorListing`'s own constants/enum — check whether one exists or if it's a bare string, per the migration's CHECK constraint) → a short label + icon, matching this codebase's `StatusIntent`/badge conventions used elsewhere on this page.
- `stock_quantity` → shown only when `availability_mode === STOCKED` (matches the migration's own `vendor_listings_stock_only_when_stocked` CHECK constraint semantics — don't show a stock count for a made-to-order or scheduled listing, it's meaningless there).
- `evidence_requirement` (`NONE`/`PHOTO`/`DOCUMENT`) → a short note on what proof of completion the vendor requires, if relevant to a shopper's expectations (implementer's judgment on exact copy — keep it short, this is informational, not a form field).
- `cancellation_policy` → free text, shown when non-null.
- Service area / delivery fee (`service_areas.area_code`/`delivery_fee_minor`) — check whether the product page already shows anything about delivery area at all; if not, a minimal "tersedia untuk N area layanan" or similar summary is in scope too, following whatever the checkout page already does to look up a vendor's service areas (`Checkout.php:104-189`) for consistency of language/format.

No migration, no new query — read the four/five fields directly off the `$listing`/service-area data already resolved by the existing `render()` method.

### Testing

A Livewire component test (or extend an existing `ProductDetailTest` if one exists) asserting the rendered view contains the availability/stock/evidence-requirement/cancellation-policy content when a listing has them set, and correctly omits stock display for a non-`STOCKED` listing.

## 3. `/akun` E2E browser suite

New `tests/browser/e2e-akun.spec.ts`. Before writing it, read the real `/akun` route group (`routes/web.php:550`) and whatever Livewire/controller handles `/akun` login — the existing `e2e-admin-vendor.spec.ts` login pattern is Filament-specific (`adminLogin()`/`vendorLogin()` fill Indonesian-label form fields against `/admin/login`/`/vendor/login`, both routed through `Filament\Auth\Pages\Login`) and does **not** apply as-is to `/akun`, which is a plain Laravel-auth customer area, not a Filament panel — confirm the real login form's field labels/route from the actual `/akun` login view before writing selectors, don't assume they match Filament's.

Cover, at minimum: login, the account dashboard, the drafts list, the orders list (per `docs/product/screen-inventory.md`'s `/akun` row descriptions — read that section for the exact current set of `/akun` sub-pages before finalizing test scope, it may have grown since the survey that flagged this gap). Follow `e2e-admin-vendor.spec.ts`'s fixture-data discipline (every string asserted against comes from reading the real source, never guessed) and its session-caching pattern if `/akun` login also needs rate-limit-aware caching (check whether `/akun`'s login shares Filament's panel-wide rate limiter or has its own/none — this determines whether the `loginOnceUnlessFreshSession` helper is needed here at all).

## 4. Booking-wizard loading-state consistency

Extend the existing spinner-span pattern (already correct at 4 of 13 action-button call sites: lines ~688, ~1113, ~1262, ~1377 in `resources/views/livewire/public/booking/wizard.blade.php`, as of this spec's research pass — confirm exact current line numbers at implementation time, the file may have shifted) to the other 9 action buttons, which currently only have `wire:loading.attr="disabled"` with no visible spinner. For each of the remaining `wire:target` actions (`selectCity`, `openPickerFor` ×2, `selectCemetery` ×2, `holdPlotForDiscovery`, `selectServiceType`, plus any not yet covered), add a paired `<span wire:loading wire:target="<action>">` containing `<x-mk.spinner>` (or whatever the existing 4 correct instances use verbatim — copy their exact markup, don't invent a new spinner pattern). Do **not** attempt to use `<x-mk.button>`'s `:loading` prop for this — confirmed it's a static server-rendered Blade boolean with no Alpine binding, incapable of reflecting live Livewire request state; the file's own existing `wire:loading` span pattern is the real, already-correct solution, just inconsistently applied.

### Testing

Existing booking-wizard Feature/browser tests should be unaffected (this only adds visible UI, doesn't change any `wire:click`/`wire:target` behavior) — no new test required beyond a visual/manual check that each button's spinner appears during its own action and not another's (i.e. `wire:target` values are correct per-button, not copy-paste-shared).

## 5. Documentation corrections

- `docs/domain/traceability-matrix.md`: correct the v0.21 note claiming the aggregate-tier `CreateCemeteryBlock` guard is still open follow-up work — it was closed by PR #215 (29 Aug 2026). Follow this repo's own convention of appending a correction rather than deleting the superseded note (per this file's own stated practice elsewhere).
- `docs/domain/traceability-matrix.md`'s PUB-021 row: correct the marketplace product-detail gap description to match what's actually still missing (display-only, on `vendor_listings`/`service_areas` fields already collected — not a schema/migration gap on `products`/`product_variants`).
- `docs/testing/release-gates.md` §A: once §1–§4 land and are tested, update the still-unchecked "Traceability contains no Missing or Partial item for stakeholder MVP" line's CARE-SUB-06 evidence to point at the new resource + tests.

## Out of scope

- ~~The `service_complaints.customer_id` foreignUuid-vs-integer type mismatch found during research — real, pre-existing, but unrelated to this work; flag for a future, separate investigation.~~ **Corrected 4 Sep 2026:** this was stale when written. `database/migrations/2026_08_22_100000_fix_customer_and_uploader_identity_columns.php` (13 days before this spec) already fixed `customer_id` to `foreignId('customer_id')->constrained('users')`, matching the model's `integer` cast. No separate investigation is needed; this line described a defect this spec's own research failed to notice was already closed.
- The `VendorProcessingStatus::KOMPLAIN` marketplace vendor-order complaint flag — a different system, not touched here.
- Any customer-facing UI for complaint status/resolution (e.g. showing the customer their complaint was resolved) — `CareHistoryPage` already lets a customer file a complaint; whether/how it should later show resolution status is a separate product decision, not assumed here.
- The other 3 sub-projects from the original decomposition (payment/notification verification, full role-by-role journey sweep, visual/UX + accessibility audit) — separate, later specs.

## Verification

Standard repo gates throughout: `declare(strict_types=1)`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse` (no path args), real Postgres 18 for every test (never SQLite, via the pinned CI image), `bash ci/verify-docs.sh` for doc-touching tasks. Once implemented: `docs/testing/release-gates.md` §A's CARE-SUB-06 line should be checkable with real evidence for the first time.
