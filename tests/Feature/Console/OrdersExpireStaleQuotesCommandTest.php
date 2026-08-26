<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\Quotation\Actions\IssueQuote;
use App\Domain\Quotation\Models\Quote;
use App\Domain\Quotation\QuoteStatus;
use App\Domain\ServiceCatalog\FulfillmentOwner;
use App\Domain\ServiceCatalog\Models\PriceVersion;
use App\Domain\ServiceCatalog\Models\ServiceDefinition;
use App\Domain\ServiceCatalog\ServiceCode;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `orders:expire-stale-quotes` — the deferred half of Task 4's ratified
 * design (Q4/Q5) in
 * `docs/superpowers/plans/2026-08-12-platform-order-orchestration.md:592`.
 * Read-model honesty only: writes `KEDALUWARSA` on an order whose current
 * quote has passed `expires_at`, and is a no-op for everything else.
 */
final class OrdersExpireStaleQuotesCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(OrderStatus $status): Order
    {
        return Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => $status->value,
        ]);
    }

    private function issueQuote(Order $order, CarbonImmutable $expiresAt): Quote
    {
        $definition = ServiceDefinition::findByCode(ServiceCode::GRAVE_DIGGING);
        $price = PriceVersion::query()
            ->where('priceable_type', ServiceDefinition::class)
            ->where('priceable_id', $definition->id)
            ->sole();

        return app(IssueQuote::class)(
            $order,
            [
                [
                    'service_definition_id' => $definition->id,
                    'price_version_id' => $price->id,
                    'price_version_number' => $price->version_number,
                    'quantity' => 1,
                    'unit_amount' => (string) $price->amount,
                    'currency' => (string) $price->currency,
                    'fulfillment_owner' => FulfillmentOwner::CEMETERY_OPERATOR,
                ],
            ],
            $expiresAt,
            'system',
            'system',
        );
    }

    /**
     * `AcceptQuote`/`Quote::accept()` both refuse an already-expired quote
     * by design, so an "accepted, then the window lapsed before payment"
     * fixture cannot be built through the Action. This bypasses the model's
     * write guard the same way the guard's own doc block says any process
     * with direct database credentials can — purely to construct the
     * fixture, never as something this test exercises as behavior.
     */
    private function forceAccepted(Quote $quote, CarbonImmutable $acceptedAt): void
    {
        DB::table('quotes')->where('id', $quote->getKey())->update([
            'status' => QuoteStatus::ACCEPTED->value,
            'accepted_at' => $acceptedAt,
            'accepted_by_ref' => 'buyer:1',
        ]);
    }

    public function test_an_order_with_an_expired_issued_quote_becomes_kedaluwarsa(): void
    {
        $order = $this->makeOrder(OrderStatus::PENAWARAN_TERKIRIM);
        $this->issueQuote($order, CarbonImmutable::now()->subDay());

        $this->artisan('orders:expire-stale-quotes')
            ->assertExitCode(0)
            ->expectsOutputToContain('Expired 1 order(s)');

        $this->assertSame(OrderStatus::KEDALUWARSA, $order->fresh()->status());
        $this->assertDatabaseHas('order_status_events', [
            'order_id' => $order->getKey(),
            'to_status' => 'KEDALUWARSA',
            'actor_ref' => 'system',
            'actor_role' => 'system',
        ]);
    }

    public function test_an_order_with_an_expired_accepted_quote_awaiting_payment_becomes_kedaluwarsa(): void
    {
        $order = $this->makeOrder(OrderStatus::MENUNGGU_PEMBAYARAN);
        $quote = $this->issueQuote($order, CarbonImmutable::now()->subDay());
        $this->forceAccepted($quote, CarbonImmutable::now()->subDays(2));

        $this->artisan('orders:expire-stale-quotes')
            ->assertExitCode(0)
            ->expectsOutputToContain('Expired 1 order(s)');

        $this->assertSame(OrderStatus::KEDALUWARSA, $order->fresh()->status());
    }

    public function test_a_non_expired_quote_is_untouched(): void
    {
        $order = $this->makeOrder(OrderStatus::PENAWARAN_TERKIRIM);
        $this->issueQuote($order, CarbonImmutable::now()->addDays(30));

        $this->artisan('orders:expire-stale-quotes')
            ->assertExitCode(0)
            ->expectsOutputToContain('Expired 0 order(s)');

        $this->assertSame(OrderStatus::PENAWARAN_TERKIRIM, $order->fresh()->status());
    }

    public function test_an_already_terminal_order_is_untouched(): void
    {
        $order = $this->makeOrder(OrderStatus::MENUNGGU_VERIFIKASI_PEMBAYARAN);
        $quote = $this->issueQuote($order, CarbonImmutable::now()->subDay());
        $this->forceAccepted($quote, CarbonImmutable::now()->subDays(2));
        Order::query()->where('id', $order->getKey())->update(['status' => OrderStatus::DIBAYAR->value]);

        $this->artisan('orders:expire-stale-quotes')
            ->assertExitCode(0)
            ->expectsOutputToContain('Expired 0 order(s)');

        $this->assertSame(OrderStatus::DIBAYAR, $order->fresh()->status());
    }

    public function test_the_command_is_idempotent_across_repeated_runs(): void
    {
        $order = $this->makeOrder(OrderStatus::DISETUJUI_PEMESAN);
        $this->issueQuote($order, CarbonImmutable::now()->subDay());

        $this->artisan('orders:expire-stale-quotes')
            ->assertExitCode(0)
            ->expectsOutputToContain('Expired 1 order(s)');
        $this->artisan('orders:expire-stale-quotes')
            ->assertExitCode(0)
            ->expectsOutputToContain('Expired 0 order(s)');

        $this->assertSame(OrderStatus::KEDALUWARSA, $order->fresh()->status());
        $this->assertDatabaseCount('order_status_events', 1);
    }
}
