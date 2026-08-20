<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Domain\Marketplace\Models\MarketplaceOrder;
use App\Domain\Marketplace\Models\Vendor;
use App\Domain\Marketplace\PaymentState;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\FeatureGate\Contracts\GateRegistrySource;
use App\Platform\FeatureGate\FeatureGateResolver;
use App\Platform\FeatureGate\GateRegistrySnapshot;
use App\Platform\FeatureGate\GateState;
use App\Platform\FeatureGate\ModeResolver;
use App\Platform\Payment\Actions\OpenPaymentSession;
use App\Platform\Payment\Actions\OpenPaymentSessionCommand;
use App\Platform\Payment\Checkout\Exceptions\PaymentCheckoutProviderException;
use App\Platform\Payment\Exceptions\PaymentSessionMerchantMismatchException;
use App\Platform\Payment\Exceptions\PaymentSessionOpeningDeniedException;
use App\Platform\Payment\Exceptions\PaymentSessionOrderAlreadyPaidException;
use App\Platform\Payment\Exceptions\PaymentSessionOrderNotFoundException;
use App\Platform\Payment\Models\PaymentIntent;
use App\Platform\Payment\Models\PaymentSession;
use App\Platform\Payment\OrderType;
use App\Platform\Payment\PaymentAuditActions;
use App\Platform\Payment\PaymentProviders;
use App\Platform\Payment\SessionState;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The marketplace follow-up to `OpenPaymentSessionTest`: `OrderType::
 * Marketplace` opening through `App\Domain\Marketplace\Actions\
 * GuardMarketplacePaymentOpening` instead of the booking six-condition
 * guard. Mirrors that file's fixture/assertion style so the two order
 * types' coverage stays comparable.
 */
final class OpenPaymentSessionMarketplaceTest extends TestCase
{
    use RefreshDatabase;

    private const string MERCHANT_REF = 'mk-merchant-dev';

    private const string BADAN_USAHA_REF = 'badan-usaha-dev';

