<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Domain\Marketplace\Actions\MarkMarketplaceOrderPaid;
use App\Domain\Marketplace\Exceptions\MarketplacePaymentAmountMismatchException;
use App\Domain\Marketplace\Models\MarketplaceOrder;
use App\Domain\Marketplace\Models\Vendor;
use App\Domain\Marketplace\PaymentState;
use App\Domain\OrderWorkflow\Actions\RecordOrderStatusChange;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\Models\OrderStatusEvent;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\Quotation\Models\Quote;
use App\Domain\Quotation\QuoteStatus;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\FeatureGate\Models\FeatureGate;
use App\Platform\FinancialLedger\Actions\VendorPayable;
use App\Platform\FinancialLedger\Models\VendorPayable as VendorPayableModel;
use App\Platform\FinancialLedger\Money;
use App\Platform\FinancialLedger\VendorPayableAssessmentTrigger;
use App\Platform\FinancialLedger\VendorPayableEligibility;
use App\Platform\FinancialLedger\VendorPayableState;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\Outbox\Models\OutboxEvent;
use App\Platform\Payment\Models\PaymentIntent;
use App\Platform\Payment\Models\PaymentSession;
use App\Platform\Payment\Models\ProviderEvent;
use App\Platform\Payment\PaymentIntentDecision;
use App\Platform\Payment\PaymentProviders;
use App\Platform\Payment\ProcessWebhookEvent;
use App\Platform\Payment\ProcessWebhookEventOutcome;
use App\Platform\Payment\ProviderEventStatus;
use App\Platform\Payment\SessionState;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task 5 — the apply half of the webhook claim: a claimed `payment.completed`
 * is dispatched to `ApplyPaymentSettlement`, which routes by the event's
 * provider transaction id (to the authorized `payment_sessions` row) and by
 * its invoice reference (to a booking `Order` or a `MarketplaceOrder`).
 *
 * ---------------------------------------------------------------------------
 * Sessions exist in these tests, and that is the point of the lane
 * ---------------------------------------------------------------------------
 * The receiver previously had no happy path because no `payment_sessions` row
 * could exist (Wave 1b ruling 1b-L3-01, deny-only guard). Task 2 widened the
 * guard: when `G-PAY-01` is open (dev), `PaymentSession::create()` works.
 * These tests open the gate exactly the way `PaymentSessionCreationTest` does
 * — a real `feature_gates` row update, resolved through the real container —
 * and create sessions through the model's own `creating` hook. No test-only
 * bypass exists.
 *
 * ---------------------------------------------------------------------------
 * The claim is exercised through the real contract
 * ---------------------------------------------------------------------------
 * The receiver persists + validates the delivery (row -> VALIDATED) and only
 * then does a worker claim it. This suite runs on the `sync` queue
 * (`phpunit.xml` sets `QUEUE_CONNECTION=sync`), so the dispatched
 * `ProcessProviderEventJob` executes INLINE during the delivery itself —
 * the full HTTP -> validate -> claim -> settle path, not a test shortcut —
 * which is exactly what the job's `handle()` delegates to on a real worker.
 *
 * Every secret in this file is a locally generated test string.
 */
final class WebhookPaidEffectsTest extends TestCase
{
    use RefreshDatabase;

    private const string MERCHANT = 'makam-sandbox';

    // Deliberately low-entropy (repeated-character) so no high-entropy-secret
    // scanner mistakes a test fixture for a leaked credential; still valid
    // base64, so the real decode path is exercised.
    private const string SECRET = 'whsec_YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFh';

    private const string ENDPOINT = '/api/payments/webhook/'.self::MERCHANT;

    /** The order/quote/payable total in integer minor units (Rp 15.000.000). */
    private const int TOTAL_MINOR = 1_500_000_00;

