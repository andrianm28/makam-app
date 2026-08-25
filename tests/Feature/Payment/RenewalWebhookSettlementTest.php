<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Domain\GraveRegistry\GraveRecordAccessMode;
use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Marketplace\Models\MarketplaceOrder;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\Renewal\Exceptions\RenewalAlreadySettledException;
use App\Domain\Renewal\Exceptions\RenewalPaymentAmountMismatchException;
use App\Domain\Renewal\Models\Renewal;
use App\Domain\Renewal\Models\RenewalQuote;
use App\Domain\Renewal\RenewalAuditActions;
use App\Domain\Renewal\RenewalStatus;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\FeatureGate\Models\FeatureGate;
use App\Platform\Outbox\Models\OutboxEvent;
use App\Platform\Payment\Models\PaymentIntent;
use App\Platform\Payment\Models\PaymentSession;
use App\Platform\Payment\Models\ProviderEvent;
use App\Platform\Payment\PaymentIntentDecision;
use App\Platform\Payment\PaymentProviders;
use App\Platform\Payment\ProviderEventStatus;
use App\Platform\Payment\SessionState;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The renewal leg of `Actions\ApplyPaymentSettlement`'s resolution chain: a
 * claimed `payment.completed` webhook whose `invoice_reference` resolves a
 * `Renewal` by its `PPJ-`-prefixed `reference` column (Task 2,
 * `docs/superpowers/plans/2026-08-25-renewal-online-payment.md`) — NOT
 * `renewals.id`.
 *
 * Follows `CareSubscriptionWebhookSettlementTest`'s payload-construction
 * convention exactly (same envelope shape, same `body()`/`signature()`/
 * `deliver()` helpers).
 */
final class RenewalWebhookSettlementTest extends TestCase
{
    use RefreshDatabase;

    private const string MERCHANT = 'makam-sandbox';

    // Deliberately low-entropy (repeated-character) so no high-entropy-secret
    // scanner mistakes a test fixture for a leaked credential; still valid
    // base64, so the real decode path is exercised.
    private const string SECRET = 'whsec_YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFh';

    private const string ENDPOINT = '/api/payments/webhook/'.self::MERCHANT;

    private const string RENEWAL_REFERENCE = 'PPJ-WEBHOOK-TEST-0001';

    /** The quote amount in integer minor units (Rp 150.000). */
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

