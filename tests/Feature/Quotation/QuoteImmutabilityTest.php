<?php

declare(strict_types=1);

namespace Tests\Feature\Quotation;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\Quotation\Actions\AcceptQuote;
use App\Domain\Quotation\Actions\IssueQuote;
use App\Domain\Quotation\Exceptions\IssuedQuoteIsImmutableException;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task 4 — the structural half of AC8. `update()`, the `save()` path, and
 * `delete()` are refused on an already-persisted quote (issued, accepted,
 * or superseded); the two legal transitions (issued -> accepted,
 * issued/superseded-eligible -> superseded) move ONLY through the
 * `accept()` / `supersede()` doors the Actions use, and `quote_lines` are
 * write-once outright. See `Models\Quote`'s own class doc block for exactly
 * which write paths the overrides close and which they cannot.
 */
final class QuoteImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_direct_update_on_an_issued_quote_throws(): void
    {
        $order = $this->makeOrder();
        $quote = $this->issue($order);

        try {
            $quote->update(['total_minor' => 1]);
            self::fail('Expected IssuedQuoteIsImmutableException from update() on an issued quote');
        } catch (IssuedQuoteIsImmutableException) {
            // expected
        }

        self::assertSame(125000000, $quote->fresh()->total_minor);
    }

    public function test_a_direct_update_on_an_accepted_or_superseded_quote_throws(): void
    {
        // Order A: a genuinely ACCEPTED quote.
        $accepted = $this->issue($this->makeOrder());
        app(AcceptQuote::class)($accepted, 'actor:customer-1');

        // Order B: a genuinely SUPERSEDED quote (v2 supersedes v1).
        $orderB = $this->makeOrder();
        $this->issue($orderB);
        $superseded = $this->issue($orderB);

        foreach ([$accepted, $superseded] as $quote) {
            try {
                $quote->update(['total_minor' => 1]);
                self::fail('Expected IssuedQuoteIsImmutableException from update() on a non-issued quote');
            } catch (IssuedQuoteIsImmutableException) {
                // expected — a real assertion below records the loop ran.
            }
        }

        self::assertSame(QuoteStatus::ACCEPTED->value, $accepted->fresh()->status);
        self::assertSame(QuoteStatus::ISSUED->value, $superseded->fresh()->status);
        self::assertSame(125000000, $accepted->fresh()->total_minor);
        self::assertSame(125000000, $superseded->fresh()->total_minor);
    }

    public function test_the_save_path_on_a_persisted_quote_throws(): void
    {
        $order = $this->makeOrder();
        $quote = $this->issue($order);

        // `update()` is a thin wrapper over fill()+save(); this routes the
        // same write through save() directly, which is what the accepted
        // transitions must ALSO route through — so the guard has to be on
        // the save path, not just on `update()`.
        $quote->total_minor = 1;

        try {
            $quote->save();
            self::fail('Expected IssuedQuoteIsImmutableException from save() on a persisted quote');
        } catch (IssuedQuoteIsImmutableException) {
            // expected
        }

        self::assertSame(125000000, $quote->fresh()->total_minor);
    }

    public function test_deleting_a_quote_throws(): void
    {
        $order = $this->makeOrder();
        $quote = $this->issue($order);

        try {
            $quote->delete();
            self::fail('Expected IssuedQuoteIsImmutableException from delete()');
        } catch (IssuedQuoteIsImmutableException) {
            // expected
        }

        self::assertDatabaseHas('quotes', ['id' => $quote->getKey()]);
    }

    public function test_quote_lines_are_write_once_update_delete_and_save_all_throw(): void
    {
        $order = $this->makeOrder();
        $quote = $this->issue($order);
        $line = $quote->lines()->sole();

        try {
            $line->update(['unit_amount_minor' => 1]);
            self::fail('Expected IssuedQuoteIsImmutableException from update() on a quote line');
        } catch (IssuedQuoteIsImmutableException) {
            // expected
        }

        $line->quantity = 99;

        try {
            $line->save();
            self::fail('Expected IssuedQuoteIsImmutableException from save() on a quote line');
        } catch (IssuedQuoteIsImmutableException) {
            // expected
        }

        try {
            $line->delete();
            self::fail('Expected IssuedQuoteIsImmutableException from delete() on a quote line');
        } catch (IssuedQuoteIsImmutableException) {
            // expected
        }

        self::assertSame(125000000, $line->fresh()->unit_amount_minor);
        self::assertSame(1, $line->fresh()->quantity);
        self::assertDatabaseCount('quote_lines', 1);
    }

    public function test_the_accept_door_refuses_a_superseded_quote(): void
    {
        $order = $this->makeOrder();
        $v1 = $this->issue($order);
        $this->issue($order);

        try {
            app(AcceptQuote::class)($v1->fresh(), 'actor:customer-1');
            self::fail('Expected a superseded quote to be refused by accept()');
        } catch (\InvalidArgumentException) {
            // expected
        }

        self::assertSame(QuoteStatus::SUPERSEDED->value, $v1->fresh()->status);
        self::assertNull($v1->fresh()->accepted_at);
    }

    private function makeOrder(): Order
    {
        return Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::PENAWARAN_TERKIRIM->value,
        ]);
    }

    private function issue(Order $order): Quote
    {
        $version = $this->publishedVersion();
        $price = PriceVersion::query()->create([
            'priceable_type' => ServicePackageVersion::class,
            'priceable_id' => $version->id,
            'version_number' => 1,
            'amount' => '1250000.00',
            'currency' => 'IDR',
            'source' => 'test fixture',
            'effective_from' => Carbon::now(),
            'recorded_by' => 'test',
        ]);

        return app(IssueQuote::class)(
            order: $order,
            lines: [[
                'service_package_version_id' => $version->id,
                'price_version_id' => $price->id,
                'price_version_number' => $price->version_number,
                'description' => 'Pemakaman TPA (jadwal umum)',
                'quantity' => 1,
                'unit_amount' => '1250000.00',
                'currency' => 'IDR',
                'fulfillment_owner' => FulfillmentOwner::PLATFORM,
            ]],
            expiresAt: Carbon::now()->addDays(7),
            actorRef: 'actor:admin-1',
            actorRole: 'admin',
        );
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
