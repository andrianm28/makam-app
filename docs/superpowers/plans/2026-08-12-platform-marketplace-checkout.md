# Marketplace Cart and Single-Vendor Checkout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the customer-facing marketplace cart and single-vendor checkout — browse to cart to checkout to manual-payment-or-gate-closed to vendor processing to customer-visible status — plus the vendor/listing/order schema a future vendor portal will consume.

**Architecture:** The merged browse skeleton (`MarketplaceIndex`, `ProductDetail`, `MarketplaceCatalogQuery`) stays untouched and keeps `products` as platform-wide catalogue master data. A new `vendor_listings` table carries the per-vendor-offer attributes the catalogue requires (price, stock, service area, schedule, delivery fee, evidence requirement), so a cart line references a vendor's offer rather than a catalogue row. Checkout writes `marketplace_orders -> marketplace_order_items -> vendor_orders -> vendor_order_items`, allocating through a `foreach`-over-one-vendor structure so multi-vendor becomes a loop-bound change rather than a rewrite. Authorization reuses the already-shipped `ScopeEntityType::VENDOR` seam; money reuses `Money` and `vendor_payables`; payment reuses the merged manual-verification path.

**Tech Stack:** Laravel 13, Livewire 4, Filament 5 (not used in this lane), PostgreSQL 18, Pest/PHPUnit feature tests, Tailwind with `resources/css/tokens.css` design tokens.

## Scope boundary — the vendor portal is NOT in this lane

**This lane builds only the customer-facing side.** The spec `funeral-marketplace-and-vendor-portal` covers two surfaces; only one is built here.

**In scope:** requirements 1-4, 9, 10, 12, 13, 14, 15.

**Explicitly out of scope, deferred to a future L10 "vendor portal" lane that is not yet dispatched:**
- Requirement 5 — the dedicated authenticated Filament vendor panel (`/vendor`). **No Filament panel, resource, or page is created by this plan.**
- Requirement 6 — vendor self-service management of its own products, variants, prices, stock, service areas, and calendar.
- Requirement 7 — the vendor's own receive/accept/reject/status-update/evidence-upload screens.
- Requirement 8 — the vendor-facing transaction history and payout status view.
- Requirement 11 — the manual payout proof workflow.
- Every `VND-*` screen in `docs/product/screen-inventory.md`.

A future reader should not read the absence of a vendor panel as an oversight. **The schema in this plan is built to be consumed by that future lane** — `vendor_users` is queryable, `vendor_orders` carries vendor-scoped fields, and the vendor scope seam is enforced at query level — even though none of its UI exists yet.

**Requirement 14 is a prohibition, and this plan honours it as one.** Nothing here builds toward multi-vendor checkout. Order splitting, partial cancellation/refund, fee/tax allocation, dispute handling, and reconciliation do not exist, so multi-vendor checkout must not exist either.

**Requirement 15 is likewise a prohibition.** No plot, land, or grave-rights product is added to the catalogue or to any table here.

---

## Current state — read this before designing anything

Everything in this section was verified against the code at `d9fea9f`, not inferred from documentation.

### What is already built and must not be rebuilt

| Seam | Location | What it gives this lane |
| --- | --- | --- |
| Marketplace browse skeleton | `app/Domain/Marketplace/` | `ProductCode` (9 codes), `MarketplaceProductCategory` (3 keys), `VendorProcessingStatus` (8 statuses), `MarketplaceCatalogQuery`, `Product`, `ProductVariant`. **Do not duplicate any of these.** |
| Payment | `app/Platform/Payment/` | `GuardPaymentSession`, `SubmitManualPayment`, `VerifyManualPayment`, `ReversalService`. Plan doc: `docs/superpowers/plans/2026-08-09-platform-payment-adapter.md` |
| Financial ledger | `app/Platform/FinancialLedger/` | `Money` (integer minor units), `Journal::post()`, `Actions\VendorPayable::assess()`, `vendor_payables`, `payouts` |
| Identity and access | `app/Platform/IdentityAccess/` | `ActorRole::VENDOR`, `ScopeEntityType::VENDOR`, `ScopeEntityType::ORDER`, `ScopeAssignmentReader`, `ScopeAssignmentGlobalScope`, `ActorContext::hasScope()` |
| Audit | `app/Platform/Audit/` | `Audit::record()`, `Audit::wrap()`, `SensitiveActions::ACTIONS` |
| Design system | `app/Support/Design/StatusIntent.php`, `resources/views/components/mk/` | `StatusIntent::FAMILY_VENDOR_PROCESSING` already maps all 8 vendor statuses to intent+icon. `x-mk.modal`, `badge`, `table`, `card`, `field`, `alert`, `button` all exist. |

### Deviations from `design.md`'s Data list, with reasons

`design.md`'s Data section lists 15 table names. It is a compact list, not an authoritative schema — it is demonstrably non-exhaustive and in one case contradicted by merged, CI-green code. This plan reconciles it table by table.

| `design.md` names | This plan | Why |
| --- | --- | --- |
| `marketplace_categories` | **NOT created** | `tasks.md` §"OPEN QUESTION" records that `marketplace-catalog.md` defines nine product codes and **zero** category codes, that inventing one would be "a second, competing source of truth — the exact defect this section exists to fix," and that category routing is **BLOCKED** pending a product-owner decision. Grouping already lives on `products.category` as three code-safe keys derived losslessly from the catalogue's own headings. Creating this table would resolve an open product question by fiat. |
| `products`, `product_variants` | already exist, **untouched** | Platform-wide catalogue master data: nine seeded rows, one per closed-list `ProductCode`. |
| — (not named) | **`vendor_listings` created** | `marketplace-catalog.md` §"Required product data" leads with **vendor** and includes stock/availability, service area, schedule, delivery fee rule, production lead time, cancellation policy, and evidence requirement — all per-vendor-**offer** attributes. `2026_07_26_180000_create_products_table.php`'s own doc block anticipates exactly this table: those attributes "belong to a future vendor-listing table that references BOTH this table and `vendors`." Putting `vendor_id` on `products` instead would break the one-row-per-code invariant, `Product::findByCode()`, `MarketplaceCatalogQuery`, and the seeded catalogue tests. |
| — (in the flow diagram, not the list) | **`marketplace_order_items` created** | `design.md`'s own MVP order flow names `MarketplaceOrderItem`. |
| `vendor_transactions`, `vendor_payouts` | **NOT created** | Already served by the merged `vendor_payables` and `payouts` tables. `design.md`'s own MVP decisions say "Vendor transaction history is **read-only from platform financial references**" — so these are a read over the ledger, not new tables. Creating them would duplicate canonical financial data, which `AGENTS.md` §Documentation forbids. Requirement 8 is out of this lane's scope regardless. |
| `vendors`, `vendor_users`, `service_areas`, `vendor_availability`, `carts`, `cart_items`, `marketplace_orders`, `vendor_orders`, `vendor_order_items`, `fulfilment_evidence` | **created** | As listed. `fulfilment_evidence` keeps `design.md`'s spelling. |

### `vendor_users` is membership metadata, NEVER an authorization source

`design.md` names `vendor_users`, and a future vendor portal needs to know which humans belong to a vendor. But the shipped identity seam **already** expresses "this actor is scoped to exactly this vendor" as a `scope_assignments` row with `entity_type = 'vendor'`, and two shipped consumers query it that way (`FinanceOrRestrictedAdminPayoutAuthorizer.php:51-57`, `FinanceVendorPayableAuthorizer.php:101-102`).

Treating `vendor_users` as an authorization source would create a **second, rival scoping mechanism** — an actor could be denied by one and allowed by the other. So:

- `vendor_users` stores membership/profile facts only (which actor belongs to which vendor, when, display contact).
- **Every authorization decision reads `scope_assignments` via the identity seam.** No policy, query scope, or guard may branch on `vendor_users`.
- A test in Task 4 asserts this: a `vendor_users` row **without** a matching scope assignment grants no access.

### The paid path does not exist yet — checkout ships manual-only plus a gate-closed online branch

`GuardPaymentSession::__invoke()` fails closed on every condition except the mode check; `GuardResult::isAllowed()` is always `false` today, so **no `payment_sessions` row can be created at all**. `G-PAY-01` and the FIN-DEC approvals are closed/ungranted, and `docs/planning/sprint-plan.md:825` states outright that "none constitutes a working paid path."

Consequence for requirement 3's "payment or manual fallback":
- The **manual fallback is the live path** — `SubmitManualPayment::submit()` and `VerifyManualPayment::verify()` are real and working.
- The **online branch renders a design-system §6.9 gate-closed state**, because `G-PAY-01` genuinely governs it.

This also makes §6.9 newly *implementable*. `tasks.md` previously recorded §6.9 as "deliberately absent" for PUB-020/021 on the grounds that "no `G-*` gate governs checkout" — that reasoning was correct then and stops being correct the moment checkout exists.

### How this lane attaches to money, without inventing anything

- **`badan_usaha` is a free-form caller-supplied string.** No model, no table, no closed list, validated only as non-blank. `vendor_payables.entity_ref` freezes it at assessment time so the ledger's entity binding never depends on a mutable order row. Checkout supplies it; it is **not** derived later.
- **`vendor_payables.(vendor_id, source_type, source_id)` is the intended extension point**, carrying `UNIQUE(vendor_id, source_type, source_id)`. This lane uses `source_type = 'marketplace_order'`. That UNIQUE constraint is also what makes repeated checkout submission idempotent at the database (design-system §6.6).
- **`vendor_payables.vendor_id` is a plain string already used as `scope_assignments.entity_id`** for `entity_type = 'vendor'`. A new `vendors` table therefore needs **no backfill and no change to `vendor_payables`** — it only has to guarantee its primary key's string form is that same value.
- **`journal_batches_source_type_check`** is a closed list: `payment`, `manual_verification`, `renewal`, `refund`, `chargeback`, `payout`, `reversal`, `vendor_payable`. Any journal posting from this lane must pick from it.
- **`Money` is the boundary type.** Use `Money` in public signatures, never a bare `int`. Its constructor takes `mixed` and hand-checks `is_int()` on purpose, because an `int` type hint would be silently coerced at any call site lacking `declare(strict_types=1)`.

### There is no `orders` table here, and this lane does not create one

`design.md` gives the marketplace its own order root (`MarketplaceOrder -> MarketplaceOrderItem -> VendorOrder`) and names `marketplace_orders`, never `orders`. A generic `orders` table belongs to the separate `booking-and-order-orchestration` spec, built concurrently by another lane. **This plan creates no table named `orders`, no generic order state machine, and no FK to one.** `VendorProcessingStatus` governs vendor fulfilment state here and is deliberately distinct from any payment state.

### Testing reality: PostgreSQL 18 is a CI gate, not a local one

`phpunit.xml` sets `DB_CONNECTION=sqlite` / `:memory:`. **Every financial CHECK and trigger in this codebase is wrapped in `if (DB::connection()->getDriverName() !== 'pgsql') { return; }`, so SQLite gets none of them.** The house convention is to **skip, not fake**:

```php
if (DB::connection()->getDriverName() !== 'pgsql') {
    $this->markTestSkipped('PostgreSQL-only constraint; asserted on PostgreSQL 18 in CI.');
}
```

**A SQLite-only green run is NOT evidence for any pgsql-only constraint.** This lane is schema-heavy and creates constraint triggers, so SQLite would silently skip exactly the things most worth proving.

**Use a disposable PostgreSQL 18 container — never the shared stack.** `makam-nonprod-postgres-1` is the **dev/staging** database; pointing `RefreshDatabase` at it would truncate real dev data. The sanctioned pattern is the one L4 used (`docs/superpowers/plans/2026-08-09-platform-financial-ledger.md:263` — "disposable container `l4-task10-pg-55571`, removed by exact name; shared `makam-nonprod` stack never touched"):

```bash
# Spin up a throwaway instance owned unambiguously by this lane.
PGNAME="l11-taskN-pg-$RANDOM"
docker run -d --name "$PGNAME" -e POSTGRES_USER=makam_test \
  -e POSTGRES_PASSWORD=makam_test -e POSTGRES_DB=makam_test \
  -P postgres:18
PGPORT=$(docker port "$PGNAME" 5432/tcp | head -1 | sed 's/.*://')

DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT="$PGPORT" \
DB_DATABASE=makam_test DB_USERNAME=makam_test DB_PASSWORD=makam_test \
php artisan test --filter=Marketplace

# Always remove by exact name.
docker rm -f "$PGNAME"
```

Rules: name the container with the `l11-` prefix plus a random suffix so ownership is unambiguous; remove it by that exact name; **never** `docker rm` by pattern, and never touch `makam-nonprod-*`.

CI also runs `postgres:18` and remains the final gate. Never report `PASS` for a skipped or unexecuted check — use `BLOCKED` or `NOT TESTED` (`AGENTS.md` §Infrastructure-agent execution).

---

## Global Constraints

- **Design tokens only.** `resources/css/tokens.css` is the single source of truth. Never hardcode a hex, px, ms, or shadow; never use a Tailwind arbitrary value such as `text-[#12545E]` or `p-[13px]`. `ci/verify-docs.sh` scans `resources/` and `app/` and fails the build on violations.
- **Money is integer minor units.** Use `App\Platform\FinancialLedger\Money`. Never a float, never a bare `int` in a public signature.
- **Never duplicate canonical catalogue data.** Product codes, category keys, and vendor statuses come from the existing closed-list classes. Never restate the nine product codes or eight statuses in a component, view, validation rule, or test fixture — iterate the class.
- **Status rendering resolves through `StatusIntent`.** Never `match` on a status in Blade or a Filament closure.
- **`DIBAYAR` is not `SELESAI`.** Payment state and fulfilment state render as two distinct indicators, never merged into one "done" badge (requirement 12).
- **One vendor per checkout** (requirement 4), made explicit to the user, and a cart conflict must never silently drop items.
- **Vendor scope is enforced at query level** (requirement 9), and a cross-vendor denial must not reveal whether the other vendor's record exists.
- **Evidence files are private.** No thumbnail or preview of an unscanned upload.
- **Restricted data never reaches logs, Pulse, Horizon tags, or error trackers.**
- **PostgreSQL-only constraints are skipped locally and asserted in CI.** Never report `PASS` for an unexecuted check; use `BLOCKED` or `NOT TESTED`.
- **Never run `npm run build` or a full `composer install` on this host.** Verify by pushing and reading CI.
- Every transactional screen carries the ten required states (design-system §6), including support (§6.10).

