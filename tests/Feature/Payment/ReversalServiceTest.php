<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\Exceptions\AuditReasonRequiredException;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\Payment\Exceptions\PaymentReversalAlreadyRecordedException;
use App\Platform\Payment\Models\PaymentReversal;
use App\Platform\Payment\Models\PaymentSession;
use App\Platform\Payment\PaymentAuditActions;
use App\Platform\Payment\PaymentReversalType;
use App\Platform\Payment\ReversalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `ReversalService` + `Actions\RecordRefund`/`RecordChargeback` — Task 6's
 * safe slice, Wave 1d Append-Correction (`task-6-brief.md`). Proves the
 * reachable pieces named by the ruling: a `payment_reversals` row is
 * recorded exactly once per `(reversal_type, reference)` pair, the
 * mandatory-reason audit is enforced by `Audit::record()`'s own
 * `SensitiveActions` check (not re-implemented here), and structurally
 * proves the hard prohibitions: no `payment_sessions` write, no
 * `Journal::post()`/`postReversal()`, no `PaymentProvider` reference, no
 * `OrderWorkflow` reference.
 */
final class ReversalServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ReversalService
    {
        return app(ReversalService::class);
    }

    public function test_recording_a_refund_creates_a_reversal_row(): void
    {
        $reversal = $this->service()->record(
            type: PaymentReversalType::Refund,
            reference: 'TRX-refund-1',
            amountMinor: 15_000_00,
            reason: 'Customer cancelled within cooling-off period',
            actorRef: 7,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        $this->assertInstanceOf(PaymentReversal::class, $reversal);
        $this->assertSame(PaymentReversalType::Refund, $reversal->reversalType());
        $this->assertSame('TRX-refund-1', $reversal->reference);
        $this->assertSame(15_000_00, $reversal->amount_minor);
        $this->assertSame('Customer cancelled within cooling-off period', $reversal->reason);
        $this->assertSame('7', $reversal->recorded_by_actor_ref);
        $this->assertNotNull($reversal->recorded_at);
    }

    public function test_recording_a_chargeback_creates_a_reversal_row(): void
    {
        $reversal = $this->service()->record(
            type: PaymentReversalType::Chargeback,
            reference: 'TRX-chargeback-1',
            amountMinor: null,
            reason: 'Card issuer disputed the transaction',
            actorRef: 7,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        $this->assertSame(PaymentReversalType::Chargeback, $reversal->reversalType());
        $this->assertNull($reversal->amount_minor);
        $this->assertSame('Card issuer disputed the transaction', $reversal->reason);
    }

    public function test_the_refund_audit_event_carries_the_mandatory_reason_and_allowed_outcome(): void
    {
        $reversal = $this->service()->record(
            type: PaymentReversalType::Refund,
            reference: 'TRX-refund-2',
            amountMinor: 5_000_00,
            reason: 'Duplicate charge',
            actorRef: 7,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        $event = AuditEvent::query()
            ->where('action', PaymentAuditActions::REFUND)
            ->where('subject_id', $reversal->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame(AuditOutcome::Allowed->value, $event->outcome);
        $this->assertSame('Duplicate charge', $event->reason);
    }

    public function test_the_chargeback_audit_event_carries_the_mandatory_reason_and_allowed_outcome(): void
    {
        $reversal = $this->service()->record(
            type: PaymentReversalType::Chargeback,
            reference: 'TRX-chargeback-2',
            amountMinor: null,
            reason: 'Unauthorized transaction reported',
            actorRef: 7,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        $event = AuditEvent::query()
            ->where('action', PaymentAuditActions::CHARGEBACK)
            ->where('subject_id', $reversal->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame(AuditOutcome::Allowed->value, $event->outcome);
        $this->assertSame('Unauthorized transaction reported', $event->reason);
    }

    public function test_a_blank_reason_is_refused_by_audits_own_sensitive_action_check_and_rolls_back(): void
    {
        try {
            $this->service()->record(
                type: PaymentReversalType::Refund,
                reference: 'TRX-refund-blank-reason',
                amountMinor: null,
                reason: '',
                actorRef: 7,
                actorRole: 'admin',
                source: AuditSource::Panel,
            );

            $this->fail('Expected AuditReasonRequiredException for a blank reason.');
        } catch (AuditReasonRequiredException) {
            $this->assertSame(
                0,
                PaymentReversal::query()->where('reference', 'TRX-refund-blank-reason')->count(),
                'A missing-reason audit failure must roll back the createRecorded() mutation too (Audit::wrap same-transaction guarantee).'
            );
        }
    }

    public function test_a_second_refund_for_the_same_reference_is_refused(): void
    {
        $this->service()->record(
            type: PaymentReversalType::Refund,
            reference: 'TRX-duplicate',
            amountMinor: null,
            reason: 'First refund',
            actorRef: 7,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        $this->expectException(PaymentReversalAlreadyRecordedException::class);

        $this->service()->record(
            type: PaymentReversalType::Refund,
            reference: 'TRX-duplicate',
            amountMinor: null,
            reason: 'Second refund attempt, must not be allowed',
            actorRef: 7,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );
    }

    public function test_a_duplicate_attempt_does_not_leave_a_second_row_or_a_second_audit_event(): void
    {
        $this->service()->record(
            type: PaymentReversalType::Chargeback,
            reference: 'TRX-duplicate-2',
            amountMinor: null,
            reason: 'First chargeback',
            actorRef: 7,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        try {
            $this->service()->record(
                type: PaymentReversalType::Chargeback,
                reference: 'TRX-duplicate-2',
                amountMinor: null,
                reason: 'Second chargeback attempt, must not be allowed',
                actorRef: 7,
                actorRole: 'admin',
                source: AuditSource::Panel,
            );

            $this->fail('Expected PaymentReversalAlreadyRecordedException.');
        } catch (PaymentReversalAlreadyRecordedException) {
            $this->assertSame(
                1,
                PaymentReversal::query()->where('reversal_type', PaymentReversalType::Chargeback->value)->where('reference', 'TRX-duplicate-2')->count()
            );
            $this->assertSame(
                1,
                AuditEvent::query()->where('action', PaymentAuditActions::CHARGEBACK)->count(),
                'The rolled-back second attempt must not have written its own audit row.'
            );
        }
    }

    public function test_the_uniqueness_scope_is_the_pair_not_the_reference_alone(): void
    {
        // The SAME reference may have both a REFUND and a CHARGEBACK
        // recorded against it — the UNIQUE constraint is on
        // (reversal_type, reference), not reference alone.
        $refund = $this->service()->record(
            type: PaymentReversalType::Refund,
            reference: 'TRX-shared-reference',
            amountMinor: null,
            reason: 'Refund side',
            actorRef: 7,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        $chargeback = $this->service()->record(
            type: PaymentReversalType::Chargeback,
            reference: 'TRX-shared-reference',
            amountMinor: null,
            reason: 'Chargeback side',
            actorRef: 7,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        $this->assertNotSame($refund->id, $chargeback->id);
        $this->assertSame(2, PaymentReversal::query()->where('reference', 'TRX-shared-reference')->count());
    }

    public function test_recording_reversals_leaves_payment_sessions_untouched(): void
    {
        $this->service()->record(
            type: PaymentReversalType::Refund,
            reference: 'TRX-untouched-1',
            amountMinor: null,
            reason: 'Refund',
            actorRef: 7,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        $this->service()->record(
            type: PaymentReversalType::Chargeback,
            reference: 'TRX-untouched-2',
            amountMinor: null,
            reason: 'Chargeback',
            actorRef: 7,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        $this->assertSame(0, PaymentSession::query()->count());
    }

    public function test_none_of_the_reversal_files_reference_journal_payment_provider_or_the_order_aggregate(): void
    {
        foreach ([
            'app/Platform/Payment/ReversalService.php',
            'app/Platform/Payment/Actions/RecordRefund.php',
            'app/Platform/Payment/Actions/RecordChargeback.php',
            'app/Platform/Payment/Models/PaymentReversal.php',
        ] as $relativePath) {
            $source = $this->withoutComments((string) file_get_contents(base_path($relativePath)));

            foreach ([
                'payment_sessions',
                'PaymentSession',
                'SessionState::Paid',
                'Journal::post',
                'Journal::postReversal',
                'Contracts\\PaymentProvider',
                'PaymentProvider::refund',
                'OrderWorkflow',
                'DIBAYAR',
            ] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source, "{$relativePath} references [{$forbidden}]");
            }
        }
    }

    private function withoutComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}
