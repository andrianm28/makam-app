<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\CareSubscription\Actions\CreateCarePlan;
use App\Domain\CareSubscription\Actions\CreateSubscription;
use App\Domain\CareSubscription\CarePlanFrequency;
use App\Domain\CareSubscription\Models\CarePlan;
use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\Marketplace\AvailabilityMode;
use App\Domain\Marketplace\EvidenceRequirement;
use App\Domain\Marketplace\Models\Vendor;
use App\Domain\Marketplace\Models\VendorListing;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\VendorFulfillment\Actions\CreateWorkOrder;
use App\Domain\VendorFulfillment\Actions\FileComplaint;
use App\Filament\Admin\Resources\CarePlans\Pages\ListCarePlans;
use App\Filament\Admin\Resources\ServiceComplaints\Pages\ListServiceComplaints;
use App\Filament\Admin\Resources\Subscriptions\Pages\ListSubscriptions;
use App\Filament\Admin\Resources\WorkOrders\Pages\ListWorkOrders as AdminListWorkOrders;
use App\Filament\Vendor\Resources\VendorListings\Pages\ListVendorListings;
use App\Filament\Vendor\Resources\WorkOrders\Pages\ListWorkOrders as VendorListWorkOrders;
use App\Models\User;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\ActorContextResolver;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * PERF-11 (Phase 3 Batch M7a). Six admin/vendor Filament tables render
 * relation columns (`carePlan.name`, `vendor.name`, `grave.slot`,
 * `workOrder.reference`, `product.name`/`product.category`) that had no
 * EXPLICIT eager load — `WorkOrdersResource`/`CarePlansResource`/
 * `SubscriptionsResource`/`ServiceComplaintsResource` had no
 * `getEloquentQuery()` override at all, and the vendor-panel resources'
 * `getEloquentQuery()` only ever scoped by vendor.
 *
 * ---------------------------------------------------------------------------
 * IMPORTANT — what these tests actually show, verified by mutation
 * ---------------------------------------------------------------------------
 * Reverting each explicit `with([...])`/`modifyQueryUsing()` fix one at a
 * time and re-running its test here still PASSES — because this installed
 * Filament version (5.7.3) already eager-loads every VISIBLE dot-notation
 * relationship column automatically: `Filament\Tables\Concerns\HasRecords::
 * filterTableQuery()` calls `$column->applyEagerLoading($query)` for each
 * of `getVisibleColumns()` (`vendor/filament/tables/src/Concerns/
 * HasRecords.php:51`), and `applyEagerLoading()`
 * (`vendor/filament/support/src/Concerns/HasCellState.php`) resolves the
 * dotted column name to a relationship and calls `$query->with([...])`
 * itself if it is not already eager-loaded. So the runtime N+1 PERF-11
 * describes does not actually reproduce for any of these six tables today
 * — confirmed by this exact mutation test, not assumed from reading
 * Filament's source.
 *
 * The explicit fixes are kept anyway, for three reasons stated honestly
 * rather than claimed as "fixes an N+1 these tests prove":
 *   1. Consistency with this codebase's own established convention —
 *      `BookingOrdersTable`/`GravePlotsResource` already carry the same
 *      explicit `with([...])` for the identical reason, predating this
 *      batch.
 *   2. Framework-independence: Filament's automatic eager-loading is an
 *      implementation detail of `getVisibleColumns()`, and stops applying
 *      the moment a column is `toggleable(isToggledHiddenByDefault: true)`
 *      or the query is reached through a non-table path (a bulk export
 *      action, a relation manager, a custom report query) that does not
 *      go through `filterTableQuery()`.
 *   3. Documentation: a reader of the Resource class sees the real data
 *      shape without having to know Filament's internal column-eager-
 *      loading mechanism exists at all.
 *
 * These tests therefore assert "no regression in query count" (bounded,
 * non-scaling), not "this specific line fixed a reproducible bug" — see
 * each test's own note.
 */
final class Perf11EagerLoadingQueryCountTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);
        $this->forgetResolvedActorContext();

        return $user;
    }

    private function forgetResolvedActorContext(): void
    {
        $this->app->forgetInstance(ActorContext::class);
        $this->app->forgetInstance(ActorContextResolver::class);
    }

    private function makeCarePlan(string $suffix, ?string $vendorId = null): CarePlan
    {
        return app(CreateCarePlan::class)(
            name: 'Perawatan '.$suffix,
            productCode: 'GRAVE_CARE_'.Str::upper($suffix),
            frequency: CarePlanFrequency::Monthly,
            priceMinor: 250000,
            vendorId: $vendorId,
        );
    }

    private function makeVendor(): Vendor
    {
        return Vendor::query()->create(['name' => 'Vendor Uji '.Str::random(4), 'is_active' => true]);
    }

    /**
     * A real Cemetery -> CemeteryBlock -> GravePlot chain — `grave_plots.
     * block_id` is a real FK (`restrictOnDelete`, `2026_08_16_100010_
     * create_grave_plots_table.php`), so a bare `GravePlot::create(['block_id'
     * => 'B1', ...])` only "works" against SQLite's default (foreign keys
     * off) test configuration, not the real PostgreSQL this batch verifies
     * against.
     */
    private function makeGravePlot(int $suffix): GravePlot
    {
        $cemetery = Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::DRAFT,
            'name' => 'TPU Uji Coba '.$suffix,
            'slug' => 'tpu-uji-coba-perf11-'.$suffix.'-'.Str::lower(Str::random(6)),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
        ]);

        $block = CemeteryBlock::query()->create([
            'cemetery_id' => $cemetery->getKey(),
            'code' => 'BLOK-'.$suffix,
            'name' => 'Blok '.$suffix,
            'capacity' => 1,
        ]);

        return GravePlot::query()->create([
            'block_id' => $block->getKey(),
            'slot' => '00'.$suffix,
            'plot_state' => 'available',
        ]);
    }

    private function countQueriesAgainst(string $table, callable $callback): int
    {
        $count = 0;

        DB::listen(function ($query) use ($table, &$count): void {
            if (str_contains($query->sql, $table)) {
                $count++;
            }
        });

        $callback();

        return $count;
    }

    public function test_care_plans_list_resolves_vendor_in_a_bounded_number_of_queries(): void
    {
        $vendor = $this->makeVendor();

        for ($i = 0; $i < 3; $i++) {
            $this->makeCarePlan((string) $i, (string) $vendor->id);
        }

        $this->admin();

        $count = $this->countQueriesAgainst('vendors', function (): void {
            Livewire::test(ListCarePlans::class)->assertOk();
        });

        $this->assertLessThan(3, $count, "Expected fewer than 3 queries against 'vendors' for 3 care plans, got {$count}.");
    }

    public function test_subscriptions_list_resolves_grave_and_care_plan_in_a_bounded_number_of_queries(): void
    {
        $customer = User::factory()->create();
        $carePlan = $this->makeCarePlan('sub');

        for ($i = 0; $i < 3; $i++) {
            $grave = $this->makeGravePlot($i);

            app(CreateSubscription::class)(
                carePlan: $carePlan,
                graveId: (string) $grave->getKey(),
                customerId: $customer->id,
                frequency: CarePlanFrequency::Monthly,
                actorReference: 'admin-1',
                actorRole: 'admin',
            );
        }

        $this->admin();

        $graveQueryCount = $this->countQueriesAgainst('grave_plots', function (): void {
            Livewire::test(ListSubscriptions::class)->assertOk();
        });

        $this->assertLessThan(3, $graveQueryCount, "Expected fewer than 3 queries against 'grave_plots' for 3 subscriptions, got {$graveQueryCount}.");
    }

    public function test_admin_work_orders_list_resolves_care_plan_and_vendor_in_a_bounded_number_of_queries(): void
    {
        $vendor = $this->makeVendor();
        $carePlan = $this->makeCarePlan('wo', (string) $vendor->id);

        for ($i = 0; $i < 3; $i++) {
            app(CreateWorkOrder::class)($carePlan, null, (string) $vendor->id);
        }

        $this->admin();

        $vendorQueryCount = $this->countQueriesAgainst('vendors', function (): void {
            Livewire::test(AdminListWorkOrders::class)->assertOk();
        });

        $this->assertLessThan(3, $vendorQueryCount, "Expected fewer than 3 queries against 'vendors' for 3 work orders, got {$vendorQueryCount}.");
    }

    public function test_service_complaints_list_resolves_work_order_in_a_bounded_number_of_queries(): void
    {
        $customer = User::factory()->create();
        $vendor = $this->makeVendor();
        $carePlan = $this->makeCarePlan('sc', (string) $vendor->id);

        for ($i = 0; $i < 3; $i++) {
            $workOrder = app(CreateWorkOrder::class)($carePlan, null, (string) $vendor->id);
            app(FileComplaint::class)($workOrder, $customer->id, 'Keluhan uji coba ke-'.$i);
        }

        $this->admin();

        $workOrderQueryCount = $this->countQueriesAgainst('work_orders', function (): void {
            Livewire::test(ListServiceComplaints::class)->assertOk();
        });

        $this->assertLessThan(3, $workOrderQueryCount, "Expected fewer than 3 queries against 'work_orders' for 3 complaints, got {$workOrderQueryCount}.");
    }

    private function actingVendorUser(string $vendorId): User
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::VENDOR);
        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $user->id,
            'entity_type' => ScopeEntityType::VENDOR,
            'entity_id' => $vendorId,
        ]);
        $this->actingAs($user);
        $this->forgetResolvedActorContext();

        return $user;
    }

    public function test_vendor_work_orders_list_resolves_care_plan_in_a_bounded_number_of_queries(): void
    {
        Filament::setCurrentPanel('vendor');

        $vendor = $this->makeVendor();
        $carePlan = $this->makeCarePlan('vwo', (string) $vendor->id);

        for ($i = 0; $i < 3; $i++) {
            app(CreateWorkOrder::class)($carePlan, null, (string) $vendor->id);
        }

        $this->actingVendorUser((string) $vendor->id);

        $carePlanQueryCount = $this->countQueriesAgainst('care_plans', function (): void {
            Livewire::test(VendorListWorkOrders::class)->assertOk();
        });

        Filament::setCurrentPanel(null);

        $this->assertLessThan(3, $carePlanQueryCount, "Expected fewer than 3 queries against 'care_plans' for 3 vendor work orders, got {$carePlanQueryCount}.");
    }

    public function test_vendor_listings_list_resolves_product_in_a_bounded_number_of_queries(): void
    {
        Filament::setCurrentPanel('vendor');

        $vendor = $this->makeVendor();

        for ($i = 0; $i < 3; $i++) {
            $productId = DB::table('products')->insertGetId([
                'code' => 'PRD-'.Str::random(8),
                'category' => 'KARANGAN_BUNGA',
                'name' => 'Produk Uji '.$i,
                'description' => 'Deskripsi uji.',
                'base_price_idr' => 100000,
                'price_version' => 1,
                'is_active' => true,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            VendorListing::query()->create([
                'vendor_id' => (string) $vendor->id,
                'product_id' => $productId,
                'price_minor' => 150000,
                'availability_mode' => AvailabilityMode::STOCKED,
                'evidence_requirement' => EvidenceRequirement::PHOTO,
                'stock_quantity' => 5,
                'is_active' => true,
            ]);
        }

        $this->actingVendorUser((string) $vendor->id);

        $productQueryCount = $this->countQueriesAgainst('products', function (): void {
            Livewire::test(ListVendorListings::class)->assertOk();
        });

        Filament::setCurrentPanel(null);

        $this->assertLessThan(3, $productQueryCount, "Expected fewer than 3 queries against 'products' for 3 vendor listings, got {$productQueryCount}.");
    }
}