    /** The same amount as the decimal rupiah value a provider payload carries. */
    private const string TOTAL_DECIMAL = '1500000';

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
            'marketplace.badan_usaha_ref' => 'BU-JKT-01',
        ]);

        FeatureGate::query()->where('gate_id', 'G-PAY-01')->update(['state' => 'open']);
    }

    // -----------------------------------------------------------------
    // Booking: a claimed payment.completed applies paid effects once.
    // -----------------------------------------------------------------

    public function test_a_booking_payment_completed_webhook_applies_paid_effects_exactly_once(): void
    {
        $order = $this->bookingOrder('MK-2026-WEBHOOK-01');
        $this->acceptedQuote($order, self::TOTAL_MINOR);
        $this->paymentSession('pay_booking_1', self::TOTAL_MINOR);

        // The suite runs on the sync queue, so the receiver's
        // `ProcessProviderEventJob` executes the claim AND the settlement
        // during the delivery itself — the full HTTP -> validate -> claim ->
        // settle path, not a test shortcut.
        $this->deliver(dataOverrides: [
            'payment_id' => 'pay_booking_1',
            'order_id' => 'MK-2026-WEBHOOK-01',
            'amount' => self::TOTAL_DECIMAL,
        ])->assertOk();

        $event = ProviderEvent::query()->sole();
        $this->assertSame(ProviderEventStatus::Processed->value, $event->status);

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::DIBAYAR->value, $fresh->status);
        $this->assertSame('webhook', $fresh->paid_via);
        $this->assertSame('pay_booking_1', $fresh->paid_source_ref);

        // The session that authorized the payment left AWAITING_PAYMENT and is
        // now PAID — the state Task 6's return screen reads.
        $session = PaymentSession::query()->where('provider_payment_id', 'pay_booking_1')->sole();
        $this->assertSame(SessionState::Paid->value, $session->state);

        $this->assertSame(1, $this->paidEventCount($order));
        $this->assertSame(1, $this->paymentReceivedCount($order));

        // The transition writer audits every move, so this order carries one
        // audit row per status hop; the DIBAYAR hop is the latest. The
        // presence of an allowed transition audit for this order is the
        // contract — the count of hops is the workflow's own business.
        $audits = AuditEvent::query()
            ->where('action', 'ORDER_STATUS_CHANGED')
            ->where('subject_id', $order->getKey())
            ->get();
        $this->assertNotEmpty($audits);
        $this->assertSame('allowed', $audits->sortByDesc('created_at')->first()->outcome);

        // AC14: no provider payload value may reach an audit row.
        foreach ($audits as $audit) {
            $serialized = (string) json_encode($audit->toArray());
            $this->assertStringNotContainsString('pay_booking_1', $serialized);
        }

        // Replay of the same row is refused by the claim; nothing re-applies.
        $this->assertSame(
            ProcessWebhookEventOutcome::NotClaimable,
            app(ProcessWebhookEvent::class)($event->getKey())
        );
        $this->assertSame(1, $this->paidEventCount($order));
        $this->assertSame(1, $this->paymentReceivedCount($order));
    }

    /**
     * The harder replay: the provider redelivers the SAME payment under a NEW
     * event id, so the receiver's primary identity guard (`provider_event_id`)
     * cannot absorb it. The SECONDARY guard — the partial unique index on
     * `(provider, provider_transaction_id, invoice_reference)` over settling
     * event types — refuses the second row, and the duplicate is acknowledged
     * with the ORIGINAL processing reference (`payment-webhook.md` §Idempotency:
     * "Duplicate valid webhook returns a success acknowledgment and the
     * original processing reference. It must not repeat journal, state,
     * invoice, or notification effects.").
     */
    public function test_a_redelivered_payment_under_a_new_event_id_is_absorbed_by_the_secondary_guard(): void
    {
        $order = $this->bookingOrder('MK-2026-REDELIVER-1');
        $this->acceptedQuote($order, self::TOTAL_MINOR);
        $this->paymentSession('pay_redeliver_1', self::TOTAL_MINOR);

        $first = $this->deliver(
            id: 'msg_redeliver_first',
            dataOverrides: ['payment_id' => 'pay_redeliver_1', 'order_id' => 'MK-2026-REDELIVER-1', 'amount' => self::TOTAL_DECIMAL],
        );
        $first->assertOk();

        $second = $this->deliver(
            id: 'msg_redeliver_second',
            dataOverrides: ['payment_id' => 'pay_redeliver_1', 'order_id' => 'MK-2026-REDELIVER-1', 'amount' => self::TOTAL_DECIMAL],
        );
        $second->assertOk();

        // One row, acknowledged twice with the same processing reference.
        $this->assertSame(1, ProviderEvent::query()->count());
        $this->assertSame($first->json('reference'), $second->json('reference'));
        $event = ProviderEvent::query()->sole();
        $this->assertSame(ProviderEventStatus::Processed->value, $event->status);

        // Effects applied exactly once.
        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::DIBAYAR->value, $fresh->status);
        $this->assertSame('webhook', $fresh->paid_via);
        $this->assertSame('pay_redeliver_1', $fresh->paid_source_ref);
        $this->assertSame(1, $this->paidEventCount($order));
        $this->assertSame(1, $this->paymentReceivedCount($order));

        $session = PaymentSession::query()->where('provider_payment_id', 'pay_redeliver_1')->sole();
        $this->assertSame(SessionState::Paid->value, $session->state);
    }

    // -----------------------------------------------------------------
    // Marketplace: a claimed payment.completed marks the order paid and
    // re-assesses the vendor payable.
    // -----------------------------------------------------------------
    public function test_a_marketplace_payment_completed_webhook_marks_the_order_paid_and_re_assesses_the_payable(): void
    {
        $order = $this->marketplaceOrder('MKT-WEBHOOK-0001', self::TOTAL_MINOR);
        $this->paymentSession('pay_marketplace_1', self::TOTAL_MINOR);

        $this->deliver(dataOverrides: [
            'payment_id' => 'pay_marketplace_1',
            'order_id' => 'MKT-WEBHOOK-0001',
            'amount' => self::TOTAL_DECIMAL,
        ])->assertOk();

        // Sync queue: the claim and settlement completed during the delivery.
        $event = ProviderEvent::query()->sole();
        $this->assertSame(ProviderEventStatus::Processed->value, $event->status);

        $this->assertSame(PaymentState::DIBAYAR, $order->fresh()->payment_state);
        $this->assertSame(ProviderEventStatus::Processed->value, $event->fresh()->status);

        // The session moved to PAID with the settled claim.
        $session = PaymentSession::query()->where('provider_payment_id', 'pay_marketplace_1')->sole();
        $this->assertSame(SessionState::Paid->value, $session->state);

        $payable = $this->payableFor($order);
        $this->assertNotNull($payable);

        // M7: ledger timestamps come from the system clock, never the
        // provider-controlled occurrence time (the fixture's payload declares
        // `completed_at` 2026-08-14T09:59:00Z — a stamped re-assessment would
        // sit in the past).
        $this->assertTrue(
            $payable->fresh()->updated_at?->greaterThan(CarbonImmutable::now()->subMinutes(5)) ?? false,
            'the payable re-assessment must be stamped with the system clock',
        );

        // M6: the DIBAYAR audit carries the internal provider event id as its
        // actor reference — the same actor identity the booking leg uses.
        $audit = AuditEvent::query()
            ->where('action', 'MARKETPLACE_ORDER_PAYMENT_STATE_CHANGED')
            ->where('subject_id', $order->getKey())
            ->sole();
        $this->assertSame($event->getKey(), $audit->actor_ref);

        // The paid fact is fed into the eligibility rule. The marketplace
        // domain has no evidence-acceptance or dispute-window records yet
        // (`VendorPayableEligibility`'s doc block names those specs), so the
        // rule's other two conditions are unmet and the payable stays HELD —
        // AC8: "a paid state SHALL NOT imply payable". The release mechanism
        // (HELD -> payable when the rule permits) is exercised in
        // `test_mark_marketplace_order_paid_releases_the_payable_when_all_conditions_hold`.
        $this->assertSame(VendorPayableState::HELD, $payable->state);

        // G-PAYOUT-01 stays closed: no payout may exist on this path.
        $this->assertSame(0, DB::table('payouts')->count());
    }

    public function test_mark_marketplace_order_paid_releases_the_payable_when_all_conditions_hold(): void
    {
        $order = $this->marketplaceOrder('MKT-ELIGIBLE-01', self::TOTAL_MINOR);

        app(MarkMarketplaceOrderPaid::class)(
            $order,
            amountMinor: self::TOTAL_MINOR,
            fulfilmentEvidenceAccepted: true,
            disputeWindowEndsAt: CarbonImmutable::now()->subDay(),
        );

        $this->assertSame(PaymentState::DIBAYAR, $order->fresh()->payment_state);

        $payable = $this->payableFor($order);
        $this->assertSame(VendorPayableState::PAYABLE, $payable->state);
        $this->assertNotNull($payable->eligible_at);
        $this->assertNull($payable->paid_at);

        // The eligible release accrues the liability (DR 5000 / CR 2100) —
        // and it is still never an automated payout (G-PAYOUT-01).
        $this->assertDatabaseHas('journal_batches', [
            'business_key' => 'vendor_payable:'.$order->vendor_id.':marketplace_order:'.$order->id,
        ]);
        $this->assertSame(0, DB::table('payouts')->count());

        // Idempotent: marking the same order paid again with a matching
        // amount changes nothing.
        app(MarkMarketplaceOrderPaid::class)(
            $order->fresh(),
            amountMinor: self::TOTAL_MINOR,
            fulfilmentEvidenceAccepted: true,
            disputeWindowEndsAt: CarbonImmutable::now()->subDay(),
        );

        $this->assertSame(PaymentState::DIBAYAR, $order->fresh()->payment_state);
        $this->assertSame(1, VendorPayableModel::query()->count());
    }

    // -----------------------------------------------------------------
    // Rejection paths: no effect may be applied from a webhook that was
    // not validated against its session.
    // -----------------------------------------------------------------

    public function test_an_amount_mismatch_webhook_is_rejected_and_applies_nothing(): void
    {
        $order = $this->bookingOrder('MK-2026-MM-0001');
        $this->acceptedQuote($order, self::TOTAL_MINOR);
        $this->paymentSession('pay_mismatch_1', self::TOTAL_MINOR);

        $this->deliver(dataOverrides: [
            'payment_id' => 'pay_mismatch_1',
            'order_id' => 'MK-2026-MM-0001',
            'amount' => 1_500_001,
        ])->assertOk();

        $event = ProviderEvent::query()->sole();
        $this->assertSame(ProviderEventStatus::RejectedAmount->value, $event->status);

        $fresh = $order->fresh();
        $this->assertNotSame(OrderStatus::DIBAYAR->value, $fresh->status);
        $this->assertNull($fresh->paid_via);
        $this->assertSame(0, $this->paidEventCount($order));
        $this->assertSame(0, $this->paymentReceivedCount($order));
    }

    /**
     * The marketplace analogue of the booking leg's quote-amount precondition
     * (I-3 regression): a session opened for the WRONG amount passes the
     * receiver's validation (the validator compares the payload against the
     * SESSION snapshot, so a self-consistent wrong session is VALIDATED), but
     * the settlement must not mark the order DIBAYAR for a payment that does
     * not equal what the order owes. The action-level amount assert rejects
     * it, the claim rolls back, and nothing is applied.
     */
    public function test_a_marketplace_session_opened_for_the_wrong_amount_cannot_mark_the_order_paid(): void
    {
        $order = $this->marketplaceOrder('MKT-WRONGAMT-01', self::TOTAL_MINOR);
        // The session was opened for Rp 14.900.000 while the order owes
        // Rp 15.000.000. The payload matches the SESSION (so the receiver
        // validates and claims it) but not the order.
        $wrongAmountMinor = self::TOTAL_MINOR - 10_000_00;
        $this->paymentSession('pay_wrong_amount_1', $wrongAmountMinor);

        $this->withoutExceptionHandling();

        try {
            $this->deliver(dataOverrides: [
                'payment_id' => 'pay_wrong_amount_1',
                'order_id' => 'MKT-WRONGAMT-01',
                'amount' => 1_490_000,
            ])->assertOk();
            $this->fail('Expected MarketplacePaymentAmountMismatchException');
        } catch (MarketplacePaymentAmountMismatchException) {
            // expected: the paid transition is refused for the wrong amount.
        }

        // The claim rolled back: the row stays VALIDATED, never PROCESSED.
        $event = ProviderEvent::query()->sole();
        $this->assertSame(ProviderEventStatus::Validated->value, $event->status);

        // No DIBAYAR: the order, its payable and the session are untouched,
        // and no paid-transition audit was recorded (the fixture's payable
        // opening assessment does write its own audit row).
        $this->assertSame(PaymentState::BELUM_DIBAYAR, $order->fresh()->payment_state);
        $this->assertSame(VendorPayableState::HELD, $this->payableFor($order)->state);
        $session = PaymentSession::query()->where('provider_payment_id', 'pay_wrong_amount_1')->sole();
        $this->assertSame(SessionState::AwaitingPayment->value, $session->state);
        $this->assertSame(0, AuditEvent::query()
            ->where('action', 'MARKETPLACE_ORDER_PAYMENT_STATE_CHANGED')
            ->where('subject_id', $order->getKey())
            ->count());
    }

    public function test_a_forged_signature_is_acknowledged_recorded_and_applies_nothing(): void
    {
        $order = $this->bookingOrder('MK-2026-FORGED-01');
        $this->acceptedQuote($order, self::TOTAL_MINOR);
        $this->paymentSession('pay_forged_1', self::TOTAL_MINOR);

        // The receiver's contract is persist-then-ack (AC5): a forged delivery
        // is acknowledged so the evidence is kept, recorded as
        // REJECTED_SIGNATURE, and never dispatched. There is no 401 on this
        // endpoint — see `WebhookReceiverTest` for the pinned contract.
        $this->deliver(signature: 'v1,'.base64_encode('forged'), dataOverrides: [
            'payment_id' => 'pay_forged_1',
            'order_id' => 'MK-2026-FORGED-01',
            'amount' => self::TOTAL_DECIMAL,
        ])->assertOk();

        $event = ProviderEvent::query()->sole();
        $this->assertSame(ProviderEventStatus::RejectedSignature->value, $event->status);

        $fresh = $order->fresh();
        $this->assertNotSame(OrderStatus::DIBAYAR->value, $fresh->status);
        $this->assertSame(0, $this->paidEventCount($order));
        $this->assertSame(0, $this->paymentReceivedCount($order));
    }

    // -----------------------------------------------------------------
    // Fixtures.
    // -----------------------------------------------------------------

    /**
     * ADR-0033 §Decision's webhook envelope.
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

    private function bookingOrder(string $reference): Order
    {
        $order = Order::query()->create([
            'reference' => $reference,
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::MASUK->value,
        ]);

        app(RecordOrderStatusChange::class)->initial($order, 'actor:admin-1', 'admin');

        foreach ([
            OrderStatus::DIVERIFIKASI,
            OrderStatus::MENUNGGU_KETERSEDIAAN,
            OrderStatus::PENAWARAN_TERKIRIM,
            OrderStatus::DISETUJUI_PEMESAN,
            OrderStatus::MENUNGGU_PEMBAYARAN,
        ] as $status) {
            app(RecordOrderStatusChange::class)($order, $status, 'actor:admin-1', 'admin');
        }

        return $order;
    }

    private function acceptedQuote(Order $order, int $totalMinor): Quote
    {
        $quote = Quote::query()->create([
            'order_id' => $order->getKey(),
            'version_number' => 1,
            'status' => QuoteStatus::ISSUED->value,
            'total_minor' => $totalMinor,
            'currency' => 'IDR',
            'issued_at' => CarbonImmutable::now(),
            'expires_at' => CarbonImmutable::now()->addDays(7),
            'issued_by_ref' => 'actor:admin-1',
            'issued_by_role' => 'admin',
        ]);

        $quote->accept(CarbonImmutable::now(), 'actor:admin-1');

        return $quote->fresh();
    }

    /**
     * A marketplace order placed through the checkout shape: BELUM_DIBAYAR,
     * with its vendor payable opened HELD by the placement assessment.
     */
    private function marketplaceOrder(string $orderNumber, int $totalMinor): MarketplaceOrder
    {
        $vendor = Vendor::query()->create(['name' => 'Toko Bunga', 'is_active' => true]);

        $order = MarketplaceOrder::query()->create([
            'order_number' => $orderNumber,
            'customer_ref' => 'cust-1',
            'entity_ref' => 'BU-JKT-01',
            'vendor_id' => $vendor->id,
            'subtotal_minor' => $totalMinor,
            'delivery_fee_minor' => 0,
            'total_minor' => $totalMinor,
            'payment_state' => PaymentState::BELUM_DIBAYAR,
            'idempotency_key' => 'mkt-'.Str::lower($orderNumber),
            'placed_at' => CarbonImmutable::now(),
        ]);

        (new VendorPayable(actorContext: app(ActorContext::class)))->assess(
            vendorId: $vendor->id,
            entityRef: 'BU-JKT-01',
            sourceType: 'marketplace_order',
            sourceId: $order->id,
            amount: new Money($totalMinor),
            eligibility: new VendorPayableEligibility(
                orderPaid: false,
                fulfilmentEvidenceAccepted: false,
                disputeWindowEndsAt: null,
            ),
            trigger: VendorPayableAssessmentTrigger::UnattendedAssessment,
            now: CarbonImmutable::now(),
        );

        return $order;
    }

    private function payableFor(MarketplaceOrder $order): ?VendorPayableModel
    {
        return VendorPayableModel::query()
            ->where('vendor_id', $order->vendor_id)
            ->where('source_type', 'marketplace_order')
            ->where('source_id', $order->id)
            ->first();
    }

    private function paidEventCount(Order $order): int
    {
        return OrderStatusEvent::query()
            ->where('order_id', $order->getKey())
            ->where('to_status', OrderStatus::DIBAYAR->value)
            ->count();
    }

    private function paymentReceivedCount(Order $order): int
    {
        return OutboxEvent::query()
            ->where('event_name', 'payment.received.v1')
            ->where('aggregate_id', $order->getKey())
            ->count();
    }
}