    public function test_a_renewal_payment_completed_webhook_settles_the_renewal(): void
    {
        $renewal = $this->makeRenewalWithAcceptedQuote(self::AMOUNT_MINOR);
        $this->paymentSession('pay_renewal_1', self::AMOUNT_MINOR);

        $this->deliver(dataOverrides: [
            'payment_id' => 'pay_renewal_1',
            'order_id' => self::RENEWAL_REFERENCE,
            'amount' => self::AMOUNT_DECIMAL,
        ])->assertOk();

        $event = ProviderEvent::query()->sole();
        $this->assertSame(ProviderEventStatus::Processed->value, $event->status);

        $fresh = $renewal->fresh();
        $this->assertSame(RenewalStatus::DIBAYAR, $fresh->status);
        $this->assertNotNull($fresh->settled_at);

        // The session that authorized the payment left AWAITING_PAYMENT and
        // is now PAID.
        $session = PaymentSession::query()->where('provider_payment_id', 'pay_renewal_1')->sole();
        $this->assertSame(SessionState::Paid->value, $session->state);

        $this->assertDatabaseHas('audit_events', [
            'action' => RenewalAuditActions::RENEWAL_PAID_ONLINE,
            'subject_type' => 'renewal',
            'subject_id' => (string) $renewal->getKey(),
            'outcome' => 'allowed',
        ]);

        $outboxEvent = OutboxEvent::query()->where('event_name', 'renewal.paid_online.v1')->sole();
        $this->assertSame('renewal', $outboxEvent->aggregate_type);
        $this->assertSame((string) $renewal->getKey(), $outboxEvent->aggregate_id);

        // AC14: no provider payload value may reach an audit row.
        $renewalAudit = AuditEvent::query()->where('action', RenewalAuditActions::RENEWAL_PAID_ONLINE)->sole();
        $this->assertStringNotContainsString('pay_renewal_1', (string) json_encode($renewalAudit->toArray()));

        // Negative-resolution discipline: this webhook must not also match
        // or settle any booking Order or MarketplaceOrder.
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, MarketplaceOrder::query()->count());
    }

    /**
     * The renewal analogue of `CareSubscriptionWebhookSettlementTest`'s
     * second-arrival test: a second, independent provider transaction
     * resolving to the SAME renewal reference must not double-transition it
     * — `MarkRenewalPaidOnline`'s idempotency guard refuses (throws), the
     * claim transaction for the SECOND event rolls back, and that event's
     * own `provider_events` row stays `VALIDATED` (never falsely
     * `PROCESSED`) while the first settlement's effects are untouched.
     */
    public function test_a_second_payment_arrival_for_an_already_settled_renewal_leaves_the_first_settlement_untouched(): void
    {
        $renewal = $this->makeRenewalWithAcceptedQuote(self::AMOUNT_MINOR);
        $this->paymentSession('pay_renewal_first', self::AMOUNT_MINOR);

        $this->deliver(dataOverrides: [
            'payment_id' => 'pay_renewal_first',
            'order_id' => self::RENEWAL_REFERENCE,
            'amount' => self::AMOUNT_DECIMAL,
        ])->assertOk();

        $this->assertSame(RenewalStatus::DIBAYAR, $renewal->fresh()->status);
        $settledAtFirst = $renewal->fresh()->settled_at;

        $this->paymentSession('pay_renewal_second', self::AMOUNT_MINOR);

        $this->withoutExceptionHandling();

        try {
            $this->deliver(
                id: 'msg_renewal_duplicate',
                dataOverrides: [
                    'payment_id' => 'pay_renewal_second',
                    'order_id' => self::RENEWAL_REFERENCE,
                    'amount' => self::AMOUNT_DECIMAL,
                ],
            )->assertOk();
            $this->fail('Expected the second settlement attempt to throw.');
        } catch (RenewalAlreadySettledException) {
            // expected — see this test's own doc block.
        }

        // The first settlement's effects are untouched.
        $this->assertSame(RenewalStatus::DIBAYAR, $renewal->fresh()->status);
        $this->assertSame($settledAtFirst?->toIso8601String(), $renewal->fresh()->settled_at?->toIso8601String());

        // Exactly one RENEWAL_PAID_ONLINE audit row and one outbox row.
        $this->assertSame(1, AuditEvent::query()->where('action', RenewalAuditActions::RENEWAL_PAID_ONLINE)
            ->where('subject_id', (string) $renewal->getKey())->count());
        $this->assertSame(1, OutboxEvent::query()->where('event_name', 'renewal.paid_online.v1')
            ->where('aggregate_id', (string) $renewal->getKey())->count());

        // The second event's claim rolled back: it stays VALIDATED, never
        // falsely PROCESSED.
        $secondEvent = ProviderEvent::query()
            ->where('provider_transaction_id', 'pay_renewal_second')->sole();
        $this->assertSame(ProviderEventStatus::Validated->value, $secondEvent->status);
    }

    /**
     * The renewal analogue of the marketplace/care-subscription "wrong
     * amount" regression: a session opened for the WRONG amount passes the
     * receiver's validation (the validator compares the payload against the
     * SESSION snapshot, so a self-consistent wrong session is VALIDATED),
     * but the settlement must not mark the renewal PAID for a payment that
     * does not equal the quoted total. `MarkRenewalPaidOnline`'s amount
     * assert rejects it, the claim rolls back, and nothing is applied.
     */
    public function test_a_session_opened_for_the_wrong_amount_cannot_mark_the_renewal_paid(): void
    {
        $renewal = $this->makeRenewalWithAcceptedQuote(self::AMOUNT_MINOR);

        // The session was opened for Rp 100.000 while the quote states
        // Rp 150.000. The payload matches the SESSION (so the receiver
        // validates and claims it) but not the quote.
        $wrongAmountMinor = 100_000_00;
        $this->paymentSession('pay_renewal_wrong', $wrongAmountMinor);

        $this->withoutExceptionHandling();

        try {
            $this->deliver(dataOverrides: [
                'payment_id' => 'pay_renewal_wrong',
                'order_id' => self::RENEWAL_REFERENCE,
                'amount' => '100000',
            ])->assertOk();
            $this->fail('Expected RenewalPaymentAmountMismatchException');
        } catch (RenewalPaymentAmountMismatchException) {
            // expected: the paid transition is refused for the wrong amount.
        }

        // The claim rolled back: the row stays at VALIDATED, never PROCESSED.
        $event = ProviderEvent::query()->sole();
        $this->assertSame(ProviderEventStatus::Validated->value, $event->status);

        $this->assertSame(RenewalStatus::MENUNGGU_PEMBAYARAN, $renewal->fresh()->status);
        $this->assertNull($renewal->fresh()->settled_at);

        $session = PaymentSession::query()->where('provider_payment_id', 'pay_renewal_wrong')->sole();
        $this->assertSame(SessionState::AwaitingPayment->value, $session->state);
    }

    // -----------------------------------------------------------------
    // Fixtures.
    // -----------------------------------------------------------------

    /**
     * ADR-0033 §Decision's webhook envelope — identical to
     * `WebhookPaidEffectsTest::body()`/`CareSubscriptionWebhookSettlementTest::body()`.
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

    private function makeRenewalWithAcceptedQuote(int $amountMinor): Renewal
    {
        $grave = GraveRecord::factory()->create(['access_mode' => GraveRecordAccessMode::OPEN]);
        $renewal = Renewal::factory()->create([
            'grave_record_id' => $grave->id,
            'reference' => self::RENEWAL_REFERENCE,
        ]);
        RenewalQuote::factory()->accepted()->create([
            'renewal_id' => $renewal->id,
            'amount_minor' => $amountMinor,
        ]);

        return $renewal;
    }
}
