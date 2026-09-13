<?php

declare(strict_types=1);

namespace Tests\Feature\OrderWorkflow;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\OrderWorkflow\Actions\RecordOrderStatusChange;
use App\Domain\OrderWorkflow\Exceptions\OrderIsGuardedException;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotInventory\PlotState;
use App\Domain\PlotReservation\Actions\ReservePlot;
use App\Domain\Quotation\Actions\IssueQuote;
use App\Domain\Quotation\Models\Quote;
use App\Domain\Quotation\QuoteStatus;
use App\Domain\ServiceCatalog\FulfillmentOwner;
use App\Domain\ServiceCatalog\Models\PriceVersion;
use App\Domain\ServiceCatalog\Models\ServiceDefinition;
use App\Domain\ServiceCatalog\ServiceCode;
use App\Platform\FeatureGate\Models\FeatureGate;
use App\Platform\Payment\Models\PaymentIntent;
use App\Platform\Payment\Models\PaymentSession;
use App\Platform\Payment\PaymentIntentDecision;
use App\Platform\Payment\PaymentProviders;
use App\Platform\Payment\SessionState;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * H-2 — `orders:expire-stale-quotes` must not expire an order whose money is
 * in flight.
 *
 * The incident, verified end to end by a money audit: an order sits in
 * `MENUNGGU_PEMBAYARAN`, its quote lapses, the hourly sweep drives it to
 * `KEDALUWARSA`, and `RecordOrderStatusChange` releases its plot back to
 * `AVAILABLE` for any other customer to take — all while the customer is on
 * the provider's hosted checkout page. They then pay. `ApplyPaidEffects`
 * throws `forMissingAcceptedQuote`, the settlement rolls back, the job
 * dead-letters, and the payment is never mentioned by the product again.
 *
 * The plot assertion is the one that matters. An order that can be
 * un-expired is a support ticket; a plot that has been resold is not
 * recoverable, and this is a funeral service.
 *
 * The second half of this file is the half that stops the fix from being a
 * different bug: an order with no session, a `Failed` session, an `Expired`
 * session, or a session that has outlived its own clock must all still
 * expire. A sweep that stops expiring anything is not a fix.
 */
final class QuoteExpiryRespectsLivePaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // `PaymentSession`'s `creating` hook refuses to insert while the
        // payment gate is closed. The fixture needs real session rows, so the
        // gate is opened here exactly as the Payment suite's own tests do.
        FeatureGate::query()->where('gate_id', 'G-PAY-01')->update(['state' => 'open']);
    }

    // ---------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------

    private function makePlot(): GravePlot
    {
        $cemetery = Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::DRAFT,
            'name' => 'TPU Uji Coba',
            'slug' => 'tpu-uji-coba-'.Str::lower(Str::random(6)),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
        ]);
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

    private function makeOrder(OrderStatus $status): Order
    {
        return Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => $status->value,
        ]);
    }

    private function issueExpiredQuote(Order $order): Quote
    {
        $definition = ServiceDefinition::findByCode(ServiceCode::GRAVE_DIGGING);
        $price = PriceVersion::query()
            ->where('priceable_type', ServiceDefinition::class)
            ->where('priceable_id', $definition->id)
            ->sole();

        return app(IssueQuote::class)(
            $order,
            [[
                'service_definition_id' => $definition->id,
                'price_version_id' => $price->id,
                'price_version_number' => $price->version_number,
                'quantity' => 1,
                'unit_amount' => (string) $price->amount,
                'currency' => (string) $price->currency,
                'fulfillment_owner' => FulfillmentOwner::CEMETERY_OPERATOR,
            ]],
            CarbonImmutable::now()->subDay(),
            'system',
            'system',
        );
    }

    /**
     * `Quote::accept()` refuses an already-expired quote by design, so the
     * "accepted, then the window lapsed before payment" fixture cannot be
     * built through the Action. Bypasses the model guard purely to construct
     * the fixture — never as behaviour under test. Same technique, and same
     * reasoning, as `OrdersExpireStaleQuotesCommandTest::forceAccepted()`.
     */
    private function forceAccepted(Quote $quote): void
    {
        DB::table('quotes')->where('id', $quote->getKey())->update([
            'status' => QuoteStatus::ACCEPTED->value,
            'accepted_at' => CarbonImmutable::now()->subDays(2),
            'accepted_by_ref' => 'buyer:1',
        ]);
    }

    /**
     * A `payment_sessions` row in the given state, linked to the order the
     * way `OpenBookingOnlinePayment` links it — through the model's
     * `linkPaymentSession()` door, not a raw column write, so the test
     * exercises the real write path.
     *
     * @param  CarbonImmutable|null  $expiresAt  null models a provider that
     *                                           omitted `expires_at`, which
     *                                           is permitted: the field is
     *                                           not in
     *                                           `SumoPodPaymentClient::REQUIRED_RESPONSE_FIELDS`.
     */
    private function linkSession(
        Order $order,
        SessionState $state,
        ?CarbonImmutable $expiresAt,
        ?CarbonImmutable $createdAt = null,
    ): PaymentSession {
        $intent = PaymentIntent::query()->create([
            'requested_amount_minor' => 1_500_000_00,
            'currency' => 'IDR',
            'payment_mode' => 'online',
            'decision' => PaymentIntentDecision::Allowed->value,
            'actor_role' => 'customer',
            'evaluated_at' => CarbonImmutable::now(),
        ]);

        $session = PaymentSession::query()->create([
            'payment_intent_id' => $intent->id,
            'provider' => PaymentProviders::SUMOPOD_SANDBOX,
            'provider_payment_id' => 'pay_'.Str::lower(Str::random(10)),
            'payment_link_url' => 'https://checkout.sumopod.com/x',
            'amount_minor' => 1_500_000_00,
            'currency' => 'IDR',
            'merchant_ref' => 'merchant-test',
            'badan_usaha_ref' => 'BU-JKT-01',
            'state' => $state->value,
            'expires_at' => $expiresAt,
        ]);

        if ($createdAt !== null) {
            // `created_at` is managed by Eloquent, so an aged session has to
            // be aged directly. Fixture construction only.
            DB::table('payment_sessions')
                ->where('id', $session->getKey())
                ->update(['created_at' => $createdAt]);
            $session->refresh();
        }

        $order->linkPaymentSession($session);

        return $session;
    }

    // ---------------------------------------------------------------------
    // The incident
    // ---------------------------------------------------------------------

    public function test_the_sweep_leaves_an_order_alone_while_its_checkout_is_open(): void
    {
        $plot = $this->makePlot();
        $order = $this->makeOrder(OrderStatus::MASUK);
        (new ReservePlot)($plot, $order, "order:{$order->getKey()}", 'system');
        $order = $this->moveTo($order, OrderStatus::MENUNGGU_PEMBAYARAN);

        $quote = $this->issueExpiredQuote($order);
        $this->forceAccepted($quote);

        $this->linkSession(
            $order,
            SessionState::AwaitingPayment,
            CarbonImmutable::now()->addHours(6),
        );

        $this->artisan('orders:expire-stale-quotes')
            ->assertExitCode(0)
            ->expectsOutputToContain('Expired 0 order(s)');

        $this->assertSame(
            OrderStatus::MENUNGGU_PEMBAYARAN,
            $order->fresh()->status(),
            'an order with a live checkout must not be expired',
        );

        // The assertion the incident is actually about.
        $this->assertSame(
            PlotState::RESERVED,
            $plot->fresh()->plot_state,
            'the plot must NOT be released while the customer is paying for it',
        );
    }

    public function test_a_session_with_no_provider_expiry_is_live_inside_the_fallback_window(): void
    {
        $order = $this->makeOrder(OrderStatus::MENUNGGU_PEMBAYARAN);
        $quote = $this->issueExpiredQuote($order);
        $this->forceAccepted($quote);

        $this->linkSession($order, SessionState::AwaitingPayment, null);

        $this->artisan('orders:expire-stale-quotes')->assertExitCode(0);

        $this->assertSame(OrderStatus::MENUNGGU_PEMBAYARAN, $order->fresh()->status());
    }

    public function test_a_created_session_counts_as_live(): void
    {
        $order = $this->makeOrder(OrderStatus::MENUNGGU_PEMBAYARAN);
        $quote = $this->issueExpiredQuote($order);
        $this->forceAccepted($quote);

        $this->linkSession(
            $order,
            SessionState::Created,
            CarbonImmutable::now()->addHours(6),
        );

        $this->artisan('orders:expire-stale-quotes')->assertExitCode(0);

        $this->assertSame(OrderStatus::MENUNGGU_PEMBAYARAN, $order->fresh()->status());
    }

    // ---------------------------------------------------------------------
    // The half that must keep working
    // ---------------------------------------------------------------------

    public function test_an_order_with_no_session_at_all_still_expires(): void
    {
        $plot = $this->makePlot();
        $order = $this->makeOrder(OrderStatus::MASUK);
        (new ReservePlot)($plot, $order, "order:{$order->getKey()}", 'system');
        $order = $this->moveTo($order, OrderStatus::MENUNGGU_PEMBAYARAN);

        $quote = $this->issueExpiredQuote($order);
        $this->forceAccepted($quote);

        $this->assertNull($order->fresh()->payment_session_id);

        $this->artisan('orders:expire-stale-quotes')
            ->assertExitCode(0)
            ->expectsOutputToContain('Expired 1 order(s)');

        $this->assertSame(OrderStatus::KEDALUWARSA, $order->fresh()->status());
        $this->assertSame(PlotState::AVAILABLE, $plot->fresh()->plot_state);
    }

    /**
     * @return array<string, array{0: SessionState}>
     */
    public static function deadSessionStates(): array
    {
        return [
            'failed' => [SessionState::Failed],
            'expired' => [SessionState::Expired],
            'refunded' => [SessionState::Refunded],
        ];
    }

    #[DataProvider('deadSessionStates')]
    public function test_an_order_whose_session_is_not_live_still_expires(SessionState $state): void
    {
        $order = $this->makeOrder(OrderStatus::MENUNGGU_PEMBAYARAN);
        $quote = $this->issueExpiredQuote($order);
        $this->forceAccepted($quote);

        // Deliberately far in the FUTURE: the state alone must be enough to
        // expire the order. If this test only passed because the clock had
        // also run out, it would not be testing the state list at all.
        $this->linkSession($order, $state, CarbonImmutable::now()->addDays(30));

        $this->artisan('orders:expire-stale-quotes')
            ->assertExitCode(0)
            ->expectsOutputToContain('Expired 1 order(s)');

        $this->assertSame(OrderStatus::KEDALUWARSA, $order->fresh()->status());
    }

    public function test_an_awaiting_payment_session_past_its_own_expiry_still_expires(): void
    {
        $order = $this->makeOrder(OrderStatus::MENUNGGU_PEMBAYARAN);
        $quote = $this->issueExpiredQuote($order);
        $this->forceAccepted($quote);

        // Beta's one real session, reproduced: AWAITING_PAYMENT, created a
        // month ago, expired the day after. Nothing ever reconciled it. It
        // must not pin its order forever.
        $this->linkSession(
            $order,
            SessionState::AwaitingPayment,
            CarbonImmutable::now()->subDays(29),
            CarbonImmutable::now()->subDays(30),
        );

        $this->artisan('orders:expire-stale-quotes')
            ->assertExitCode(0)
            ->expectsOutputToContain('Expired 1 order(s)');

        $this->assertSame(OrderStatus::KEDALUWARSA, $order->fresh()->status());
    }

    public function test_a_null_expiry_session_older_than_the_fallback_window_still_expires(): void
    {
        $order = $this->makeOrder(OrderStatus::MENUNGGU_PEMBAYARAN);
        $quote = $this->issueExpiredQuote($order);
        $this->forceAccepted($quote);

        // No provider expiry AND older than the fallback bound. This is the
        // case that stops a provider which omits `expires_at` from making
        // orders un-expirable forever.
        $this->linkSession(
            $order,
            SessionState::AwaitingPayment,
            null,
            CarbonImmutable::now()->subDays(3),
        );

        $this->artisan('orders:expire-stale-quotes')
            ->assertExitCode(0)
            ->expectsOutputToContain('Expired 1 order(s)');

        $this->assertSame(OrderStatus::KEDALUWARSA, $order->fresh()->status());
    }

    // ---------------------------------------------------------------------
    // The write door
    // ---------------------------------------------------------------------

    public function test_an_unsaved_session_cannot_link_itself_to_an_order(): void
    {
        $order = $this->makeOrder(OrderStatus::MENUNGGU_PEMBAYARAN);

        $forged = new PaymentSession;
        $forged->forceFill(['id' => (string) Str::uuid()]);

        $this->expectException(OrderIsGuardedException::class);

        $order->linkPaymentSession($forged);
    }

    /**
     * Moves an order along the real transition path, because
     * `OrderTransition::ALLOWED` will not jump straight from `MASUK` to
     * `MENUNGGU_PEMBAYARAN`.
     */
    private function moveTo(Order $order, OrderStatus $target): Order
    {
        $path = [
            OrderStatus::DIVERIFIKASI,
            OrderStatus::MENUNGGU_KETERSEDIAAN,
            OrderStatus::PENAWARAN_TERKIRIM,
            OrderStatus::DISETUJUI_PEMESAN,
            $target,
        ];

        foreach ($path as $status) {
            app(RecordOrderStatusChange::class)(
                $order,
                $status,
                'system',
                'system',
            );
        }

        return $order->fresh();
    }
}
