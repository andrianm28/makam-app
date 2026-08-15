<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Domain\Marketplace\Models\MarketplaceOrder;
use App\Domain\Marketplace\Models\Vendor;
use App\Domain\Marketplace\PaymentState;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\FeatureGate\Models\FeatureGate;
use App\Platform\FinancialLedger\Actions\VendorPayable;
use App\Platform\FinancialLedger\Money;
use App\Platform\FinancialLedger\VendorPayableAssessmentTrigger;
use App\Platform\FinancialLedger\VendorPayableEligibility;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\Payment\Exceptions\SettlementTargetUnresolvableException;
use App\Platform\Payment\Jobs\ProcessProviderEventJob;
use App\Platform\Payment\Models\PaymentIntent;
use App\Platform\Payment\Models\PaymentSession;
use App\Platform\Payment\Models\ProviderEvent;
use App\Platform\Payment\PaymentAuditActions;
use App\Platform\Payment\PaymentIntentDecision;
use App\Platform\Payment\PaymentProviders;
use App\Platform\Payment\ProcessWebhookEvent;
use App\Platform\Payment\ProcessWebhookEventOutcome;
use App\Platform\Payment\ProviderEventStatus;
use App\Platform\Payment\SessionState;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 4 as re-scoped by Wave 1b ruling 1b-L3-04, updated by Task 5 (the
 * online-payment-gateway lane) when the apply half was wired.
 *
 * 1. The `provider_events` row claim: `VALIDATED -> PROCESSING` under
 *    `SELECT ... FOR UPDATE`, exactly once, so a redelivered queue job cannot
 *    apply a second effect.
 * 2. The `(provider, provider_transaction_id)` apply-time claim, which closes
 *    the gap Task 3 recorded: the insert-time partial unique index stops the
 *    same (transaction, invoice) pair settling twice but does NOT stop one
 *    provider transaction settling two DIFFERENT invoices, which is
 *    `docs/contracts/payment-webhook.md` §Idempotency's literal rule.
 * 3. Task 5's settlement dispatch: a claimed SETTLING event is settled inside
 *    the claim transaction (`Actions\ApplyPaymentSettlement`), so a settling
 *    claim ends at `PROCESSED` with its effects committed; a claimed
 *    non-settling event also ends at `PROCESSED` with no effects by design; a
 *    settlement failure rolls the claim back and the row stays `VALIDATED`.
 *
 * A `provider_events` row's status is seeded directly through `markStatus()`,
 * exactly as `ProviderEventModelTest::test_the_row_is_append_only_except_for_its_lifecycle_columns`
 * already does. Settling claims additionally resolve a REAL session + target
 * (the settlement refuses to fabricate either), so the fixtures open the
 * payment gate exactly as `PaymentSessionCreationTest` does and create
 * marketplace targets through the checkout shape.
 *
 * NOT TESTED here, and not testable on this suite: real concurrency. The suite
 * runs on SQLite (`phpunit.xml`), where `lockForUpdate()` compiles to an empty
 * string (`Illuminate\Database\Query\Grammars\SQLiteGrammar::compileLock`).
 * These tests prove the compare-and-set LOGIC of the claim in sequence; they do
 * not prove that two simultaneous PostgreSQL workers serialize against each
 * other. That remains NOT TESTED until CI runs on PostgreSQL.
 */
