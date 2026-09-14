<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Platform\Audit\Models\AuditEvent;
use App\Platform\Payment\Exceptions\SettlementTargetUnresolvableException;
use App\Platform\Payment\Jobs\ProcessProviderEventJob;
use App\Platform\Payment\Models\ProviderEvent;
use App\Platform\Payment\PaymentAuditActions;
use App\Platform\Payment\ProviderEventStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Batch M1b, PAY-02 — `ProcessProviderEventJob::failed()`: once a settlement
 * job exhausts its retries, the `provider_events` row must move to
 * `MANUAL_REVIEW` with an audit trail, never stay `VALIDATED` forever with
 * only a `failed_jobs` entry to find it.
 */
final class ProcessProviderEventJobFailedTest extends TestCase
{
    use RefreshDatabase;

    private function makeValidatedEvent(): ProviderEvent
    {
        return ProviderEvent::query()->create([
            'provider' => 'sumopod_sandbox',
            'provider_event_id' => 'evt_'.bin2hex(random_bytes(8)),
            'event_id_source' => 'svix',
            'provider_transaction_id' => 'pay_'.bin2hex(random_bytes(4)),
            'invoice_reference' => 'NOT-A-REAL-REFERENCE',
            'event_type' => 'payment.completed',
            'merchant_ref' => 'makam-sandbox',
            'amount_minor' => 150_000_00,
            'raw_payload' => '{}',
            'payload_digest' => hash('sha256', '{}'),
            'signature_mechanism' => 'svix',
            'status' => ProviderEventStatus::Validated->value,
            'received_at' => now(),
            'validated_at' => now(),
        ]);
    }

    public function test_a_permanently_failed_settlement_moves_the_row_to_manual_review_and_audits_it(): void
    {
        $event = $this->makeValidatedEvent();

        (new ProcessProviderEventJob($event->getKey()))
            ->failed(SettlementTargetUnresolvableException::becauseNoOrder('NOT-A-REAL-REFERENCE'));

        $fresh = $event->fresh();
        $this->assertSame(ProviderEventStatus::ManualReview->value, $fresh->status);
        $this->assertSame('settlement target unresolvable; retries exhausted', $fresh->rejection_detail);

        $audit = AuditEvent::query()
            ->where('action', PaymentAuditActions::SETTLEMENT_PERMANENTLY_FAILED)
            ->sole();

        $this->assertSame('provider_event', $audit->subject_type);
        $this->assertSame($event->getKey(), $audit->subject_id);
        $this->assertSame('denied', $audit->outcome);
        $this->assertNull($audit->actor_ref);
        $this->assertSame('system', $audit->actor_role);
        $this->assertSame('job', $audit->source);
        $this->assertSame(
            'settlement retries exhausted: target unresolvable',
            $audit->metadata['note'] ?? null,
        );
    }

    /**
     * A non-`SettlementTargetUnresolvableException` failure still moves the
     * row and still audits it — the closed-list default branch.
     */
    public function test_an_unrecognised_exception_still_moves_the_row_with_the_default_closed_list_detail(): void
    {
        $event = $this->makeValidatedEvent();

        (new ProcessProviderEventJob($event->getKey()))->failed(new RuntimeException('anything'));

        $fresh = $event->fresh();
        $this->assertSame(ProviderEventStatus::ManualReview->value, $fresh->status);
        $this->assertSame('settlement failed; retries exhausted', $fresh->rejection_detail);

        // The raw exception message must never reach the row or the audit
        // trail (AC14) — only the closed-list detail/note.
        $this->assertStringNotContainsString('anything', (string) $fresh->rejection_detail);

        $audit = AuditEvent::query()
            ->where('action', PaymentAuditActions::SETTLEMENT_PERMANENTLY_FAILED)
            ->sole();
        $this->assertSame('settlement retries exhausted: permanent failure', $audit->metadata['note'] ?? null);
        $this->assertStringNotContainsString('anything', (string) json_encode($audit->toArray()));
    }

    /**
     * A row that already moved past `VALIDATED` (a redelivered webhook that
     * successfully claimed after this job's last attempt failed for an
     * unrelated reason, or an earlier `failed()` invocation) must never be
     * regressed — the same "re-check inside the lock" discipline
     * `ProcessWebhookEvent::__invoke()` uses for its own claim.
     */
    public function test_a_row_no_longer_validated_is_not_regressed(): void
    {
        $event = $this->makeValidatedEvent();
        $event->markStatus(ProviderEventStatus::Processed);

        (new ProcessProviderEventJob($event->getKey()))
            ->failed(SettlementTargetUnresolvableException::becauseNoOrder('should-not-apply'));

        $this->assertSame(ProviderEventStatus::Processed->value, $event->fresh()->status);
        $this->assertSame(0, AuditEvent::query()
            ->where('action', PaymentAuditActions::SETTLEMENT_PERMANENTLY_FAILED)
            ->count());
    }

    public function test_a_row_that_no_longer_exists_is_handled_without_error(): void
    {
        (new ProcessProviderEventJob('01996f4e-0000-7000-8000-000000000000'))
            ->failed(SettlementTargetUnresolvableException::becauseNoOrder('gone'));

        $this->assertSame(0, AuditEvent::query()
            ->where('action', PaymentAuditActions::SETTLEMENT_PERMANENTLY_FAILED)
            ->count());
    }
}
