<?php

declare(strict_types=1);

/**
 * ===========================================================================
 * THIS IS DUMMY / PLACEHOLDER DATA. NONE OF THE FOLLOWING IS REAL.
 * ===========================================================================
 * Two fictional throwaway login accounts for the E2E-ADMIN/VENDOR browser
 * suite (`tests/browser/e2e-admin-vendor.spec.ts`) — the `@example.test`
 * domain and fixed, published-in-this-repo passwords make it unmistakable
 * these are not real credentials, matching `RECIPIENT`'s convention in
 * `tests/browser/e2e-marketplace.spec.ts` and this migration file's own
 * "THIS IS DUMMY DATA" convention from `2026_08_14_100000_seed_vendors_and_
 * listings.php`.
 *
 * ---------------------------------------------------------------------------
 * Why a data migration and not `database/seeders/`
 * ---------------------------------------------------------------------------
 * Nothing in CI, the Dockerfile, or any deployment script runs
 * `php artisan db:seed` — every fixture dataset in this repository ships as
 * a timestamped data migration instead (same reasoning as the vendor/
 * listing seed migration this one depends on).
 *
 * ---------------------------------------------------------------------------
 * Why `GrantActorRole`/`GrantScopeAssignment`, not a raw `create()`
 * ---------------------------------------------------------------------------
 * Both Actions are this codebase's only sanctioned write path onto
 * `actor_role_assignments`/`scope_assignments` (see each Action's own doc
 * block) — real audited grants, not a test-only bypass. Calling them here
 * keeps the seeded state indistinguishable, from the application's own
 * authorization code's point of view, from a real operator-run
 * `identity:grant-role`/`identity:grant-scope` grant. `grantedBy: null`
 * matches every other genuinely-unattended, no-human-operator write in
 * this codebase (`ActorRole::SYSTEM` is already the sentinel for exactly
 * this case).
 *
 * ---------------------------------------------------------------------------
 * `e2e-admin` also holds `finance` + a privileged BUSINESS_ENTITY grant
 * ---------------------------------------------------------------------------
 * Task 2 of this suite's plan discovered, live, that plain `ActorRole::ADMIN`
 * is not enough to exercise every "required dashboard module": `FinanceLedgerReadAuthorizer`
 * (`app/Platform/FinancialLedger/FinanceLedgerReadAuthorizer.php`) gates
 * `FinancialOverviewWidget`, `FailedPaymentExceptionQueueWidget`, and 3 of 6
 * admin report pages (`finance-reports`, `receipts-report`,
 * `outgoing-payments-report`) behind the real `finance` role PLUS a
 * non-revoked `ScopeGrantLevel::PRIVILEGED` `BUSINESS_ENTITY`-scoped grant —
 * a strictly narrower gate than the four-role master-data authorizer the
 * rest of the dashboard uses. Without both, this suite could only prove
 * those modules are correctly DENIED, not that they render, which falls
 * short of the suite's own "all required dashboard modules" AC. `e2e-admin`
 * is therefore also granted `ActorRole::FINANCE` and a privileged
 * `BUSINESS_ENTITY` scope against a clearly-fake reference
 * (`'e2e-admin-vendor-fixture-entity'` — no `business_entities` table exists
 * in this codebase; the entity_id is a free-form reference string, matching
 * `FinancialOverviewWidgetTest`'s own `grant()` helper and the
 * `MARKETPLACE_BADAN_USAHA_REF` fake-reference convention already used
 * elsewhere in this repo's CI). This is a throwaway fixture login with no
 * real separation-of-duties concern, not a change to any real authorization
 * policy.
 *
 * ---------------------------------------------------------------------------
 * The vendor scope's `entity_id` is looked up, never hardcoded
 * ---------------------------------------------------------------------------
 * `2026_08_14_100000_seed_vendors_and_listings.php` seeds vendor rows with
 * auto-incrementing ids that are not guaranteed stable across a from-
 * scratch migration run in a different environment (fresh CI database vs.
 * a long-lived dev database with extra vendors added later) — this
 * migration queries the real, current first `vendors` row at migration
 * time instead of assuming an id.
 *
 * Migration timestamp slot: `2026_08_22_100000`, after every existing
 * identity/vendor migration.
 *
 * ---------------------------------------------------------------------------
 * Task 4 addition — two `vendor_orders` fixture rows for a real scoping proof
 * ---------------------------------------------------------------------------
 * `/vendor/transactions` (`App\Filament\Vendor\Pages\TransactionHistory`)
 * scopes its query to `CurrentVendorScope::grantedVendorIds()` — already
 * proven correct at the query level for two vendors by
 * `tests/Feature/Filament/Vendor/VendorPanelScopingTest`. But `vendor_orders`
 * has ZERO seed rows anywhere in this codebase before this addition (checked:
 * no factory exists for `VendorOrder`, and `VendorListingExampleData` seeds
 * only `vendors`/`vendor_listings`/`service_areas`, never orders) — so a
 * fresh `migrate:fresh` left the browser suite's own vendor with nothing to
 * assert scoping against beyond "the empty state renders", which is exactly
 * the vacuous 0-vs-0 comparison this suite already declined to fake for
 * `/admin/reconciliations` (see the second `test.describe` block's header
 * comment in `tests/browser/e2e-admin-vendor.spec.ts`).
 *
 * Unlike that Reconciliation case, a `VendorOrder` row is cheap and safe to
 * fixture — no complex domain Action is required, `VendorPanelScopingTest`'s
 * own `makeVendorWithRecords()` helper already creates one with a plain
 * `DB::table('vendor_orders')->insert()`-shaped write, and every vendor this
 * migration could pick already has at least one real `vendor_listings` row
 * (`VendorListingExampleData::VENDOR_COUNT = 5`, every vendor index gets ≥1
 * listing). So this migration adds exactly two throwaway orders, each
 * against a real existing listing, real existing vendor:
 *
 *   - one for `$firstVendorId` (the same vendor `e2e-vendor` is granted,
 *     looked up the same way that grant already is, immediately above);
 *   - one for a DIFFERENT, ungranted vendor (`orderBy('id')` excluding
 *     `$firstVendorId`, so it is deterministic within a single migration run
 *     without assuming which of the 5 seeded vendors ends up "first" — see
 *     the note above on why that id is looked up rather than assumed).
 *
 * Each order carries a distinct, unmistakable `customer_name` marker so the
 * browser suite can assert the granted vendor's order is visible and the
 * OTHER vendor's order is NOT — a real presence/absence proof on the page's
 * own rendered "Pelanggan" column, not an invented row count. Both are
 * additive, config-gated the same as everything else in this file, and
 * idempotency-guarded on their own `customer_email` marker.
 *
 * ---------------------------------------------------------------------------
 * Gated behind `config('e2e_fixtures.seed_admin_vendor_users')`, default false
 * ---------------------------------------------------------------------------
 * `RefreshDatabase` applies EVERY migration once per PHPUnit process, not
 * scoped to the test class under test — so an unconditional `up()` here would
 * permanently write real rows into `actor_role_assignments`/
 * `scope_assignments`/`audit_events` for every unrelated Feature test in the
 * same process. Confirmed the hard way 21 Aug 2026: 8+ pre-existing tests
 * broke, including `PurgeStaleBookingDraftsTest` and
 * `ServiceCatalogAuditIntegrationTest`, both of which assert exact/zero
 * counts on those tables. `config/e2e_fixtures.php` explains why this is a
 * dedicated env flag and not `app()->environment('testing')` — same
 * collision-with-`phpunit.xml` reasoning `THROTTLE_PUBLIC_GUEST_DISABLED`
 * (`config/rate_limiting.php`) already hit and solved.
 */