final class ProcessWebhookEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_settling_event_is_claimed_settled_and_processed(): void
    {
        $order = $this->marketplaceTarget('order_A');
        $event = $this->validatedEvent([
            'provider_transaction_id' => 'pay_claim',
            'invoice_reference' => 'order_A',
        ]);
        $this->paymentSession('pay_claim', 250_000);

        $outcome = $this->claim($event);

        $this->assertSame(ProcessWebhookEventOutcome::Claimed, $outcome);
        $this->assertSame(ProviderEventStatus::Processed->value, $event->fresh()->status);
        $this->assertSame(PaymentState::DIBAYAR, $order->fresh()->payment_state);
    }

    public function test_a_claimed_non_settling_event_completes_without_effects(): void
    {
        $event = $this->validatedEvent(['event_type' => 'payment.failed']);

        $outcome = $this->claim($event);

        $this->assertSame(ProcessWebhookEventOutcome::Claimed, $outcome);
        $this->assertSame(ProviderEventStatus::Processed->value, $event->fresh()->status);
        $this->assertSame(0, MarketplaceOrder::query()->count());
    }

    public function test_a_second_claim_of_the_same_row_is_refused_and_changes_nothing(): void
    {
        $order = $this->marketplaceTarget('order_once');
        $event = $this->validatedEvent([
            'provider_transaction_id' => 'pay_once',
            'invoice_reference' => 'order_once',
        ]);
        $this->paymentSession('pay_once', 250_000);

        $this->assertSame(ProcessWebhookEventOutcome::Claimed, $this->claim($event));

        // At-least-once delivery (`AGENTS.md` §Queue and event reliability):
        // the same job id can and will be handed to a worker twice.
        $second = $this->claim($event);

        $this->assertSame(ProcessWebhookEventOutcome::NotClaimable, $second);
        $this->assertSame(ProviderEventStatus::Processed->value, $event->fresh()->status);
        $this->assertSame(PaymentState::DIBAYAR, $order->fresh()->payment_state);
        $this->assertSame(1, $this->paidMarketplaceCount($order));
    }

    public function test_a_row_that_never_reached_validated_is_not_claimable(): void
    {
        foreach ([
            ProviderEventStatus::Received,
            ProviderEventStatus::RejectedSession,
            ProviderEventStatus::RejectedSignature,
            ProviderEventStatus::Processed,
            ProviderEventStatus::ManualReview,
        ] as $status) {
            $event = $this->event();
            $event->markStatus($status);

            $this->assertSame(
                ProcessWebhookEventOutcome::NotClaimable,
                $this->claim($event),
                "a row at [{$status->value}] must not be claimable"
            );

            $this->assertSame($status->value, $event->fresh()->status);
        }
    }

    public function test_a_settling_event_with_no_resolvable_target_fails_closed_and_rolls_back(): void
    {
        // A VALIDATED row whose provider transaction resolves to no session —
        // seeded around the validator — must fail loudly, not settle nowhere.
        // The claim rolls back: the row stays VALIDATED, no order appears.
        $event = $this->validatedEvent([
            'provider_transaction_id' => 'pay_orphan',
            'invoice_reference' => 'order_orphan',
        ]);

        try {
            $this->claim($event);
            $this->fail('Expected SettlementTargetUnresolvableException');
        } catch (SettlementTargetUnresolvableException) {
            // expected
        }

        $this->assertSame(ProviderEventStatus::Validated->value, $event->fresh()->status);
        $this->assertSame(0, MarketplaceOrder::query()->count());
        $this->assertSame(0, AuditEvent::query()->count());
    }

    public function test_a_missing_row_is_a_no_op_rather_than_an_error(): void
    {
        $outcome = app(ProcessWebhookEvent::class)('01996f4e-0000-7000-8000-000000000000');

        $this->assertSame(ProcessWebhookEventOutcome::NotFound, $outcome);
    }

    public function test_one_provider_transaction_cannot_settle_two_different_invoices(): void
    {
        $order = $this->marketplaceTarget('order_A');
        $first = $this->validatedEvent([
            'provider_transaction_id' => 'pay_conflict',
            'invoice_reference' => 'order_A',
            'event_type' => 'payment.completed',
        ]);
        $this->paymentSession('pay_conflict', 250_000);

        $second = $this->validatedEvent([
            'provider_transaction_id' => 'pay_conflict',
            'invoice_reference' => 'order_B',
            'event_type' => 'payment.completed',
        ]);

        $this->assertSame(ProcessWebhookEventOutcome::Claimed, $this->claim($first));
        $this->assertSame(ProcessWebhookEventOutcome::SettlementConflict, $this->claim($second));

        $this->assertSame(ProviderEventStatus::Processed->value, $first->fresh()->status);
        $this->assertSame(ProviderEventStatus::ManualReview->value, $second->fresh()->status);
        $this->assertSame(PaymentState::DIBAYAR, $order->fresh()->payment_state);
    }

    public function test_a_settlement_conflict_is_recorded_rather_than_silently_dropped(): void
    {
        $this->marketplaceTarget('order_A');
        $first = $this->validatedEvent([
            'provider_transaction_id' => 'pay_audit',
            'invoice_reference' => 'order_A',
        ]);
        $this->paymentSession('pay_audit', 250_000);
        $second = $this->validatedEvent([
            'provider_transaction_id' => 'pay_audit',
            'invoice_reference' => 'order_B',
        ]);

        $this->claim($first);
        $this->claim($second);

        $audit = AuditEvent::query()
            ->where('action', PaymentAuditActions::WEBHOOK_SETTLEMENT_CONFLICT)
            ->sole();

        $this->assertSame($second->getKey(), $audit->subject_id);

        // AC14 / `AGENTS.md` §Observability: no payload value may reach an
        // audit row. The invoice references are provider payload values.
        $serialized = (string) json_encode($audit->toArray());
        $this->assertStringNotContainsString('order_A', $serialized);
        $this->assertStringNotContainsString('order_B', $serialized);
        $this->assertStringNotContainsString('pay_audit', $serialized);
    }

    public function test_a_non_settling_event_for_an_already_claimed_transaction_still_claims(): void
    {
        // Task 3's deliberate design: the insert-time unique guard is PARTIAL,
        // over settling event types only, so an out-of-order `expired` arriving
        // after `completed` is persistable. The apply-time claim must not undo
        // that by treating the late `expired` as a settlement conflict.
        $completed = $this->validatedEvent([
            'provider_transaction_id' => 'pay_late',
            'invoice_reference' => 'order_late',
            'event_type' => 'payment.completed',
        ]);

        $expired = $this->validatedEvent([
            'provider_transaction_id' => 'pay_late',
            'invoice_reference' => 'order_late',
            'event_type' => 'payment.expired',
        ]);
        $this->paymentSession('pay_late', 250_000);
        $this->marketplaceTarget('order_late');

        $this->assertSame(ProcessWebhookEventOutcome::Claimed, $this->claim($completed));
        $this->assertSame(ProcessWebhookEventOutcome::Claimed, $this->claim($expired));

        $this->assertSame(ProviderEventStatus::Processed->value, $expired->fresh()->status);
    }

    public function test_a_claimed_non_settling_event_does_not_block_the_settling_one(): void
    {
        $order = $this->marketplaceTarget('order_x');
        $expired = $this->validatedEvent([
            'provider_transaction_id' => 'pay_order',
            'invoice_reference' => 'order_x',
            'event_type' => 'payment.expired',
        ]);

        $completed = $this->validatedEvent([
            'provider_transaction_id' => 'pay_order',
            'invoice_reference' => 'order_x',
            'event_type' => 'payment.completed',
        ]);
        $this->paymentSession('pay_order', 250_000);

        $this->assertSame(ProcessWebhookEventOutcome::Claimed, $this->claim($expired));
        $this->assertSame(ProcessWebhookEventOutcome::Claimed, $this->claim($completed));
        $this->assertSame(PaymentState::DIBAYAR, $order->fresh()->payment_state);
    }

    public function test_rows_without_a_provider_transaction_id_do_not_claim_each_other(): void
    {
        // Two identity-less rows must not collide on `NULL = NULL`. Neither is
        // settling (no transaction id to settle by), so both claim and complete
        // with no effects.
        $first = $this->validatedEvent(['provider_transaction_id' => null, 'invoice_reference' => null]);
        $second = $this->validatedEvent(['provider_transaction_id' => null, 'invoice_reference' => null]);

        $this->assertSame(ProcessWebhookEventOutcome::Claimed, $this->claim($first));
        $this->assertSame(ProcessWebhookEventOutcome::Claimed, $this->claim($second));
        $this->assertSame(ProviderEventStatus::Processed->value, $first->fresh()->status);
    }

    public function test_the_queued_job_performs_the_claim(): void
    {
        $order = $this->marketplaceTarget('order_job');
        $event = $this->validatedEvent([
            'provider_transaction_id' => 'pay_job',
            'invoice_reference' => 'order_job',
        ]);
        $this->paymentSession('pay_job', 250_000);

        (new ProcessProviderEventJob($event->getKey()))->handle(app(ProcessWebhookEvent::class));

        $this->assertSame(ProviderEventStatus::Processed->value, $event->fresh()->status);
        $this->assertSame(PaymentState::DIBAYAR, $order->fresh()->payment_state);
    }

    private function claim(ProviderEvent $event): ProcessWebhookEventOutcome
    {
        return app(ProcessWebhookEvent::class)($event->getKey());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function validatedEvent(array $overrides = []): ProviderEvent
    {
        $event = $this->event($overrides);

        // The one permitted way to move the lifecycle column — the same call
        // `ProviderEventModelTest` uses to reach `VALIDATED` in a test.
        $event->markStatus(ProviderEventStatus::Validated);

        return $event;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function event(array $overrides = []): ProviderEvent
    {
        $body = '{"event_type":"payment.completed"}';

        return ProviderEvent::create(array_merge([
            'provider' => PaymentProviders::SUMOPOD_SANDBOX,
            'provider_event_id' => 'msg_'.bin2hex(random_bytes(8)),
            'event_id_source' => 'svix-id',
            'provider_transaction_id' => 'pay_'.bin2hex(random_bytes(4)),
            'invoice_reference' => 'order_'.bin2hex(random_bytes(4)),
            'event_type' => 'payment.completed',
            'merchant_ref' => 'makam-sandbox',
            'amount_minor' => 1_500_000_00,
            'declared_currency' => 'IDR',
            'raw_payload' => $body,
            'payload_digest' => hash('sha256', $body),
            'signature_timestamp' => (string) CarbonImmutable::now()->getTimestamp(),
            'signature_header' => 'v1,test-signature',
            'status' => ProviderEventStatus::Received->value,
            'received_at' => CarbonImmutable::now(),
        ], $overrides));
    }

    /**
     * The settlement refuses to fabricate a target: a settling claim resolves
     * a real session (payment gate open, the `PaymentSessionCreationTest`
     * convention) and a real marketplace order with the payable checkout
     * opened for it.
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

    private function paidMarketplaceCount(MarketplaceOrder $order): int
    {
        return MarketplaceOrder::query()
            ->whereKey($order->getKey())
            ->where('payment_state', PaymentState::DIBAYAR)
            ->count();
    }
}
