<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Domain\Marketplace\Models\MarketplaceOrder;
use App\Domain\Marketplace\Models\Vendor;
use App\Domain\Marketplace\PaymentState;
use App\Platform\FeatureGate\Models\FeatureGate;
use App\Platform\FinancialLedger\Actions\VendorPayable;
use App\Platform\FinancialLedger\Money;
use App\Platform\FinancialLedger\VendorPayableAssessmentTrigger;
use App\Platform\FinancialLedger\VendorPayableEligibility;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\Payment\Actions\ReconcilePaymentSession;
use App\Platform\Payment\Checkout\Contracts\PaymentCheckoutClient;
use App\Platform\Payment\Checkout\CreatePaymentRequest;
use App\Platform\Payment\Checkout\PaymentCheckoutResult;
use App\Platform\Payment\Checkout\PaymentStatusResult;
use App\Platform\Payment\Jobs\ReconcileStalePaymentSessionsJob;
use App\Platform\Payment\Models\PaymentIntent;
use App\Platform\Payment\Models\PaymentSession;
use App\Platform\Payment\Models\ProviderEvent;
use App\Platform\Payment\PaymentIntentDecision;
use App\Platform\Payment\PaymentProviders;
use App\Platform\Payment\SessionState;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * `Jobs\ReconcileStalePaymentSessionsJob` — the scheduled-sweep half of
 * `Actions\ReconcilePaymentSession`. See that class's doc block for why this
 * exists (the on-return check only fires when the customer's browser comes
 * back; this catches every case that never does).
 */
final class ReconcileStalePaymentSessionsJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reconciles_a_stale_awaiting_payment_session(): void
    {
        $order = $this->marketplaceTarget('order_sweep_1');
        $session = $this->staleSession('pay_sweep_1', 250_000);
        $this->fakeStatuses(['pay_sweep_1' => ['order_sweep_1', 'completed']]);

        $this->job()->handle(app(ReconcilePaymentSession::class));

        $this->assertSame(PaymentState::DIBAYAR, $order->fresh()->payment_state);
        $this->assertSame(SessionState::Paid->value, $session->fresh()->state);
    }

    public function test_it_skips_a_session_that_is_not_yet_stale(): void
    {
        $session = $this->staleSession('pay_sweep_fresh', 250_000, ageMinutes: 1);
        $this->fakeStatuses(['pay_sweep_fresh' => ['order_sweep_fresh', 'completed']], failIfCalled: true);

        $this->job()->handle(app(ReconcilePaymentSession::class));

        $this->assertSame(SessionState::AwaitingPayment->value, $session->fresh()->state);
        $this->assertSame(0, ProviderEvent::query()->count());
    }

    public function test_a_failing_session_does_not_block_the_rest_of_the_sweep(): void
    {
        $order = $this->marketplaceTarget('order_sweep_ok');
        $failing = $this->staleSession('pay_sweep_bad', 100_000);
        $ok = $this->staleSession('pay_sweep_ok', 250_000);

        $this->app->instance(PaymentCheckoutClient::class, new class implements PaymentCheckoutClient
        {
            public function createPayment(CreatePaymentRequest $request): PaymentCheckoutResult
            {
                throw new LogicException('not exercised');
            }

            public function fetchStatus(string $providerPaymentId): PaymentStatusResult
            {
                if ($providerPaymentId === 'pay_sweep_bad') {
                    throw new LogicException('provider unreachable');
                }

                return new PaymentStatusResult(
                    paymentId: $providerPaymentId,
                    orderId: 'order_sweep_ok',
                    status: 'completed',
                    amountMinor: 250_000,
                    feeMinor: 0,
                    netAmountMinor: 250_000,
                    paymentMethod: 'qris',
                    completedAt: CarbonImmutable::now(),
                );
            }
        });

        $this->expectException(LogicException::class);

        try {
            $this->job()->handle(app(ReconcilePaymentSession::class));
        } finally {
            // Even though the job rethrows the failing session's exception
            // (so the queue's own retry/backoff applies), the OTHER stale
            // session must still have been reconciled.
            $this->assertSame(PaymentState::DIBAYAR, $order->fresh()->payment_state);
            $this->assertSame(SessionState::Paid->value, $ok->fresh()->state);
            $this->assertSame(SessionState::AwaitingPayment->value, $failing->fresh()->state);
        }
    }

    private function job(): ReconcileStalePaymentSessionsJob
    {
        return new ReconcileStalePaymentSessionsJob;
    }

    /**
     * @param  array<string, array{0: string, 1: string}>  $byPaymentId  provider_payment_id => [order_id, status]
     */
    private function fakeStatuses(array $byPaymentId, bool $failIfCalled = false): void
    {
        $this->app->instance(PaymentCheckoutClient::class, new class($byPaymentId, $failIfCalled) implements PaymentCheckoutClient
        {
            public function __construct(
                private array $byPaymentId,
                private bool $failIfCalled,
            ) {}

            public function createPayment(CreatePaymentRequest $request): PaymentCheckoutResult
            {
                throw new LogicException('not exercised by these tests');
            }

            public function fetchStatus(string $providerPaymentId): PaymentStatusResult
            {
                if ($this->failIfCalled) {
                    throw new LogicException("fetchStatus must not be called for [{$providerPaymentId}]");
                }

                [$orderId, $status] = $this->byPaymentId[$providerPaymentId]
                    ?? throw new LogicException("no fixture status for [{$providerPaymentId}]");

                return new PaymentStatusResult(
                    paymentId: $providerPaymentId,
                    orderId: $orderId,
                    status: $status,
                    amountMinor: 250_000,
                    feeMinor: 0,
                    netAmountMinor: 250_000,
                    paymentMethod: 'qris',
                    completedAt: CarbonImmutable::now(),
                );
            }
        });
    }

    private function staleSession(string $providerPaymentId, int $amountMinor, int $ageMinutes = 10): PaymentSession
    {
        FeatureGate::query()->where('gate_id', 'G-PAY-01')->update(['state' => 'open']);

        $intent = PaymentIntent::query()->create([
            'requested_amount_minor' => $amountMinor,
            'currency' => 'IDR',
            'payment_mode' => 'online',
            'decision' => PaymentIntentDecision::Allowed->value,
            'actor_role' => 'customer',
            'evaluated_at' => CarbonImmutable::now(),
        ]);

        $session = PaymentSession::query()->create([
            'payment_intent_id' => $intent->id,
            'provider' => PaymentProviders::SUMOPOD_SANDBOX,
            'provider_payment_id' => $providerPaymentId,
            'payment_link_url' => 'https://checkout.sumopod.com/x',
            'amount_minor' => $amountMinor,
            'currency' => 'IDR',
            'merchant_ref' => 'makam-sandbox',
            'badan_usaha_ref' => 'BU-JKT-01',
            'state' => SessionState::AwaitingPayment->value,
        ]);

        // `created_at` drives the sweep's staleness window — backdate it
        // directly (Eloquent timestamps would otherwise stamp "now" on
        // create) rather than sleeping in the test.
        $session->forceFill(['created_at' => CarbonImmutable::now()->subMinutes($ageMinutes)])->save();

        return $session->fresh();
    }

    private function marketplaceTarget(string $orderNumber): MarketplaceOrder
    {
        $vendor = Vendor::query()->create(['name' => 'Toko Bunga', 'is_active' => true]);

        $order = MarketplaceOrder::query()->create([
            'order_number' => $orderNumber,
            'customer_ref' => 'cust-1',
            'entity_ref' => 'BU-JKT-01',
            'vendor_id' => $vendor->id,
            'subtotal_minor' => 250_000,
            'delivery_fee_minor' => 0,
            'total_minor' => 250_000,
            'payment_state' => PaymentState::BELUM_DIBAYAR,
            'idempotency_key' => 'mkt-'.$orderNumber,
            'placed_at' => CarbonImmutable::now(),
        ]);

        (new VendorPayable(actorContext: app(ActorContext::class)))->assess(
            vendorId: $vendor->id,
            entityRef: 'BU-JKT-01',
            sourceType: 'marketplace_order',
            sourceId: $order->id,
            amount: new Money(250_000),
            eligibility: new VendorPayableEligibility(false, false, null),
            trigger: VendorPayableAssessmentTrigger::UnattendedAssessment,
            now: CarbonImmutable::now(),
        );

        return $order;
    }
}