---

## File Structure

**Domain — `app/Domain/Marketplace/`** (extends the existing module; do not touch `ProductCode`, `MarketplaceProductCategory`, `VendorProcessingStatus`, `MarketplaceCatalogQuery`, `Product`, `ProductVariant`)

| File | Responsibility |
| --- | --- |
| `EvidenceRequirement.php` | Closed list for `vendor_listings.evidence_requirement` |
| `AvailabilityMode.php` | Closed list for `vendor_listings.availability_mode` |
| `PaymentState.php` | Closed list for `marketplace_orders.payment_state` — deliberately separate from `VendorProcessingStatus` (requirement 12) |
| `Models/Vendor.php`, `Models/VendorUser.php` | Vendor identity and membership |
| `Models/VendorListing.php`, `Models/ServiceArea.php`, `Models/VendorAvailability.php` | Per-vendor offer, coverage, calendar |
| `Models/Cart.php`, `Models/CartItem.php` | Cart aggregate |
| `Models/MarketplaceOrder.php`, `Models/MarketplaceOrderItem.php` | Order root |
| `Models/VendorOrder.php`, `Models/VendorOrderItem.php`, `Models/FulfilmentEvidence.php` | Vendor allocation and evidence |
| `Scopes/VendorScoped.php` | Query-level vendor scope trait (requirement 9) |
| `Actions/AddToCart.php`, `Actions/UpdateCartItem.php`, `Actions/RemoveCartItem.php`, `Actions/ReplaceCartWithVendor.php` | Cart mutations and single-vendor conflict handling |
| `Actions/PlaceMarketplaceOrder.php` | Checkout: order + allocation + payable (requirements 3, 4, 10) |
| `CartConflict.php` | Value object describing a single-vendor conflict for the UI |
| `MarketplaceOrderQuery.php` | Customer-facing order reads (requirement 13) |

**Livewire — `app/Livewire/Public/Marketplace/`**: `Cart.php` (PUB-022), `Checkout.php` (PUB-023), `OrderTracking.php` (PUB-024).
**Views — `resources/views/livewire/public/marketplace/`**: `cart.blade.php`, `checkout.blade.php`, `order-tracking.blade.php`.
**Migrations — `database/migrations/`**: one per table group, dated `2026_08_12_*`.
**Tests — `tests/Feature/Domain/Marketplace/` and `tests/Feature/Livewire/Public/Marketplace/`**.

---

## Task 1: Vendor identity, membership, and the scoping boundary

**Files:**
- Create: `database/migrations/2026_08_12_100000_create_vendors_table.php`
- Create: `database/migrations/2026_08_12_100010_create_vendor_users_table.php`
- Create: `app/Domain/Marketplace/Models/Vendor.php`, `app/Domain/Marketplace/Models/VendorUser.php`
- Test: `tests/Feature/Domain/Marketplace/VendorIdentityTest.php`

**Interfaces:**
- Consumes: `App\Platform\IdentityAccess\Scopes\ScopeEntityType::VENDOR`, `ScopeAssignmentReader`, `ActorContext::hasScope()`.
- Produces: `Vendor` model with `uuid` PK whose **string form is the `scope_assignments.entity_id` value**; `Vendor::scopeActive()`; `VendorUser` with `vendor_id` + `actor_identifier`.

**Why `vendors.id` must be a UUID string:** `vendor_payables.vendor_id` is a plain `string` already used as `scope_assignments.entity_id` for `entity_type='vendor'` in shipped code (`FinanceOrRestrictedAdminPayoutAuthorizer.php:51-57`). Matching that means **no backfill and no change to `vendor_payables`**.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Marketplace;

use App\Domain\Marketplace\Models\Vendor;
use App\Domain\Marketplace\Models\VendorUser;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class VendorIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_vendor_id_is_a_string_uuid_usable_as_a_scope_entity_id(): void
    {
        $vendor = Vendor::create(['name' => 'Toko Bunga Melati', 'is_active' => true]);

        $this->assertIsString($vendor->id);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $vendor->id
        );
        $this->assertSame($vendor->id, (string) $vendor->id);
    }

    public function test_vendor_users_records_membership_without_granting_any_access(): void
    {
        $vendor = Vendor::create(['name' => 'Batu Nisan Jaya', 'is_active' => true]);

        $membership = VendorUser::create([
            'vendor_id' => $vendor->id,
            'actor_identifier' => 'actor-77',
        ]);

        $this->assertSame($vendor->id, $membership->vendor_id);

        // The membership row exists, but NO scope assignment was written.
        // vendor_users must never be an authorization source.
        $this->assertDatabaseMissing('scope_assignments', [
            'actor_identifier' => 'actor-77',
            'entity_type' => ScopeEntityType::VENDOR,
            'entity_id' => $vendor->id,
        ]);
    }

    public function test_inactive_vendors_are_excluded_by_the_active_scope(): void
    {
        Vendor::create(['name' => 'Aktif', 'is_active' => true]);
        Vendor::create(['name' => 'Nonaktif', 'is_active' => false]);

        $this->assertSame(['Aktif'], Vendor::active()->pluck('name')->all());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=VendorIdentityTest`
Expected: FAIL — `Class "App\Domain\Marketplace\Models\Vendor" not found`.

- [ ] **Step 3: Write the migrations**

`2026_08_12_100000_create_vendors_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `vendors` — the marketplace vendor identity this repository has referred to
 * by opaque string id since the financial ledger landed.
 *
 * `id` is a UUID because `vendor_payables.vendor_id` is a plain string that
 * shipped code already uses as `scope_assignments.entity_id` for
 * `entity_type = 'vendor'`. Matching that value space means this table needs
 * no backfill and `vendor_payables` needs no schema change.
 *
 * No bank or payout columns: payouts (requirement 11) are out of this lane's
 * scope, and an unused column holding bank details is a liability, not a
 * placeholder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};
