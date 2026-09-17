<?php

declare(strict_types=1);

namespace Tests\Feature\Database\Migrations;

use App\Domain\CemeteryCapability\CemeteryPackageAvailabilityStatus;
use App\Domain\CemeteryCapability\Models\CemeteryPackage;
use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\CemeteryDirectory\PlotTrackingMode;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\Quotation\Actions\IssueQuote;
use App\Domain\ServiceCatalog\Actions\DefineServicePackage;
use App\Domain\ServiceCatalog\Actions\PublishServicePackageVersion;
use App\Domain\ServiceCatalog\FulfillmentOwner;
use App\Domain\ServiceCatalog\Models\ServiceDefinition;
use App\Domain\ServiceCatalog\Models\ServicePackageVersion;
use App\Domain\ServiceCatalog\ServiceCode;
use App\Domain\ServiceCatalog\ServicePackageItemType;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `2026_09_17_110000_add_plot_line_columns_to_quote_lines_table` — ADR-0042.
 *
 * The CHECK is the point of this file. The application also enforces the
 * per-row rule (`IssueQuote::lineFamilyOf()`), and duplicating it here is
 * deliberate: the application produces the readable message, the database
 * provides the guarantee. A stray `DB::table('quote_lines')->insert()` reaches
 * only one of the two.
 */
