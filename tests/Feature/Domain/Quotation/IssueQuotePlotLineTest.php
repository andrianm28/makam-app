<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Quotation;

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
use App\Domain\Quotation\Models\Quote;
use App\Domain\ServiceCatalog\Actions\DefineServicePackage;
use App\Domain\ServiceCatalog\Actions\PublishServicePackageVersion;
use App\Domain\ServiceCatalog\FulfillmentOwner;
use App\Domain\ServiceCatalog\Models\PriceVersion;
use App\Domain\ServiceCatalog\Models\ServiceDefinition;
use App\Domain\ServiceCatalog\Models\ServicePackageVersion;
use App\Domain\ServiceCatalog\ServiceCode;
use App\Domain\ServiceCatalog\ServicePackageItemType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * ADR-0042 — the PLOT family and the enumerated combinations.
 *
 * The fixture is built row by row rather than from `CemeteryExampleData`: this
 * asserts what `IssueQuote` does with a shape, and a fixture taken from the
 * generator would only ever exercise today's generator (DB-13).
 *
 * `makeOrder()`/`issue()`/`serviceLine()`/`packageLine()`/`publishedVersion()`
 * are copied verbatim from `IssueQuoteServiceLineTest` (lines 304/316/338/
 * 364/389 there) rather than re-derived — a second way to build an order
 * would rot. Only `plotLine()` (and the fixture builders it needs) is new.
 */