use App\Domain\Marketplace\VendorProcessingStatus;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Roles\Actions\GrantActorRole;
use App\Platform\IdentityAccess\Scopes\Actions\GrantScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\IdentityAccess\Scopes\ScopeGrantLevel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const string ADMIN_EMAIL = 'e2e-admin@example.test';

    private const string VENDOR_EMAIL = 'e2e-vendor@example.test';

    private const string ADMIN_FINANCE_ENTITY_REF = 'e2e-admin-vendor-fixture-entity';

    /**
     * Distinct markers for the two Task 4 `vendor_orders` fixture rows — see
     * this file's "Task 4 addition" doc block above. Names carry the "E2E"
     * and "Contoh" fabricated-data markers this repo's fixtures already use
     * (`VendorListingExampleData`'s own doc block); emails double as the
     * idempotency guard so a re-run never inserts a duplicate.
     */
    private const string OWN_VENDOR_ORDER_CUSTOMER_NAME = 'Pelanggan Contoh E2E (Vendor Tertaut)';

    private const string OWN_VENDOR_ORDER_CUSTOMER_EMAIL = 'e2e-vendor-scope-own@example.test';

    private const string OTHER_VENDOR_ORDER_CUSTOMER_NAME = 'Pelanggan Contoh E2E (Vendor Lain)';

    private const string OTHER_VENDOR_ORDER_CUSTOMER_EMAIL = 'e2e-vendor-scope-other@example.test';

    public function up(): void
    {
        if (! config('e2e_fixtures.seed_admin_vendor_users')) {
            // Default-false no-op everywhere except the one CI step that
            // opts in (`SEED_E2E_ADMIN_VENDOR_USERS=true`) — see this file's
            // own doc block and `config/e2e_fixtures.php`.
            return;
        }

        if (app()->isProduction()) {
            // Defence-in-depth, independent of the config flag above: this
            // migration seeds a privileged admin+finance login with a
            // password published in this repo, so a second, unconditional
            // guard refuses to run in production even if
            // SEED_E2E_ADMIN_VENDOR_USERS were ever mistakenly set true on a
            // production deploy.
            return;
        }

        $admin = User::query()->firstOrCreate(
            ['email' => self::ADMIN_EMAIL],
            [
                'name' => 'E2E Admin (Contoh)',
                'password' => Hash::make('E2eAdminPassword!1'),
                'email_verified_at' => now(),
            ],
        );

        if (! DB::table('actor_role_assignments')->where('actor_identifier', (string) $admin->id)->where('role', ActorRole::ADMIN)->exists()) {
            app(GrantActorRole::class)(
                actorIdentifier: $admin->id,
                role: ActorRole::ADMIN,
                reason: 'E2E-ADMIN/VENDOR suite seed — throwaway CI/dev login, not a real operator grant.',
                grantedBy: null,
            );
        }

        if (! DB::table('actor_role_assignments')->where('actor_identifier', (string) $admin->id)->where('role', ActorRole::FINANCE)->exists()) {
            app(GrantActorRole::class)(
                actorIdentifier: $admin->id,
                role: ActorRole::FINANCE,
                reason: 'E2E-ADMIN/VENDOR suite seed — grants finance-ledger read access so all required dashboard modules/reports are testable.',
                grantedBy: null,
            );
        }

        if (! DB::table('scope_assignments')
            ->where('actor_identifier', (string) $admin->id)
            ->where('entity_type', ScopeEntityType::BUSINESS_ENTITY)
            ->where('entity_id', self::ADMIN_FINANCE_ENTITY_REF)
            ->exists()
        ) {
            app(GrantScopeAssignment::class)(
                actorIdentifier: $admin->id,
                entityType: ScopeEntityType::BUSINESS_ENTITY,
                entityId: self::ADMIN_FINANCE_ENTITY_REF,
                grantLevel: ScopeGrantLevel::PRIVILEGED,
                reason: 'E2E-ADMIN/VENDOR suite seed — grants finance-ledger read access so all required dashboard modules/reports are testable.',
                grantedBy: null,
            );
        }

        $vendor = User::query()->firstOrCreate(
            ['email' => self::VENDOR_EMAIL],
            [
                'name' => 'E2E Vendor (Contoh)',
                'password' => Hash::make('E2eVendorPassword!1'),
                'email_verified_at' => now(),
            ],
        );

        if (! DB::table('actor_role_assignments')->where('actor_identifier', (string) $vendor->id)->where('role', ActorRole::VENDOR)->exists()) {
            app(GrantActorRole::class)(
                actorIdentifier: $vendor->id,
                role: ActorRole::VENDOR,
                reason: 'E2E-ADMIN/VENDOR suite seed — throwaway CI/dev login, not a real operator grant.',
                grantedBy: null,
            );
        }

        $firstVendorId = DB::table('vendors')->orderBy('id')->value('id');

        if ($firstVendorId !== null
            && ! DB::table('scope_assignments')
                ->where('actor_identifier', (string) $vendor->id)
                ->where('entity_type', ScopeEntityType::VENDOR)
                ->where('entity_id', (string) $firstVendorId)
                ->exists()
        ) {
            app(GrantScopeAssignment::class)(
                actorIdentifier: $vendor->id,
                entityType: ScopeEntityType::VENDOR,
                entityId: $firstVendorId,
                grantLevel: null,
                reason: 'E2E-ADMIN/VENDOR suite seed — scopes the throwaway vendor login to the first seeded vendor.',
                grantedBy: null,
            );
        }

        // See this file's "Task 4 addition" doc block above: two throwaway
        // vendor_orders rows so /vendor/transactions has something real to
        // assert scoping against, instead of an empty-state-only page.
        if ($firstVendorId !== null) {
            $this->seedVendorOrderFixture(
                vendorId: (string) $firstVendorId,
                customerName: self::OWN_VENDOR_ORDER_CUSTOMER_NAME,
                customerEmail: self::OWN_VENDOR_ORDER_CUSTOMER_EMAIL,
            );

            $otherVendorId = DB::table('vendors')
                ->where('id', '!=', $firstVendorId)
                ->orderBy('id')
                ->value('id');

            if ($otherVendorId !== null) {
                $this->seedVendorOrderFixture(
                    vendorId: (string) $otherVendorId,
                    customerName: self::OTHER_VENDOR_ORDER_CUSTOMER_NAME,
                    customerEmail: self::OTHER_VENDOR_ORDER_CUSTOMER_EMAIL,
                );
            }
        }
    }

    /**
     * Inserts one throwaway `vendor_orders` row against a real listing that
     * already belongs to `$vendorId` — skipped (never failing) if that vendor
     * has no listing, matching this file's existing "skip rather than block a
     * real deployment" convention for fixture data (see
     * `VendorListingExampleData::seed()`'s identical guard). A raw
     * `DB::table()->insert()`, not `VendorOrder::create()`: this is ordinary
     * fixture business data, not an audited identity/scope grant, so it does
     * not go through a sanctioned Action the way the role/scope grants above
     * do — the same distinction this file's own "why GrantActorRole, not a
     * raw create()" doc block draws.
     */
    private function seedVendorOrderFixture(string $vendorId, string $customerName, string $customerEmail): void
    {
        if (DB::table('vendor_orders')->where('customer_email', $customerEmail)->exists()) {
            return;
        }

        $listingId = DB::table('vendor_listings')->where('vendor_id', $vendorId)->value('id');

        if ($listingId === null) {
            return;
        }

        DB::table('vendor_orders')->insert([
            'uuid' => (string) Str::uuid(),
            'vendor_id' => $vendorId,
            'listing_id' => $listingId,
            'customer_name' => $customerName,
            'customer_phone' => '081200000000',
            'customer_email' => $customerEmail,
            'status' => VendorProcessingStatus::MENUNGGU_VENDOR,
            'notes' => 'E2E-ADMIN/VENDOR suite seed — throwaway fixture order for the transaction-history scoping test, not a real order.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Deliberately does NOT delete the `audit_events` rows GrantActorRole/
        // GrantScopeAssignment wrote above — an audit log is intentionally
        // not erasable by a migration rollback, the same as it isn't erasable
        // by any other actor in this codebase.
        DB::table('vendor_orders')->whereIn('customer_email', [
            self::OWN_VENDOR_ORDER_CUSTOMER_EMAIL,
            self::OTHER_VENDOR_ORDER_CUSTOMER_EMAIL,
        ])->delete();

        $admin = User::query()->where('email', self::ADMIN_EMAIL)->first();
        $vendor = User::query()->where('email', self::VENDOR_EMAIL)->first();

        foreach ([$admin, $vendor] as $user) {
            if ($user === null) {
                continue;
            }

            DB::table('scope_assignments')->where('actor_identifier', (string) $user->id)->delete();
            DB::table('actor_role_assignments')->where('actor_identifier', (string) $user->id)->delete();
            $user->delete();
        }
    }
};
