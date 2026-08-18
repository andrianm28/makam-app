<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\GraveRegistry\GraveRecordSource;
use App\Domain\Marketplace\ProductCode;
use App\Domain\ServiceCatalog\ServiceCode;
use App\Support\ExampleData\CemeteryExampleData;
use App\Support\ExampleData\VendorListingExampleData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `example-data:purge` — see the command's own doc block for the full
 * rationale. `RefreshDatabase` runs the real migrations before every test
 * here, which is what seeds the fixtures in the first place (this
 * codebase's data migrations ARE the seed mechanism — no `db:seed` call
 * needed to populate them, matching `VendorListingBootstrapTest`'s same
 * assumption).
 */
final class PurgeExampleDataCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_refuses_to_run_without_force(): void
    {
        $this->artisan('example-data:purge')
            ->expectsOutputToContain('Refusing to run without --force')
            ->assertExitCode(1);

        $this->assertGreaterThan(0, DB::table('cemeteries')->whereIn('slug', CemeteryExampleData::slugs())->count());
    }

    public function test_it_removes_every_fixture_cemetery_and_cascades_capability_profiles_and_packages(): void
    {
        $cemeteryIds = DB::table('cemeteries')->whereIn('slug', CemeteryExampleData::slugs())->pluck('id');
        $this->assertCount(10, $cemeteryIds, 'Precondition: the ten seeded example cemeteries exist.');
        $this->assertGreaterThan(0, DB::table('cemetery_capability_profiles')->whereIn('cemetery_id', $cemeteryIds)->count());
        $this->assertGreaterThan(0, DB::table('cemetery_packages')->whereIn('cemetery_id', $cemeteryIds)->count());

        $this->artisan('example-data:purge', ['--force' => true])->assertExitCode(0);

        $this->assertSame(0, DB::table('cemeteries')->whereIn('slug', CemeteryExampleData::slugs())->count());
        $this->assertSame(0, DB::table('cemetery_capability_profiles')->whereIn('cemetery_id', $cemeteryIds)->count());
        $this->assertSame(0, DB::table('cemetery_packages')->whereIn('cemetery_id', $cemeteryIds)->count());
    }

    public function test_it_removes_every_fixture_grave_record(): void
    {
        $this->assertGreaterThan(0, DB::table('grave_records')->where('source', GraveRecordSource::CONTOH)->count());

        $this->artisan('example-data:purge', ['--force' => true])->assertExitCode(0);

        $this->assertSame(0, DB::table('grave_records')->where('source', GraveRecordSource::CONTOH)->count());
    }

    public function test_it_removes_fixture_vendors_and_their_listings_and_service_areas(): void
    {
        $vendorNames = array_column(VendorListingExampleData::vendors(), 0);
        $vendorIds = DB::table('vendors')->whereIn('name', $vendorNames)->pluck('id');
        $this->assertCount(5, $vendorIds, 'Precondition: the five seeded example vendors exist.');
        $this->assertGreaterThan(0, DB::table('vendor_listings')->whereIn('vendor_id', $vendorIds)->count());

        $this->artisan('example-data:purge', ['--force' => true])->assertExitCode(0);

        $this->assertSame(0, DB::table('vendors')->whereIn('name', $vendorNames)->count());
        $this->assertSame(0, DB::table('vendor_listings')->whereIn('vendor_id', $vendorIds)->count());
        $this->assertSame(0, DB::table('service_areas')->whereIn('vendor_id', $vendorIds)->count());
    }

    public function test_it_resets_the_three_dummy_columns_on_products_without_deleting_the_product(): void
    {
        $codes = array_values(ProductCode::KNOWN_CODES);
        $before = DB::table('products')->whereIn('code', $codes)->whereNotNull('vendor_name')->count();
        $this->assertSame(count($codes), $before, 'Precondition: every seeded product carries the dummy vendor columns.');

        $this->artisan('example-data:purge', ['--force' => true])->assertExitCode(0);

        $this->assertSame(count($codes), DB::table('products')->whereIn('code', $codes)->count(), 'The product rows themselves — canonical catalogue — must survive.');
        $this->assertSame(0, DB::table('products')->whereIn('code', $codes)->whereNotNull('vendor_name')->count());
        $this->assertSame(0, DB::table('products')->whereIn('code', $codes)->whereNotNull('base_price_idr')->count());
        $this->assertSame(0, DB::table('products')->whereIn('code', $codes)->whereNotNull('photo_path')->count());
        $this->assertSame(
            count($codes),
            DB::table('products')->whereIn('code', $codes)->where('price_version', 1)->count(),
            'price_version resets to 1, mirroring the add-columns migration\'s own down().'
        );
    }

    /**
     * The load-bearing exclusion — see the command's own doc block for why
     * deleting service price_versions would break the entire booking
     * wizard, not just degrade gracefully like an empty cemetery/vendor
     * list does.
     */
    public function test_it_never_touches_service_definition_pricing_or_operational_semantics(): void
    {
        $codes = array_values(ServiceCode::KNOWN_CODES);
        $priceVersionsBefore = DB::table('price_versions')
            ->join('service_definitions', 'service_definitions.id', '=', 'price_versions.priceable_id')
            ->whereIn('service_definitions.code', $codes)
            ->count();
        $this->assertGreaterThan(0, $priceVersionsBefore, 'Precondition: service price_versions exist.');

        $ownerBefore = DB::table('service_definitions')
            ->where('code', ServiceCode::AMBULANCE)
            ->value('fulfillment_owner');
        $this->assertNotNull($ownerBefore);

        $this->artisan('example-data:purge', ['--force' => true])->assertExitCode(0);

        $priceVersionsAfter = DB::table('price_versions')
            ->join('service_definitions', 'service_definitions.id', '=', 'price_versions.priceable_id')
            ->whereIn('service_definitions.code', $codes)
            ->count();
        $this->assertSame($priceVersionsBefore, $priceVersionsAfter, 'Purge must never delete a price_version — every funeral service would become unpriced.');

        $this->assertSame(
            $ownerBefore,
            DB::table('service_definitions')->where('code', ServiceCode::AMBULANCE)->value('fulfillment_owner'),
            'operationalDefaults() is domain semantics, never touched by this command.'
        );
    }

    public function test_running_it_twice_is_idempotent_and_reports_nothing_to_purge_the_second_time(): void
    {
        $this->artisan('example-data:purge', ['--force' => true])->assertExitCode(0);

        $this->artisan('example-data:purge', ['--force' => true])
            ->expectsOutputToContain('Nothing to purge')
            ->assertExitCode(0);
    }

    /**
     * A real operational row (not anything an ExampleData generator would
     * create) attached to a fixture cemetery must block the WHOLE purge,
     * atomically — never a silent partial purge that destroys the fixture
     * out from under real data.
     */
    public function test_a_real_row_referencing_a_fixture_cemetery_aborts_the_whole_purge_atomically(): void
    {
        $cemeteryId = DB::table('cemeteries')->where('slug', CemeteryExampleData::slugs()[0])->value('id');

        DB::table('cemetery_blocks')->insert([
            'id' => (string) Str::uuid(),
            'cemetery_id' => $cemeteryId,
            'code' => 'REAL-BLOCK-1',
            'name' => 'Real operator-created block',
            'capacity' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $graveRecordsBefore = DB::table('grave_records')->where('source', GraveRecordSource::CONTOH)->count();

        $this->artisan('example-data:purge', ['--force' => true])
            ->expectsOutputToContain('Purge aborted and rolled back')
            ->assertExitCode(1);

        // The whole transaction rolled back -- grave_records, which would
        // have been deleted first, must still be exactly as before.
        $this->assertSame($graveRecordsBefore, DB::table('grave_records')->where('source', GraveRecordSource::CONTOH)->count());
        $this->assertNotNull(DB::table('cemeteries')->where('id', $cemeteryId)->value('id'));
    }
}
