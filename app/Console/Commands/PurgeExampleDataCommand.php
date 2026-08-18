<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\GraveRegistry\GraveRecordSource;
use App\Domain\Marketplace\ProductCode;
use App\Support\ExampleData\CemeteryExampleData;
use App\Support\ExampleData\VendorExampleData;
use App\Support\ExampleData\VendorListingExampleData;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * `php artisan example-data:purge --force`
 *
 * Removes the fabricated cemetery/vendor/grave-record fixtures every
 * environment's `migrate --force` installs, so a public beta shows real
 * content or an honest empty state instead of ten fictional cemeteries and
 * nine fictional grave records. Run once, immediately after `migrate
 * --force`, on a fresh environment with no real customer traffic yet — see
 * "Not a general-purpose cleanup tool" below for why that scoping matters.
 *
 * ---------------------------------------------------------------------------
 * Why a command, not a migration edit
 * ---------------------------------------------------------------------------
 * ~17 data-writing migrations are the delivery mechanism for every
 * environment — CI's `browser-test` job runs `migrate --force` and the test
 * suite asserts against the resulting rows (`CemeterySeedTest`,
 * `CemeteryPackageAvailabilityTest`, `GraveRecordSeedTest` — this codebase's
 * testing convention forbids ad hoc domain factories in their place).
 * Rewriting those migrations, or adding a new migration that deletes their
 * rows, would run in CI too and break that suite; a deleting migration is
 * also exactly the destructive pattern `AGENTS.md` §Database forbids.
 * An operator-invoked, `--force`-guarded command run only against the beta
 * deployment leaves CI, dev, and every other environment untouched.
 *
 * ---------------------------------------------------------------------------
 * What is purged, and why each piece is safe to remove
 * ---------------------------------------------------------------------------
 * Reuses `App\Support\ExampleData\*`'s own generators for every match
 * criterion — the fixture data has exactly one source of truth, and this
 * command never restates it (`AGENTS.md` §Documentation).
 *
 *   1. `grave_records` WHERE source=CONTOH AND deceased_name IN
 *      `CemeteryExampleData::graveRecords()`'s names — deleted FIRST: it
 *      `restrictOnDelete`s on `cemeteries`
 *      (`2026_08_08_100000_create_grave_records_table.php`).
 *   2. `vendor_listings` and `service_areas` for the fixture vendors —
 *      deleted before `vendors`: both `restrictOnDelete` on it
 *      (`2026_08_12_100020_create_vendor_listings_table.php`,
 *      `2026_08_12_100030_create_service_areas_table.php`).
 *   3. `vendors` WHERE name IN `VendorListingExampleData::vendors()`'s
 *      names.
 *   4. `cemeteries` WHERE slug IN `CemeteryExampleData::slugs()`.
 *      `cemetery_capability_profiles` and `cemetery_packages` both
 *      `cascadeOnDelete` on this
 *      (`2026_07_26_190100_create_cemetery_capability_profiles_table.php`,
 *      `2026_07_26_190200_create_cemetery_packages_table.php`) so deleting
 *      the cemetery row removes them too — counted here before the delete,
 *      since a cascaded row cannot be counted after.
 *   5. `products.vendor_name`/`base_price_idr`/`photo_path`/`price_version`
 *      reset to their pre-fixture NULL/1 state for
 *      `ProductCode::KNOWN_CODES` — this table is the canonical marketplace
 *      catalogue (never purged), only the three dummy columns
 *      `VendorExampleData::seed()` wrote onto it are reset. Exactly the
 *      revert `2026_07_26_200100_add_dummy_vendor_pricing_and_photo_to_
 *      products.php`'s own `down()` performs (minus dropping the columns).
 *
 * ---------------------------------------------------------------------------
 * Deliberately NOT purged — service pricing is load-bearing, not decorative
 * ---------------------------------------------------------------------------
 * `App\Support\ExampleData\ServiceOperationalExampleData` is NOT touched,
 * on purpose, and this is a considered exception to "purge every fixture,"
 * not an oversight:
 *
 *   - `operationalDefaults()` (fulfilment owner, scheduling/confirmation
 *      flags on `service_definitions`) is DOMAIN SEMANTICS per that class's
 *      own doc block — a real, correct fact about who fulfils each service,
 *      never example data. Nothing here should ever touch it.
 *   - `dummyPrices()` (the `price_versions` rows) IS example data by that
 *      same doc block, but deleting it would do active harm rather than
 *      remove noise: it is currently the ONLY price source funeral
 *      services have. `quote_lines.price_version_id` `restrictOnDelete`s
 *      on `price_versions`
 *      (`2026_08_12_100050_create_quote_lines_table.php`), so deleting a
 *      price version referenced by any real quote would fail the whole
 *      purge outright — and on an environment with no quotes yet, deleting
 *      it would succeed and leave every funeral service unpriced, which
 *      does not degrade to an honest empty state the way an empty
 *      marketplace or cemetery list does: `SubmitBookingDraft`'s quote
 *      composition throws `UnpricedBookingServiceException` and the ENTIRE
 *      booking wizard — the platform's core journey — becomes unusable.
 *      Cemeteries and marketplace vendors purge safely BECAUSE the product
 *      already has a designed honest-empty-state degradation for zero of
 *      them (`ProductDetail`'s own doc block: "the marketplace journey
 *      dies at the first add-to-cart button" without listings, rendering
 *      "Pemesanan online belum tersedia" — by design, not by accident).
 *      No equivalent designed degradation exists for an unpriced service,
 *      and providing real service pricing is a separate, business/
 *      regulatory-sensitive decision this command does not make.
 *
 * ---------------------------------------------------------------------------
 * Not a general-purpose cleanup tool
 * ---------------------------------------------------------------------------
 * This command assumes no real operator data has yet been attached to a
 * fixture row — the expected beta deploy sequence is `migrate --force` then
 * `example-data:purge --force` then real-data entry, with no customer
 * traffic in between. It does NOT special-case every table that could ever
 * reference a fixture cemetery/vendor (`cemetery_blocks`,
 * `cemetery_visitation_policies`, `visitation_bookings`, `grave_plots`,
 * `vendor_users`, `vendor_availability`, `marketplace_orders`,
 * `vendor_orders` all `restrictOnDelete` on cemeteries/vendors and are
 * deliberately left alone here, since none of the `ExampleData` generators
 * populate them). If any of those tables hold a real row against a fixture
 * cemetery/vendor, the delete fails with a foreign key violation and the
 * WHOLE purge rolls back atomically (`DB::transaction`) — the command
 * refuses to partially purge rather than silently destroying whatever
 * created that row. Booking drafts in progress lose their cemetery
 * selection (`booking_drafts.cemetery_id` `nullOnDelete`s) — acceptable for
 * a pre-launch purge, not for a purge run against a live system with
 * in-flight customer sessions.
 *
 * Idempotent: running it twice, or against an environment that never had
 * the fixtures, deletes/updates zero rows on the second run and reports
 * that plainly — never an error.
 */