    private const int ORDER_TOTAL_MINOR = 175_000_00;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'payment.merchant_ref' => self::MERCHANT_REF,
            'payment.badan_usaha_ref' => self::BADAN_USAHA_REF,
            'payment.providers.'.PaymentProviders::SUMOPOD_SANDBOX.'.api_key' => 'test-key',
        ]);
    }

    private function fakeProviderResponse(array $body, int $status = 201): void
    {
        Http::fake([
            'api-pay-sandbox.sumopod.com/api/v1/payments' => Http::response($body, $status),
        ]);
    }

    private function fakeProviderSuccess(): void
    {
        $this->fakeProviderResponse([
            'payment_id' => 'uuid-mkt-1',
            'order_id' => 'MKT-ORD-1',
            // Whole rupiah — the provider's wire unit (Rp 175.000), the
            // major-unit form of self::ORDER_TOTAL_MINOR (175_000_00).
            'amount' => 175_000,
            'fee' => 1_525,
            'net_amount' => 173_475,
            'payment_link_url' => 'https://checkout.sumopod.com/mkt',
            'status' => 'pending',
        ]);
    }

    private function guardWithPaymentGate(bool $open): void
    {
        $source = new class($open) implements GateRegistrySource
        {
            public function __construct(private readonly bool $open) {}

            public function load(): GateRegistrySnapshot
            {
                return new GateRegistrySnapshot([
                    'G-PAY-01' => GateState::fromRecord('G-PAY-01', open: $this->open),
                ]);
            }
        };

        $this->app->instance(ModeResolver::class, new ModeResolver(new FeatureGateResolver($source)));
    }

    private function makeOrder(
        string $paymentState = PaymentState::BELUM_DIBAYAR,
        int $totalMinor = self::ORDER_TOTAL_MINOR,
    ): MarketplaceOrder {
        $vendor = Vendor::create(['name' => 'Toko Bunga', 'is_active' => true]);

        return MarketplaceOrder::query()->create([
            'order_number' => 'MKT-ORD-1',
            'customer_ref' => 'customer:test-1',
            'entity_ref' => 'badan-usaha-marketplace',
            'vendor_id' => $vendor->id,
            'subtotal_minor' => $totalMinor,
            'delivery_fee_minor' => 0,
            'total_minor' => $totalMinor,
            'payment_state' => $paymentState,
            'idempotency_key' => (string) Str::uuid(),
            'placed_at' => CarbonImmutable::now(),
        ]);
    }

    private function command(array $overrides = []): OpenPaymentSessionCommand
    {
        return new OpenPaymentSessionCommand(
            orderType: OrderType::Marketplace,
            orderRef: $overrides['orderRef'] ?? 'MKT-ORD-1',
            amountMinor: $overrides['amountMinor'] ?? self::ORDER_TOTAL_MINOR,
            merchantRef: $overrides['merchantRef'] ?? self::MERCHANT_REF,
            successReturnUrl: $overrides['successReturnUrl'] ?? 'https://makam.test/payment/success',
            cancelReturnUrl: $overrides['cancelReturnUrl'] ?? 'https://makam.test/payment/cancelled',
        );
    }

    public function test_gate_open_with_all_preconditions_creates_a_session_from_the_provider_link(): void
    {
        $this->guardWithPaymentGate(open: true);
        $this->makeOrder();
        $this->fakeProviderSuccess();

        $session = app(OpenPaymentSession::class)($this->command());

        $this->assertInstanceOf(PaymentSession::class, $session);
        $this->assertSame(SessionState::AwaitingPayment->value, $session->state);
        $this->assertSame('uuid-mkt-1', $session->provider_payment_id);
        $this->assertSame('https://checkout.sumopod.com/mkt', $session->payment_link_url);
        $this->assertSame(self::ORDER_TOTAL_MINOR, $session->amount_minor);
        $this->assertSame(self::MERCHANT_REF, $session->merchant_ref);
        $this->assertSame(self::BADAN_USAHA_REF, $session->badan_usaha_ref);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/v1/payments')
            && $request['order_id'] === 'MKT-ORD-1');
    }

    public function test_the_opening_writes_an_allowed_intent_and_audit(): void
    {
        $this->guardWithPaymentGate(open: true);
        $this->makeOrder();
        $this->fakeProviderSuccess();

        $session = app(OpenPaymentSession::class)($this->command());

        $intent = PaymentIntent::query()->whereKey($session->payment_intent_id)->sole();
        $this->assertSame('allowed', $intent->decision);

        $event = AuditEvent::query()
            ->where('action', PaymentAuditActions::SESSION_OPENED)
            ->sole();
        $this->assertSame(AuditOutcome::Allowed->value, $event->outcome);
        $this->assertSame('payment_session', $event->subject_type);
    }

    public function test_a_closed_gate_denies_without_creating_a_session(): void
    {
        $this->guardWithPaymentGate(open: false);
        $this->makeOrder();
        Http::fake();

        try {
            app(OpenPaymentSession::class)($this->command());
            $this->fail('Expected PaymentSessionOpeningDeniedException to be thrown.');
        } catch (PaymentSessionOpeningDeniedException $exception) {
            $this->assertStringContainsString(
                'Online payment is not currently available',
                $exception->publicMessage(),
            );
        }

        $this->assertSame(0, PaymentSession::query()->count());
        Http::assertNothingSent();

        $event = AuditEvent::query()
            ->where('action', 'MARKETPLACE_PAYMENT_OPENING_DENIED')
            ->sole();
        $this->assertSame('denied', $event->outcome);
        $this->assertSame('marketplace_order', $event->subject_type);
    }

    public function test_an_order_not_awaiting_payment_is_denied(): void
    {
        $this->guardWithPaymentGate(open: true);
        $this->makeOrder(PaymentState::MENUNGGU_VERIFIKASI);
        Http::fake();

        try {
            app(OpenPaymentSession::class)($this->command());
            $this->fail('Expected PaymentSessionOpeningDeniedException to be thrown.');
        } catch (PaymentSessionOpeningDeniedException $exception) {
            $this->assertStringContainsString('not awaiting payment', $exception->publicMessage());
        }

        $this->assertSame(0, PaymentSession::query()->count());
        Http::assertNothingSent();
    }

    public function test_an_amount_mismatch_is_denied(): void
    {
        $this->guardWithPaymentGate(open: true);
        $this->makeOrder();
        Http::fake();

        try {
            app(OpenPaymentSession::class)($this->command(['amountMinor' => self::ORDER_TOTAL_MINOR + 1]));
            $this->fail('Expected PaymentSessionOpeningDeniedException to be thrown.');
        } catch (PaymentSessionOpeningDeniedException $exception) {
            $this->assertStringContainsString('does not match the order total', $exception->publicMessage());
        }

        $this->assertSame(0, PaymentSession::query()->count());
        Http::assertNothingSent();
    }

    public function test_an_unbound_merchant_denies_before_the_provider_is_called(): void
    {
        $this->guardWithPaymentGate(open: true);
        $this->makeOrder();
        config(['payment.merchant_ref' => '', 'payment.badan_usaha_ref' => '']);
        Http::fake();

        try {
            app(OpenPaymentSession::class)($this->command(['merchantRef' => '']));
            $this->fail('Expected PaymentSessionOpeningDeniedException to be thrown.');
        } catch (PaymentSessionOpeningDeniedException $exception) {
            $this->assertStringContainsString('merchant and business-entity binding', $exception->publicMessage());
        }

        $this->assertSame(0, PaymentSession::query()->count());
        Http::assertNothingSent();
    }

    public function test_an_unknown_order_reference_is_refused_before_the_guard(): void
    {
        $this->guardWithPaymentGate(open: true);
        Http::fake();

        try {
            app(OpenPaymentSession::class)($this->command(['orderRef' => 'MKT-NOPE']));
            $this->fail('Expected PaymentSessionOrderNotFoundException to be thrown.');
        } catch (PaymentSessionOrderNotFoundException $exception) {
            $this->assertStringContainsString('MKT-NOPE', $exception->getMessage());
        }

        $this->assertSame(0, PaymentSession::query()->count());
        Http::assertNothingSent();
    }

    /**
     * Mirrors `OpenPaymentSessionTest::
     * test_an_already_paid_order_cannot_open_a_new_session` — a DIBAYAR
     * marketplace order must be refused BEFORE the guard runs, so a resumed
     * checkout cannot open a second session for an order already paid.
     */
    public function test_an_already_paid_order_cannot_open_a_new_session(): void
    {
        $this->guardWithPaymentGate(open: true);
        $order = $this->makeOrder(PaymentState::DIBAYAR);
        Http::fake();

        try {
            app(OpenPaymentSession::class)($this->command());
            $this->fail('Expected PaymentSessionOrderAlreadyPaidException to be thrown.');
        } catch (PaymentSessionOrderAlreadyPaidException $exception) {
            $this->assertStringContainsString('MKT-ORD-1', $exception->getMessage());
        }

        $this->assertSame(0, PaymentSession::query()->count());
        Http::assertNothingSent();

        $audit = AuditEvent::query()
            ->where('action', PaymentAuditActions::SESSION_OPENING_REFUSED)
            ->sole();
        $this->assertSame('marketplace_order', $audit->subject_type);
        $this->assertSame((string) $order->getKey(), $audit->subject_id);
        $this->assertSame('denied', $audit->outcome);
    }

    public function test_a_merchant_ref_that_is_not_the_bound_merchant_fails_closed(): void
    {
        $this->guardWithPaymentGate(open: true);
        $this->makeOrder();
        Http::fake();

        try {
            app(OpenPaymentSession::class)($this->command(['merchantRef' => 'some-other-merchant']));
            $this->fail('Expected PaymentSessionMerchantMismatchException to be thrown.');
        } catch (PaymentSessionMerchantMismatchException) {
            // A session must never open under a merchant this deployment does not serve.
        }

        $this->assertSame(0, PaymentSession::query()->count());
        Http::assertNothingSent();
    }

    public function test_a_provider_failure_leaves_no_session(): void
    {
        $this->guardWithPaymentGate(open: true);
        $this->makeOrder();
        $this->fakeProviderResponse(['error' => 'bad'], 400);

        try {
            app(OpenPaymentSession::class)($this->command());
            $this->fail('Expected PaymentCheckoutProviderException to be thrown.');
        } catch (PaymentCheckoutProviderException) {
            // Expected: the provider refused; nothing may be recorded.
        }

        $this->assertSame(0, PaymentSession::query()->count());
    }
}
