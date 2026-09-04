<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\CareSubscription\Models\Subscription;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\Marketplace\Models\MarketplaceOrder;
use App\Domain\Marketplace\Models\Vendor;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\Renewal\Models\Renewal;
use App\Domain\Visitation\Models\CemeteryVisitationPolicy;
use App\Models\DemoDataBatch;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class DemoDataPurgeCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_purge_without_force_refuses(): void
    {
        $this->artisan('demo-data:purge')->assertFailed();
    }

    /**
     * Every table this subsystem's generators write to, tagged or not —
     * this is the real proof the FK-chain deletion logic in
     * `DemoDataPurgeCommand` (`deleteVendorScopedTables()`,
     * `deleteOrderScopedTables()`, `deleteCareSubscriptionScopedTables()`)
     * works, not just the tables carrying a `demo_batch_id` column. A test
     * that only checked the tagged tables would miss exactly the class of
     * bug (an orphaned untagged child row) this task exists to prevent.
     */
    public function test_seed_then_purge_returns_the_database_to_its_pre_seed_state(): void
    {
        $tables = [
            'cemeteries', 'orders', 'renewals', 'vendors', 'users', 'vendor_users',
            'care_plans', 'subscriptions', 'subscription_cycles', 'subscription_invoices',
            'subscription_payment_references', 'work_orders', 'work_order_tasks',
            'work_evidence', 'service_acceptances', 'service_complaints',
            'marketplace_orders', 'vendor_orders', 'marketplace_order_items',
            'vendor_order_evidences', 'vendor_listings', 'service_areas',
            'agreements', 'certificates', 'visitation_bookings',
            'cemetery_visitation_policies', 'visitation_date_capacities', 'booking_drafts',
            'scope_assignments', 'actor_role_assignments',
            'order_status_events', 'order_parties', 'quotes', 'quote_lines',
            'order_invoices', 'funeral_cases', 'renewal_quotes',
            'renewal_external_markings', 'vendor_payables',
            'demo_data_batches',
        ];

        $before = collect($tables)->mapWithKeys(
            static fn (string $table): array => [$table => DB::table($table)->count()]
        )->all();

        $this->artisan('demo-data:seed')->assertSuccessful();
        $this->artisan('demo-data:purge --force')->assertSuccessful();

        foreach ($tables as $table) {
            $this->assertSame(
                $before[$table],
                DB::table($table)->count(),
                "table [{$table}] did not return to its pre-seed row count"
            );
        }
    }

    /**
     * `subscriptions.care_plan_id` is the only real FK constraint pointing
     * at `care_plans` — confirmed against
     * `database/migrations/2026_08_17_110010_create_subscriptions_table.php`
     * (`work_orders.care_plan_id` is a plain `foreignUuid()` column with no
     * `->constrained()`, so it carries no DB-level constraint and does not
     * need dropping first). Dropping that FK then the table itself — the
     * same two-step technique
     * `BookingWizardDegradedReadsTest::makeServiceCatalogUnreadable()`
     * established for `service_definitions` — makes the very first write
     * `CareSubscriptionExampleData::seed()` performs after creating its own
     * demo vendor (`CreateCarePlan`'s insert into `care_plans`) fail with a
     * real "relation does not exist" error from Postgres.
     *
     * `care_subscriptions` is the LAST domain `DemoDataSeedCommand::handle()`
     * runs before this failure point — vendor accounts, cemetery operator,
     * booking orders, renewals, and marketplace orders have each already
     * committed in their own `DB::transaction()` by the time the command
     * reaches it, and the dedicated customer `User` row is created directly
     * in `handle()` (outside any domain transaction) immediately before
     * care-subscription seeding starts. Every one of those must survive;
     * only `care_plans`/`subscriptions` (this failing domain's own
     * transaction, including the demo vendor it creates first) must not.
     *
     * `DemoDataSeedCommand::runDomain()` re-throws after logging, so the
     * exception propagates out of `handle()` itself rather than the
     * command returning `self::FAILURE`. `$this->artisan(...)` drives the
     * command through `Illuminate\Foundation\Console\Kernel::call()`
     * (`Illuminate\Testing\PendingCommand::run()`), which is
     * `Artisan::call()` under the hood — unlike the real CLI entry point's
     * `Symfony\Component\Console\Application::run()`, it does not catch a
     * generic `Throwable` into an exit code, so `->assertFailed()` cannot
     * be used here (confirmed by running this test: the exception surfaces
     * as an uncaught PHPUnit error, not a failed assertion). The exception
     * itself must be caught directly instead.
     */
    public function test_a_forced_mid_run_domain_failure_leaves_earlier_domains_data_intact(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropForeign(['care_plan_id']);
        });
        Schema::dropIfExists('care_plans');

        try {
            $this->artisan('demo-data:seed')->run();
            $this->fail('Expected demo-data:seed to throw once care_plans is missing.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('care_plans', $exception->getMessage());
        }

        $this->assertSame(5, Order::query()->whereNotNull('demo_batch_id')->count());
        $this->assertSame(3, Renewal::query()->whereNotNull('demo_batch_id')->count());
        $this->assertSame(2, MarketplaceOrder::query()->whereNotNull('demo_batch_id')->count());
        $this->assertSame(2, Vendor::query()->whereNotNull('demo_batch_id')->count());
        $this->assertSame(4, User::query()->whereNotNull('demo_batch_id')->count());

        // The failing domain's own transaction rolled back cleanly: its
        // vendor never persisted, and `subscriptions` — the table whose FK
        // this test dropped — stayed empty. `care_plans` itself no longer
        // exists (dropped above), so there is nothing to assert against it.
        $this->assertSame(0, DB::table('subscriptions')->count());
        $this->assertSame(0, Subscription::query()->count());

        // I2: the `demo_data_batches` row is written UP FRONT, before any
        // domain runs, specifically so a partial failure like this one
        // still leaves the batch id discoverable (`demo-data:purge`'s
        // no-argument "most recent batch" lookup reads this table) —
        // see `DemoDataSeedCommand::handle()`'s own comment. `summary`
        // stays null: it is only filled in once every domain has actually
        // succeeded, which never happened here.
        $this->assertSame(1, DemoDataBatch::query()->count());
        $this->assertNull(DemoDataBatch::query()->value('summary'));
    }

    /**
     * C2 regression test: proves `demo-data:seed` + `demo-data:purge
     * --force` never adopts, tags, or deletes a REAL, pre-existing
     * `CemeteryVisitationPolicy` — the exact live-host defect this test
     * exists to catch. `VisitationExampleData` used to be handed whatever
     * cemetery `DemoDataSeedCommand` selected via
     * `Cemetery::query()->firstOrFail()` — an arbitrary, possibly-real
     * cemetery — and `firstOrCreate()` there returns (and then tags) an
     * EXISTING policy rather than creating a new one when one already
     * exists for that cemetery. `cemetery_visitation_policies` sits in
     * `DemoDataPurgeCommand::DELETE_ORDER_BEFORE_VENDOR_CHILDREN`, so a
     * purge would delete this real, pre-existing policy (and cascade its
     * capacity/blackout rows) as a side effect of removing demo data.
     *
     * `Cemetery::query()->firstOrFail()` below deliberately mirrors the
     * exact pre-fix selection query — this test creates its policy on
     * whichever cemetery that arbitrary query resolves to (one of the real,
     * migration-seeded example cemeteries; no writes to `cemeteries` happen
     * between this call and `demo-data:seed`'s own internal selection, so
     * both queries see the same unchanged table and resolve to the same
     * row), so the test reproduces the exact real-world collision rather
     * than a synthetic one.
     */
    public function test_purge_never_deletes_a_real_pre_existing_visitation_policy(): void
    {
        $targetCemetery = Cemetery::query()->firstOrFail();

        // Every weekday open — `RequestVisitation` throws
        // `VisitationClosedDayException` for a date the policy's own
        // `operating_hours` marks closed, so a narrower policy (e.g. only
        // `mon`) would make `demo-data:seed`'s own visitation step fail for
        // an unrelated reason (an arbitrarily-chosen visit date landing on
        // a day this policy has closed) rather than exercising the real
        // purge-deletion risk this test targets.
        $policy = CemeteryVisitationPolicy::query()->create([
            'cemetery_id' => $targetCemetery->id,
            'operating_hours' => [
                'mon' => ['open' => '08:00', 'close' => '17:00'],
                'tue' => ['open' => '08:00', 'close' => '17:00'],
                'wed' => ['open' => '08:00', 'close' => '17:00'],
                'thu' => ['open' => '08:00', 'close' => '17:00'],
                'fri' => ['open' => '08:00', 'close' => '17:00'],
                'sat' => ['open' => '08:00', 'close' => '15:00'],
                'sun' => ['open' => '08:00', 'close' => '15:00'],
            ],
            'daily_capacity' => 7,
        ]);

        $this->artisan('demo-data:seed')->assertSuccessful();
        $this->artisan('demo-data:purge --force')->assertSuccessful();

        $fresh = $policy->fresh();
        $this->assertNotNull($fresh, 'a real, pre-existing visitation policy must survive demo-data:seed + demo-data:purge');
        $this->assertNull($fresh->demo_batch_id, 'a real, pre-existing visitation policy must never be tagged as demo data');
        $this->assertSame(7, $fresh->daily_capacity);
    }
}