final class PurgeExampleDataCommand extends Command
{
    protected $signature = 'example-data:purge {--force : Required. Purges without this flag are refused.}';

    protected $description = 'Remove fabricated cemetery/vendor/grave-record example data before a public beta launch.';

    public function handle(): int
    {
        if (! $this->option('force')) {
            $this->error('Refusing to run without --force. This deletes real rows from the fixture tables listed in this command\'s own doc block.');

            return self::FAILURE;
        }

        try {
            $counts = DB::transaction(fn (): array => $this->purge());
        } catch (QueryException $exception) {
            $this->error(
                'Purge aborted and rolled back: a foreign key constraint blocked a delete. '.
                'This means real operational data now references a fixture cemetery or vendor — '.
                'investigate before retrying. No rows were changed.'
            );

            report($exception);

            return self::FAILURE;
        }

        $total = array_sum($counts);

        if ($total === 0) {
            $this->info('Nothing to purge — no example-data rows were found. Already clean, or never seeded.');

            return self::SUCCESS;
        }

        foreach ($counts as $label => $count) {
            if ($count > 0) {
                $this->info(sprintf('%-28s %d', $label, $count));
            }
        }

        $this->info("Purged {$total} example-data row(s) total.");

        return self::SUCCESS;
    }

    /**
     * @return array<string, int>
     */
    private function purge(): array
    {
        $graveRecordNames = array_column(CemeteryExampleData::graveRecords(), 1);
        $cemeterySlugs = CemeteryExampleData::slugs();
        $vendorNames = array_column(VendorListingExampleData::vendors(), 0);
        $productCodes = array_values(ProductCode::KNOWN_CODES);

        $graveRecordsDeleted = DB::table('grave_records')
            ->where('source', GraveRecordSource::CONTOH)
            ->whereIn('deceased_name', $graveRecordNames)
            ->delete();

        $vendorIds = DB::table('vendors')->whereIn('name', $vendorNames)->pluck('id');

        $vendorListingsDeleted = DB::table('vendor_listings')->whereIn('vendor_id', $vendorIds)->delete();
        $serviceAreasDeleted = DB::table('service_areas')->whereIn('vendor_id', $vendorIds)->delete();
        $vendorsDeleted = DB::table('vendors')->whereIn('id', $vendorIds)->delete();

        $cemeteryIds = DB::table('cemeteries')->whereIn('slug', $cemeterySlugs)->pluck('id');
        // Counted before the delete below cascades them away — a cascaded
        // row cannot be counted after the fact.
        $capabilityProfilesCascaded = DB::table('cemetery_capability_profiles')->whereIn('cemetery_id', $cemeteryIds)->count();
        $cemeteryPackagesCascaded = DB::table('cemetery_packages')->whereIn('cemetery_id', $cemeteryIds)->count();
        $cemeteriesDeleted = DB::table('cemeteries')->whereIn('id', $cemeteryIds)->delete();

        $productsReset = DB::table('products')
            ->whereIn('code', $productCodes)
            ->where(function ($query): void {
                $query->whereNotNull('vendor_name')
                    ->orWhereNotNull('base_price_idr')
                    ->orWhereNotNull('photo_path');
            })
            ->update([
                'vendor_name' => null,
                'base_price_idr' => null,
                'photo_path' => null,
                'price_version' => 1,
                'updated_at' => now(),
            ]);

        return [
            'grave_records' => $graveRecordsDeleted,
            'vendor_listings' => $vendorListingsDeleted,
            'service_areas' => $serviceAreasDeleted,
            'vendors' => $vendorsDeleted,
            'cemeteries' => $cemeteriesDeleted,
            'cemetery_capability_profiles (cascaded)' => $capabilityProfilesCascaded,
            'cemetery_packages (cascaded)' => $cemeteryPackagesCascaded,
            'products (dummy columns reset)' => $productsReset,
        ];
    }
}
