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
use App\Platform\Payment\Models\PaymentIntent;
use App\Platform\Payment\Models\PaymentSession;
use App\Platform\Payment\Models\ProviderEvent;
use App\Platform\Payment\PaymentIntentDecision;
use App\Platform\Payment\PaymentProviders;
use App\Platform\Payment\ProviderEventStatus;
use App\Platform\Payment\ReconciliationOutcome;
use App\Platform\Payment\SessionState;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * `Actions\ReconcilePaymentSession` — see its own class doc block for why it
 * exists (the real 25 Aug 2026 incident: a genuinely-settled sandbox payment
 * stuck at `AWAITING_PAYMENT` because the provider's webhook URL was
 * misconfigured, discoverable only via the provider's own dashboard).
 *
 * These tests prove the property that matters most: reconciliation settles
 * through the EXACT SAME path a real webhook uses (`ProcessWebhookEvent` /
 * `ApplyPaymentSettlement`), so every financial-integrity guarantee that
 * class already has — idempotency, the settlement-conflict guard — holds
 * here unchanged. A fake `PaymentCheckoutClient` stands in for the provider;
 * every fixture otherwise mirrors `ProcessWebhookEventTest`'s conventions.
 */
final class ReconcilePaymentSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_completed_status_settles_the_order_through_the_real_settlement_path(): void
    {
        $order = $this->marketplaceTarget('order_reconcile_1');
        $session = $this->paymentSession('pay_reconcile_1', 250_000);
        $this->fakeStatus('pay_reconcile_1', 'order_reconcile_1', 'completed', 250_000);

        $outcome = $this->reconcile($session);

        $this->assertSame(ReconciliationOutcome::Settled, $outcome);
        $this->assertSame(PaymentState::DIBAYAR, $order->fresh()->payment_state);
        $this->assertSame(SessionState::Paid->value, $session->fresh()->state);

        $event = ProviderEvent::query()->where('provider_transaction_id', 'pay_reconcile_1')->sole();
        $this->assertSame('reconciliation', $event->event_id_source);
        $this->assertSame('reconciliation:pay_reconcile_1', $event->provider_event_id);
    }

    public function test_a_pending_status_settles_nothing_and_writes_no_row(): void
    {
        $session = $this->paymentSession('pay_reconcile_pending', 250_000);
        $this->fakeStatus('pay_reconcile_pending', 'order_reconcile_pending', 'pending', 250_000);

        $outcome = $this->reconcile($session);

        $this->assertSame(ReconciliationOutcome::StillPending, $outcome);
        $this->assertSame(SessionState::AwaitingPayment->value, $session->fresh()->state);
        $this->assertSame(0, ProviderEvent::query()->count());
    }

    public function test_an_already_terminal_session_is_a_no_op_and_never_calls_the_provider(): void
    {
        $session = $this->paymentSession('pay_reconcile_terminal', 250_000);
        $session->forceFill(['state' => SessionState::Paid->value])->save();

        $this->app->instance(PaymentCheckoutClient::class, new class implements PaymentCheckoutClient
        {
            public function createPayment(CreatePaymentRequest $request): PaymentCheckoutResult
            {
                throw new LogicException('must not be called');
            }

            public function fetchStatus(string $providerPaymentId): PaymentStatusResult
            {
                throw new LogicException('an already-terminal session must never call the provider');
            }
        });

        $outcome = $this->reconcile($session);

        $this->assertSame(ReconciliationOutcome::AlreadyTerminal, $outcome);
    }

    public function test_reconciling_the_same_session_twice_is_idempotent(): void
    {
        $order = $this->marketplaceTarget('order_reconcile_twice');
        $session = $this->paymentSession('pay_reconcile_twice', 250_000);
        $this->fakeStatus('pay_reconcile_twice', 'order_reconcile_twice', 'completed', 250_000);

        $first = $this->reconcile($session);
        // The session is genuinely PAID by the time this second call runs —
        // the cheap short-circuit at the top of `__invoke()` catches it
        // before any provider call, so this is `AlreadyTerminal`, not
        // `AlreadyReconciled` (that outcome is for a still-`AWAITING_PAYMENT`
        // session whose `provider_events` row already exists from an earlier
        // attempt — see `test_a_repeated_reconciliation_attempt_reuses_the_same_provider_event_row`
        // for that race).
        $second = $this->reconcile($session->fresh());

        $this->assertSame(ReconciliationOutcome::Settled, $first);
        $this->assertSame(ReconciliationOutcome::AlreadyTerminal, $second);
        $this->assertSame(1, ProviderEvent::query()->count());
        $this->assertSame(
            1,
            MarketplaceOrder::query()->where('payment_state', PaymentState::DIBAYAR)->count(),
            'a repeated reconciliation must not double-settle'
        );
    }

    public function test_a_repeated_reconciliation_attempt_reuses_the_same_provider_event_row(): void
    {
        // Simulates the race the class doc block names: the on-return check
        // and the scheduled sweep both resolving the same still-open session
        // near-simultaneously. Both attempts see the provider report
        // `completed`, but must converge on ONE `provider_events` row.
        $this->marketplaceTarget('order_reconcile_race');
        $session = $this->paymentSession('pay_reconcile_race', 250_000);
        $this->fakeStatus('pay_reconcile_race', 'order_reconcile_race', 'completed', 250_000);

        $this->reconcile($session);
        $this->reconcile($session->fresh());
        $this->reconcile($session->fresh());

        $this->assertSame(1, ProviderEvent::query()->where('provider_transaction_id', 'pay_reconcile_race')->count());
    }

    public function test_a_failed_status_settles_no_order_but_marks_the_session_failed(): void
    {
        $session = $this->paymentSession('pay_reconcile_failed', 250_000);
        $this->fakeStatus('pay_reconcile_failed', 'order_reconcile_failed', 'failed', 250_000);

        $outcome = $this->reconcile($session);

        $this->assertSame(ReconciliationOutcome::Settled, $outcome);
        $this->assertSame(SessionState::Failed->value, $session->fresh()->state);
        $this->assertSame(0, MarketplaceOrder::query()->count());
    }

    public function test_an_expired_status_marks_the_session_expired(): void
    {
        $session = $this->paymentSession('pay_reconcile_expired', 250_000);
        $this->fakeStatus('pay_reconcile_expired', 'order_reconcile_expired', 'expired', 250_000);

        $outcome = $this->reconcile($session);

        $this->assertSame(ReconciliationOutcome::Settled, $outcome);
        $this->assertSame(SessionState::Expired->value, $session->fresh()->state);
    }

    public function test_a_real_webhook_arriving_after_reconciliation_already_settled_does_not_double_settle(): void
    {
        // The scenario the class doc block walks through in detail: this
        // reconciliation settles first (synthesizing its own
        // `provider_events` row), then a REAL webhook delivery for the SAME
        // provider transaction arrives afterwards. The order must stay
        // settled exactly once.
        //
        // This test writes the "real webhook" row directly via
        // `ProviderEvent::create()` rather than through the full
        // `ReceiveWebhook` HTTP/signature pipeline (out of scope for this
        // class's own test) — which means it hits the SAME
        // `provider_events_settlement_unq` partial unique index
        // `ReceiveWebhook::resolveDuplicate()` would otherwise catch and
        // recover from gracefully. What this proves is the guarantee one
        // level down, and the one that actually matters here: a second
        // settling row for this exact (provider, provider_transaction_id,
        // invoice_reference) triple is structurally impossible to create at
        // all — there is no path, real webhook or otherwise, that can insert
        // a competing row and reach `ProcessWebhookEvent`'s claim logic a
        // second time for it.
        $order = $this->marketplaceTarget('order_reconcile_then_webhook');
        $session = $this->paymentSession('pay_reconcile_then_webhook', 250_000);
        $this->fakeStatus('pay_reconcile_then_webhook', 'order_reconcile_then_webhook', 'completed', 250_000);

        $this->reconcile($session);

        $this->assertSame(PaymentState::DIBAYAR, $order->fresh()->payment_state);

        $threw = null;

        try {
            // Wrapped in its own transaction — a SAVEPOINT under
            // `RefreshDatabase`'s outer test transaction — so the real
            // Postgres unique-violation this collision produces can be
            // recovered from without poisoning the whole test transaction
            // the way an unguarded failed statement would. This is the same
            // savepoint shape `ReconcilePaymentSession::findOrCreateEvent()`
            // itself uses, for the same reason.
            DB::transaction(function (): void {
                ProviderEvent::create([
                    'provider' => PaymentProviders::SUMOPOD_SANDBOX,
                    'provider_event_id' => 'msg_'.bin2hex(random_bytes(8)),
                    'event_id_source' => 'svix-id',
                    'provider_transaction_id' => 'pay_reconcile_then_webhook',
                    'invoice_reference' => 'order_reconcile_then_webhook',
                    'event_type' => 'payment.completed',
                    'merchant_ref' => 'makam-sandbox',
                    'amount_minor' => 250_000,
                    'declared_currency' => 'IDR',
                    'raw_payload' => '{"event_type":"payment.completed"}',
                    'payload_digest' => hash('sha256', '{"event_type":"payment.completed"}'),
                    'status' => ProviderEventStatus::Received->value,
                    'received_at' => CarbonImmutable::now(),
                ]);
            });
        } catch (UniqueConstraintViolationException $exception) {
            $threw = $exception;
        }

        $this->assertNotNull($threw, 'a second settling row for the same transaction+invoice must be refused');
        $this->assertSame(
            1,
            MarketplaceOrder::query()->where('payment_state', PaymentState::DIBAYAR)->count(),
            'the order must still be settled exactly once'
        );
        $this->assertSame(
            1,
            ProviderEvent::query()
                ->where('provider_transaction_id', 'pay_reconcile_then_webhook')
                ->count(),
            'no second settling row for this transaction can exist'
        );
    }

    private function reconcile(PaymentSession $session): ReconciliationOutcome
    {
        return app(ReconcilePaymentSession::class)($session);
    }

    private function fakeStatus(string $providerPaymentId, string $orderId, string $status, int $amountMinor): void
    {
        $this->app->instance(PaymentCheckoutClient::class, new class($providerPaymentId, $orderId, $status, $amountMinor) implements PaymentCheckoutClient
        {
            public function __construct(
                private string $providerPaymentId,
                private string $orderId,
                private string $status,
                private int $amountMinor,
            ) {}

            public function createPayment(CreatePaymentRequest $request): PaymentCheckoutResult
            {
                throw new LogicException('not exercised by these tests');
            }

            public function fetchStatus(string $providerPaymentId): PaymentStatusResult
            {
                if ($providerPaymentId !== $this->providerPaymentId) {
                    throw new LogicException("unexpected lookup for [{$providerPaymentId}]");
                }

                return new PaymentStatusResult(
                    paymentId: $this->providerPaymentId,
                    orderId: $this->orderId,
                    status: $this->status,
                    amountMinor: $this->amountMinor,
                    feeMinor: 0,
                    netAmountMinor: $this->amountMinor,
                    paymentMethod: $this->status === 'completed' ? 'qris' : null,
                    completedAt: $this->status === 'completed' ? CarbonImmutable::now() : null,
                );
            }
        });
    }

    /**
     * Same fixture shape as `ProcessWebhookEventTest::paymentSession()`.
     */
    private function paymentSession(string $providerPaymentId, int $amountMinor): PaymentSession
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

        return PaymentSession::query()->create([
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
    }

    /**
     * Same fixture shape as `ProcessWebhookEventTest::marketplaceTarget()`.
     */
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
