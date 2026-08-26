<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\Quotation\Actions\IssueQuote;
use App\Domain\Quotation\Models\Quote;
use App\Domain\ServiceCatalog\Models\ServiceDefinition;
use App\Domain\ServiceCatalog\ServiceCode;
use App\Filament\Admin\Resources\BookingOrders\Pages\ViewBookingOrder;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * The `BookingOrderInfolist` "Penawaran" section previously rendered only a
 * bundled total (`Rp <total> · <status>`) — `Quote::currentFor()->lines`
 * (a real `hasMany` on `Quote`, each `QuoteLine` carrying `description` /
 * `quantity` / `unit_amount_minor` / `line_total_minor`) was loaded nowhere.
 * This proves the line-item breakdown now renders alongside the total,
 * mirroring `MarketplaceOrderInfolist`'s own `RepeatableEntry::make('items')`
 * precedent.
 */
final class BookingOrderQuoteLineItemsTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);
    }

    private function orderFor(BookingDraft $draft): Order
    {
        return Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::PENAWARAN_TERKIRIM->value,
            'booking_draft_id' => $draft->getKey(),
        ]);
    }

    /**
     * A real, issued quote with two real service lines — the same
     * `ComposeQuoteLinesFromBookingDraft` -> `IssueQuote` shape the booking
     * wizard's own online-payment chain produces, so the frozen `quote_lines`
     * rows satisfy their real FKs (`service_definitions`, `price_versions`)
     * rather than pointing at fabricated ids.
     */
    private function issueQuoteFor(Order $order): Quote
    {
        $document = ServiceDefinition::findByCode(ServiceCode::DOCUMENT_PROCESSING);
        $grave = ServiceDefinition::findByCode(ServiceCode::GRAVE_DIGGING);
        $this->assertNotNull($document);
        $this->assertNotNull($grave);

        $documentPrice = $document->currentPriceVersion();
        $gravePrice = $grave->currentPriceVersion();
        $this->assertNotNull($documentPrice);
        $this->assertNotNull($gravePrice);

        return (new IssueQuote)(
            $order,
            [
                [
                    'service_definition_id' => $document->getKey(),
                    'price_version_id' => $documentPrice->getKey(),
                    'price_version_number' => $documentPrice->version_number,
                    'quantity' => 1,
                    'unit_amount' => (string) $documentPrice->amount,
                    'currency' => (string) $documentPrice->currency,
                    'fulfillment_owner' => $document->fulfillment_owner,
                ],
                [
                    'service_definition_id' => $grave->getKey(),
                    'price_version_id' => $gravePrice->getKey(),
                    'price_version_number' => $gravePrice->version_number,
                    'quantity' => 2,
                    'unit_amount' => (string) $gravePrice->amount,
                    'currency' => (string) $gravePrice->currency,
                    'fulfillment_owner' => $grave->fulfillment_owner,
                ],
            ],
            now()->addDays(7),
            'actor:admin-1',
            'admin',
        );
    }

    /**
     * Matches `BookingOrderInfolist::moneyString()` (and its
     * `MarketplaceOrderInfolist` precedent) exactly, rather than
     * `Money::format()`, which can differ on a non-whole-rupiah fraction.
     */
    private function moneyString(int $amountMinor): string
    {
        return 'Rp '.number_format($amountMinor / 100, 0, ',', '.');
    }

    public function test_the_quote_line_items_render_alongside_the_total(): void
    {
        $draft = BookingDraft::query()->create(['customer_full_name' => 'UAT Pemesan']);
        $order = $this->orderFor($draft);
        $quote = $this->issueQuoteFor($order);
        $lines = $quote->lines()->orderBy('id')->get();

        $component = Livewire::test(ViewBookingOrder::class, ['record' => $order->getRouteKey()])
            ->assertOk();

        foreach ($lines as $line) {
            $component->assertSee($line->description)
                ->assertSee($this->moneyString($line->unit_amount_minor))
                ->assertSee($this->moneyString($line->line_total_minor));
        }

        // The pre-existing bundled total stays too — this is additive, not
        // a replacement.
        $component->assertSee($this->moneyString($quote->totalMinor()->toMinorInt()));
    }

    public function test_an_unquoted_order_shows_the_honest_placeholder_instead_of_an_error(): void
    {
        $draft = BookingDraft::query()->create(['customer_full_name' => 'UAT Pemesan']);
        $order = $this->orderFor($draft);

        Livewire::test(ViewBookingOrder::class, ['record' => $order->getRouteKey()])
            ->assertOk()
            ->assertSee('Belum ada penawaran');
    }
}
