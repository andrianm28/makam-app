<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Platform\Audit\Models\AuditEvent;
use App\Platform\Payment\Jobs\ProcessProviderEventJob;
use App\Platform\Payment\Models\ProviderEvent;
use App\Platform\Payment\PaymentAuditActions;
use App\Platform\Payment\PaymentProviders;
use App\Platform\Payment\ProcessWebhookEvent;
use App\Platform\Payment\ProcessWebhookEventOutcome;
use App\Platform\Payment\ProviderEventStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 4 as re-scoped by Wave 1b ruling 1b-L3-04 — the two claims that are
 * reachable without a `payment_sessions` row.
 *
 * 1. The `provider_events` row claim: `VALIDATED -> PROCESSING` under
 *    `SELECT ... FOR UPDATE`, exactly once, so a redelivered queue job cannot
 *    apply a second effect.
 * 2. The `(provider, provider_transaction_id)` apply-time claim, which closes
 *    the gap Task 3 recorded: the insert-time partial unique index stops the
 *    same (transaction, invoice) pair settling twice but does NOT stop one
 *    provider transaction settling two DIFFERENT invoices, which is
 *    `docs/contracts/payment-webhook.md` §Idempotency's literal rule.
 *
 * A `provider_events` row's status is seeded directly through `markStatus()`,
 * exactly as `ProviderEventModelTest::test_the_row_is_append_only_except_for_its_lifecycle_columns`
 * already does. That is seeding a table this lane owns, not fabricating a
 * `payment_sessions` row — no session is created here by any mechanism, and the
 * apply half that would need one is deliberately absent (see `ProcessWebhookEvent`).
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

    public function test_it_claims_a_validated_row_into_processing(): void
    {
        $event = $this->validatedEvent();

        $outcome = $this->claim($event);

        $this->assertSame(ProcessWebhookEventOutcome::Claimed, $outcome);
        $this->assertSame(ProviderEventStatus::Processing->value, $event->fresh()->status);
    }

    public function test_a_second_claim_of_the_same_row_is_refused_and_changes_nothing(): void
    {
        $event = $this->validatedEvent();

        $this->assertSame(ProcessWebhookEventOutcome::Claimed, $this->claim($event));

        // At-least-once delivery (`AGENTS.md` §Queue and event reliability):
        // the same job id can and will be handed to a worker twice.
        $second = $this->claim($event);

        $this->assertSame(ProcessWebhookEventOutcome::NotClaimable, $second);
        $this->assertSame(ProviderEventStatus::Processing->value, $event->fresh()->status);
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

    public function test_the_claim_never_marks_a_row_processed(): void
    {
        // Nothing is applied yet — the session-dependent apply path is
        // unbuildable under ruling 1b-L3-01 — so a `PROCESSED` status would be
        // a false claim written into the append-only replay source of truth.
        $event = $this->validatedEvent();

        $this->claim($event);

        $this->assertNotSame(ProviderEventStatus::Processed->value, $event->fresh()->status);
    }

    public function test_a_missing_row_is_a_no_op_rather_than_an_error(): void
    {
        $outcome = app(ProcessWebhookEvent::class)('01996f4e-0000-7000-8000-000000000000');

        $this->assertSame(ProcessWebhookEventOutcome::NotFound, $outcome);
    }

    public function test_one_provider_transaction_cannot_settle_two_different_invoices(): void
    {
        $first = $this->validatedEvent([
            'provider_transaction_id' => 'pay_conflict',
            'invoice_reference' => 'order_A',
            'event_type' => 'payment.completed',
        ]);

        $second = $this->validatedEvent([
            'provider_transaction_id' => 'pay_conflict',
            'invoice_reference' => 'order_B',
            'event_type' => 'payment.completed',
        ]);

        $this->assertSame(ProcessWebhookEventOutcome::Claimed, $this->claim($first));
        $this->assertSame(ProcessWebhookEventOutcome::SettlementConflict, $this->claim($second));

        $this->assertSame(ProviderEventStatus::Processing->value, $first->fresh()->status);
        $this->assertSame(ProviderEventStatus::ManualReview->value, $second->fresh()->status);
    }

    public function test_a_settlement_conflict_is_recorded_rather_than_silently_dropped(): void
    {
        $first = $this->validatedEvent([
            'provider_transaction_id' => 'pay_audit',
            'invoice_reference' => 'order_A',
        ]);
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

        $this->assertSame(ProcessWebhookEventOutcome::Claimed, $this->claim($completed));
        $this->assertSame(ProcessWebhookEventOutcome::Claimed, $this->claim($expired));

        $this->assertSame(ProviderEventStatus::Processing->value, $expired->fresh()->status);
    }

    public function test_a_claimed_non_settling_event_does_not_block_the_settling_one(): void
    {
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

        $this->assertSame(ProcessWebhookEventOutcome::Claimed, $this->claim($expired));
        $this->assertSame(ProcessWebhookEventOutcome::Claimed, $this->claim($completed));
    }

    public function test_rows_without_a_provider_transaction_id_do_not_claim_each_other(): void
    {
        // Two identity-less rows must not collide on `NULL = NULL`.
        $first = $this->validatedEvent(['provider_transaction_id' => null, 'invoice_reference' => null]);
        $second = $this->validatedEvent(['provider_transaction_id' => null, 'invoice_reference' => null]);

        $this->assertSame(ProcessWebhookEventOutcome::Claimed, $this->claim($first));
        $this->assertSame(ProcessWebhookEventOutcome::Claimed, $this->claim($second));
    }

    public function test_the_queued_job_performs_the_claim(): void
    {
        $event = $this->validatedEvent();

        (new ProcessProviderEventJob($event->getKey()))->handle(app(ProcessWebhookEvent::class));

        $this->assertSame(ProviderEventStatus::Processing->value, $event->fresh()->status);
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
}
