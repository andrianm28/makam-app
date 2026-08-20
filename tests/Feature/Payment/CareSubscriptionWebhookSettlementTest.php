<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Domain\CareSubscription\Exceptions\CyclePaymentAmountMismatchException;
use App\Domain\CareSubscription\Models\CarePlan;
use App\Domain\CareSubscription\Models\Subscription;
use App\Domain\CareSubscription\Models\SubscriptionCycle;
use App\Domain\CareSubscription\Models\SubscriptionInvoice;
use App\Domain\CareSubscription\SubscriptionCycleStatus;
use App\Domain\CareSubscription\SubscriptionStatus;
use App\Domain\VendorFulfillment\Models\WorkOrder;
use App\Domain\VendorFulfillment\Models\WorkOrderTask;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\FeatureGate\Models\FeatureGate;
use App\Platform\Payment\Exceptions\SettlementTargetUnresolvableException;
use App\Platform\Payment\Models\PaymentIntent;
use App\Platform\Payment\Models\PaymentSession;
use App\Platform\Payment\Models\ProviderEvent;
use App\Platform\Payment\PaymentIntentDecision;
use App\Platform\Payment\PaymentProviders;
use App\Platform\Payment\ProviderEventStatus;
use App\Platform\Payment\SessionState;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The care-subscription leg of `Actions\ApplyPaymentSettlement`'s third
 * resolution branch: a claimed `payment.completed` webhook whose
 * `invoice_reference` resolves a `SubscriptionCycle` by its own id (see
 * that class's "Care subscription" doc section for why — `subscription_invoices`
 * carries no business reference column comparable to `orders.reference` /
 * `MarketplaceOrder.order_number`).
 *
 * Follows `WebhookPaidEffectsTest`'s payload-construction convention exactly
 * (same envelope shape, same `body()`/`signature()`/`deliver()` helpers) —
 * no session-opening producer exists yet for this `OrderType::CareSubscription`
 * case, so `paymentSession()` here creates the session directly, the same
 * way that file's fixtures do for booking/marketplace.
 */
final class CareSubscriptionWebhookSettlementTest extends TestCase
{
    use RefreshDatabase;

    private const string MERCHANT = 'makam-sandbox';

    // Deliberately low-entropy (repeated-character) so no high-entropy-secret
    // scanner mistakes a test fixture for a leaked credential; still valid
    // base64, so the real decode path is exercised.
    private const string SECRET = 'whsec_YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFh';

    private const string ENDPOINT = '/api/payments/webhook/'.self::MERCHANT;

    /** The cycle invoice amount in integer minor units (Rp 150.000). */
    private const int AMOUNT_MINOR = 150_000_00;

