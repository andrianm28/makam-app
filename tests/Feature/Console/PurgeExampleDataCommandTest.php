<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\GraveRegistry\GraveNameNormalizer;
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

    /**
     * The regression this file could not previously express.
     *
     * Every other test here builds its fixture with `CemeteryExampleData` and
     * then asserts against `CemeteryExampleData` — the same function on both
     * sides of the equation. That proves the command deletes what the
     * generator produces TODAY, which is the one case that never fails. It
     * cannot prove anything about a row seeded by an EARLIER generator, and
     * that is the only case that occurs in a real environment: data is
     * written once, by whichever version was live that day, and stays.
     *
     * It is not hypothetical. Commit `15075d8e` (13 Aug 2026) replaced the
     * literal fixture rows with generated ones, and both live environments
     * had been seeded on 26 Jul. From that commit until this test, dev and
     * beta each held ten fabricated cemeteries that `example-data:purge`
     * deleted zero of, while exiting 0.
     *
     * So this test constructs its row BY HAND, with a slug deliberately
     * absent from `slugs()`, and carrying only the marker an old seeder
     * wrote. Nothing here calls the generator.
     */
    public function test_it_reports_fabricated_cemeteries_that_predate_the_current_generator(): void
    {
        $legacyId = $this->seedPreGeneratorCemetery();

        $this->assertNotContains('tpu-jakarta-menteng', CemeteryExampleData::slugs());

        $this->artisan('example-data:purge', ['--force' => true])
            ->expectsOutputToContain('Data fiktif masih tertinggal setelah purge')
            ->expectsOutputToContain('cemeteries (alamat "Jl. Contoh")')
            ->assertExitCode(1);

        // Reported, not deleted -- on beta this row is referenced by
        // cemetery_blocks/grave_plots/visitation_bookings with
        // restrictOnDelete, so removing it here would roll the whole purge
        // back. See the command's unpurgedFabricatedRows() doc block.
        $this->assertNotNull(DB::table('cemeteries')->where('id', $legacyId)->value('id'));
    }

    /**
     * Same defect, second marker: a `grave_records` row whose `source` says
     * `contoh` but whose name is not one the current generator emits.
     *
     * `purge()` reads that column already, then narrows it with
     * `whereIn('deceased_name', ...)`. Since `GraveRecordSource::CONTOH`'s own
     * doc block states "a row carrying this source is never real business
     * data", the name filter can only ever cause a miss -- it cannot prevent
     * a wrong delete. Fourteen rows on beta were missed exactly this way.
     */
    public function test_it_reports_fabricated_grave_records_the_name_filter_misses(): void
    {
        // Attached to a cemetery the purge also cannot see. That is beta's
        // actual shape, and it is forced: `grave_records.cemetery_id` is
        // `restrictOnDelete`, so hanging this row off a cemetery the purge
        // DOES delete would abort the transaction and roll everything back --
        // a different failure, proving nothing about this one.
        $cemeteryId = $this->seedPreGeneratorCemetery();

        DB::table('grave_records')->insert([
            'id' => (string) Str::uuid(),
            'cemetery_id' => $cemeteryId,
            // Carries the source marker; the name is not one graveRecords()
            // produces today.
            'deceased_name' => 'Contoh Budi Santoso',
            'deceased_name_normalized' => GraveNameNormalizer::normalize('Contoh Budi Santoso'),
            'source' => GraveRecordSource::CONTOH,
            'block' => 'A',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('example-data:purge', ['--force' => true])
            ->expectsOutputToContain('grave_records (source=contoh)')
            ->assertExitCode(1);

        $this->assertSame(
            1,
            DB::table('grave_records')->where('source', GraveRecordSource::CONTOH)->count(),
        );
    }

    /**
     * The other half of the contract: when nothing fabricated is left, the
     * new check must stay quiet and the command must still exit 0. Without
     * this, a check that reported on every run would pass the two tests above
     * while breaking every real purge.
     */
    public function test_it_exits_zero_and_reports_nothing_when_no_fabricated_rows_survive(): void
    {
        $this->artisan('example-data:purge', ['--force' => true])
            ->doesntExpectOutputToContain('Data fiktif masih tertinggal')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('grave_records')->where('source', GraveRecordSource::CONTOH)->count());
        $this->assertSame(0, DB::table('cemeteries')->where('address', 'like', 'Jl. Contoh%')->count());
    }

    /**
     * A cemetery row in the shape a pre-13-Aug-2026 seeder left behind:
     * carrying the "Jl. Contoh" marker an applied migration wrote, with a
     * slug that `CemeteryExampleData::slugs()` no longer emits.
     *
     * Built by hand on purpose. Calling the generator here would reintroduce
     * the very circularity that let this defect live for a month.
     */
    private function seedPreGeneratorCemetery(): string
    {
        $id = (string) Str::uuid();

        DB::table('cemeteries')->insert([
            'id' => $id,
            'type' => 'tpu',
            'publication_status' => 'published',
            'name' => 'TPU Jakarta Menteng',
            'slug' => 'tpu-jakarta-menteng',
            'city' => 'Jakarta',
            'address' => 'Jl. Contoh Melati No. 1, Menteng, Jakarta Pusat',
            'facilities' => json_encode([]),
            'price_currency' => 'IDR',
            'operator_name' => 'Dinas Pertamanan dan Pemakaman',
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
