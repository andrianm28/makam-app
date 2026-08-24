<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\IdentityAccess\Scopes\ScopeGrantLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * `RefreshDatabase` applies every migration once per PHPUnit process,
 * including this one — so the migration itself is gated behind
 * `config('e2e_fixtures.seed_admin_vendor_users')` (default false; see
 * `config/e2e_fixtures.php`) and is a no-op during the ambient migrate that
 * happens in `setUp()`. Every positive-path test here therefore sets the
 * flag explicitly and invokes `up()` directly — mirroring how
 * `Tests\Feature\RateLimiting\PublicGuestThrottleTest` verifies
 * `THROTTLE_PUBLIC_GUEST_DISABLED` explicitly rather than relying on
 * ambient environment state — instead of relying on the implicit full-suite
 * migrate to have seeded anything.
 */
final class SeedE2eAdminVendorTestUsersTest extends TestCase
{
    use RefreshDatabase;

    private const string MIGRATION_PATH = 'migrations/2026_08_22_110000_seed_e2e_admin_vendor_test_users.php';

    public function test_it_seeds_a_logged_in_able_admin_user(): void
    {
        config(['e2e_fixtures.seed_admin_vendor_users' => true]);
        (require database_path(self::MIGRATION_PATH))->up();

        $admin = User::query()->where('email', 'e2e-admin@example.test')->first();

        $this->assertNotNull($admin, 'expected the E2E-seeded admin user to exist after migration');
        $this->assertTrue(Hash::check('E2eAdminPassword!1', $admin->password));

        $this->assertDatabaseHas('actor_role_assignments', [
            'actor_identifier' => (string) $admin->id,
            'role' => ActorRole::ADMIN,
        ]);
    }

    /**
     * `FinanceLedgerReadAuthorizer` gates `FinancialOverviewWidget`,
     * `FailedPaymentExceptionQueueWidget`, and 3 of 6 admin report pages
     * behind the real `finance` role PLUS a non-revoked
     * `ScopeGrantLevel::PRIVILEGED` `BUSINESS_ENTITY`-scoped grant — a
     * strictly narrower gate than the four-role master-data authorizer
     * plain `ActorRole::ADMIN` satisfies. Without both, this suite could
     * only prove those modules are correctly denied, not that they render.
     */
    public function test_it_grants_the_admin_user_finance_ledger_read_access(): void
    {
        config(['e2e_fixtures.seed_admin_vendor_users' => true]);
        (require database_path(self::MIGRATION_PATH))->up();

        $admin = User::query()->where('email', 'e2e-admin@example.test')->first();

        $this->assertNotNull($admin);

        $this->assertDatabaseHas('actor_role_assignments', [
            'actor_identifier' => (string) $admin->id,
            'role' => ActorRole::FINANCE,
        ]);

        $this->assertDatabaseHas('scope_assignments', [
            'actor_identifier' => (string) $admin->id,
            'entity_type' => ScopeEntityType::BUSINESS_ENTITY,
            'entity_id' => 'e2e-admin-vendor-fixture-entity',
            'grant_level' => ScopeGrantLevel::PRIVILEGED,
        ]);
    }

    public function test_it_seeds_a_logged_in_able_vendor_user_scoped_to_a_real_vendor(): void
    {
        config(['e2e_fixtures.seed_admin_vendor_users' => true]);
        (require database_path(self::MIGRATION_PATH))->up();

        $vendor = User::query()->where('email', 'e2e-vendor@example.test')->first();

        $this->assertNotNull($vendor, 'expected the E2E-seeded vendor user to exist after migration');
        $this->assertTrue(Hash::check('E2eVendorPassword!1', $vendor->password));

        $this->assertDatabaseHas('actor_role_assignments', [
            'actor_identifier' => (string) $vendor->id,
            'role' => ActorRole::VENDOR,
        ]);

        $scope = ScopeAssignment::query()
            ->where('actor_identifier', (string) $vendor->id)
            ->where('entity_type', ScopeEntityType::VENDOR)
            ->first();

        $this->assertNotNull($scope, 'expected the E2E-seeded vendor user to hold a vendor-entity scope assignment');
        $this->assertNotNull(
            DB::table('vendors')->where('id', $scope->entity_id)->first(),
            'the scope assignment must point at a real, existing vendor row'
        );
    }