    /** The same amount as the decimal rupiah value a provider payload carries. */
    private const string AMOUNT_DECIMAL = '150000';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'payment.default' => PaymentProviders::SUMOPOD_SANDBOX,
            'payment.providers.'.PaymentProviders::SUMOPOD_SANDBOX.'.webhook_signing_secrets' => [self::SECRET],
            'payment.providers.'.PaymentProviders::SUMOPOD_SANDBOX.'.webhook_tokens' => [],
            'payment.webhook.allow_shared_token' => false,
            'payment.webhook.merchants' => [self::MERCHANT],
            'payment.webhook.replay_window_seconds' => 300,
        ]);

        FeatureGate::query()->where('gate_id', 'G-PAY-01')->update(['state' => 'open']);
    }

    public function test_a_care_subscription_payment_completed_webhook_pays_the_cycle_and_auto_creates_a_work_order(): void
    {
        $checklist = [
            ['name' => 'Clean headstone', 'required_evidence' => true],
            ['name' => 'Trim grass', 'required_evidence' => false],
        ];
        $carePlan = $this->makeCarePlan($checklist);
        $subscription = $this->makeDraftSubscription($carePlan);
        [$cycle, $invoice] = $this->makeCycleWithInvoice($subscription, self::AMOUNT_MINOR);
        $this->paymentSession('pay_care_1', self::AMOUNT_MINOR);

        $this->deliver(dataOverrides: [
            'payment_id' => 'pay_care_1',
            'order_id' => (string) $cycle->getKey(),
            'amount' => self::AMOUNT_DECIMAL,
        ])->assertOk();

        $event = ProviderEvent::query()->sole();
        $this->assertSame(ProviderEventStatus::Processed->value, $event->status);

        $this->assertSame(SubscriptionCycleStatus::Paid->value, $cycle->fresh()->status);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertNotNull($invoice->fresh()->paid_at);

        $freshSubscription = $subscription->fresh();
        $this->assertSame(SubscriptionStatus::Active->value, $freshSubscription->status);
        $this->assertSame(1, $freshSubscription->current_cycle_number);

        // The session that authorized the payment left AWAITING_PAYMENT and
        // is now PAID.
        $session = PaymentSession::query()->where('provider_payment_id', 'pay_care_1')->sole();
        $this->assertSame(SessionState::Paid->value, $session->state);

        // CARE-SUB-04: the work order is auto-created from the now-paid
        // cycle, with its checklist template expanded into tasks.
        $workOrder = WorkOrder::query()->where('subscription_cycle_id', $cycle->getKey())->sole();
        $this->assertSame('pending', $workOrder->status);
        $this->assertSame((string) $carePlan->getKey(), $workOrder->care_plan_id);

        $tasks = WorkOrderTask::query()->where('work_order_id', $workOrder->getKey())->orderBy('sort_order')->get();
        $this->assertCount(2, $tasks);
        $this->assertSame('Clean headstone', $tasks[0]->name);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'CYCLE_PAID',
            'subject_type' => 'subscription_cycle',
            'subject_id' => (string) $cycle->getKey(),
            'outcome' => 'allowed',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'WORK_ORDER_CREATED',
            'subject_type' => 'work_order',
            'subject_id' => (string) $workOrder->getKey(),
        ]);

        // AC14: no provider payload value may reach an audit row.
        $cycleAudit = AuditEvent::query()->where('action', 'CYCLE_PAID')->sole();
        $this->assertStringNotContainsString('pay_care_1', (string) json_encode($cycleAudit->toArray()));
    }

    /**
     * The booking/marketplace duplicate-arrival analogue: a second,
     * independent provider transaction for the SAME cycle (a new
     * `payment_id`, under a new message id) must not double-advance the
     * cycle counter or create a second work order — `MarkCyclePaid`'s
     * idempotent-if-already-paid guard and `CreateWorkOrderFromCycle`'s own
     * idempotency both apply.
     */
    public function test_a_second_payment_arrival_for_an_already_paid_cycle_changes_nothing_further(): void
    {
        $carePlan = $this->makeCarePlan([]);
        $subscription = $this->makeDraftSubscription($carePlan);
        [$cycle] = $this->makeCycleWithInvoice($subscription, self::AMOUNT_MINOR);
        $this->paymentSession('pay_care_first', self::AMOUNT_MINOR);

        $this->deliver(dataOverrides: [
            'payment_id' => 'pay_care_first',
            'order_id' => (string) $cycle->getKey(),
            'amount' => self::AMOUNT_DECIMAL,
        ])->assertOk();

        $this->assertSame(1, $subscription->fresh()->current_cycle_number);
        $workOrder = WorkOrder::query()->where('subscription_cycle_id', $cycle->getKey())->sole();

        $this->paymentSession('pay_care_second', self::AMOUNT_MINOR);

        $this->deliver(
            id: 'msg_care_duplicate',
            dataOverrides: [
                'payment_id' => 'pay_care_second',
                'order_id' => (string) $cycle->getKey(),
                'amount' => self::AMOUNT_DECIMAL,
            ],
        )->assertOk();

        // Still PAID, still cycle 1 — no re-advance from the second arrival.
        $this->assertSame(SubscriptionCycleStatus::Paid->value, $cycle->fresh()->status);
        $this->assertSame(1, $subscription->fresh()->current_cycle_number);

        // Exactly one CYCLE_PAID audit row and one work order for this cycle.
        $this->assertSame(1, AuditEvent::query()->where('action', 'CYCLE_PAID')
            ->where('subject_id', (string) $cycle->getKey())->count());
        $this->assertSame(1, WorkOrder::query()->where('subscription_cycle_id', $cycle->getKey())->count());
        $this->assertSame((string) $workOrder->getKey(), (string) WorkOrder::query()
            ->where('subscription_cycle_id', $cycle->getKey())->sole()->getKey());

        // Money DID arrive for the second transaction, so its own session is
        // still marked PAID — the record of what was collected.
        $secondSession = PaymentSession::query()->where('provider_payment_id', 'pay_care_second')->sole();
        $this->assertSame(SessionState::Paid->value, $secondSession->state);
    }

    /**
     * The care-subscription analogue of the marketplace "wrong amount"
     * regression: a session opened for the WRONG amount passes the
     * receiver's validation (the validator compares the payload against the
     * SESSION snapshot, so a self-consistent wrong session is VALIDATED),
     * but the settlement must not mark the cycle PAID for a payment that
     * does not equal the invoice amount. `MarkCyclePaid`'s amount assert
     * rejects it, the claim rolls back, and nothing is applied.
     */
    public function test_a_session_opened_for_the_wrong_amount_cannot_mark_the_cycle_paid(): void
    {
        $carePlan = $this->makeCarePlan([]);
        $subscription = $this->makeDraftSubscription($carePlan);
        [$cycle] = $this->makeCycleWithInvoice($subscription, self::AMOUNT_MINOR);

        // The session was opened for Rp 100.000 while the invoice states
        // Rp 150.000. The payload matches the SESSION (so the receiver
        // validates and claims it) but not the invoice.
        $wrongAmountMinor = 100_000_00;
        $this->paymentSession('pay_care_wrong', $wrongAmountMinor);

        $this->withoutExceptionHandling();

        try {
            $this->deliver(dataOverrides: [
                'payment_id' => 'pay_care_wrong',
                'order_id' => (string) $cycle->getKey(),
                'amount' => '100000',
            ])->assertOk();
            $this->fail('Expected CyclePaymentAmountMismatchException');
        } catch (CyclePaymentAmountMismatchException) {
            // expected: the paid transition is refused for the wrong amount.
        }

        // The claim rolled back: the row stays VALIDATED, never PROCESSED.
        $event = ProviderEvent::query()->sole();
        $this->assertSame(ProviderEventStatus::Validated->value, $event->status);

        $this->assertSame(SubscriptionCycleStatus::Scheduled->value, $cycle->fresh()->status);
        $this->assertSame(SubscriptionStatus::Draft->value, $subscription->fresh()->status);
        $this->assertSame(0, WorkOrder::query()->where('subscription_cycle_id', $cycle->getKey())->count());

        $session = PaymentSession::query()->where('provider_payment_id', 'pay_care_wrong')->sole();
        $this->assertSame(SessionState::AwaitingPayment->value, $session->state);
    }

    /**
     * An invoice reference that matches no booking order, marketplace order,
     * or subscription cycle is a data-integrity anomaly and fails closed —
     * the same stance `SettlementTargetUnresolvableException` already
     * documents for the other two legs.
     */
    public function test_an_unresolvable_invoice_reference_fails_closed(): void
    {
        $this->paymentSession('pay_unknown_ref', self::AMOUNT_MINOR);

        $this->withoutExceptionHandling();

        try {
            $this->deliver(dataOverrides: [
                'payment_id' => 'pay_unknown_ref',
                'order_id' => 'NOT-A-REAL-REFERENCE',
                'amount' => self::AMOUNT_DECIMAL,
            ])->assertOk();
            $this->fail('Expected SettlementTargetUnresolvableException');
        } catch (SettlementTargetUnresolvableException) {
            // expected
        }

        $event = ProviderEvent::query()->sole();
        $this->assertSame(ProviderEventStatus::Validated->value, $event->status);
        $this->assertSame(0, WorkOrder::query()->count());
    }

    // -----------------------------------------------------------------
    // Fixtures.
    // -----------------------------------------------------------------

    /**
     * ADR-0033 §Decision's webhook envelope — identical to
     * `WebhookPaidEffectsTest::body()`.
     */
    private function body(array $overrides = [], array $dataOverrides = []): string
    {
        return json_encode(array_merge([
            'event_type' => 'payment.completed',
            'data' => array_merge([
                'payment_id' => 'pay_'.bin2hex(random_bytes(4)),
                'order_id' => 'INV-2026-0001',
                'amount' => 1_500_000,
                'fee' => 10_800,
                'net_amount' => 1_489_200,
                'status' => 'completed',
                'payment_method' => 'QRIS',
                'completed_at' => '2026-08-14T09:59:00+00:00',
            ], $dataOverrides),
        ], $overrides), JSON_THROW_ON_ERROR);
    }

    private function signature(string $id, string $timestamp, string $body): string
    {
        $key = (string) base64_decode(substr(self::SECRET, strlen('whsec_')), true);

        return 'v1,'.base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true));
    }

    private function deliver(
        ?string $signature = null,
        array $dataOverrides = [],
        string $id = 'msg_01',
        ?string $timestamp = null,
    ) {
        $body = $this->body(dataOverrides: $dataOverrides);
        $timestamp ??= (string) CarbonImmutable::now()->getTimestamp();

        $headers = $this->transformHeadersToServerVars([
            'Content-Type' => 'application/json',
            'svix-id' => $id,
            'svix-timestamp' => $timestamp,
            'svix-signature' => $signature ?? $this->signature($id, $timestamp, $body),
        ]);

        return $this->call('POST', self::ENDPOINT, [], [], [], $headers, $body);
    }

    private function paymentSession(string $providerPaymentId, int $amountMinor): PaymentSession
    {
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
            'merchant_ref' => self::MERCHANT,
            'badan_usaha_ref' => 'BU-JKT-01',
            'state' => SessionState::AwaitingPayment->value,
        ]);
    }

    private function makeCarePlan(array $checklistTemplate): CarePlan
    {
        return CarePlan::query()->create([
            'reference' => 'CP-'.Str::upper(Str::random(8)),
            'name' => 'Basic Grave Care',
            'frequency' => 'monthly',
            'price_minor' => self::AMOUNT_MINOR,
            'product_code' => 'GC-MONTHLY',
            'status' => 'active',
            'checklist_template' => $checklistTemplate,
        ]);
    }

    private function makeDraftSubscription(CarePlan $carePlan): Subscription
    {
        return Subscription::query()->create([
            'reference' => 'SUB-'.Str::upper(Str::random(8)),
            'grave_id' => (string) Str::uuid(),
            'care_plan_id' => $carePlan->getKey(),
            'customer_id' => (string) Str::uuid(),
            'status' => SubscriptionStatus::Draft->value,
            'frequency' => 'monthly',
            'price_minor' => self::AMOUNT_MINOR,
            'currency' => 'IDR',
            'current_cycle_number' => 0,
            'started_at' => null,
        ]);
    }

    /**
     * @return array{0: SubscriptionCycle, 1: SubscriptionInvoice}
     */
    private function makeCycleWithInvoice(Subscription $subscription, int $amountMinor): array
    {
        $cycle = SubscriptionCycle::query()->create([
            'subscription_id' => $subscription->getKey(),
            'cycle_start' => CarbonImmutable::now()->startOfMonth()->toDateString(),
            'cycle_end' => CarbonImmutable::now()->startOfMonth()->addMonth()->subDay()->toDateString(),
            'status' => SubscriptionCycleStatus::Scheduled->value,
        ]);

        $invoice = SubscriptionInvoice::query()->create([
            'subscription_cycle_id' => $cycle->getKey(),
            'amount_minor' => $amountMinor,
            'currency' => 'IDR',
            'status' => 'pending',
            'issued_at' => now(),
        ]);

        $cycle->update(['invoice_id' => $invoice->getKey()]);

        return [$cycle->fresh(), $invoice];
    }
}
