<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Quotation;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\Quotation\Actions\IssueQuote;
use App\Domain\Quotation\Models\Quote;
use App\Domain\Quotation\Models\QuoteLine;
use App\Domain\Quotation\QuoteStatus;
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
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The P0 ruling's service-version line family on `Actions\IssueQuote` —
 * Task 1 of `docs/superpowers/plans/2026-08-14-p0-booking-submission-chain.md`.
 * A line carrying `service_definition_id` (instead of
 * `service_package_version_id`) references the current, frozen `PriceVersion`
 * of that service directly — the shape the booking wizard's quote-line
 * mapper produces (the wizard quotes individual SERVICES, not packages).
 *
 * The package-version family is untouched — `tests/Feature/Quotation/
 * IssueQuoteTest.php` pins its contract and stays byte-identical.
 *
 * The seeded `DOCUMENT_PROCESSING` (350000.00, platform-fulfilled) and
 * `GRAVE_DIGGING` (550000.00, cemetery-operator-fulfilled) definitions both
 * carry a v1 dummy price version
 * (`2026_07_26_220000_seed_service_definition_dummy_operational_data.php`),
 * so a service line resolves against the same seam Step 5's summary prices
 * with (`ServiceDefinition::currentPriceVersion()`).
 */
final class IssueQuoteServiceLineTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_service_version_line_issues_a_quote_line_with_the_frozen_price(): void
    {
        $order = $this->makeOrder();

        $quote = $this->issue($order, [$this->serviceLine(ServiceCode::DOCUMENT_PROCESSING)]);

        self::assertSame(1, $quote->version_number);
        self::assertSame(QuoteStatus::ISSUED->value, $quote->status);
        self::assertSame(35000000, $quote->totalMinor()->toMinorInt());

        $definition = ServiceDefinition::findByCode(ServiceCode::DOCUMENT_PROCESSING);
        $price = $definition->currentPriceVersion();

        $line = $quote->lines()->sole();
        self::assertSame((int) $definition->getKey(), $line->service_definition_id);
        self::assertSame((int) $price->getKey(), $line->price_version_id);
        self::assertSame(1, $line->price_version_number);
        self::assertNull($line->service_package_version_id);
        self::assertSame($definition->name, $line->description);
        self::assertSame(1, $line->quantity);
        self::assertSame(35000000, $line->unit_amount_minor);
        self::assertSame(35000000, $line->line_total_minor);
        self::assertSame('IDR', $line->currency);
        self::assertSame(FulfillmentOwner::PLATFORM, $line->fulfillment_owner);
    }

    public function test_the_quote_total_is_the_sum_of_service_line_totals(): void
    {
        $order = $this->makeOrder();

        $quote = $this->issue($order, [
            $this->serviceLine(ServiceCode::DOCUMENT_PROCESSING, quantity: 1),
            $this->serviceLine(ServiceCode::GRAVE_DIGGING, quantity: 2),
        ]);

        // 350000.00 + (550000.00 * 2) = 1450000.00 -> 145000000 minor.
        self::assertSame(145000000, $quote->totalMinor()->toMinorInt());
        self::assertSame(2, $quote->lines()->count());
        self::assertSame(110000000, $quote->lines()->get()[1]->line_total_minor);
    }

    public function test_a_mixed_line_family_set_is_rejected(): void
    {
        $order = $this->makeOrder();
        $packageVersion = $this->publishedVersion();

        try {
            $this->issue($order, [
                $this->serviceLine(ServiceCode::DOCUMENT_PROCESSING),
                $this->packageLine($packageVersion),
            ]);
            self::fail('Expected a mixed package/service line set to be refused');
        } catch (InvalidArgumentException) {
            // expected
        }

        self::assertSame(0, Quote::query()->count());
        self::assertSame(0, QuoteLine::query()->count());
    }

    public function test_a_superseded_price_version_is_refused(): void
    {
        $order = $this->makeOrder();
        $definition = ServiceDefinition::findByCode(ServiceCode::GRAVE_DIGGING);
        $price = $definition->currentPriceVersion();

        // The one legal price-version mutation: stamp superseded_at.
        $price->forceFill(['superseded_at' => Carbon::now()])->save();

        try {
            $this->issue($order, [$this->serviceLine(
                ServiceCode::GRAVE_DIGGING,
                priceVersion: $price,
            )]);
            self::fail('Expected a superseded price version to be refused');
        } catch (InvalidArgumentException) {
            // expected
        }

        self::assertSame(0, Quote::query()->count());
        self::assertSame(0, QuoteLine::query()->count());
    }

    public function test_a_price_version_of_another_definition_is_refused(): void
    {
        $order = $this->makeOrder();
        $otherPrice = ServiceDefinition::findByCode(ServiceCode::GRAVE_DIGGING)->currentPriceVersion();

        try {
            $this->issue($order, [$this->serviceLine(
                ServiceCode::DOCUMENT_PROCESSING,
                priceVersion: $otherPrice,
            )]);
            self::fail('Expected a foreign price version to be refused');
        } catch (InvalidArgumentException) {
            // expected
        }

        self::assertSame(0, Quote::query()->count());
    }

    public function test_an_unknown_service_definition_is_refused(): void
    {
        $order = $this->makeOrder();

        try {
            $this->issue($order, [[
                'service_definition_id' => 999999,
                'price_version_id' => 1,
                'price_version_number' => 1,
                'quantity' => 1,
                'unit_amount' => '350000.00',
                'currency' => 'IDR',
                'fulfillment_owner' => FulfillmentOwner::PLATFORM,
            ]]);
            self::fail('Expected an unknown service definition to be refused');
        } catch (InvalidArgumentException) {
            // expected
        }

        self::assertSame(0, Quote::query()->count());
    }

    public function test_a_line_naming_both_families_is_rejected(): void
    {
        $order = $this->makeOrder();
        $packageVersion = $this->publishedVersion();

        try {
            $this->issue($order, [[
                'service_definition_id' => (int) ServiceDefinition::findByCode(ServiceCode::DOCUMENT_PROCESSING)->getKey(),
                'service_package_version_id' => $packageVersion->id,
                'price_version_id' => (int) ServiceDefinition::findByCode(ServiceCode::DOCUMENT_PROCESSING)->currentPriceVersion()->getKey(),
                'price_version_number' => 1,
                'quantity' => 1,
                'unit_amount' => '350000.00',
                'currency' => 'IDR',
                'fulfillment_owner' => FulfillmentOwner::PLATFORM,
            ]]);
            self::fail('Expected a line naming both families to be refused');
        } catch (InvalidArgumentException) {
            // expected
        }

        self::assertSame(0, Quote::query()->count());
    }

    public function test_an_unknown_fulfillment_owner_on_a_service_line_is_refused(): void
    {
        $order = $this->makeOrder();

        try {
            $this->issue($order, [[
                'service_definition_id' => (int) ServiceDefinition::findByCode(ServiceCode::DOCUMENT_PROCESSING)->getKey(),
                'price_version_id' => (int) ServiceDefinition::findByCode(ServiceCode::DOCUMENT_PROCESSING)->currentPriceVersion()->getKey(),
                'price_version_number' => 1,
                'quantity' => 1,
                'unit_amount' => '350000.00',
                'currency' => 'IDR',
                'fulfillment_owner' => 'not-a-real-owner',
            ]]);
            self::fail('Expected an unknown fulfillment owner to be refused');
        } catch (InvalidArgumentException) {
            // expected
        }

        self::assertSame(0, Quote::query()->count());
    }

    /**
     * Review finding I-1: the caller-supplied anchor must not contradict
     * the frozen version it names — the version's own stored values are
     * authoritative, and an amount mismatch is refused (no quote).
     */
    public function test_a_service_line_whose_amount_contradicts_its_price_version_is_refused(): void
    {
        $order = $this->makeOrder();

        try {
            $this->issue($order, [$this->serviceLine(
                ServiceCode::DOCUMENT_PROCESSING,
                unitAmount: '999999.00',
            )]);
            self::fail('Expected a contradicting unit amount to be refused');
        } catch (InvalidArgumentException) {
            // expected
        }

        self::assertSame(0, Quote::query()->count());
        self::assertSame(0, QuoteLine::query()->count());
    }

    public function test_a_service_line_whose_currency_contradicts_its_price_version_is_refused(): void
    {
        $order = $this->makeOrder();

        try {
            $this->issue($order, [$this->serviceLine(
                ServiceCode::DOCUMENT_PROCESSING,
                currency: 'USD',
            )]);
            self::fail('Expected a contradicting currency to be refused');
        } catch (InvalidArgumentException) {
            // expected
        }

        self::assertSame(0, Quote::query()->count());
        self::assertSame(0, QuoteLine::query()->count());
    }

    public function test_a_service_line_whose_version_number_contradicts_its_price_version_is_refused(): void
    {
        $order = $this->makeOrder();

        try {
            $this->issue($order, [$this->serviceLine(
                ServiceCode::DOCUMENT_PROCESSING,
                priceVersionNumber: 999,
            )]);
            self::fail('Expected a contradicting version number to be refused');
        } catch (InvalidArgumentException) {
            // expected
        }

        self::assertSame(0, Quote::query()->count());
        self::assertSame(0, QuoteLine::query()->count());
    }

    /**
     * Review finding M-2: a line naming NEITHER family key is as ambiguous
     * as one naming both — refused.
     */
    public function test_a_line_naming_neither_family_is_rejected(): void
    {
        $order = $this->makeOrder();

        try {
            $this->issue($order, [[
                'price_version_id' => (int) ServiceDefinition::findByCode(ServiceCode::DOCUMENT_PROCESSING)->currentPriceVersion()->getKey(),
                'price_version_number' => 1,
                'quantity' => 1,
                'unit_amount' => '350000.00',
                'currency' => 'IDR',
                'fulfillment_owner' => FulfillmentOwner::PLATFORM,
            ]]);
            self::fail('Expected a line naming neither family to be refused');
        } catch (InvalidArgumentException) {
            // expected
        }

        self::assertSame(0, Quote::query()->count());
        self::assertSame(0, QuoteLine::query()->count());
    }

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