final class QuoteLinePlotColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_plot_columns_exist_and_are_nullable(): void
    {
        $this->assertTrue(Schema::hasColumn('quote_lines', 'grave_plot_id'));
        $this->assertTrue(Schema::hasColumn('quote_lines', 'cemetery_package_id'));
    }

    public function test_the_check_refuses_a_plot_line_carrying_only_the_plot(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/quote_lines_line_family_check/');

        DB::table('quote_lines')->insert($this->row([
            'grave_plot_id' => (string) Str::uuid(),
        ]));
    }

    public function test_the_check_refuses_a_plot_line_carrying_only_the_package(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/quote_lines_line_family_check/');

        DB::table('quote_lines')->insert($this->row([
            'cemetery_package_id' => 1,
        ]));
    }

    public function test_the_check_refuses_a_row_mixing_a_service_key_with_a_plot_key(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/quote_lines_line_family_check/');

        DB::table('quote_lines')->insert($this->row([
            'service_definition_id' => 1,
            'grave_plot_id' => (string) Str::uuid(),
            'cemetery_package_id' => 1,
        ]));
    }

    public function test_the_check_refuses_a_row_naming_no_family_at_all(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/quote_lines_line_family_check/');

        DB::table('quote_lines')->insert($this->row([]));
    }

    /**
     * The positive case none of the five rejection tests cover: a real PLOT
     * line — both `grave_plot_id` and `cemetery_package_id` set, referencing
     * real rows so the FKs are satisfied too, both service columns null —
     * must be ACCEPTED. Without this, a mutation making the PLOT arm
     * permanently unsatisfiable (e.g. flipping `cemetery_package_id IS NOT
     * NULL` to `IS NULL` inside that arm) passes every other test in this
     * file silently, because every other test already expects rejection for
     * an unrelated reason.
     */
    public function test_the_check_accepts_a_valid_plot_line(): void
    {
        $plot = $this->makeGravePlot();
        $package = $this->makeCemeteryPackage();

        $id = (string) Str::uuid();

        DB::table('quote_lines')->insert($this->row([
            'grave_plot_id' => (string) $plot->getKey(),
            'cemetery_package_id' => (int) $package->getKey(),
        ], $id));

        $this->assertDatabaseHas('quote_lines', [
            'id' => $id,
            'grave_plot_id' => $plot->getKey(),
            'cemetery_package_id' => $package->getKey(),
            'service_definition_id' => null,
            'service_package_version_id' => null,
        ]);
    }

    /**
     * Isolates the SERVICE arm's "excludes `cemetery_package_id`" clause.
     * `test_the_check_refuses_a_row_mixing_a_service_key_with_a_plot_key`
     * sets `grave_plot_id` too, so that row is already rejected by the
     * SERVICE arm's `grave_plot_id IS NULL` requirement alone — a mutation
     * removing only `AND cemetery_package_id IS NULL` from the SERVICE arm
     * (leaving `grave_plot_id IS NULL` intact) would not be caught by it.
     * This row leaves `grave_plot_id` null and sets only
     * `cemetery_package_id` alongside `service_definition_id`, so it is
     * refused only by the clause this test exists to pin.
     */
    public function test_the_check_refuses_a_row_mixing_a_service_key_with_a_package_key(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/quote_lines_line_family_check/');

        DB::table('quote_lines')->insert($this->row([
            'service_definition_id' => 1,
            'cemetery_package_id' => 1,
        ]));
    }

    /**
     * Review finding I-3. No test in this file, before these five, ever
     * inserted a row carrying `service_package_version_id` at all — the
     * single root cause behind six of the twelve pinned rejections being
     * unpinned. Each of these rows sets exactly the two columns needed to
     * violate exactly ONE clause of ONE arm while leaving every OTHER
     * clause (in every arm) satisfied on its own terms, so the row is
     * refused for the reason under test and no other.
     */
    public function test_the_check_refuses_a_row_naming_both_a_package_and_a_service(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/quote_lines_line_family_check/');

        DB::table('quote_lines')->insert($this->row([
            'service_package_version_id' => $this->makeServicePackageVersion()->id,
            'service_definition_id' => (int) ServiceDefinition::findByCode(ServiceCode::DOCUMENT_PROCESSING)->getKey(),
        ]));
    }

    /**
     * Isolates the PACKAGE arm's `grave_plot_id IS NULL` clause: every
     * other PACKAGE clause holds (spv set, sd null, cp null), so only that
     * clause blocks it.
     */
    public function test_the_check_refuses_a_row_naming_a_package_and_a_plot(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/quote_lines_line_family_check/');

        DB::table('quote_lines')->insert($this->row([
            'service_package_version_id' => $this->makeServicePackageVersion()->id,
            'grave_plot_id' => (string) $this->makeGravePlot()->getKey(),
        ]));
    }

    /**
     * Isolates the PACKAGE arm's `cemetery_package_id IS NULL` clause.
     */
    public function test_the_check_refuses_a_row_naming_a_package_and_a_cemetery_package(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/quote_lines_line_family_check/');

        DB::table('quote_lines')->insert($this->row([
            'service_package_version_id' => $this->makeServicePackageVersion()->id,
            'cemetery_package_id' => (int) $this->makeCemeteryPackage()->getKey(),
        ]));
    }

    /**
     * Isolates the SERVICE arm's `grave_plot_id IS NULL` clause. (The
     * SERVICE arm's `cemetery_package_id IS NULL` clause already has
     * `test_the_check_refuses_a_row_mixing_a_service_key_with_a_package_key`.)
     */
    public function test_the_check_refuses_a_row_naming_a_service_and_a_plot(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/quote_lines_line_family_check/');

        DB::table('quote_lines')->insert($this->row([
            'service_definition_id' => (int) ServiceDefinition::findByCode(ServiceCode::DOCUMENT_PROCESSING)->getKey(),
            'grave_plot_id' => (string) $this->makeGravePlot()->getKey(),
        ]));
    }

    /**
     * Isolates the PLOT arm's `service_package_version_id IS NULL` clause.
     */
    public function test_the_check_refuses_a_row_naming_a_package_a_plot_and_a_cemetery_package(): void
    {
        $plot = $this->makeGravePlot();
        $package = $this->makeCemeteryPackage();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/quote_lines_line_family_check/');

        DB::table('quote_lines')->insert($this->row([
            'service_package_version_id' => $this->makeServicePackageVersion()->id,
            'grave_plot_id' => (string) $plot->getKey(),
            'cemetery_package_id' => (int) $package->getKey(),
        ]));
    }

    private function makeServicePackageVersion(): ServicePackageVersion
    {
        $package = (new DefineServicePackage)(
            code: 'PKG-'.Str::upper(Str::random(6)),
            name: 'Paket Uji Migrasi',
            items: [[
                'service_definition_id' => ServiceDefinition::findByCode(ServiceCode::GRAVE_DIGGING)->id,
                'item_type' => ServicePackageItemType::INCLUDED,
                'quantity' => 1,
                'unit' => 'paket',
                'fulfillment_owner' => FulfillmentOwner::PLATFORM,
            ]],
            actorReference: 7,
        );

        return (new PublishServicePackageVersion)($package->draftVersion(), actorReference: 7);
    }

    private function makeCemetery(): Cemetery
    {
        return Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::PUBLISHED,
            'name' => 'TPU Uji Coba',
            'slug' => 'tpu-uji-coba-'.Str::lower(Str::random(6)),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
            'plot_tracking_mode' => PlotTrackingMode::GRANULAR,
        ]);
    }

    private function makeGravePlot(): GravePlot
    {
        $cemetery = $this->makeCemetery();
        $block = CemeteryBlock::query()->create([
            'cemetery_id' => $cemetery->getKey(),
            'code' => 'BLOK-A',
            'name' => 'Blok A',
            'capacity' => 1,
        ]);

        return GravePlot::query()->create([
            'block_id' => $block->getKey(),
            'slot' => '001',
            'plot_state' => 'available',
        ]);
    }

    private function makeCemeteryPackage(): CemeteryPackage
    {
        return CemeteryPackage::query()->create([
            'cemetery_id' => $this->makeCemetery()->getKey(),
            'name' => 'Makam Uji',
            'availability_status' => CemeteryPackageAvailabilityStatus::AVAILABLE,
        ]);
    }

    /**
     * A real quote, so `quote_id` cannot trip the FK before the CHECK fires.
     *
     * Every test here asserts the exception message names
     * `quote_lines_line_family_check`. Without that, `QueryException` alone
     * would pass on an FK violation just as happily as on the CHECK, and the
     * test could not tell "the CHECK works" from "some constraint works".
     *
     * `quotes.id` is a UUID (`2026_08_12_100040_create_quotes_table.php`
     * — `$table->uuid('id')->primary()`), so this returns `string`, not
     * `int`. Built with the same order + service-line fixture as
     * `IssueQuoteServiceLineTest::makeOrder()` / `issue()` / `serviceLine()`
     * (lines 304/316/338), rather than a second way to build a quote.
     */
    private function realQuoteId(): string
    {
        $order = Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::PENAWARAN_TERKIRIM->value,
        ]);

        $definition = ServiceDefinition::findByCode(ServiceCode::DOCUMENT_PROCESSING);
        $price = $definition->currentPriceVersion();

        $quote = app(IssueQuote::class)(
            order: $order,
            lines: [[
                'service_definition_id' => (int) $definition->getKey(),
                'price_version_id' => (int) $price->getKey(),
                'price_version_number' => (int) $price->version_number,
                'quantity' => 1,
                'unit_amount' => (string) $price->amount,
                'currency' => (string) $price->currency,
                'fulfillment_owner' => (string) $definition->fulfillment_owner,
            ]],
            expiresAt: Carbon::now()->addDays(7),
            actorRef: 'actor:admin-1',
            actorRole: 'admin',
        );

        return (string) $quote->getKey();
    }

    /**
     * `quote_lines` has no `created_at`/`updated_at` columns
     * (`2026_08_12_100050_create_quote_lines_table.php` — `QuoteLine::
     * $timestamps` is `false` to match), so unlike the brief's row shape
     * this omits them; including them 42703s on the raw insert before the
     * CHECK ever fires.
     *
     * `id` is supplied explicitly too: `quote_lines.id` is a UUID primary
     * key with no database-level default (`HasUuids` on the model assigns
     * one on `creating`, but a raw `DB::table()->insert()` never fires that
     * event), so leaving it out is a NOT NULL violation, not the CHECK.
     *
     * @param  array<string, mixed>  $family
     * @return array<string, mixed>
     */
    private function row(array $family, ?string $id = null): array
    {
        return array_merge([
            'id' => $id ?? (string) Str::uuid(),
            'quote_id' => $this->realQuoteId(),
            'price_version_id' => 1,
            'price_version_number' => 1,
            'description' => 'x',
            'quantity' => 1,
            'unit_amount_minor' => 1000,
            'line_total_minor' => 1000,
            'currency' => 'IDR',
            'fulfillment_owner' => 'platform',
        ], $family);
    }
}
