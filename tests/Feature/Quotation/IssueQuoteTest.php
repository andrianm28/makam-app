<?php

declare(strict_types=1);

namespace Tests\Feature\Quotation;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\Quotation\Actions\AcceptQuote;
use App\Domain\Quotation\Actions\IssueQuote;
use App\Domain\Quotation\Models\Quote;
use App\Domain\Quotation\QuoteStatus;
use App\Domain\ServiceCatalog\Actions\DefineServicePackage;
use App\Domain\ServiceCatalog\Actions\PublishServicePackageVersion;
use App\Domain\ServiceCatalog\FulfillmentOwner;
use App\Domain\ServiceCatalog\Models\PriceVersion;
use App\Domain\ServiceCatalog\Models\ServiceDefinition;
use App\Domain\ServiceCatalog\Models\ServicePackageVersion;
use App\Domain\ServiceCatalog\ServiceCode;
use App\Domain\ServiceCatalog\ServicePackageItemType;
use App\Platform\Outbox\OutboxClassification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Task 4 of `docs/superpowers/plans/2026-08-12-platform-order-orchestration.md`
 * — AC8 ("THE SYSTEM SHALL NOT modify a quote after it is issued. WHEN a
 * quote is revised THE SYSTEM SHALL create a new version"), exercised
 * through the real `IssueQuote` / `AcceptQuote` Actions.
 *
 * The `$lines` shape this suite pins (documented in `task-4-report.md`):
 * each element carries `service_package_version_id` (a frozen PUBLISHED
 * version — the Action refuses anything else), `price_version_id` +
 * `price_version_number`, `description`, `quantity` (positive integer),
 * `unit_amount` (a decimal:2 string — the Action converts it exactly once,
 * at issuance), `currency` (identical across the whole set), and
 * `fulfillment_owner` (a `FulfillmentOwner` known value). The Action
 * computes both `unit_amount_minor` and `line_total_minor = unit_amount_minor
 * * quantity`; nothing amount-shaped is trusted from the caller.
 */
final class IssueQuoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_issuing_a_first_quote_records_the_lines_and_emits_quote_issued(): void
    {
        $order = $this->makeOrder();

        $quote = $this->issue($order, [$this->line()]);

        self::assertSame(1, $quote->version_number);
        self::assertSame(QuoteStatus::ISSUED->value, $quote->status);
        self::assertNotNull($quote->issued_at);
        self::assertSame('actor:admin-1', $quote->issued_by_ref);
        self::assertSame('admin', $quote->issued_by_role);

        $line = $quote->lines()->sole();
        self::assertSame(125000000, $line->unit_amount_minor);
        self::assertSame(125000000, $line->line_total_minor);
        self::assertSame(1, $line->quantity);
        self::assertSame('IDR', $line->currency);
        self::assertSame(FulfillmentOwner::PLATFORM, $line->fulfillment_owner);

        self::assertDatabaseHas('outbox_events', [
            'event_name' => 'quote.issued.v1',
            'event_version' => 1,
            'aggregate_type' => 'quote',
            'aggregate_id' => $quote->getKey(),
            'classification' => OutboxClassification::Internal->value,
            'idempotency_key' => "quote_issued:{$quote->getKey()}",
        ]);
    }

    public function test_issuing_a_second_quote_marks_the_first_superseded_and_leaves_its_amounts_byte_identical(): void
    {
        $order = $this->makeOrder();

        $v1 = $this->issue($order, [$this->line(['unit_amount' => '1250000.00', 'quantity' => 1])]);
        $v1Line = $v1->lines()->sole();

        $v2 = $this->issue($order, [$this->line(['unit_amount' => '1750000.00', 'quantity' => 2])]);

        self::assertSame(1, $v1->version_number);
        self::assertSame(2, $v2->version_number);
        self::assertSame(QuoteStatus::SUPERSEDED->value, $v1->fresh()->status);
        self::assertNotNull($v1->fresh()->superseded_at);
        self::assertSame(QuoteStatus::ISSUED->value, $v2->status);

        // v1's stored amounts are byte-identical to what was issued — a
        // supersede stamp is the ONLY change the write path may make.
        $v1Again = $v1->fresh();
        $v1LineAgain = $v1Again->lines()->sole();
        self::assertSame(125000000, $v1Again->total_minor);
        self::assertSame('IDR', $v1Again->currency);
        self::assertSame(125000000, $v1LineAgain->unit_amount_minor);
        self::assertSame(125000000, $v1LineAgain->line_total_minor);
        self::assertSame($v1Line->unit_amount_minor, $v1LineAgain->unit_amount_minor);
        self::assertSame($v1Line->line_total_minor, $v1LineAgain->line_total_minor);
        self::assertSame($v1Line->currency, $v1LineAgain->currency);
    }

    public function test_superseding_an_accepted_quote_does_not_change_the_orders_status(): void
    {
        $order = $this->makeOrder(OrderStatus::PENAWARAN_TERKIRIM);

        $v1 = $this->issue($order, [$this->line()]);
        app(AcceptQuote::class)($v1, 'actor:customer-1');

        self::assertSame(OrderStatus::PENAWARAN_TERKIRIM->value, $order->fresh()->status);

        $this->issue($order, [$this->line(['unit_amount' => '1750000.00'])]);

        // Acceptance was a property of the QUOTE VERSION. Superseding the
        // accepted v1 must not move the order backward — or forward.
        self::assertSame(OrderStatus::PENAWARAN_TERKIRIM->value, $order->fresh()->status);
        self::assertSame(QuoteStatus::SUPERSEDED->value, $v1->fresh()->status);
    }

    public function test_a_superseded_accepted_quote_makes_is_accepted_and_unexpired_false_for_the_current_version(): void
    {
        $order = $this->makeOrder();

        $v1 = $this->issue($order, [$this->line()]);
        app(AcceptQuote::class)($v1, 'actor:customer-1');

        $v2 = $this->issue($order, [$this->line(['unit_amount' => '1750000.00'])]);

        $current = Quote::currentFor($order);
        self::assertNotNull($current);
        self::assertSame($v2->getKey(), $current->getKey());

        // The Task 6 guard's condition 3 fails here WITHOUT any backward
        // transition: the current version is issued, not accepted.
        self::assertFalse($current->isAcceptedAndUnexpired(Carbon::now()));

        // The superseded-accepted v1 is no longer acceptable either.
        self::assertFalse($v1->fresh()->isAcceptedAndUnexpired(Carbon::now()));
    }

    public function test_a_decimal_2_amount_converts_to_exact_minor_units(): void
    {
        $order = $this->makeOrder();

        $quote = $this->issue($order, [$this->line(['unit_amount' => '1250000.00', 'quantity' => 1])]);

        // 1250000.00 -> 125000000 minor units, exactly.
        self::assertSame(125000000, $quote->totalMinor()->minorUnits);
        self::assertSame(125000000, $quote->lines()->sole()->unit_amount_minor);
    }

    public function test_the_quote_total_is_the_money_add_sum_of_the_line_totals(): void
    {
        $order = $this->makeOrder();

        $quote = $this->issue($order, [
            $this->line(['unit_amount' => '1250000.00', 'quantity' => 1]),
            $this->line(['unit_amount' => '750000.00', 'quantity' => 2]),
        ]);

        // 1250000.00 + (750000.00 * 2) = 2750000.00 -> 275000000 minor.
        self::assertSame(275000000, $quote->totalMinor()->minorUnits);
        self::assertSame(125000000, $quote->lines()->first()->line_total_minor);
        self::assertSame(150000000, $quote->lines()->get()[1]->line_total_minor);
    }

    public function test_a_mixed_currency_line_set_is_rejected(): void
    {
        $order = $this->makeOrder();

        try {
            $this->issue($order, [
                $this->line(['currency' => 'IDR']),
                $this->line(['currency' => 'USD']),
            ]);
            self::fail('Expected InvalidArgumentException for a mixed-currency line set');
        } catch (InvalidArgumentException) {
            // expected
        }

        self::assertSame(0, Quote::query()->count());
        self::assertSame(0, $order->fresh()->statusEvents()->count());
    }

    public function test_a_zero_or_negative_total_is_rejected(): void
    {
        $order = $this->makeOrder();

        foreach (['0.00', '-1000000.00'] as $amount) {
            try {
                $this->issue($order, [$this->line(['unit_amount' => $amount, 'quantity' => 1])]);
                self::fail("Expected InvalidArgumentException for total {$amount}");
            } catch (InvalidArgumentException) {
                // expected
            }
        }

        self::assertSame(0, Quote::query()->count());
    }

    public function test_a_zero_quantity_line_is_rejected(): void
    {
        $order = $this->makeOrder();

        try {
            $this->issue($order, [$this->line(['quantity' => 0])]);
            self::fail('Expected InvalidArgumentException for a zero-quantity line');
        } catch (InvalidArgumentException) {
            // expected
        }

        self::assertSame(0, Quote::query()->count());
    }

    public function test_an_expired_quote_is_not_acceptable(): void
    {
        $order = $this->makeOrder();

        $quote = $this->issue($order, [$this->line()], Carbon::now()->subDay());

        try {
            app(AcceptQuote::class)($quote, 'actor:customer-1');
            self::fail('Expected an expired quote to be refused at acceptance');
        } catch (InvalidArgumentException) {
            // expected
        }

        self::assertSame(QuoteStatus::ISSUED->value, $quote->fresh()->status);
        self::assertNull($quote->fresh()->accepted_at);
    }

    public function test_accepting_a_current_quote_stamps_accepted_at_and_emits_quote_accepted(): void
    {
        $order = $this->makeOrder();

        $quote = $this->issue($order, [$this->line()]);

        $accepted = app(AcceptQuote::class)($quote, 'actor:customer-1');

        self::assertSame($quote->getKey(), $accepted->getKey());
        self::assertSame(QuoteStatus::ACCEPTED->value, $quote->fresh()->status);
        self::assertNotNull($quote->fresh()->accepted_at);
        self::assertSame('actor:customer-1', $quote->fresh()->accepted_by_ref);
        self::assertTrue($quote->fresh()->isAcceptedAndUnexpired(Carbon::now()));

        self::assertDatabaseHas('outbox_events', [
            'event_name' => 'quote.accepted.v1',
            'event_version' => 1,
            'aggregate_type' => 'quote',
            'aggregate_id' => $quote->getKey(),
            'classification' => OutboxClassification::Internal->value,
            'idempotency_key' => "quote_accepted:{$quote->getKey()}",
        ]);

        // Acceptance is single-use on a version: accepting again throws.
        try {
            app(AcceptQuote::class)($quote->fresh(), 'actor:customer-1');
            self::fail('Expected an already-accepted quote to be refused');
        } catch (InvalidArgumentException) {
            // expected
        }
    }

    public function test_current_for_returns_the_current_unsuperseded_quote_or_null(): void
    {
        $order = $this->makeOrder();

        self::assertNull(Quote::currentFor($order));

        $v1 = $this->issue($order, [$this->line()]);
        $currentV1 = Quote::currentFor($order);
        self::assertNotNull($currentV1);
        self::assertSame($v1->getKey(), $currentV1->getKey());

        $v2 = $this->issue($order, [$this->line(['unit_amount' => '1750000.00'])]);
        $currentV2 = Quote::currentFor($order);
        self::assertNotNull($currentV2);
        self::assertSame($v2->getKey(), $currentV2->getKey());
    }

    public function test_issuing_against_a_draft_package_version_is_rejected(): void
    {
        $order = $this->makeOrder();
        $draft = $this->draftVersion();
        $price = $this->priceVersion($draft);

        try {
            $this->issue($order, [$this->line([
                'service_package_version_id' => $draft->id,
                'price_version_id' => $price->id,
            ])]);
            self::fail('Expected a draft package version to be refused');
        } catch (InvalidArgumentException) {
            // expected
        }

        self::assertSame(0, Quote::query()->count());
    }

    private function makeOrder(OrderStatus $status = OrderStatus::PENAWARAN_TERKIRIM): Order
    {
        return Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => $status->value,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function issue(Order $order, array $lines, ?Carbon $expiresAt = null): Quote
    {
        return app(IssueQuote::class)(
            order: $order,
            lines: $lines,
            expiresAt: $expiresAt ?? Carbon::now()->addDays(7),
            actorRef: 'actor:admin-1',
            actorRole: 'admin',
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function line(array $overrides = []): array
    {
        $version = $this->publishedVersion();
        $price = $this->priceVersion($version);

        return array_merge([
            'service_package_version_id' => $version->id,
            'price_version_id' => $price->id,
            'price_version_number' => $price->version_number,
            'description' => 'Pemakaman TPA (jadwal umum)',
            'quantity' => 1,
            'unit_amount' => '1250000.00',
            'currency' => 'IDR',
            'fulfillment_owner' => FulfillmentOwner::PLATFORM,
        ], $overrides);
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

    private function draftVersion(): ServicePackageVersion
    {
        $package = (new DefineServicePackage)(
            code: 'PKG-'.Str::upper(Str::random(6)),
            name: 'Paket Uji Draft',
            items: [[
                'service_definition_id' => ServiceDefinition::findByCode(ServiceCode::GRAVE_DIGGING)->id,
                'item_type' => ServicePackageItemType::INCLUDED,
                'quantity' => 1,
                'unit' => 'paket',
                'fulfillment_owner' => FulfillmentOwner::PLATFORM,
            ]],
            actorReference: 7,
        );

        return $package->draftVersion();
    }

    private function priceVersion(ServicePackageVersion $version): PriceVersion
    {
        return PriceVersion::query()->create([
            'priceable_type' => ServicePackageVersion::class,
            'priceable_id' => $version->id,
            'version_number' => 1,
            'amount' => '1250000.00',
            'currency' => 'IDR',
            'source' => 'test fixture',
            'effective_from' => Carbon::now(),
            'recorded_by' => 'test',
        ]);
    }
}
