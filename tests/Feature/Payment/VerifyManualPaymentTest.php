<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\Exceptions\AuditReasonRequiredException;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\Payment\Exceptions\PaymentVerificationAlreadyDecidedException;
use App\Platform\Payment\Models\PaymentSession;
use App\Platform\Payment\Models\PaymentVerification;
use App\Platform\Payment\PaymentAuditActions;
use App\Platform\Payment\PaymentVerificationDecision;
use App\Platform\Payment\PaymentVerificationStatus;
use App\Platform\Payment\VerifyManualPayment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `VerifyManualPayment` — Task 5's safe slice, Wave 1c Append-Correction
 * (`task-5-brief.md`). Proves AC8's "separate authorized action": a
 * `payment_verifications` row moves from `SUBMITTED` to `VERIFIED`/
 * `REJECTED` exactly once, with a mandatory reason enforced by
 * `Audit::record()`'s own `SensitiveActions` check (not re-implemented
 * here), and structurally proves the hard prohibition: no
 * `payment_sessions` write, no `Journal::post()`, no `SessionState::Paid`,
 * no `OrderWorkflow` reference. `VerifyManualPaymentRouteTest` covers the
 * HTTP/re-authentication half.
 */
final class VerifyManualPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function submittedVerification(): PaymentVerification
    {
        return PaymentVerification::createSubmitted([
            'reference' => 'order-1',
            'payment_method' => 'bank_transfer',
            'payment_reference' => 'TRX-1',
            'instructions' => null,
            'submitted_at' => CarbonImmutable::now(),
        ]);
    }

    public function test_approving_transitions_to_verified_and_records_the_decision(): void
    {
        $verification = $this->submittedVerification();

        (new VerifyManualPayment)->verify(
            verification: $verification,
            decision: PaymentVerificationDecision::Approve,
            reason: 'Proof matched provider statement',
            actorRef: 9,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        $verification->refresh();

        $this->assertSame(PaymentVerificationStatus::Verified, $verification->status());
        $this->assertNotNull($verification->decided_at);
        $this->assertSame('Proof matched provider statement', $verification->decided_reason);
        $this->assertSame('9', $verification->decided_by_actor_ref);
    }

    public function test_rejecting_transitions_to_rejected_and_records_the_reason(): void
    {
        $verification = $this->submittedVerification();

        (new VerifyManualPayment)->verify(
            verification: $verification,
            decision: PaymentVerificationDecision::Reject,
            reason: 'Amount does not match',
            actorRef: 9,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        $verification->refresh();

        $this->assertSame(PaymentVerificationStatus::Rejected, $verification->status());
        $this->assertSame('Amount does not match', $verification->decided_reason);
    }

    public function test_the_audit_event_carries_the_mandatory_reason_and_the_right_outcome(): void
    {
        $approved = $this->submittedVerification();

        (new VerifyManualPayment)->verify(
            verification: $approved,
            decision: PaymentVerificationDecision::Approve,
            reason: 'Proof matched provider statement',
            actorRef: 9,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        $approvedEvent = AuditEvent::query()
            ->where('action', PaymentAuditActions::MANUAL_VERIFICATION)
            ->where('subject_id', $approved->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($approvedEvent);
        $this->assertSame(AuditOutcome::Allowed->value, $approvedEvent->outcome);
        $this->assertSame('Proof matched provider statement', $approvedEvent->reason);

        $rejected = $this->submittedVerification();

        (new VerifyManualPayment)->verify(
            verification: $rejected,
            decision: PaymentVerificationDecision::Reject,
            reason: 'Amount does not match',
            actorRef: 9,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        $rejectedEvent = AuditEvent::query()
            ->where('action', PaymentAuditActions::MANUAL_VERIFICATION)
            ->where('subject_id', $rejected->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($rejectedEvent);
        $this->assertSame(AuditOutcome::Denied->value, $rejectedEvent->outcome);
    }

    public function test_a_blank_reason_is_refused_by_audits_own_sensitive_action_check_and_rolls_back(): void
    {
        $verification = $this->submittedVerification();

        try {
            (new VerifyManualPayment)->verify(
                verification: $verification,
                decision: PaymentVerificationDecision::Approve,
                reason: '',
                actorRef: 9,
                actorRole: 'admin',
                source: AuditSource::Panel,
            );

            $this->fail('Expected AuditReasonRequiredException for a blank reason.');
        } catch (AuditReasonRequiredException) {
            $verification->refresh();

            $this->assertSame(
                PaymentVerificationStatus::Submitted,
                $verification->status(),
                'A missing-reason audit failure must roll back the decide() mutation too (Audit::wrap same-transaction guarantee).'
            );
        }
    }

    public function test_a_verification_can_only_be_decided_once(): void
    {
        $verification = $this->submittedVerification();

        (new VerifyManualPayment)->verify(
            verification: $verification,
            decision: PaymentVerificationDecision::Approve,
            reason: 'First decision',
            actorRef: 9,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        $this->expectException(PaymentVerificationAlreadyDecidedException::class);

        (new VerifyManualPayment)->verify(
            verification: $verification,
            decision: PaymentVerificationDecision::Reject,
            reason: 'Second decision, must not be allowed',
            actorRef: 9,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );
    }

    public function test_it_never_touches_payment_sessions_the_journal_or_the_order_aggregate(): void
    {
        $source = $this->withoutComments((string) file_get_contents(base_path('app/Platform/Payment/VerifyManualPayment.php')));

        foreach ([
            'payment_sessions',
            'PaymentSession',
            'SessionState::Paid',
            'Journal::post',
            'OrderWorkflow',
            'DIBAYAR',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, "VerifyManualPayment.php references [{$forbidden}]");
        }
    }

    public function test_deciding_leaves_payment_sessions_untouched(): void
    {
        $verification = $this->submittedVerification();

        (new VerifyManualPayment)->verify(
            verification: $verification,
            decision: PaymentVerificationDecision::Approve,
            reason: 'Proof matched provider statement',
            actorRef: 9,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        $this->assertSame(0, PaymentSession::query()->count());
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