```

`2026_08_12_100010_create_vendor_users_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `vendor_users` — MEMBERSHIP METADATA ONLY. Never an authorization source.
 *
 * `scope_assignments` with `entity_type = 'vendor'` is the single authority on
 * whether an actor may act for a vendor, and shipped code already queries it
 * that way. If this table also answered that question the two could disagree,
 * which is exactly the rival-scoping-mechanism defect the identity seam exists
 * to prevent. No policy or query scope may branch on this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_users', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('vendor_id');
            $table->string('actor_identifier');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->foreign('vendor_id')->references('id')->on('vendors')->restrictOnDelete();
            $table->unique(['vendor_id', 'actor_identifier'], 'vendor_users_membership_unique');
            $table->index('actor_identifier');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_users');
    }
};
```

- [ ] **Step 4: Write the models**

`app/Domain/Marketplace/Models/Vendor.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Vendor extends Model
{
    use HasUuids;

    protected $table = 'vendors';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = ['name', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<VendorUser, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(VendorUser::class, 'vendor_id');
    }

    /** @return HasMany<VendorListing, $this> */
    public function listings(): HasMany
    {
        return $this->hasMany(VendorListing::class, 'vendor_id');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
```

`app/Domain/Marketplace/Models/VendorUser.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Membership metadata only. See the migration's doc block: authorization is
 * decided by `scope_assignments`, never by this table.
 */
final class VendorUser extends Model
{
    protected $table = 'vendor_users';

    /** @var list<string> */
    protected $fillable = ['vendor_id', 'actor_identifier', 'revoked_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['revoked_at' => 'datetime'];
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --filter=VendorIdentityTest`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_08_12_1000*.php app/Domain/Marketplace/Models/Vendor.php \
  app/Domain/Marketplace/Models/VendorUser.php tests/Feature/Domain/Marketplace/VendorIdentityTest.php
git commit -m "feat(marketplace): add vendors and vendor_users, membership separate from scope"
```

---

## Task 2: Vendor listings, service areas, availability — closing requirement 2's five-column gap

**Files:**
- Create: `database/migrations/2026_08_12_100020_create_vendor_listings_table.php`, `2026_08_12_100030_create_service_areas_table.php`, `2026_08_12_100040_create_vendor_availability_table.php`
- Create: `app/Domain/Marketplace/EvidenceRequirement.php`, `app/Domain/Marketplace/AvailabilityMode.php`
- Create: `app/Domain/Marketplace/Models/VendorListing.php`, `Models/ServiceArea.php`, `Models/VendorAvailability.php`
- Test: `tests/Feature/Domain/Marketplace/VendorListingTest.php`

**Interfaces:**
- Consumes: `Vendor` (Task 1), the existing `Product` and `ProductCode`.
- Produces: `VendorListing` with `priceMoney(): Money`, `scopeActive()`, `scopeForProduct(int $productId)`; `EvidenceRequirement::KNOWN` / `AvailabilityMode::KNOWN`.

**What this closes.** `docs/product/screen-inventory.md` PUB-021 records that `schedule` and `area unavailable` are "genuinely unimplementable, not merely unbuilt" because five of requirement 2's fields have nowhere to live. This task gives all five a home: **stock/availability** (`availability_mode` + `stock_quantity`), **service area** (`service_areas`), **schedule** (`vendor_availability`), **delivery fee** (`service_areas.delivery_fee_minor`), **evidence requirement** (`evidence_requirement`). Production lead time and cancellation policy come along because the same catalogue list names them.

**Region codes are vendor-supplied, not a platform taxonomy.** `service_areas.area_code` is a free string. No closed list of Indonesian regions is invented here — that would be a rival source of truth, the same defect the category-code question is blocked on.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Marketplace;

use App\Domain\Marketplace\AvailabilityMode;
use App\Domain\Marketplace\EvidenceRequirement;
use App\Domain\Marketplace\Models\ServiceArea;
use App\Domain\Marketplace\Models\Vendor;
use App\Domain\Marketplace\Models\VendorAvailability;
use App\Domain\Marketplace\Models\VendorListing;
use App\Domain\Marketplace\Models\Product;
use App\Domain\Marketplace\ProductCode;
use App\Platform\FinancialLedger\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

final class VendorListingTest extends TestCase
{
    use RefreshDatabase;

    private function vendor(): Vendor
    {
        return Vendor::create(['name' => 'Toko Uji', 'is_active' => true]);
    }

    private function product(): Product
    {
        return Product::findByCode(ProductCode::FLOWER_BOARD);
    }

    public function test_a_listing_carries_every_field_requirement_2_names(): void
    {
        $listing = VendorListing::create([
            'vendor_id' => $this->vendor()->id,
            'product_id' => $this->product()->id,
            'price_minor' => 250_000,
            'price_version' => 1,
            'availability_mode' => AvailabilityMode::STOCKED,
            'stock_quantity' => 12,
            'production_lead_time_days' => 2,
            'cancellation_policy' => 'Batal maksimal 24 jam sebelum pengiriman.',
            'evidence_requirement' => EvidenceRequirement::PHOTO,
            'is_active' => true,
        ]);

        $this->assertEquals(new Money(250_000), $listing->priceMoney());
        $this->assertSame(12, $listing->stock_quantity);
        $this->assertSame(EvidenceRequirement::PHOTO, $listing->evidence_requirement);
    }

    public function test_price_is_returned_as_money_never_a_float(): void
    {
        $listing = VendorListing::create([
            'vendor_id' => $this->vendor()->id,
            'product_id' => $this->product()->id,
            'price_minor' => 199_999,
            'price_version' => 1,
            'availability_mode' => AvailabilityMode::MADE_TO_ORDER,
            'evidence_requirement' => EvidenceRequirement::NONE,
            'is_active' => true,
        ]);

        $this->assertInstanceOf(Money::class, $listing->priceMoney());
        $this->assertIsInt($listing->priceMoney()->toMinorInt());
        $this->assertSame(199_999, $listing->priceMoney()->toMinorInt());
    }

    public function test_an_unknown_evidence_requirement_is_rejected_on_save(): void
    {
        $this->expectException(InvalidArgumentException::class);

        VendorListing::create([
            'vendor_id' => $this->vendor()->id,
            'product_id' => $this->product()->id,
            'price_minor' => 1000,
            'price_version' => 1,
            'availability_mode' => AvailabilityMode::STOCKED,
            'evidence_requirement' => 'VIDEO_4K',
            'is_active' => true,
        ]);
    }

    public function test_a_vendor_may_list_a_product_only_once(): void
    {
        $vendor = $this->vendor();
        $product = $this->product();
        $row = [
            'vendor_id' => $vendor->id, 'product_id' => $product->id,
            'price_minor' => 1000, 'price_version' => 1,
            'availability_mode' => AvailabilityMode::STOCKED,
            'evidence_requirement' => EvidenceRequirement::NONE, 'is_active' => true,
        ];

        VendorListing::create($row);

        $this->expectException(\Illuminate\Database\QueryException::class);
        VendorListing::create($row);
    }

    public function test_a_service_area_carries_its_own_delivery_fee(): void
    {
        $area = ServiceArea::create([
            'vendor_id' => $this->vendor()->id,
            'area_code' => 'JKT-SELATAN',
            'area_label' => 'Jakarta Selatan',
            'delivery_fee_minor' => 25_000,
            'is_active' => true,
        ]);

        $this->assertEquals(new Money(25_000), $area->deliveryFeeMoney());
    }

    public function test_availability_marks_a_blocked_date(): void
    {
        $vendor = $this->vendor();

        VendorAvailability::create([
            'vendor_id' => $vendor->id,
            'available_date' => '2026-09-01',
            'capacity' => 0,
            'is_blocked' => true,
        ]);

        $this->assertTrue(
            VendorAvailability::where('vendor_id', $vendor->id)
                ->whereDate('available_date', '2026-09-01')->first()->is_blocked
        );
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=VendorListingTest`
Expected: FAIL — `Class "App\Domain\Marketplace\AvailabilityMode" not found`.

- [ ] **Step 3: Write the two closed lists**

`app/Domain/Marketplace/EvidenceRequirement.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Marketplace;

use InvalidArgumentException;

/**
 * What a vendor must upload to close out a fulfilment — `marketplace-catalog.md`
 * §"Required product data" names "evidence requirement" without enumerating
 * values, so these three are the minimum needed to express "nothing",
 * "a photo", and "a signed document". Plain-string-class convention, matching
 * `ProductCode` and `VendorProcessingStatus`.
 */
final class EvidenceRequirement
{
    public const string NONE = 'NONE';

    public const string PHOTO = 'PHOTO';

    public const string DOCUMENT = 'DOCUMENT';

    /** @var list<string> */
    public const array KNOWN = [self::NONE, self::PHOTO, self::DOCUMENT];

    public static function isKnown(string $value): bool
    {
        return in_array($value, self::KNOWN, true);
    }

    public static function assertKnown(string $value): void
    {
        if (! self::isKnown($value)) {
            throw new InvalidArgumentException(
                "Unknown evidence requirement [{$value}]. Known: ".implode(', ', self::KNOWN).'.'
            );
        }
    }
}
```

`app/Domain/Marketplace/AvailabilityMode.php`: identical shape, constants `STOCKED = 'STOCKED'`, `MADE_TO_ORDER = 'MADE_TO_ORDER'`, `SCHEDULED = 'SCHEDULED'`, with `KNOWN`, `isKnown()`, and `assertKnown()` written out the same way. Doc block: "How a listing's availability is determined — a counted stock level, built on demand after ordering, or booked against `vendor_availability`. `stock_quantity` is meaningful only for `STOCKED`."

- [ ] **Step 4: Write the migrations**

`2026_08_12_100020_create_vendor_listings_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `vendor_listings` — one vendor's offer of one catalogue product.
 *
 * `products` stays platform-wide catalogue master data (nine rows, one per
 * `ProductCode`). Everything that varies per vendor lives here, exactly as
 * `2026_07_26_180000_create_products_table.php` anticipated: those attributes
 * "belong to a future vendor-listing table that references BOTH this table and
 * `vendors`". `marketplace-catalog.md` §"Required product data" leads with
 * "vendor", which is why these are per-offer and not per-product.
 *
 * Money is integer minor units (`price_minor`), never a float or a decimal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_listings', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('vendor_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('price_minor');
            $table->unsignedInteger('price_version')->default(1);
            $table->string('availability_mode', 32);
            $table->unsignedInteger('stock_quantity')->nullable();
            $table->unsignedSmallInteger('production_lead_time_days')->nullable();
            $table->text('cancellation_policy')->nullable();
            $table->string('evidence_requirement', 32);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('vendor_id')->references('id')->on('vendors')->restrictOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
            $table->unique(['vendor_id', 'product_id'], 'vendor_listings_offer_unique');
            $table->index(['product_id', 'is_active']);
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE vendor_listings ADD CONSTRAINT vendor_listings_price_positive CHECK (price_minor > 0)");
        DB::statement("ALTER TABLE vendor_listings ADD CONSTRAINT vendor_listings_availability_mode_known CHECK (availability_mode IN ('STOCKED','MADE_TO_ORDER','SCHEDULED'))");
        DB::statement("ALTER TABLE vendor_listings ADD CONSTRAINT vendor_listings_evidence_known CHECK (evidence_requirement IN ('NONE','PHOTO','DOCUMENT'))");
        // stock_quantity is meaningful only for a STOCKED listing.
        DB::statement("ALTER TABLE vendor_listings ADD CONSTRAINT vendor_listings_stock_only_when_stocked CHECK ((availability_mode = 'STOCKED') OR (stock_quantity IS NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_listings');
    }
};
```

`2026_08_12_100030_create_service_areas_table.php` — columns `bigIncrements('id')`, `uuid('vendor_id')` FK to `vendors` `restrictOnDelete`, `string('area_code', 64)`, `string('area_label')`, `unsignedBigInteger('delivery_fee_minor')->default(0)`, `boolean('is_active')->default(true)`, `timestamps()`; `unique(['vendor_id','area_code'], 'service_areas_vendor_area_unique')`; index on `area_code`. Doc block records that `area_code` is a vendor-supplied free string and that **no platform region taxonomy is invented here**.

`2026_08_12_100040_create_vendor_availability_table.php` — columns `bigIncrements('id')`, `uuid('vendor_id')` FK `restrictOnDelete`, `date('available_date')`, `unsignedSmallInteger('capacity')->default(0)`, `boolean('is_blocked')->default(false)`, `timestamps()`; `unique(['vendor_id','available_date'], 'vendor_availability_day_unique')`. Under a `pgsql` guard add `CHECK ((is_blocked = false) OR (capacity = 0))` so a blocked day can never advertise capacity.

- [ ] **Step 5: Write the three models**

`VendorListing` — `$fillable` covering every column above; `casts()` mapping `price_minor`/`price_version`/`stock_quantity`/`production_lead_time_days` to `integer` and `is_active` to `boolean`; `booted()` calling `AvailabilityMode::assertKnown()` and `EvidenceRequirement::assertKnown()` on `saving`; `vendor()` and `product()` `BelongsTo`; `scopeActive()`; `scopeForProduct(Builder $q, int $productId)`; and:

```php
public function priceMoney(): Money
{
    return new Money((int) $this->price_minor);
}
```

`ServiceArea` — same shape, with `deliveryFeeMoney(): Money { return new Money((int) $this->delivery_fee_minor); }`.
`VendorAvailability` — `casts()` mapping `available_date` to `date`, `capacity` to `integer`, `is_blocked` to `boolean`.

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test --filter=VendorListingTest`
Expected: PASS (6 tests). The uniqueness test relies on the unique index, which SQLite does honour.

- [ ] **Step 7: Verify the PostgreSQL-only CHECKs on a disposable container**

Use the disposable-container recipe from the Current state section, with `PGNAME="l11-task2-pg-$RANDOM"`. Confirm `vendor_listings_price_positive` and `vendor_listings_stock_only_when_stocked` actually reject bad rows. Remove the container by exact name afterwards. If a CHECK is not exercised, report it `NOT TESTED` — never `PASS`.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_08_12_1000[234]0_*.php app/Domain/Marketplace/ tests/Feature/Domain/Marketplace/VendorListingTest.php
git commit -m "feat(marketplace): add vendor listings, service areas, availability"
```

---

## Task 3: Query-level vendor scope (requirement 9)

**Files:**
- Create: `app/Domain/Marketplace/Scopes/VendorScoped.php`
- Modify: `app/Domain/Marketplace/Models/VendorListing.php`, `Models/ServiceArea.php`, `Models/VendorAvailability.php` (apply the trait)
- Test: `tests/Feature/Domain/Marketplace/VendorScopeIsolationTest.php`

**Interfaces:**
- Consumes: `ScopeAssignmentReader::grantedEntityIds()`, `ActorContext`, `ScopeEntityType::VENDOR`.
- Produces: `VendorScoped` trait exposing `scopeForActorVendorScope(Builder $q, ActorContext $actor)`.

**This is a hard security boundary.** Requirement 9: "THE SYSTEM SHALL enforce query-level authorization, and THE SYSTEM SHALL NOT allow cross-vendor access." It is enforced here even though the vendor panel that consumes it is out of scope, because it protects data this lane creates.

**Fail closed.** An actor with no vendor grants sees **zero** rows, never all rows. This mirrors `ScopeAssignmentGlobalScope`, which closes a query on an empty grant list rather than leaving it unconstrained.

**Denial must not leak existence** (design-system §6.4): a cross-vendor read returns the same empty result as a vendor that never existed. Never a "belongs to another vendor" message.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Marketplace;

use App\Domain\Marketplace\AvailabilityMode;
use App\Domain\Marketplace\EvidenceRequirement;
use App\Domain\Marketplace\Models\Product;
use App\Domain\Marketplace\Models\Vendor;
use App\Domain\Marketplace\Models\VendorListing;
use App\Domain\Marketplace\Models\VendorUser;
use App\Domain\Marketplace\ProductCode;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class VendorScopeIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function listingFor(Vendor $vendor, string $code): VendorListing
    {
        return VendorListing::create([
            'vendor_id' => $vendor->id,
            'product_id' => Product::findByCode($code)->id,
            'price_minor' => 100_000,
            'price_version' => 1,
            'availability_mode' => AvailabilityMode::STOCKED,
            'stock_quantity' => 5,
            'evidence_requirement' => EvidenceRequirement::NONE,
            'is_active' => true,
        ]);
    }

    public function test_an_actor_sees_only_its_own_vendors_listings(): void
    {
        $mine = Vendor::create(['name' => 'Punya Saya', 'is_active' => true]);
        $theirs = Vendor::create(['name' => 'Punya Orang Lain', 'is_active' => true]);

        $this->listingFor($mine, ProductCode::FLOWER_BOARD);
        $this->listingFor($theirs, ProductCode::GRAVESTONE_GRANITE);

        $actor = new ActorContext('actor-1', ['vendor'], [ScopeEntityType::VENDOR.':'.$mine->id]);

        $visible = VendorListing::forActorVendorScope($actor)->get();

        $this->assertCount(1, $visible);
        $this->assertSame($mine->id, $visible->first()->vendor_id);
    }

    public function test_an_actor_with_no_vendor_grant_sees_nothing_not_everything(): void
    {
        $vendor = Vendor::create(['name' => 'Ada', 'is_active' => true]);
        $this->listingFor($vendor, ProductCode::FLOWER_BOARD);

        $actor = new ActorContext('actor-2', ['vendor'], []);

        $this->assertCount(0, VendorListing::forActorVendorScope($actor)->get());
    }

    public function test_a_guest_sees_nothing(): void
    {
        $vendor = Vendor::create(['name' => 'Ada', 'is_active' => true]);
        $this->listingFor($vendor, ProductCode::FLOWER_BOARD);

        $this->assertCount(0, VendorListing::forActorVendorScope(ActorContext::guest())->get());
    }

    public function test_a_cross_vendor_read_is_indistinguishable_from_a_vendor_that_never_existed(): void
    {
        $mine = Vendor::create(['name' => 'Punya Saya', 'is_active' => true]);
        $theirs = Vendor::create(['name' => 'Punya Orang Lain', 'is_active' => true]);
        $theirListing = $this->listingFor($theirs, ProductCode::GRAVESTONE_MARBLE);

        $actor = new ActorContext('actor-3', ['vendor'], [ScopeEntityType::VENDOR.':'.$mine->id]);

        $realButForbidden = VendorListing::forActorVendorScope($actor)
            ->where('id', $theirListing->id)->first();
        $neverExisted = VendorListing::forActorVendorScope($actor)
            ->where('id', 999_999)->first();

        // Identical outcome: no row, no exception, nothing that reveals which id is real.
        $this->assertNull($realButForbidden);
        $this->assertNull($neverExisted);
    }

    public function test_a_vendor_users_row_alone_grants_no_access(): void
    {
        $vendor = Vendor::create(['name' => 'Ada', 'is_active' => true]);
        $this->listingFor($vendor, ProductCode::FLOWER_BOARD);

        // Membership exists...
        VendorUser::create(['vendor_id' => $vendor->id, 'actor_identifier' => 'actor-4']);

        // ...but no scope assignment does, so the actor still sees nothing.
        $actor = new ActorContext('actor-4', ['vendor'], []);

        $this->assertCount(0, VendorListing::forActorVendorScope($actor)->get());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=VendorScopeIsolationTest`
Expected: FAIL — `Call to undefined method ...::forActorVendorScope()`.

- [ ] **Step 3: Write the trait**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Scopes;

use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Illuminate\Database\Eloquent\Builder;

/**
 * Query-level vendor isolation (requirement 9: "SHALL NOT allow cross-vendor
 * access"). Applied to every table carrying a `vendor_id`.
 *
 * FAILS CLOSED. An actor holding no `vendor:` scope matches zero rows, never
 * every row — the same posture `ScopeAssignmentGlobalScope` takes. An
 * unscoped query is a data leak, so the absence of a grant must narrow the
 * query, not widen it.
 *
 * Grants are read from `ActorContext::$scopes`, which the identity adapter
 * populates from `scope_assignments`. `vendor_users` is deliberately NOT
 * consulted: it is membership metadata, and a second source that could
 * disagree with `scope_assignments` would be a rival authorization mechanism.
 */
trait VendorScoped
{
    public function scopeForActorVendorScope(Builder $query, ActorContext $actor): void
    {
        $prefix = ScopeEntityType::VENDOR.':';

        $vendorIds = [];
        foreach ($actor->scopes as $scope) {
            if (str_starts_with($scope, $prefix)) {
                $vendorIds[] = substr($scope, strlen($prefix));
            }
        }

        if ($vendorIds === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereIn($this->getTable().'.vendor_id', $vendorIds);
    }
}
```

- [ ] **Step 4: Apply the trait**

Add `use VendorScoped;` (and the `use App\Domain\Marketplace\Scopes\VendorScoped;` import) to `VendorListing`, `ServiceArea`, and `VendorAvailability`.

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --filter=VendorScopeIsolationTest`
Expected: PASS (5 tests).

- [ ] **Step 6: Mutation-check the fail-closed branch**

A passing test proves nothing until you have seen it fail for the right reason. Temporarily change `$query->whereRaw('1 = 0');` to `return;` and re-run: `test_an_actor_with_no_vendor_grant_sees_nothing_not_everything` and `test_a_guest_sees_nothing` **must** fail. Restore the line. If they still pass, the test is vacuous and must be fixed before proceeding.

- [ ] **Step 7: Commit**

```bash
git add app/Domain/Marketplace/Scopes/VendorScoped.php app/Domain/Marketplace/Models/ tests/Feature/Domain/Marketplace/VendorScopeIsolationTest.php
git commit -m "feat(marketplace): enforce query-level vendor scope, fail closed"
```

---

## Task 4: Cart, and the single-vendor conflict that must never lose items

**Files:**
- Create: `database/migrations/2026_08_12_100050_create_carts_table.php`, `2026_08_12_100060_create_cart_items_table.php`
- Create: `app/Domain/Marketplace/Models/Cart.php`, `Models/CartItem.php`, `app/Domain/Marketplace/CartConflict.php`
- Create: `app/Domain/Marketplace/Actions/AddToCart.php`, `Actions/UpdateCartItem.php`, `Actions/RemoveCartItem.php`, `Actions/ReplaceCartWithVendor.php`
- Test: `tests/Feature/Domain/Marketplace/CartTest.php`

**Interfaces:**
- Consumes: `VendorListing::priceMoney()`, `Vendor`.
- Produces: `AddToCart::handle(Cart $cart, VendorListing $listing, int $quantity, ?int $variantId = null): CartConflict|CartItem`; `CartConflict` with `->existingVendor`, `->incomingVendor`, `->existingItemCount`; `Cart::vendorId()`, `Cart::subtotal(): Money`, `Cart::hasStalePricing(): bool`; `ReplaceCartWithVendor::handle(Cart $cart, VendorListing $listing, int $quantity, ?int $variantId = null): CartItem`.

**Requirement 4 is the whole point of this task.** One vendor per checkout, the constraint made explicit, and — per `marketplace-catalog.md` §"MVP operating constraint" and design-system §6.2 — the cart **must not silently lose items**. `AddToCart` therefore *returns* a `CartConflict` describing the clash instead of throwing, dropping, or auto-replacing. The caller (Task 7's UI) decides; only an explicit `ReplaceCartWithVendor` call clears anything.

`cart_items.unit_price_minor` and `price_version` are captured at add time so PUB-022 can detect a changed price and demand explicit reconfirmation.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Marketplace;

use App\Domain\Marketplace\Actions\AddToCart;
use App\Domain\Marketplace\Actions\ReplaceCartWithVendor;
use App\Domain\Marketplace\AvailabilityMode;
use App\Domain\Marketplace\CartConflict;
use App\Domain\Marketplace\EvidenceRequirement;
use App\Domain\Marketplace\Models\Cart;
use App\Domain\Marketplace\Models\CartItem;
use App\Domain\Marketplace\Models\Product;
use App\Domain\Marketplace\Models\Vendor;
use App\Domain\Marketplace\Models\VendorListing;
use App\Domain\Marketplace\ProductCode;
use App\Platform\FinancialLedger\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CartTest extends TestCase
{
    use RefreshDatabase;

    private function listing(string $vendorName, string $code, int $priceMinor): VendorListing
    {
        $vendor = Vendor::create(['name' => $vendorName, 'is_active' => true]);

        return VendorListing::create([
            'vendor_id' => $vendor->id,
            'product_id' => Product::findByCode($code)->id,
            'price_minor' => $priceMinor,
            'price_version' => 1,
            'availability_mode' => AvailabilityMode::STOCKED,
            'stock_quantity' => 10,
            'evidence_requirement' => EvidenceRequirement::NONE,
            'is_active' => true,
        ]);
    }

    public function test_adding_a_first_item_locks_the_cart_to_that_vendor(): void
    {
        $cart = Cart::create(['customer_ref' => 'cust-1']);
        $listing = $this->listing('Vendor A', ProductCode::FLOWER_BOARD, 150_000);

        $item = (new AddToCart)->handle($cart, $listing, 2);

        $this->assertInstanceOf(CartItem::class, $item);
        $this->assertSame($listing->vendor_id, $cart->fresh()->vendor_id);
        $this->assertEquals(new Money(300_000), $cart->fresh()->subtotal());
    }

    public function test_a_second_vendor_returns_a_conflict_and_changes_nothing(): void
    {
        $cart = Cart::create(['customer_ref' => 'cust-2']);
        $a = $this->listing('Vendor A', ProductCode::FLOWER_BOARD, 150_000);
        $b = $this->listing('Vendor B', ProductCode::GRAVESTONE_GRANITE, 900_000);

        (new AddToCart)->handle($cart, $a, 1);
        $result = (new AddToCart)->handle($cart, $b, 1);

        $this->assertInstanceOf(CartConflict::class, $result);
        $this->assertSame($a->vendor_id, $result->existingVendorId);
        $this->assertSame($b->vendor_id, $result->incomingVendorId);
        $this->assertSame(1, $result->existingItemCount);

        // AC4: the existing item must NOT be lost, and the new one must NOT be added.
        $this->assertSame(1, $cart->fresh()->items()->count());
        $this->assertSame($a->id, $cart->fresh()->items()->first()->vendor_listing_id);
        $this->assertSame($a->vendor_id, $cart->fresh()->vendor_id);
    }

    public function test_replacing_the_cart_is_explicit_and_only_then_clears_items(): void
    {
        $cart = Cart::create(['customer_ref' => 'cust-3']);
        $a = $this->listing('Vendor A', ProductCode::FLOWER_BOARD, 150_000);
        $b = $this->listing('Vendor B', ProductCode::GRAVESTONE_GRANITE, 900_000);

        (new AddToCart)->handle($cart, $a, 1);
        (new ReplaceCartWithVendor)->handle($cart, $b, 1);

        $this->assertSame(1, $cart->fresh()->items()->count());
        $this->assertSame($b->vendor_id, $cart->fresh()->vendor_id);
        $this->assertEquals(new Money(900_000), $cart->fresh()->subtotal());
    }

    public function test_adding_the_same_listing_twice_increments_rather_than_duplicating(): void
    {
        $cart = Cart::create(['customer_ref' => 'cust-4']);
        $listing = $this->listing('Vendor A', ProductCode::FLOWER_BOARD, 100_000);

        (new AddToCart)->handle($cart, $listing, 1);
        (new AddToCart)->handle($cart, $listing, 3);

        $this->assertSame(1, $cart->fresh()->items()->count());
        $this->assertSame(4, $cart->fresh()->items()->first()->quantity);
    }

    public function test_a_price_change_after_adding_is_detected(): void
    {
        $cart = Cart::create(['customer_ref' => 'cust-5']);
        $listing = $this->listing('Vendor A', ProductCode::FLOWER_BOARD, 100_000);

        (new AddToCart)->handle($cart, $listing, 1);
        $this->assertFalse($cart->fresh()->hasStalePricing());

        $listing->update(['price_minor' => 120_000, 'price_version' => 2]);

        $this->assertTrue($cart->fresh()->hasStalePricing());
    }

    public function test_an_emptied_cart_releases_its_vendor_lock(): void
    {
        $cart = Cart::create(['customer_ref' => 'cust-6']);
        $a = $this->listing('Vendor A', ProductCode::FLOWER_BOARD, 100_000);
        $b = $this->listing('Vendor B', ProductCode::GRAVESTONE_GRANITE, 500_000);

        $item = (new AddToCart)->handle($cart, $a, 1);
        $item->delete();
        $cart->fresh()->releaseVendorLockIfEmpty();

        // With the cart empty, the other vendor is now addable without a conflict.
        $this->assertInstanceOf(CartItem::class, (new AddToCart)->handle($cart->fresh(), $b, 1));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=CartTest`
Expected: FAIL — `Class "App\Domain\Marketplace\Models\Cart" not found`.

- [ ] **Step 3: Write the migrations**

`2026_08_12_100050_create_carts_table.php` — `uuid('id')->primary()`, `string('customer_ref')->nullable()`, `string('session_ref')->nullable()`, `uuid('vendor_id')->nullable()`, `timestamps()`; FK `vendor_id` to `vendors` `nullOnDelete()`; index on `customer_ref` and on `session_ref`.

The nullable `vendor_id` **is** the single-vendor lock: null means empty, non-null means locked to that vendor. Doc block must say so, and must say that requirement 4 forbids widening this to a set without the requirement-14 prerequisites.

`2026_08_12_100060_create_cart_items_table.php`:

```php
Schema::create('cart_items', function (Blueprint $table): void {
    $table->bigIncrements('id');
    $table->uuid('cart_id');
    $table->unsignedBigInteger('vendor_listing_id');
    $table->unsignedBigInteger('product_variant_id')->nullable();
    $table->unsignedInteger('quantity');
    $table->unsignedBigInteger('unit_price_minor');   // frozen at add time
    $table->unsignedInteger('price_version');          // frozen at add time
    $table->timestamps();

    $table->foreign('cart_id')->references('id')->on('carts')->cascadeOnDelete();
    $table->foreign('vendor_listing_id')->references('id')->on('vendor_listings')->restrictOnDelete();
    $table->foreign('product_variant_id')->references('id')->on('product_variants')->restrictOnDelete();
    $table->unique(['cart_id', 'vendor_listing_id', 'product_variant_id'], 'cart_items_line_unique');
});
```

Under a `pgsql` guard: `CHECK (quantity > 0)` and `CHECK (unit_price_minor > 0)`.

- [ ] **Step 4: Write `CartConflict`**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Marketplace;

/**
 * Describes a single-vendor checkout clash (requirement 4) WITHOUT resolving
 * it. `AddToCart` returns this instead of throwing or auto-replacing, because
 * `marketplace-catalog.md` §"MVP operating constraint" requires the UI to
 * offer separate checkout or an explicit split and forbids silently losing
 * items. Resolution is the caller's decision, never this layer's.
 */
final readonly class CartConflict
{
    public function __construct(
        public string $existingVendorId,
        public string $existingVendorName,
        public string $incomingVendorId,
        public string $incomingVendorName,
        public int $existingItemCount,
    ) {}
}
```

- [ ] **Step 5: Write the models**

`Cart` — `HasUuids`, `$keyType = 'string'`, `$incrementing = false`, `$fillable = ['customer_ref', 'session_ref', 'vendor_id']`; `items(): HasMany` to `CartItem`; `vendor(): BelongsTo`; and:

```php
public function subtotal(): Money
{
    $total = 0;
    foreach ($this->items as $item) {
        $total += (int) $item->unit_price_minor * (int) $item->quantity;
    }

    return new Money($total);
}

/** True when any line's frozen price no longer matches its listing (PUB-022 reconfirmation). */
public function hasStalePricing(): bool
{
    foreach ($this->items()->with('listing')->get() as $item) {
        if ((int) $item->price_version !== (int) $item->listing->price_version
            || (int) $item->unit_price_minor !== (int) $item->listing->price_minor) {
            return true;
        }
    }

    return false;
}

public function releaseVendorLockIfEmpty(): void
{
    if ($this->items()->count() === 0 && $this->vendor_id !== null) {
        $this->update(['vendor_id' => null]);
    }
}
```

`CartItem` — `$fillable` for all columns, integer casts, `listing(): BelongsTo` to `VendorListing`, `variant(): BelongsTo` to `ProductVariant`, and `lineTotal(): Money { return new Money((int) $this->unit_price_minor * (int) $this->quantity); }`.

- [ ] **Step 6: Write the Actions**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Actions;

use App\Domain\Marketplace\CartConflict;
use App\Domain\Marketplace\Models\Cart;
use App\Domain\Marketplace\Models\CartItem;
use App\Domain\Marketplace\Models\VendorListing;
use Illuminate\Support\Facades\DB;

/**
 * Adds a listing to a cart, or reports a single-vendor conflict.
 *
 * Returns `CartConflict` — it does NOT throw and does NOT mutate — when the
 * cart is already locked to a different vendor. Requirement 4 requires the
 * constraint be made explicit to the user, and the catalogue forbids silently
 * losing items, so the decision belongs to the caller.
 */
final class AddToCart
{
    public function handle(Cart $cart, VendorListing $listing, int $quantity, ?int $variantId = null): CartConflict|CartItem
    {
        if ($quantity < 1) {
            throw new \InvalidArgumentException('Quantity must be at least 1.');
        }

        if ($cart->vendor_id !== null && $cart->vendor_id !== $listing->vendor_id) {
            return new CartConflict(
                existingVendorId: $cart->vendor_id,
                existingVendorName: $cart->vendor->name,
                incomingVendorId: $listing->vendor_id,
                incomingVendorName: $listing->vendor->name,
                existingItemCount: $cart->items()->count(),
            );
        }

        return DB::transaction(function () use ($cart, $listing, $quantity, $variantId): CartItem {
            if ($cart->vendor_id === null) {
                $cart->update(['vendor_id' => $listing->vendor_id]);
            }

            $existing = $cart->items()
                ->where('vendor_listing_id', $listing->id)
                ->where('product_variant_id', $variantId)
                ->first();

            if ($existing !== null) {
                $existing->update(['quantity' => (int) $existing->quantity + $quantity]);

                return $existing;
            }

            return $cart->items()->create([
                'vendor_listing_id' => $listing->id,
                'product_variant_id' => $variantId,
                'quantity' => $quantity,
                'unit_price_minor' => $listing->price_minor,
                'price_version' => $listing->price_version,
            ]);
        });
    }
}
```

`ReplaceCartWithVendor::handle()` — inside one transaction: `$cart->items()->delete()`, `$cart->update(['vendor_id' => null])`, then delegate to `(new AddToCart)->handle(...)` and return the `CartItem`. Doc block: this is the **explicit** resolution the user chose in the §3.4 conflict modal; nothing else may clear a cart.

`UpdateCartItem::handle(CartItem $item, int $quantity): void` — quantity `< 1` deletes the row and calls `releaseVendorLockIfEmpty()`; otherwise updates. `RemoveCartItem::handle(CartItem $item): void` — deletes, then `releaseVendorLockIfEmpty()`.

- [ ] **Step 7: Run the test to verify it passes**

Run: `php artisan test --filter=CartTest`
Expected: PASS (6 tests).

- [ ] **Step 8: Mutation-check the no-loss guarantee**

Temporarily make `AddToCart` clear the cart before adding when vendors differ (the naive "just replace" behaviour). `test_a_second_vendor_returns_a_conflict_and_changes_nothing` **must** fail. Restore. This is the AC4 guarantee; if the test passes either way it is not testing anything.

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_08_12_10005*.php database/migrations/2026_08_12_10006*.php \
  app/Domain/Marketplace/Models/Cart*.php app/Domain/Marketplace/CartConflict.php \
  app/Domain/Marketplace/Actions/ tests/Feature/Domain/Marketplace/CartTest.php
git commit -m "feat(marketplace): add cart with non-destructive single-vendor conflict"
```

---

## Task 5: Order, vendor allocation, and evidence schema

**Files:**
- Create: `database/migrations/2026_08_12_100070_create_marketplace_orders_table.php`, `2026_08_12_100080_create_marketplace_order_items_table.php`, `2026_08_12_100090_create_vendor_orders_table.php`, `2026_08_12_100100_create_vendor_order_items_table.php`, `2026_08_12_100110_create_fulfilment_evidence_table.php`, `2026_08_12_100120_enforce_single_vendor_per_order.php`
- Create: `app/Domain/Marketplace/PaymentState.php`, `Models/MarketplaceOrder.php`, `Models/MarketplaceOrderItem.php`, `Models/VendorOrder.php`, `Models/VendorOrderItem.php`, `Models/FulfilmentEvidence.php`
- Test: `tests/Feature/Domain/Marketplace/MarketplaceOrderSchemaTest.php`

**Interfaces:**
- Consumes: `VendorProcessingStatus`, `Vendor`, `VendorListing`, `DocumentKind::VendorEvidence`.
- Produces: `MarketplaceOrder` with `total(): Money`, `vendorOrders(): HasMany`, `scopeForCustomer()`; `VendorOrder` with `status` from `VendorProcessingStatus` and the `VendorScoped` trait; `PaymentState::KNOWN`.

**`PaymentState` is deliberately a separate closed list from `VendorProcessingStatus`** — requirement 12: "SHALL NOT treat a paid vendor order as fulfillment complete." `VendorProcessingStatus`'s own doc block already refuses to carry `DIBAYAR` for this reason. Values: `BELUM_DIBAYAR`, `MENUNGGU_VERIFIKASI`, `DIBAYAR`, `GAGAL`, `DIKEMBALIKAN`.

**The single-vendor constraint is enforced in the database**, not just in the Action, because requirement 4 is an MVP invariant and requirement 14 forbids relaxing it until five other capabilities exist. A deferred constraint trigger asserts each `marketplace_order` has **exactly one** `vendor_orders` row at COMMIT — deferred so the Action can insert order and allocation in either sequence within one transaction.

**The allocation is written as a loop over one vendor**, per `design.md`: "The data model preserves `vendor_orders` and allocation so multi-vendor can be added later." The loop body must not assume its own single-ness; only the DB constraint and the cart lock enforce that.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Marketplace;

use App\Domain\Marketplace\Models\MarketplaceOrder;
use App\Domain\Marketplace\Models\VendorOrder;
use App\Domain\Marketplace\Models\Vendor;
use App\Domain\Marketplace\PaymentState;
use App\Domain\Marketplace\VendorProcessingStatus;
use App\Platform\FinancialLedger\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

final class MarketplaceOrderSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function order(array $overrides = []): MarketplaceOrder
    {
        return MarketplaceOrder::create(array_merge([
            'order_number' => 'MKT-'.uniqid(),
            'customer_ref' => 'cust-1',
            'entity_ref' => 'badan-usaha-test',
            'vendor_id' => Vendor::create(['name' => 'V', 'is_active' => true])->id,
            'subtotal_minor' => 300_000,
            'delivery_fee_minor' => 25_000,
            'total_minor' => 325_000,
            'payment_state' => PaymentState::BELUM_DIBAYAR,
            'idempotency_key' => uniqid('idem-'),
            'placed_at' => now(),
        ], $overrides));
    }

    public function test_an_order_totals_in_money_not_floats(): void
    {
        $order = $this->order();

        $this->assertEquals(new Money(325_000), $order->total());
        $this->assertIsInt($order->total()->toMinorInt());
    }

    public function test_payment_state_and_processing_status_are_separate_vocabularies(): void
    {
        // AC12: paid is not completed. Neither list may contain the other's values.
        $this->assertNotContains('DIBAYAR', VendorProcessingStatus::KNOWN_STATUSES);
        $this->assertNotContains(VendorProcessingStatus::SELESAI, PaymentState::KNOWN);
    }

    public function test_an_unknown_payment_state_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->order(['payment_state' => 'LUNAS_BANGET']);
    }

    public function test_the_idempotency_key_is_unique(): void
    {
        $this->order(['idempotency_key' => 'fixed-key']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->order(['idempotency_key' => 'fixed-key']);
    }

    public function test_a_vendor_order_starts_awaiting_the_vendor(): void
    {
        $order = $this->order();

        $vendorOrder = VendorOrder::create([
            'marketplace_order_id' => $order->id,
            'vendor_id' => $order->vendor_id,
            'status' => VendorProcessingStatus::MENUNGGU_VENDOR,
        ]);

        $this->assertSame(VendorProcessingStatus::MENUNGGU_VENDOR, $vendorOrder->status);
    }

    public function test_a_second_vendor_order_on_one_order_is_refused_by_the_database(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Deferred constraint trigger is PostgreSQL-only; asserted on PostgreSQL 18.');
        }

        $order = $this->order();
        $other = Vendor::create(['name' => 'Vendor Lain', 'is_active' => true]);

        VendorOrder::create([
            'marketplace_order_id' => $order->id,
            'vendor_id' => $order->vendor_id,
            'status' => VendorProcessingStatus::MENUNGGU_VENDOR,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::transaction(function () use ($order, $other): void {
            VendorOrder::create([
                'marketplace_order_id' => $order->id,
                'vendor_id' => $other->id,
                'status' => VendorProcessingStatus::MENUNGGU_VENDOR,
            ]);
        });
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=MarketplaceOrderSchemaTest`
Expected: FAIL — `Class "App\Domain\Marketplace\PaymentState" not found`. The last test reports SKIPPED on SQLite; that is expected and is **not** a pass.

- [ ] **Step 3: Write `PaymentState`**

Same plain-string-class shape as `EvidenceRequirement`, constants `BELUM_DIBAYAR`, `MENUNGGU_VERIFIKASI`, `DIBAYAR`, `GAGAL`, `DIKEMBALIKAN`, plus `KNOWN`, `isKnown()`, `assertKnown()`. Doc block must state that this list is deliberately disjoint from `VendorProcessingStatus` because requirement 12 forbids conflating payment with fulfilment, and that the two render as two separate indicators, never one merged "done" badge.

- [ ] **Step 4: Write the five table migrations**

`marketplace_orders` — `uuid('id')->primary()`, `string('order_number')->unique()`, `string('customer_ref')`, `string('entity_ref')` (the `badan_usaha`, frozen at placement — requirement 10), `uuid('vendor_id')` FK `restrictOnDelete`, `unsignedBigInteger('subtotal_minor')`, `unsignedBigInteger('delivery_fee_minor')->default(0)`, `unsignedBigInteger('total_minor')`, `string('payment_state', 32)`, `string('idempotency_key')->unique()`, `timestamp('placed_at')`, `timestamps()`. Indexes on `customer_ref`, `vendor_id`, `payment_state`. Under `pgsql`: `CHECK (total_minor = subtotal_minor + delivery_fee_minor)`, `CHECK (total_minor > 0)`, `CHECK (entity_ref <> '')`, and a `payment_state IN (...)` check listing the five values.

`marketplace_order_items` — `bigIncrements`, `uuid('marketplace_order_id')` FK `cascadeOnDelete`, `unsignedBigInteger('vendor_listing_id')` FK `restrictOnDelete`, `unsignedBigInteger('product_id')` FK, `unsignedBigInteger('product_variant_id')->nullable()` FK, `unsignedInteger('quantity')`, `unsignedBigInteger('unit_price_minor')`, `unsignedBigInteger('line_total_minor')`, `unsignedInteger('price_version')`, `timestamps()`. Under `pgsql`: `CHECK (quantity > 0)`, `CHECK (line_total_minor = unit_price_minor * quantity)`.

`vendor_orders` — `uuid('id')->primary()`, `uuid('marketplace_order_id')` FK `cascadeOnDelete`, `uuid('vendor_id')` FK `restrictOnDelete`, `string('status', 40)`, `date('scheduled_for')->nullable()`, `unsignedBigInteger('service_area_id')->nullable()` FK `restrictOnDelete`, `timestamp('accepted_at')->nullable()`, `timestamp('rejected_at')->nullable()`, `text('rejection_reason')->nullable()`, `timestamps()`. `unique(['marketplace_order_id','vendor_id'], 'vendor_orders_allocation_unique')`; indexes on `vendor_id` and `status`. Under `pgsql`: a `status IN (...)` CHECK generated from `VendorProcessingStatus::KNOWN_STATUSES` via `implode` so the list is never retyped.

`vendor_order_items` — `bigIncrements`, `uuid('vendor_order_id')` FK `cascadeOnDelete`, `unsignedBigInteger('marketplace_order_item_id')` FK `cascadeOnDelete`, `unsignedInteger('quantity')`, `timestamps()`; `unique(['vendor_order_id','marketplace_order_item_id'], 'vendor_order_items_line_unique')`.

`fulfilment_evidence` — `bigIncrements`, `uuid('vendor_order_id')` FK `cascadeOnDelete`, `string('document_id')` (a DocumentVault reference, never file content), `string('document_kind', 64)`, `string('uploaded_by_actor')`, `timestamp('uploaded_at')`, `timestamps()`. Under `pgsql`: `CHECK (document_kind = 'VENDOR_EVIDENCE')` — `DocumentKind::VendorEvidence` already exists, so no new closed list. Doc block: **evidence files are private**; this table stores only a reference, and no preview may be rendered for an unscanned upload.

- [ ] **Step 5: Write the single-vendor constraint trigger**

`2026_08_12_100120_enforce_single_vendor_per_order.php`, modelled on `2026_08_10_120300_enforce_vendor_payable_payout_consistency.php` (nowdoc `DB::unprepared`, `pgsql` early return, deferred constraint trigger, no-op `down()` with written justification):

```php
DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION assert_single_vendor_per_marketplace_order(p_order_id uuid)
RETURNS void AS $function$
DECLARE
    v_vendor_count integer;
BEGIN
    IF NOT EXISTS (SELECT 1 FROM marketplace_orders WHERE id = p_order_id) THEN
        RETURN; -- the order itself was deleted; nothing to assert
    END IF;

    SELECT COUNT(DISTINCT vendor_id) INTO v_vendor_count
    FROM vendor_orders WHERE marketplace_order_id = p_order_id;

    IF v_vendor_count > 1 THEN
        RAISE EXCEPTION
            'marketplace order % allocates to % vendors; MVP allows exactly one (requirement 4/14)',
            p_order_id, v_vendor_count
            USING ERRCODE = '23514';
    END IF;
END;
$function$ LANGUAGE plpgsql;
SQL);
```

wired as `CREATE CONSTRAINT TRIGGER vendor_orders_single_vendor AFTER INSERT OR UPDATE OR DELETE ON vendor_orders DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION enforce_single_vendor_per_marketplace_order();`, where that trigger function calls the assertion with `COALESCE(NEW.marketplace_order_id, OLD.marketplace_order_id)`. Precede each `CREATE TRIGGER` with `DROP TRIGGER IF EXISTS` so the migration is re-runnable.

The doc block must say: **this constraint is the enforcement of requirement 14.** Relaxing it requires order splitting, partial cancellation/refund, fee/tax allocation, dispute handling, and reconciliation to exist first.

- [ ] **Step 6: Write the models**

All five, following the established shape. `MarketplaceOrder` — `HasUuids`, `booted()` calling `PaymentState::assertKnown()` on saving, `total(): Money`, `items()`, `vendorOrders()`, `scopeForCustomer(Builder $q, string $customerRef)`. `VendorOrder` — `HasUuids`, `use VendorScoped;`, `booted()` calling `VendorProcessingStatus::assertKnown()`, `order()`, `items()`, `evidence()`. `MarketplaceOrderItem`, `VendorOrderItem`, `FulfilmentEvidence` — fillables, integer casts, relations.

- [ ] **Step 7: Run the test to verify it passes**

Run: `php artisan test --filter=MarketplaceOrderSchemaTest`
Expected: PASS (5 tests) + 1 SKIPPED on SQLite.

- [ ] **Step 8: Verify the PostgreSQL-only invariants on a disposable container**

`PGNAME="l11-task5-pg-$RANDOM"`, per the Current state recipe. The skipped test **must** run and pass here — this is the only place the single-vendor DB guarantee and the `total = subtotal + delivery_fee` CHECK are actually proven. Remove the container by exact name. Record the result; if it did not run, report `NOT TESTED`, never `PASS`.

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_08_12_1000[789]0_*.php database/migrations/2026_08_12_1001[012]0_*.php \
  app/Domain/Marketplace/PaymentState.php app/Domain/Marketplace/Models/ \
  tests/Feature/Domain/Marketplace/MarketplaceOrderSchemaTest.php
git commit -m "feat(marketplace): add order, allocation, evidence schema with single-vendor guard"
```

---

## Task 6: Place the order — allocation, `badan_usaha`, and the vendor payable (requirements 3, 4, 10)

**Files:**
- Create: `config/marketplace.php`, `app/Domain/Marketplace/Actions/PlaceMarketplaceOrder.php`
- Create: `app/Domain/Marketplace/Exceptions/BadanUsahaNotConfiguredException.php`, `Exceptions/CartPricingChangedException.php`
- Modify: `.env.example`
- Test: `tests/Feature/Domain/Marketplace/PlaceMarketplaceOrderTest.php`

**Interfaces:**
- Consumes: `Cart`, `CartItem`, `VendorListing`, `App\Platform\FinancialLedger\Actions\VendorPayable::assess()`, `Money`.
- Produces: `PlaceMarketplaceOrder::handle(Cart $cart, string $customerRef, ServiceArea $area, string $idempotencyKey, ?CarbonImmutable $now = null): MarketplaceOrder`.

**Requirement 10** — "WHEN a payment or payable is created THE SYSTEM SHALL reference the correct `badan_usaha` and vendor allocation." Both halves are asserted by tests here.

**`badan_usaha` fails closed.** It is a free-form string with no registry anywhere in the platform (`vendor_payables.entity_ref` and `journal_batches.entity_ref` are open strings validated only as non-blank). This Action reads `config('marketplace.badan_usaha_ref')` and **throws when blank** rather than defaulting. An invented entity reference silently misattributes money — the exact failure that freezing it at assessment time exists to prevent.

**Idempotency is enforced twice**, because a double-submitted checkout must not create a second order (design-system §6.6): `marketplace_orders.idempotency_key` is `UNIQUE`, and `vendor_payables` already carries `UNIQUE(vendor_id, source_type, source_id)`. This lane uses `source_type = 'marketplace_order'`, `source_id = $order->id`.

**Allocation is a loop over one vendor** — `foreach` over the cart's distinct vendors, so multi-vendor later becomes a constraint change rather than a rewrite (per `design.md`). The cart's vendor lock and Task 5's constraint trigger hold it to exactly one today. The loop shape does **not** mean multi-vendor is supported; requirement 14 forbids it.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Marketplace;

use App\Domain\Marketplace\Actions\AddToCart;
use App\Domain\Marketplace\Actions\PlaceMarketplaceOrder;
use App\Domain\Marketplace\AvailabilityMode;
use App\Domain\Marketplace\EvidenceRequirement;
use App\Domain\Marketplace\Exceptions\BadanUsahaNotConfiguredException;
use App\Domain\Marketplace\Exceptions\CartPricingChangedException;
use App\Domain\Marketplace\Models\Cart;
use App\Domain\Marketplace\Models\Product;
use App\Domain\Marketplace\Models\ServiceArea;
use App\Domain\Marketplace\Models\Vendor;
use App\Domain\Marketplace\Models\VendorListing;
use App\Domain\Marketplace\PaymentState;
use App\Domain\Marketplace\ProductCode;
use App\Domain\Marketplace\VendorProcessingStatus;
use App\Platform\FinancialLedger\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

final class PlaceMarketplaceOrderTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendor;

    private VendorListing $listing;

    private ServiceArea $area;

    protected function setUp(): void
    {
        parent::setUp();

        config(['marketplace.badan_usaha_ref' => 'badan-usaha-test']);

        $this->vendor = Vendor::create(['name' => 'Toko Bunga', 'is_active' => true]);
        $this->listing = VendorListing::create([
            'vendor_id' => $this->vendor->id,
            'product_id' => Product::findByCode(ProductCode::FLOWER_BOARD)->id,
            'price_minor' => 150_000,
            'price_version' => 1,
            'availability_mode' => AvailabilityMode::STOCKED,
            'stock_quantity' => 10,
            'evidence_requirement' => EvidenceRequirement::PHOTO,
            'is_active' => true,
        ]);
        $this->area = ServiceArea::create([
            'vendor_id' => $this->vendor->id,
            'area_code' => 'JKT-SELATAN',
            'area_label' => 'Jakarta Selatan',
            'delivery_fee_minor' => 25_000,
            'is_active' => true,
        ]);
    }

    private function cartWithTwo(): Cart
    {
        $cart = Cart::create(['customer_ref' => 'cust-1']);
        (new AddToCart)->handle($cart, $this->listing, 2);

        return $cart->fresh();
    }

    public function test_placing_an_order_totals_correctly_and_starts_unpaid(): void
    {
        $order = (new PlaceMarketplaceOrder)->handle($this->cartWithTwo(), 'cust-1', $this->area, 'idem-1');

        $this->assertSame(300_000, (int) $order->subtotal_minor);
        $this->assertSame(25_000, (int) $order->delivery_fee_minor);
        $this->assertEquals(new Money(325_000), $order->total());
        $this->assertSame(PaymentState::BELUM_DIBAYAR, $order->payment_state);
    }

    public function test_the_order_allocates_exactly_one_vendor_order_awaiting_the_vendor(): void
    {
        $order = (new PlaceMarketplaceOrder)->handle($this->cartWithTwo(), 'cust-1', $this->area, 'idem-2');

        $this->assertSame(1, $order->vendorOrders()->count());
        $vendorOrder = $order->vendorOrders()->first();
        $this->assertSame($this->vendor->id, $vendorOrder->vendor_id);
        $this->assertSame(VendorProcessingStatus::MENUNGGU_VENDOR, $vendorOrder->status);
        $this->assertSame(1, $vendorOrder->items()->count());
    }

    public function test_the_payable_references_the_correct_badan_usaha_and_vendor(): void
    {
        $order = (new PlaceMarketplaceOrder)->handle($this->cartWithTwo(), 'cust-1', $this->area, 'idem-3');

        // AC10, both halves. Read via the query builder so this test does not
        // depend on the ledger's model class location.
        $payable = DB::table('vendor_payables')
            ->where('source_type', 'marketplace_order')
            ->where('source_id', $order->id)
            ->first();

        $this->assertNotNull($payable);
        $this->assertSame('badan-usaha-test', $payable->entity_ref);
        $this->assertSame($this->vendor->id, $payable->vendor_id);
        $this->assertSame(300_000, (int) $payable->amount_minor);
    }

    public function test_a_blank_badan_usaha_fails_closed_and_writes_nothing(): void
    {
        config(['marketplace.badan_usaha_ref' => '']);

        $this->expectException(BadanUsahaNotConfiguredException::class);

        try {
            (new PlaceMarketplaceOrder)->handle($this->cartWithTwo(), 'cust-1', $this->area, 'idem-4');
        } finally {
            $this->assertDatabaseCount('marketplace_orders', 0);
            $this->assertDatabaseCount('vendor_payables', 0);
        }
    }

    public function test_resubmitting_the_same_idempotency_key_returns_the_same_order(): void
    {
        $cart = $this->cartWithTwo();
        $first = (new PlaceMarketplaceOrder)->handle($cart, 'cust-1', $this->area, 'idem-same');
        $second = (new PlaceMarketplaceOrder)->handle($cart->fresh(), 'cust-1', $this->area, 'idem-same');

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('marketplace_orders', 1);
        $this->assertDatabaseCount('vendor_payables', 1);
    }

    public function test_an_empty_cart_cannot_be_checked_out(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PlaceMarketplaceOrder)->handle(Cart::create(['customer_ref' => 'x']), 'x', $this->area, 'idem-5');
    }

    public function test_a_stale_price_blocks_checkout_rather_than_silently_recharging(): void
    {
        $cart = $this->cartWithTwo();
        $this->listing->update(['price_minor' => 200_000, 'price_version' => 2]);

        $this->expectException(CartPricingChangedException::class);

        (new PlaceMarketplaceOrder)->handle($cart->fresh(), 'cust-1', $this->area, 'idem-6');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=PlaceMarketplaceOrderTest`
Expected: FAIL — `Class "App\Domain\Marketplace\Actions\PlaceMarketplaceOrder" not found`.

- [ ] **Step 3: Add the config file and env placeholder**

`config/marketplace.php`:

```php
<?php

declare(strict_types=1);

return [
    /*
     * The `badan_usaha` (legal business entity) marketplace money settles
     * under. No registry or closed list for this value exists anywhere in the
     * platform — `vendor_payables.entity_ref` and `journal_batches.entity_ref`
     * are open strings validated only as non-blank — so it is configured, never
     * derived and never defaulted. A blank value fails checkout closed rather
     * than misattributing money to a placeholder entity (requirement 10).
     */
    'badan_usaha_ref' => env('MARKETPLACE_BADAN_USAHA_REF'),
];
```

Append to `.env.example`:

```
# Marketplace checkout fails closed while this is unset (requirement 10).
MARKETPLACE_BADAN_USAHA_REF=
```

- [ ] **Step 4: Write the two exceptions**

Both `final class ... extends RuntimeException` in `app/Domain/Marketplace/Exceptions/`, no added behaviour:
- `BadanUsahaNotConfiguredException` — doc block cites requirement 10 and says checkout refuses rather than defaulting.
- `CartPricingChangedException` — doc block cites PUB-022's changed-price state and says the customer must explicitly reconfirm.

- [ ] **Step 5: Read the real ledger signature before writing the Action**

Open `app/Platform/FinancialLedger/Actions/VendorPayable.php` and read `__construct()` and `assess()`. The call below shows the intended shape; **the real signature governs**, including the authorizer/`ActorContext` wiring and the exact eligibility constant. A payable created at checkout is **held**, not immediately payable — the vendor has fulfilled nothing yet (requirement 12).

- [ ] **Step 6: Write the Action**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Actions;

use App\Domain\Marketplace\Exceptions\BadanUsahaNotConfiguredException;
use App\Domain\Marketplace\Exceptions\CartPricingChangedException;
use App\Domain\Marketplace\Models\Cart;
use App\Domain\Marketplace\Models\MarketplaceOrder;
use App\Domain\Marketplace\Models\ServiceArea;
use App\Domain\Marketplace\PaymentState;
use App\Domain\Marketplace\VendorProcessingStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Turns a cart into an order, its vendor allocation, and the vendor's payable.
 *
 * The payable's `(source_type, source_id)` is `('marketplace_order', $order->id)`.
 * `vendor_payables` carries `UNIQUE(vendor_id, source_type, source_id)`, so a
 * replayed checkout cannot create a second debt even if the application-level
 * idempotency check is bypassed.
 *
 * Allocation is a `foreach` over the cart's distinct vendors. The cart's vendor
 * lock and the `vendor_orders_single_vendor` constraint trigger hold that to
 * exactly one today (requirement 4). The loop shape exists so multi-vendor is
 * later a constraint change rather than a rewrite, per design.md — it does NOT
 * mean multi-vendor is supported. Requirement 14 forbids that until order
 * splitting, partial cancellation/refund, fee/tax allocation, dispute handling,
 * and reconciliation all exist.
 */
final class PlaceMarketplaceOrder
{
    public function handle(
        Cart $cart,
        string $customerRef,
        ServiceArea $area,
        string $idempotencyKey,
        ?CarbonImmutable $now = null,
    ): MarketplaceOrder {
        $existing = MarketplaceOrder::where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        $items = $cart->items()->with('listing')->get();
        if ($items->isEmpty()) {
            throw new InvalidArgumentException('Cannot place an order from an empty cart.');
        }

        if ($cart->hasStalePricing()) {
            throw new CartPricingChangedException(
                "A cart line's price changed since it was added. The customer must reconfirm before checkout."
            );
        }

        $entityRef = (string) config('marketplace.badan_usaha_ref', '');
        if (trim($entityRef) === '') {
            throw new BadanUsahaNotConfiguredException(
                'Marketplace checkout requires `marketplace.badan_usaha_ref` to be configured. '
                .'Refusing to place an order without an explicit badan usaha.'
            );
        }

        $now ??= CarbonImmutable::now();

        $subtotal = 0;
        foreach ($items as $item) {
            $subtotal += (int) $item->unit_price_minor * (int) $item->quantity;
        }
        $deliveryFee = (int) $area->delivery_fee_minor;

        return DB::transaction(function () use (
            $cart, $items, $customerRef, $area, $idempotencyKey, $entityRef, $subtotal, $deliveryFee, $now
        ): MarketplaceOrder {
            $order = MarketplaceOrder::create([
                'order_number' => 'MKT-'.strtoupper(Str::random(10)),
                'customer_ref' => $customerRef,
                'entity_ref' => $entityRef,
                'vendor_id' => $cart->vendor_id,
                'subtotal_minor' => $subtotal,
                'delivery_fee_minor' => $deliveryFee,
                'total_minor' => $subtotal + $deliveryFee,
                'payment_state' => PaymentState::BELUM_DIBAYAR,
                'idempotency_key' => $idempotencyKey,
                'placed_at' => $now,
            ]);

            $itemsByVendor = [];
            foreach ($items as $item) {
                $orderItem = $order->items()->create([
                    'vendor_listing_id' => $item->vendor_listing_id,
                    'product_id' => $item->listing->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'quantity' => (int) $item->quantity,
                    'unit_price_minor' => (int) $item->unit_price_minor,
                    'line_total_minor' => (int) $item->unit_price_minor * (int) $item->quantity,
                    'price_version' => (int) $item->price_version,
                ]);

                $itemsByVendor[$item->listing->vendor_id][] = $orderItem;
            }

            foreach ($itemsByVendor as $vendorId => $vendorItems) {
                $vendorOrder = $order->vendorOrders()->create([
                    'vendor_id' => $vendorId,
                    'status' => VendorProcessingStatus::MENUNGGU_VENDOR,
                    'service_area_id' => $area->id,
                ]);

                $vendorSubtotal = 0;
                foreach ($vendorItems as $vendorItem) {
                    $vendorOrder->items()->create([
                        'marketplace_order_item_id' => $vendorItem->id,
                        'quantity' => (int) $vendorItem->quantity,
                    ]);
                    $vendorSubtotal += (int) $vendorItem->line_total_minor;
                }

                $this->assessPayable((string) $vendorId, $entityRef, $order->id, $vendorSubtotal);
            }

            $cart->items()->delete();
            $cart->update(['vendor_id' => null]);

            return $order->fresh();
        });
    }
}
```

Add a private `assessPayable(string $vendorId, string $entityRef, string $orderId, int $amountMinor): void` that wraps the real `VendorPayable::assess()` call using the signature read in Step 5, passing `new Money($amountMinor)` and the held-eligibility constant. Keeping it in one private method means the ledger call site appears exactly once.

- [ ] **Step 7: Run the test to verify it passes**

Run: `php artisan test --filter=PlaceMarketplaceOrderTest`
Expected: PASS (7 tests).

- [ ] **Step 8: Mutation-check all three guards**

Each change made, re-run, then reverted:
1. Delete the blank-`entity_ref` guard — `test_a_blank_badan_usaha_fails_closed_and_writes_nothing` **must** fail.
2. Delete the `$existing !== null` early return — `test_resubmitting_the_same_idempotency_key_returns_the_same_order` **must** fail.
3. Delete the `hasStalePricing()` guard — `test_a_stale_price_blocks_checkout_rather_than_silently_recharging` **must** fail.

Any test that still passes is vacuous and must be fixed before proceeding.

- [ ] **Step 9: Verify on a disposable PostgreSQL 18 container**

`PGNAME="l11-task6-pg-$RANDOM"`, per the Current state recipe. This run proves the payable UNIQUE constraint and the `total_minor = subtotal_minor + delivery_fee_minor` CHECK hold under the real engine. Remove the container by exact name. Report anything unexecuted as `NOT TESTED`.

- [ ] **Step 10: Commit**

```bash
git add config/marketplace.php .env.example app/Domain/Marketplace/Actions/PlaceMarketplaceOrder.php \
  app/Domain/Marketplace/Exceptions/ tests/Feature/Domain/Marketplace/PlaceMarketplaceOrderTest.php
git commit -m "feat(marketplace): place order with vendor allocation and badan usaha payable"
```

---

## Task 7: PUB-022 cart screen — the single-vendor conflict modal

**Files:**
- Create: `app/Livewire/Public/Marketplace/Cart.php`, `resources/views/livewire/public/marketplace/cart.blade.php`
- Modify: `routes/web.php` (register `/marketplace/keranjang`)
- Test: `tests/Feature/Livewire/Public/Marketplace/CartScreenTest.php`

**Interfaces:**
- Consumes: `AddToCart`, `UpdateCartItem`, `RemoveCartItem`, `ReplaceCartWithVendor`, `CartConflict`, `Cart::subtotal()`, `Cart::hasStalePricing()`.
- Produces: Livewire actions `addListing(int $listingId, int $quantity, ?int $variantId)`, `resolveConflictByReplacing()`, `dismissConflict()`, `updateQuantity(int $itemId, int $quantity)`, `removeItem(int $itemId)`, `reconfirmPricing()`.

**Required states** (design-system §6, `tasks.md` "Required UI states" row PUB-022): empty §6.2, **vendor conflict** via `x-mk.modal` §3.4, **changed price** with explicit reconfirmation, duplicate/retry-safe §6.6, support §6.10.

**The conflict modal is the AC4 surface.** It must offer separate checkout **or** an explicit split, and must never silently drop items. Below `--breakpoint-md` it is a bottom sheet with `flex-col-reverse` footer so the primary action sits in thumb reach. Use only `x-mk.*` primitives and tokens — `ci/verify-docs.sh` fails the build on a hardcoded hex/px/ms/shadow or a Tailwind arbitrary value.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Marketplace;

use App\Domain\Marketplace\Actions\AddToCart;
use App\Domain\Marketplace\AvailabilityMode;
use App\Domain\Marketplace\EvidenceRequirement;
use App\Domain\Marketplace\Models\Cart as CartModel;
use App\Domain\Marketplace\Models\Product;
use App\Domain\Marketplace\Models\Vendor;
use App\Domain\Marketplace\Models\VendorListing;
use App\Domain\Marketplace\ProductCode;
use App\Livewire\Public\Marketplace\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class CartScreenTest extends TestCase
{
    use RefreshDatabase;

    private function listing(string $vendorName, string $code, int $price = 150_000): VendorListing
    {
        $vendor = Vendor::create(['name' => $vendorName, 'is_active' => true]);

        return VendorListing::create([
            'vendor_id' => $vendor->id,
            'product_id' => Product::findByCode($code)->id,
            'price_minor' => $price,
            'price_version' => 1,
            'availability_mode' => AvailabilityMode::STOCKED,
            'stock_quantity' => 10,
            'evidence_requirement' => EvidenceRequirement::NONE,
            'is_active' => true,
        ]);
    }

    public function test_an_empty_cart_shows_the_empty_state_not_a_broken_table(): void
    {
        Livewire::test(Cart::class)
            ->assertOk()
            ->assertSee('Keranjang Anda masih kosong')
            ->assertSee('Lihat katalog');
    }

    public function test_adding_a_second_vendor_opens_the_conflict_modal_and_keeps_both_options(): void
    {
        $a = $this->listing('Vendor A', ProductCode::FLOWER_BOARD);
        $b = $this->listing('Vendor B', ProductCode::GRAVESTONE_GRANITE);

        Livewire::test(Cart::class)
            ->call('addListing', $a->id, 1, null)
            ->call('addListing', $b->id, 1, null)
            ->assertSet('conflictOpen', true)
            ->assertSee('Vendor A')
            ->assertSee('Vendor B')
            // AC4: the constraint is stated, and both resolutions are offered.
            ->assertSee('satu vendor')
            ->assertSee('Ganti keranjang')
            ->assertSee('Selesaikan pesanan ini dulu');
    }

    public function test_the_conflict_never_loses_the_existing_item(): void
    {
        $a = $this->listing('Vendor A', ProductCode::FLOWER_BOARD);
        $b = $this->listing('Vendor B', ProductCode::GRAVESTONE_GRANITE);

        $component = Livewire::test(Cart::class)
            ->call('addListing', $a->id, 1, null)
            ->call('addListing', $b->id, 1, null);

        $cart = CartModel::firstOrFail();
        $this->assertSame(1, $cart->items()->count());
        $this->assertSame($a->id, $cart->items()->first()->vendor_listing_id);

        // Dismissing the modal still keeps the original item.
        $component->call('dismissConflict')->assertSet('conflictOpen', false);
        $this->assertSame(1, $cart->fresh()->items()->count());
    }

    public function test_replacing_is_explicit_and_only_then_swaps_the_vendor(): void
    {
        $a = $this->listing('Vendor A', ProductCode::FLOWER_BOARD);
        $b = $this->listing('Vendor B', ProductCode::GRAVESTONE_GRANITE);

        Livewire::test(Cart::class)
            ->call('addListing', $a->id, 1, null)
            ->call('addListing', $b->id, 1, null)
            ->call('resolveConflictByReplacing')
            ->assertSet('conflictOpen', false);

        $cart = CartModel::firstOrFail();
        $this->assertSame(1, $cart->items()->count());
        $this->assertSame($b->id, $cart->items()->first()->vendor_listing_id);
    }

    public function test_a_changed_price_demands_reconfirmation_before_checkout(): void
    {
        $listing = $this->listing('Vendor A', ProductCode::FLOWER_BOARD, 100_000);

        $component = Livewire::test(Cart::class)->call('addListing', $listing->id, 1, null);
        $listing->update(['price_minor' => 130_000, 'price_version' => 2]);

        $component->call('$refresh')
            ->assertSee('Harga berubah')
            ->assertSee('Konfirmasi harga baru');
    }

    public function test_the_screen_offers_a_support_affordance(): void
    {
        Livewire::test(Cart::class)->assertSee('Butuh bantuan');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=CartScreenTest`
Expected: FAIL — `Class "App\Livewire\Public\Marketplace\Cart" not found`.

- [ ] **Step 3: Write the Livewire component**

`app/Livewire/Public/Marketplace/Cart.php` — a `Livewire\Component` with public `?bool $conflictOpen = false`, public `?array $conflict = null` (the `CartConflict` flattened for the view), and a `pendingListingId`/`pendingQuantity`/`pendingVariantId` triple remembering what the user tried to add.

`addListing()` resolves or creates the session's `Cart`, calls `AddToCart::handle()`, and branches: a `CartItem` result clears any conflict; a `CartConflict` result sets `$this->conflict` and `$this->conflictOpen = true` and remembers the pending listing. `resolveConflictByReplacing()` calls `ReplaceCartWithVendor::handle()` with the remembered pending values and closes the modal. `dismissConflict()` clears the modal and the pending values **without touching the cart**. `updateQuantity()`/`removeItem()` delegate to their Actions and then `releaseVendorLockIfEmpty()`. `reconfirmPricing()` refreshes each line's `unit_price_minor`/`price_version` from its listing.

Cart identity: resolve by authenticated customer reference when present, otherwise by `session()->getId()` stored in `carts.session_ref`. Never trust a cart id from the request.

- [ ] **Step 4: Write the Blade view**

`resources/views/livewire/public/marketplace/cart.blade.php` using only `x-mk.*` primitives and tokens:
- Empty state (§6.2): `x-mk.card` with "Keranjang Anda masih kosong" and a `Lihat katalog` link to `/marketplace`.
- Line items: `x-mk.table` above `--breakpoint-md`, stacked `x-mk.card`s below (§4.3). Prices `text-right tabular-nums` with `--font-mono`; the total uses `--font-weight-bold`.
- Changed price (§6.2/§6.3): an `x-mk.alert` with `intent="info"` reading "Harga berubah sejak item ditambahkan" plus a `Konfirmasi harga baru` button wired to `reconfirmPricing`. Checkout stays disabled until reconfirmed.
- Conflict modal (§3.4): `x-mk.modal` bound to `conflictOpen`, stating the one-vendor-per-checkout constraint in plain Indonesian, naming both vendors, and offering two actions — `Ganti keranjang` (calls `resolveConflictByReplacing`) and `Selesaikan pesanan ini dulu` (calls `dismissConflict`). Footer `flex-col-reverse` below `--breakpoint-md`.
- Support (§6.10): a "Butuh bantuan" link.

- [ ] **Step 5: Register the route**

Add `/marketplace/keranjang` to `routes/web.php` next to the existing marketplace routes, following their exact registration style.

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test --filter=CartScreenTest`
Expected: PASS (6 tests).

- [ ] **Step 7: Run the design gate**

Run: `./ci/verify-docs.sh`
Expected: PASS. It scans `resources/` and `app/` for hardcoded design values and Tailwind arbitrary values. A failure here is a real violation, not a false positive — fix the token usage rather than suppressing it.

- [ ] **Step 8: Commit**

```bash
git add app/Livewire/Public/Marketplace/Cart.php resources/views/livewire/public/marketplace/cart.blade.php \
  routes/web.php tests/Feature/Livewire/Public/Marketplace/CartScreenTest.php
git commit -m "feat(marketplace): add cart screen with single-vendor conflict modal"
```

---

## Task 8: PUB-023 checkout screen — manual fallback live, online branch gate-closed

**Files:**
- Create: `app/Livewire/Public/Marketplace/Checkout.php`, `resources/views/livewire/public/marketplace/checkout.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Livewire/Public/Marketplace/CheckoutScreenTest.php`

**Interfaces:**
- Consumes: `PlaceMarketplaceOrder::handle()`, `GuardPaymentSession::__invoke()`, `SubmitManualPayment::submit()`, `ServiceArea`, `CartPricingChangedException`, `BadanUsahaNotConfiguredException`.
- Produces: Livewire actions `placeOrder()`, `submitManualProof()`; public `string $selectedAreaCode`, `string $idempotencyKey`.

**The online branch is honestly gate-closed.** `GuardPaymentSession` denies unconditionally today, so the online option renders a design-system §6.9 gated-fallback banner (`intent=info`) explaining that online payment is unavailable and pointing at the manual path. **Do not fake a paid path, and do not hide the option silently** — the customer must see why and what to do instead.

**Validation errors never clear entered data** (§6.3): inline `aria-invalid` plus a summary alert. **Duplicate submit is safe** (§6.6): `idempotencyKey` is generated once at mount and reused, so a double click returns the same order.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Marketplace;

use App\Domain\Marketplace\Actions\AddToCart;
use App\Domain\Marketplace\AvailabilityMode;
use App\Domain\Marketplace\EvidenceRequirement;
use App\Domain\Marketplace\Models\Cart as CartModel;
use App\Domain\Marketplace\Models\MarketplaceOrder;
use App\Domain\Marketplace\Models\Product;
use App\Domain\Marketplace\Models\ServiceArea;
use App\Domain\Marketplace\Models\Vendor;
use App\Domain\Marketplace\Models\VendorListing;
use App\Domain\Marketplace\PaymentState;
use App\Domain\Marketplace\ProductCode;
use App\Livewire\Public\Marketplace\Checkout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class CheckoutScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['marketplace.badan_usaha_ref' => 'badan-usaha-test']);
    }

    private function seedCart(): array
    {
        $vendor = Vendor::create(['name' => 'Toko Bunga', 'is_active' => true]);
        $listing = VendorListing::create([
            'vendor_id' => $vendor->id,
            'product_id' => Product::findByCode(ProductCode::FLOWER_BOARD)->id,
            'price_minor' => 150_000, 'price_version' => 1,
            'availability_mode' => AvailabilityMode::STOCKED, 'stock_quantity' => 10,
            'evidence_requirement' => EvidenceRequirement::NONE, 'is_active' => true,
        ]);
        $area = ServiceArea::create([
            'vendor_id' => $vendor->id, 'area_code' => 'JKT-SELATAN',
            'area_label' => 'Jakarta Selatan', 'delivery_fee_minor' => 25_000, 'is_active' => true,
        ]);
        $cart = CartModel::create(['session_ref' => session()->getId()]);
        (new AddToCart)->handle($cart, $listing, 2);

        return [$cart->fresh(), $area];
    }

    public function test_the_online_payment_option_is_shown_as_gate_closed_not_hidden(): void
    {
        $this->seedCart();

        Livewire::test(Checkout::class)
            ->assertOk()
            // §6.9: the gate is disclosed with a reason and a live alternative.
            ->assertSee('Pembayaran online belum tersedia')
            ->assertSee('transfer manual');
    }

    public function test_the_screen_states_the_single_vendor_constraint(): void
    {
        $this->seedCart();

        Livewire::test(Checkout::class)->assertSee('satu vendor');
    }

    public function test_placing_an_order_creates_it_unpaid_and_awaiting_the_vendor(): void
    {
        [, $area] = $this->seedCart();

        Livewire::test(Checkout::class)
            ->set('selectedAreaCode', $area->area_code)
            ->call('placeOrder')
            ->assertHasNoErrors();

        $order = MarketplaceOrder::firstOrFail();
        $this->assertSame(PaymentState::BELUM_DIBAYAR, $order->payment_state);
        $this->assertSame(1, $order->vendorOrders()->count());
    }

    public function test_a_double_submit_creates_only_one_order(): void
    {
        [, $area] = $this->seedCart();

        $component = Livewire::test(Checkout::class)->set('selectedAreaCode', $area->area_code);
        $component->call('placeOrder');
        $component->call('placeOrder');

        $this->assertDatabaseCount('marketplace_orders', 1);
    }

    public function test_a_missing_service_area_shows_an_inline_error_and_keeps_entered_data(): void
    {
        $this->seedCart();

        Livewire::test(Checkout::class)
            ->set('recipientName', 'Budi Santoso')
            ->set('selectedAreaCode', '')
            ->call('placeOrder')
            ->assertHasErrors(['selectedAreaCode'])
            // §6.3: never clear what the customer typed.
            ->assertSet('recipientName', 'Budi Santoso');

        $this->assertDatabaseCount('marketplace_orders', 0);
    }

    public function test_an_unconfigured_badan_usaha_degrades_without_leaking_internals(): void
    {
        config(['marketplace.badan_usaha_ref' => '']);
        [, $area] = $this->seedCart();

        Livewire::test(Checkout::class)
            ->set('selectedAreaCode', $area->area_code)
            ->call('placeOrder')
            ->assertOk()
            ->assertSee('Checkout belum dapat diproses')
            ->assertDontSee('badan_usaha_ref');

        $this->assertDatabaseCount('marketplace_orders', 0);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=CheckoutScreenTest`
Expected: FAIL — `Class "App\Livewire\Public\Marketplace\Checkout" not found`.

- [ ] **Step 3: Write the component**

`mount()` generates `$this->idempotencyKey = (string) Str::uuid()` once and loads the session cart with its vendor's active `ServiceArea`s. `placeOrder()` validates `recipientName`, `recipientPhone`, `selectedAreaCode`, and the schedule date, then calls `PlaceMarketplaceOrder::handle()`.

Exception handling — the key correctness detail:
- `CartPricingChangedException` → redirect back to the cart with the changed-price state showing. Never silently recharge.
- `BadanUsahaNotConfiguredException` → render "Checkout belum dapat diproses. Silakan hubungi dukungan." **Never surface the config key or the exception message** (`AGENTS.md` §Observability: restricted data never reaches logs or chat; an internal config name in a customer-facing string is an internal detail leak).

Online payment: call `GuardPaymentSession` and render its denial as a §6.9 gated banner. Because it always denies today, the manual path is the only enabled submit. Do not branch on a hardcoded "gate closed" boolean — read the guard, so the screen starts working the day the gate opens.

- [ ] **Step 4: Write the Blade view**

Sections: order summary (`x-mk.table`, `tabular-nums`, `--font-mono`), recipient + schedule + service-area fields (`x-mk.field`, 44px targets, `aria-invalid` on error), a validation summary `x-mk.alert` on submit, the §6.9 gated-fallback banner (`intent=info`) for online payment, the manual-transfer instructions block, an explicit statement of the one-vendor-per-checkout constraint, and support (§6.10). Tokens only.

- [ ] **Step 5: Register the route, run tests, run the design gate**

Add `/marketplace/checkout`. Run `php artisan test --filter=CheckoutScreenTest` (expect PASS, 6 tests) and `./ci/verify-docs.sh` (expect PASS).

- [ ] **Step 6: Mutation-check the gate and the leak guard**

1. Hardcode `GuardResult::isAllowed()` handling to `true` in the component — `test_the_online_payment_option_is_shown_as_gate_closed_not_hidden` must fail.
2. Change the `BadanUsahaNotConfiguredException` handler to echo `$e->getMessage()` — `test_an_unconfigured_badan_usaha_degrades_without_leaking_internals` must fail on `assertDontSee('badan_usaha_ref')`.

Revert both.

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/Public/Marketplace/Checkout.php \
  resources/views/livewire/public/marketplace/checkout.blade.php routes/web.php \
  tests/Feature/Livewire/Public/Marketplace/CheckoutScreenTest.php
git commit -m "feat(marketplace): add checkout with manual fallback and gated online branch"
```

---

## Task 9: PUB-024 order tracking — payment and fulfilment as two separate indicators

**Files:**
- Create: `app/Livewire/Public/Marketplace/OrderTracking.php`, `resources/views/livewire/public/marketplace/order-tracking.blade.php`, `app/Domain/Marketplace/MarketplaceOrderQuery.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Livewire/Public/Marketplace/OrderTrackingScreenTest.php`

**Interfaces:**
- Consumes: `StatusIntent::intent()/icon()/label()` with `FAMILY_VENDOR_PROCESSING`, `VendorProcessingStatus`, `PaymentState`.
- Produces: `MarketplaceOrderQuery::findForCustomer(string $orderNumber, string $customerRef): ?MarketplaceOrder`.

**Requirement 13** — show the customer the current vendor-processing status. **Requirement 12** — a paid order is not a completed one. `tasks.md`: "`DIBAYAR` ≠ `SELESAI` … must render as **two distinct indicators**, never merged into one 'done' badge."

**Statuses resolve through `StatusIntent` only** — never a `match` in Blade. The helper already maps all eight vendor statuses.

**Enumeration safety:** an order number belonging to another customer returns the same not-found result as one that never existed.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Marketplace;

use App\Domain\Marketplace\Models\MarketplaceOrder;
use App\Domain\Marketplace\Models\Vendor;
use App\Domain\Marketplace\PaymentState;
use App\Domain\Marketplace\VendorProcessingStatus;
use App\Livewire\Public\Marketplace\OrderTracking;
use App\Support\Design\StatusIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class OrderTrackingScreenTest extends TestCase
{
    use RefreshDatabase;

    private function order(string $customerRef, string $status, string $paymentState): MarketplaceOrder
    {
        $vendor = Vendor::create(['name' => 'Toko Bunga', 'is_active' => true]);
        $order = MarketplaceOrder::create([
            'order_number' => 'MKT-'.strtoupper(uniqid()),
            'customer_ref' => $customerRef,
            'entity_ref' => 'badan-usaha-test',
            'vendor_id' => $vendor->id,
            'subtotal_minor' => 300_000, 'delivery_fee_minor' => 25_000, 'total_minor' => 325_000,
            'payment_state' => $paymentState,
            'idempotency_key' => uniqid('idem-'),
            'placed_at' => now(),
        ]);
        $order->vendorOrders()->create(['vendor_id' => $vendor->id, 'status' => $status]);

        return $order;
    }

    public function test_the_customer_sees_the_current_vendor_processing_status(): void
    {
        $order = $this->order('cust-1', VendorProcessingStatus::DIPROSES, PaymentState::BELUM_DIBAYAR);

        Livewire::test(OrderTracking::class, ['orderNumber' => $order->order_number, 'customerRef' => 'cust-1'])
            ->assertOk()
            ->assertSee(StatusIntent::label(VendorProcessingStatus::DIPROSES, StatusIntent::FAMILY_VENDOR_PROCESSING));
    }

    public function test_a_paid_order_is_never_shown_as_fulfilment_complete(): void
    {
        // AC12: paid, but the vendor has not finished.
        $order = $this->order('cust-1', VendorProcessingStatus::DIPROSES, PaymentState::DIBAYAR);

        $component = Livewire::test(OrderTracking::class, [
            'orderNumber' => $order->order_number, 'customerRef' => 'cust-1',
        ]);

        // Two separate indicators, both visible.
        $component->assertSee('Pembayaran');
        $component->assertSee('Proses vendor');
        $component->assertSee(StatusIntent::label(VendorProcessingStatus::DIPROSES, StatusIntent::FAMILY_VENDOR_PROCESSING));
        // The fulfilment-complete label must NOT appear.
        $component->assertDontSee(StatusIntent::label(VendorProcessingStatus::SELESAI, StatusIntent::FAMILY_VENDOR_PROCESSING));
    }

    public function test_every_vendor_status_resolves_through_status_intent(): void
    {
        foreach (VendorProcessingStatus::KNOWN_STATUSES as $status) {
            $order = $this->order('cust-loop', $status, PaymentState::BELUM_DIBAYAR);

            Livewire::test(OrderTracking::class, [
                'orderNumber' => $order->order_number, 'customerRef' => 'cust-loop',
            ])->assertOk()->assertSee(StatusIntent::label($status, StatusIntent::FAMILY_VENDOR_PROCESSING));
        }
    }

    public function test_a_pending_status_is_never_styled_as_success(): void
    {
        $this->assertSame(
            StatusIntent::INTENT_PENDING,
            StatusIntent::intent(VendorProcessingStatus::MENUNGGU_VENDOR, StatusIntent::FAMILY_VENDOR_PROCESSING)
        );
    }

    public function test_another_customers_order_is_indistinguishable_from_one_that_never_existed(): void
    {
        $order = $this->order('cust-owner', VendorProcessingStatus::DIPROSES, PaymentState::BELUM_DIBAYAR);

        $forbidden = Livewire::test(OrderTracking::class, [
            'orderNumber' => $order->order_number, 'customerRef' => 'cust-intruder',
        ]);
        $missing = Livewire::test(OrderTracking::class, [
            'orderNumber' => 'MKT-DOESNOTEXIST', 'customerRef' => 'cust-intruder',
        ]);

        foreach ([$forbidden, $missing] as $component) {
            $component->assertOk()->assertSee('Pesanan tidak ditemukan');
            $component->assertDontSee('Toko Bunga');
        }
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=OrderTrackingScreenTest`
Expected: FAIL — `Class "App\Domain\Marketplace\MarketplaceOrderQuery" not found`.

- [ ] **Step 3: Write the query class**

`MarketplaceOrderQuery::findForCustomer()` returns `null` for both an unknown order number and one owned by a different customer — a single `where('order_number', ...)->where('customer_ref', ...)->first()`. The doc block must state that the two cases are deliberately indistinguishable (design-system §6.4 enumeration safety) and that no "belongs to another customer" message may ever be produced.

- [ ] **Step 4: Write the component and view**

Component takes `orderNumber` and `customerRef`, resolves through the query class, and exposes `?MarketplaceOrder $order`. The view renders:
- Not-found state: "Pesanan tidak ditemukan" and nothing else about the order.
- **Two labelled indicator rows** — "Pembayaran" using `PaymentState`, and "Proses vendor" using `x-mk.badge` with `StatusIntent::intent()`/`icon()`/`label()` under `FAMILY_VENDOR_PROCESSING`. They must be visually and structurally distinct; never a single merged badge.
- Order summary and support (§6.10). Tokens only; no `match` on status in Blade.

- [ ] **Step 5: Register the route, run tests, run the design gate**

Add `/marketplace/pesanan/{orderNumber}`. Run `php artisan test --filter=OrderTrackingScreenTest` (expect PASS, 5 tests) and `./ci/verify-docs.sh`.

- [ ] **Step 6: Mutation-check the AC12 separation**

Merge the two indicators into one badge that shows "selesai" whenever `payment_state === PaymentState::DIBAYAR`. `test_a_paid_order_is_never_shown_as_fulfilment_complete` **must** fail. Revert.

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/Public/Marketplace/OrderTracking.php app/Domain/Marketplace/MarketplaceOrderQuery.php \
  resources/views/livewire/public/marketplace/order-tracking.blade.php routes/web.php \
  tests/Feature/Livewire/Public/Marketplace/OrderTrackingScreenTest.php
git commit -m "feat(marketplace): add order tracking with separate payment and fulfilment state"
```

---

## Task 10: Whole-branch verification and documentation reconciliation

**Files:**
- Modify: `.kiro/specs/funeral-marketplace-and-vendor-portal/tasks.md`
- Modify: `docs/product/screen-inventory.md`
- Modify: `docs/planning/sprint-plan.md` (task status only, if it tracks these items)

**No new canonical data.** These edits record what shipped and correct statements that this branch made false. Do not restate product codes, statuses, or design values — reference the existing canonical files.

- [ ] **Step 1: Full suite on SQLite**

Run: `php artisan test`
Expected: PASS, with the PostgreSQL-only tests reported SKIPPED. A skip is **not** a pass.

- [ ] **Step 2: Full suite on a disposable PostgreSQL 18 container**

`PGNAME="l11-final-pg-$RANDOM"`, per the Current state recipe. This is the authoritative local run: every constraint trigger and CHECK from Tasks 2, 5, and 6 executes here and nowhere else. Record which previously-skipped tests actually ran. Remove the container by exact name; confirm `makam-nonprod-postgres-1` was never touched.

- [ ] **Step 3: Static analysis and formatting**

Run: `vendor/bin/pint --test` and `vendor/bin/phpstan analyse`. Fix what they flag. Do not run `composer install` or `npm run build` on this host.

- [ ] **Step 4: Design and docs gate**

Run: `./ci/verify-docs.sh`. Expected: PASS.

- [ ] **Step 5: Update `tasks.md`**

Tick and annotate, with evidence, in this file's established style (dated, with the test names and the commit or CI run):
- "Implement cart and multi-vendor order decomposition" (requirements 3, 4, 14) — now **done for the single-vendor MVP**. Note explicitly that multi-vendor decomposition is NOT built and is forbidden by requirement 14, and that the constraint is now enforced by `vendor_orders_single_vendor` at the database, not merely stated as a note.
- "Implement schedule and region delivery pricing" (requirement 2) — the schema gap is **closed**: `vendor_listings`, `service_areas`, and `vendor_availability` give all five previously-homeless AC2 fields a place to live.
- "Implement the single-vendor conflict modal per §3.4; never silently drop cart items" — done, with the `CartScreenTest` no-loss test named.
- "Resolve all vendor and order statuses through `StatusIntent`; render payment and fulfilment as two separate indicators" — done for PUB-024.
- "Confirm the single-vendor checkout constraint is enforced across categories" — now enforced, since the cart locks by vendor regardless of product category.
- Leave every vendor-panel item (requirements 5-8, 11) `[ ]` and add one line noting they are deferred to a future L10 lane, not overlooked.
- The category-code OPEN QUESTION stays **BLOCKED and untouched** — this branch invented no category code or slug.

- [ ] **Step 6: Update `screen-inventory.md`**

PUB-022, PUB-023, PUB-024 move from unbuilt to shipped, each with its implemented states and its test names. Correct the PUB-021 row: the sentence saying schedule/service-area/delivery-fee/stock/evidence-requirement have nowhere to live is **no longer true** — point it at `vendor_listings`. Record honestly that PUB-021 itself still does not *render* schedule or area-unavailable states; this branch gave those fields a home, it did not add them to the product detail page.

- [ ] **Step 7: Record what is NOT tested**

Add a short, honest note to `tasks.md` covering, at minimum: touch targets, focus ring, and mobile reflow are still unmeasured (no browser or headless harness exists in this repository); the online paid path is unexercised because `G-PAY-01` is closed and `GuardPaymentSession` denies unconditionally; and `MARKETPLACE_BADAN_USAHA_REF` is unset by default, so checkout fails closed until an operator configures it. Never report `PASS` for any of these.

- [ ] **Step 8: Commit**

```bash
git add .kiro/specs/funeral-marketplace-and-vendor-portal/tasks.md docs/product/screen-inventory.md docs/planning/sprint-plan.md
git commit -m "docs(marketplace): reconcile spec and screen inventory with shipped checkout"
```

---

## Self-review checklist for the lane driver

Run this after Task 10 and before opening the PR.

**Requirement coverage:**

| Requirement | Where |
| --- | --- |
| 1 (nine-product catalogue) | Already shipped; untouched. `ProductCatalogueSeedTest` still guards it. |
| 2 (full per-entry data) | Task 2 — `vendor_listings`, `service_areas`, `vendor_availability` |
| 3 (browse→cart→checkout→payment/manual→processing→status) | Tasks 4, 6, 7, 8, 9 |
| 4 (one vendor per checkout, made explicit) | Tasks 4, 5 (DB trigger), 7 (modal), 8 (stated on checkout) |
| 9 (query-level authorization, no cross-vendor access) | Task 3 |
| 10 (correct `badan_usaha` and vendor allocation) | Task 6 |
| 12 (paid ≠ fulfilled) | Task 5 (`PaymentState` disjoint from `VendorProcessingStatus`), Task 9 (two indicators) |
| 13 (customer-visible vendor status) | Task 9 |
| 14 (no multi-vendor checkout) | Task 5's constraint trigger; no splitting/refund/allocation code exists |
| 15 (no land/plot marketplace) | Nothing added to the catalogue |
| 5, 6, 7, 8, 11 | **Deliberately out of scope** — future L10 vendor-portal lane |

**Before opening the PR, confirm:**
- No table named `orders` and no generic order state machine was created.
- No `marketplace_categories` table, and no category code or public slug was invented.
- No Filament panel, resource, or page exists in the diff.
- Every closed-list value comes from a class; no product code, category key, or vendor status is retyped in a view, validation rule, or fixture.
- Money never appears as a float; `Money` is used at every module boundary.
- Every pgsql-only constraint was exercised on the disposable PostgreSQL 18 container, and anything that was not is reported `NOT TESTED`.
- The disposable container was removed by exact name and `makam-nonprod-postgres-1` was never touched.

