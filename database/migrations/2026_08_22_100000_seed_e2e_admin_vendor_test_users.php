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

use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Roles\Actions\GrantActorRole;
use App\Platform\IdentityAccess\Scopes\Actions\GrantScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\IdentityAccess\Scopes\ScopeGrantLevel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    private const string ADMIN_EMAIL = 'e2e-admin@example.test';

    private const string VENDOR_EMAIL = 'e2e-vendor@example.test';

    private const string ADMIN_FINANCE_ENTITY_REF = 'e2e-admin-vendor-fixture-entity';

    public function up(): void
    {
        if (! config('e2e_fixtures.seed_admin_vendor_users')) {
            // Default-false no-op everywhere except the one CI step that
            // opts in (`SEED_E2E_ADMIN_VENDOR_USERS=true`) — see this file's
            // own doc block and `config/e2e_fixtures.php`.
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
    }

    public function down(): void
    {
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