    /**
     * `/vendor/transaksi` (`App\Filament\Vendor\Pages\TransactionHistory`)
     * scopes its query to the vendor `e2e-vendor` is granted — this proves
     * the migration gives that test something real to assert against: one
     * order belonging to the granted vendor, and one belonging to a
     * DIFFERENT vendor, so the browser suite can assert the first is visible
     * and the second is not. See the migration's own "Task 4 addition" doc
     * block for why `vendor_orders` needed fixture rows at all (no seed path
     * for that table existed anywhere in this codebase before this).
     */
    public function test_it_seeds_a_vendor_order_for_the_granted_vendor_and_a_different_one_for_another_vendor(): void
    {
        config(['e2e_fixtures.seed_admin_vendor_users' => true]);
        (require database_path(self::MIGRATION_PATH))->up();

        $vendor = User::query()->where('email', 'e2e-vendor@example.test')->first();
        $this->assertNotNull($vendor);

        $grantedVendorId = ScopeAssignment::query()
            ->where('actor_identifier', (string) $vendor->id)
            ->where('entity_type', ScopeEntityType::VENDOR)
            ->value('entity_id');

        $this->assertNotNull($grantedVendorId);

        $ownOrder = DB::table('vendor_orders')
            ->where('customer_email', 'e2e-vendor-scope-own@example.test')
            ->first();

        $this->assertNotNull($ownOrder, 'expected a fixture vendor_orders row for the granted vendor');
        $this->assertSame(
            $grantedVendorId,
            $ownOrder->vendor_id,
            'the "own" fixture order must belong to the vendor e2e-vendor is actually granted'
        );

        $otherOrder = DB::table('vendor_orders')
            ->where('customer_email', 'e2e-vendor-scope-other@example.test')
            ->first();

        $this->assertNotNull($otherOrder, 'expected a fixture vendor_orders row for a different, ungranted vendor');
        $this->assertNotSame(
            $grantedVendorId,
            $otherOrder->vendor_id,
            'the "other" fixture order must belong to a vendor e2e-vendor is NOT granted, or the scoping proof is vacuous'
        );
    }

    public function test_it_is_idempotent_on_a_re_run(): void
    {
        config(['e2e_fixtures.seed_admin_vendor_users' => true]);

        // Re-running up() a second time (the real thing that happens if a CI
        // runner ever re-migrates without a fresh database) must not throw a
        // duplicate-key error.
        $migration = require database_path(self::MIGRATION_PATH);

        $migration->up();
        $migration->up();

        $this->assertSame(
            1,
            User::query()->where('email', 'e2e-admin@example.test')->count(),
            're-running the migration must not create a duplicate admin user'
        );

        $this->assertSame(
            1,
            DB::table('vendor_orders')->where('customer_email', 'e2e-vendor-scope-own@example.test')->count(),
            're-running the migration must not duplicate the "own" fixture order'
        );
        $this->assertSame(
            1,
            DB::table('vendor_orders')->where('customer_email', 'e2e-vendor-scope-other@example.test')->count(),
            're-running the migration must not duplicate the "other" fixture order'
        );
    }

    /**
     * This is the test that actually protects the pre-existing suite
     * (`PurgeStaleBookingDraftsTest`, `ServiceCatalogAuditIntegrationTest`,
     * and others that assert exact/zero counts on
     * `actor_role_assignments`/`scope_assignments`/`audit_events`) going
     * forward: with the flag left at its real default (unset — false),
     * `up()` must write nothing at all.
     */
    public function test_it_is_a_no_op_when_the_flag_is_left_at_its_default(): void
    {
        $this->assertFalse(
            config('e2e_fixtures.seed_admin_vendor_users'),
            'this test only proves anything if the flag is genuinely at its default-false value'
        );

        (require database_path(self::MIGRATION_PATH))->up();

        $this->assertDatabaseMissing('users', ['email' => 'e2e-admin@example.test']);
        $this->assertDatabaseMissing('users', ['email' => 'e2e-vendor@example.test']);
        $this->assertDatabaseMissing('actor_role_assignments', ['role' => ActorRole::ADMIN]);
        $this->assertDatabaseMissing('actor_role_assignments', ['role' => ActorRole::VENDOR]);
        $this->assertDatabaseMissing('actor_role_assignments', ['role' => ActorRole::FINANCE]);
        $this->assertDatabaseCount('scope_assignments', 0);
        $this->assertDatabaseMissing('vendor_orders', ['customer_email' => 'e2e-vendor-scope-own@example.test']);
        $this->assertDatabaseMissing('vendor_orders', ['customer_email' => 'e2e-vendor-scope-other@example.test']);
    }
}