final class IssueQuotePlotLineTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_plot_line_freezes_both_the_plot_and_the_package(): void
    {
        $order = $this->makeOrder();
        $line = $this->plotLine();

        $quote = $this->issue($order, [$line]);

        $row = DB::table('quote_lines')->where('quote_id', $quote->getKey())->sole();

        $this->assertSame($line['grave_plot_id'], (string) $row->grave_plot_id);
        $this->assertSame($line['cemetery_package_id'], (int) $row->cemetery_package_id);
        $this->assertNull($row->service_definition_id);
        $this->assertNull($row->service_package_version_id);
    }

    public function test_a_plot_line_and_a_service_line_may_share_one_quote(): void
    {
        $order = $this->makeOrder();

        $quote = $this->issue($order, [
            $this->plotLine(),
            $this->serviceLine(ServiceCode::DOCUMENT_PROCESSING),
        ]);

        $this->assertSame(2, DB::table('quote_lines')->where('quote_id', $quote->getKey())->count());
    }

    public function test_a_plot_line_may_not_share_a_quote_with_a_package_line(): void
    {
        $order = $this->makeOrder();
        $packageVersion = $this->publishedVersion();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/combination/i');

        $this->issue($order, [$this->plotLine(), $this->packageLine($packageVersion)]);
    }

    public function test_a_price_version_belonging_to_another_package_is_refused(): void
    {
        $order = $this->makeOrder();

        [$plot, $package] = $this->makePlotAndPackage();
        [, $otherPackage] = $this->makePlotAndPackage();

        $foreignPrice = $this->packagePriceVersion($otherPackage);

        $line = $this->plotLine(plot: $plot, package: $package, priceVersion: $foreignPrice);

        $this->expectException(InvalidArgumentException::class);

        $this->issue($order, [$line]);
    }

    /**
     * Review finding (Task 3 fix round 1): the polymorphic-priceable check
     * is `$priceVersion->priceable_type !== CemeteryPackage::class ||
     * (int) $priceVersion->priceable_id !== $cemeteryPackageId` — two
     * clauses ORed together. `test_a_price_version_belonging_to_another_
     * package_is_refused` only exercises the SECOND clause (same type,
     * different id). This test isolates the FIRST clause: a `PriceVersion`
     * for a DIFFERENT priceable kind (`ServiceDefinition`) whose
     * `priceable_id` deliberately COLLIDES with the plot's
     * `cemetery_package_id` — a non-colliding id would be caught by the
     * second clause alone and would prove nothing about the first.
     * `version_number` is a value the seeded `ServiceDefinition` price
     * versions never use, so this insert cannot collide with
     * `price_versions_priceable_version_unique`.
     */
    public function test_a_price_version_of_a_different_priceable_type_with_a_colliding_id_is_refused(): void
    {
        $order = $this->makeOrder();

        [$plot, $package] = $this->makePlotAndPackage();
        $cemeteryPackageId = (int) $package->getKey();

        $collidingPrice = PriceVersion::query()->create([
            'priceable_type' => ServiceDefinition::class,
            'priceable_id' => $cemeteryPackageId,
            'version_number' => 999,
            'amount' => '75000000.00',
            'currency' => 'IDR',
            'source' => 'test fixture',
            'effective_from' => Carbon::now(),
            'recorded_by' => 'test',
        ]);

        $line = $this->plotLine(plot: $plot, package: $package, priceVersion: $collidingPrice);

        $this->expectException(InvalidArgumentException::class);

        $this->issue($order, [$line]);
    }

    public function test_a_package_from_another_cemetery_than_the_plot_is_refused(): void
    {
        $order = $this->makeOrder();

        [$plot] = $this->makePlotAndPackage();
        [, $foreignPackage] = $this->makePlotAndPackage();

        $line = $this->plotLine(plot: $plot, package: $foreignPackage);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/cemetery/i');

        $this->issue($order, [$line]);
    }

    public function test_a_unit_amount_contradicting_the_frozen_version_is_refused(): void
    {
        $order = $this->makeOrder();
        $line = $this->plotLine(unitAmount: '999999.00');

        $this->expectException(InvalidArgumentException::class);

        $this->issue($order, [$line]);
    }

    /**
     * Review finding I-1: `normalizePlotLine()`'s current-version check is
     * `! $priceVersion instanceof PriceVersion || ! $priceVersion->isCurrent()
     * || ...`, mirroring `normalizeServiceLine()`'s identical clause — but
     * only the service branch had a test that superseded the version first.
     * A superseded, stale package price must not be frozen onto a plot line.
     */
    public function test_a_superseded_price_version_is_refused(): void
    {
        $order = $this->makeOrder();

        [$plot, $package] = $this->makePlotAndPackage();
        $price = $this->packagePriceVersion($package);

        // The one legal price-version mutation: stamp superseded_at.
        $price->forceFill(['superseded_at' => Carbon::now()])->save();

        $line = $this->plotLine(plot: $plot, package: $package, priceVersion: $price);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not the current price version/i');

        $this->issue($order, [$line]);
    }

    /**
     * Review finding M-4: `normalizePlotLine()`'s anchor cross-check ORs
     * three clauses (`unit_amount`, `currency`, `version_number`), but only
     * `unit_amount` had a dedicated test — the same three-clauses-one-test
     * shape I-1 closed a few lines up.
     */
    public function test_a_currency_contradicting_the_frozen_version_is_refused(): void
    {
        $order = $this->makeOrder();
        $line = $this->plotLine(currency: 'USD');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/contradicts/i');

        $this->issue($order, [$line]);
    }

    public function test_a_version_number_contradicting_the_frozen_version_is_refused(): void
    {
        $order = $this->makeOrder();
        $line = $this->plotLine(priceVersionNumber: 999);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/contradicts/i');

        $this->issue($order, [$line]);
    }

    /**
     * Review finding M-1: the spec says "a plot line is always quantity 1";
     * the composer hardcodes it, but `IssueQuote` itself must refuse a
     * caller that does not.
     */
    public function test_a_quantity_other_than_one_is_refused(): void
    {
        $order = $this->makeOrder();
        $line = $this->plotLine(quantity: 5);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/quantity exactly 1/i');

        $this->issue($order, [$line]);

        self::assertSame(0, Quote::query()->count());
    }

    /**
     * Review finding M-2: a non-UUID `grave_plot_id` must surface the
     * readable exception layer 2 promises, not a raw Postgres
     * `SQLSTATE[22P02]` `QueryException` from comparing a non-UUID string
     * against the real `uuid` column.
     */
    public function test_a_non_uuid_grave_plot_id_is_refused_with_a_readable_message(): void
    {
        $order = $this->makeOrder();
        [, $package] = $this->makePlotAndPackage();
        $price = $package->currentPriceVersion() ?? $this->packagePriceVersion($package);

        $line = [
            'grave_plot_id' => 'not-a-uuid',
            'cemetery_package_id' => (int) $package->getKey(),
            'price_version_id' => (int) $price->getKey(),
            'price_version_number' => (int) $price->version_number,
            'quantity' => 1,
            'unit_amount' => (string) $price->amount,
            'currency' => (string) $price->currency,
            'fulfillment_owner' => FulfillmentOwner::CEMETERY_OPERATOR,
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not a valid UUID/i');

        $this->issue($order, [$line]);
    }

    // -------------------------------------------------------------------
    // New fixture: a cemetery + block + plot + package + current PriceVersion.
    // -------------------------------------------------------------------

    /**
     * A plot line naming a fresh cemetery/block/plot/package (same
     * cemetery, by construction) and that package's current `PriceVersion`
     * — the eight-key shape `IssueQuote::normalizePlotLine()` expects.
     *
     * `$plot`/`$package` are supplied together by the cross-cemetery and
     * foreign-price-version tests to name a deliberately mismatched pair;
     * `$priceVersion`/`$unitAmount` override the caller-supplied anchor
     * fields the same way `serviceLine()`'s overrides do.
     *
     * @return array<string, mixed>
     */
    private function plotLine(
        ?GravePlot $plot = null,
        ?CemeteryPackage $package = null,
        ?PriceVersion $priceVersion = null,
        ?string $unitAmount = null,
        ?string $currency = null,
        ?int $priceVersionNumber = null,
        int $quantity = 1,
    ): array {
        if ($plot === null && $package === null) {
            [$plot, $package] = $this->makePlotAndPackage();
        }

        $price = $priceVersion ?? $package->currentPriceVersion() ?? $this->packagePriceVersion($package);

        return [
            'grave_plot_id' => (string) $plot->getKey(),
            'cemetery_package_id' => (int) $package->getKey(),
            'price_version_id' => (int) $price->getKey(),
            'price_version_number' => $priceVersionNumber ?? (int) $price->version_number,
            'quantity' => $quantity,
            'unit_amount' => $unitAmount ?? (string) $price->amount,
            'currency' => $currency ?? (string) $price->currency,
            'fulfillment_owner' => FulfillmentOwner::CEMETERY_OPERATOR,
        ];
    }

    /**
     * @return array{0: GravePlot, 1: CemeteryPackage}
     */
    private function makePlotAndPackage(): array
    {
        $cemetery = $this->makeCemetery();

        $block = CemeteryBlock::query()->create([
            'cemetery_id' => $cemetery->getKey(),
            'code' => 'BLOK-'.Str::upper(Str::random(6)),
            'name' => 'Blok Uji',
            'capacity' => 1,
        ]);

        $plot = GravePlot::query()->create([
            'block_id' => $block->getKey(),
            'slot' => '001',
            'plot_state' => 'available',
        ]);

        $package = CemeteryPackage::query()->create([
            'cemetery_id' => $cemetery->getKey(),
            'name' => 'Makam Tumpang',
            'availability_status' => CemeteryPackageAvailabilityStatus::AVAILABLE,
        ]);

        return [$plot, $package];
    }

    private function makeCemetery(): Cemetery
    {
        return Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::PUBLISHED,
            'name' => 'TPU Uji Coba',
            'slug' => 'tpu-uji-coba-'.Str::lower(Str::random(8)),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
            'plot_tracking_mode' => PlotTrackingMode::GRANULAR,
        ]);
    }

    private function packagePriceVersion(CemeteryPackage $package): PriceVersion
    {
        return PriceVersion::query()->create([
            'priceable_type' => CemeteryPackage::class,
            'priceable_id' => $package->getKey(),
            'version_number' => 1,
            'amount' => '75000000.00',
            'currency' => 'IDR',
            'source' => 'test fixture',
            'effective_from' => Carbon::now(),
            'recorded_by' => 'test',
        ]);
    }

    // -------------------------------------------------------------------
    // Copied verbatim from IssueQuoteServiceLineTest (lines 304-406 there).
    // -------------------------------------------------------------------

    private function makeOrder(): Order
    {
        return Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::PENAWARAN_TERKIRIM->value,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function issue(Order $order, array $lines): Quote
    {
        return app(IssueQuote::class)(
            order: $order,
            lines: $lines,
            expiresAt: Carbon::now()->addDays(7),
            actorRef: 'actor:admin-1',
            actorRole: 'admin',
        );
    }

    /**
     * A service line for the given code, resolved against the seeded
     * current price version — the exact shape
     * `ComposeQuoteLinesFromBookingDraft` produces. `$priceVersion`
     * overrides the resolution when a test needs to name a specific
     * (e.g. already-superseded) version; `$unitAmount`/`$currency`
     * override the caller-supplied anchor fields when a test needs to
     * contradict the version's stored values.
     *
     * @return array<string, mixed>
     */
    private function serviceLine(
        string $code,
        int $quantity = 1,
        ?PriceVersion $priceVersion = null,
        ?int $priceVersionId = null,
        ?int $priceVersionNumber = null,
        ?string $unitAmount = null,
        ?string $currency = null,
    ): array {
        $definition = ServiceDefinition::findByCode($code);
        $price = $priceVersion ?? $definition->currentPriceVersion();

        return [
            'service_definition_id' => (int) $definition->getKey(),
            'price_version_id' => $priceVersionId ?? (int) $price->getKey(),
            'price_version_number' => $priceVersionNumber ?? (int) $price->version_number,
            'quantity' => $quantity,
            'unit_amount' => $unitAmount ?? (string) $price->amount,
            'currency' => $currency ?? (string) $price->currency,
            'fulfillment_owner' => (string) $definition->fulfillment_owner,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function packageLine(ServicePackageVersion $version, ?PriceVersion $price = null): array
    {
        $price ??= PriceVersion::query()->create([
            'priceable_type' => $version::class,
            'priceable_id' => $version->id,
            'version_number' => 1,
            'amount' => '1250000.00',
            'currency' => 'IDR',
            'source' => 'test fixture',
            'effective_from' => Carbon::now(),
            'recorded_by' => 'test',
        ]);

        return [
            'service_package_version_id' => $version->id,
            'price_version_id' => (int) $price->getKey(),
            'price_version_number' => 1,
            'description' => 'Paket uji',
            'quantity' => 1,
            'unit_amount' => '1250000.00',
            'currency' => 'IDR',
            'fulfillment_owner' => FulfillmentOwner::PLATFORM,
        ];
    }

    private function publishedVersion(): ServicePackageVersion
    {
        $package = (new DefineServicePackage)(
            code: 'PKG-'.Str::upper(Str::random(6)),
            name: 'Paket Uji Quote',
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
}
