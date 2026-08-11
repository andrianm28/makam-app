<?php

declare(strict_types=1);

namespace App\Platform\Payment;

use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Payment\Models\PaymentVerification;

/**
 * Task 5's `VerifyManualPayment` — AC8's "separate authorized action" and
 * AC9's re-authentication gate (Wave 1c Append-Correction,
 * `.superpowers/sdd/2026-08-09-platform-payment-adapter/task-5-brief.md`).
 * Its only real HTTP caller is
 * `App\Platform\Payment\Http\Controllers\VerifyManualPaymentController`,
 * reached only after `App\Http\Middleware\RequireRecentAuthentication`'s
 * freshness check has passed — see `routes/web.php` for the route.
 *
 * Transitions a `PaymentVerification` row's OWN status
 * (`Models\PaymentVerification::decide()` — `SUBMITTED → VERIFIED` on
 * approve, `SUBMITTED → REJECTED` on reject) and writes the mandatory-reason
 * audit `PaymentAuditActions::MANUAL_VERIFICATION`
 * (`SensitiveActions::PAYMENT_MANUAL_VERIFICATION`).
 *
 * ---------------------------------------------------------------------------
 * The mandatory reason is enforced by `Audit::record()`, not re-implemented
 * here
 * ---------------------------------------------------------------------------
 * `$reason` is passed straight through to `Audit::wrap()`'s own `$reason`
 * parameter. `Audit::record()` already checks
 * `SensitiveActions::requiresReason(PaymentAuditActions::MANUAL_VERIFICATION)`
 * (true — that action is already on `SensitiveActions::ACTIONS`) and throws
 * `AuditReasonRequiredException` on a null/blank reason. Because this runs
 * inside `Audit::wrap()`'s single transaction, a missing reason rolls back
 * the `decide()` mutation too — the pair can never be committed separately
 * (the same AC4 guarantee `Audit::wrap()`'s own class doc block describes).
 *
 * ---------------------------------------------------------------------------
 * Deliberately does NOT call `Journal::post()` and does NOT touch
 * `payment_sessions`/`PaymentSession`/`SessionState::Paid`/`OrderWorkflow`
 * ---------------------------------------------------------------------------
 * The original plan's Task 5 text asked the approve path to also transition
 * `payment_sessions` to `PAID` and post a same-transaction journal entry
 * (AC10). The Wave 1c ruling found no `payment_sessions` row can exist
 * (`GuardResult::isAllowed()` is always false) and forbids fabricating one
 * or the journal write. This class writes only its own
 * `payment_verifications` row and its own audit event.
 * `tests/Feature/Payment/VerifyManualPaymentTest.php` grep-asserts this file
 * contains none of those references and behaviourally asserts
 * `payment_sessions` stays empty across both decisions.
 */
final readonly class VerifyManualPayment
{
    public function verify(
        PaymentVerification $verification,
        PaymentVerificationDecision $decision,
        string $reason,
        int|string|null $actorRef,
        string $actorRole,
        AuditSource $source,
    ): PaymentVerification {
        return Audit::wrap(
            mutation: function () use ($verification, $decision, $reason, $actorRef): PaymentVerification {
                $verification->decide(
                    decision: $decision,
                    decidedByActorRef: $actorRef !== null ? (string) $actorRef : null,
                    reason: $reason,
                );

                return $verification;
            },
            action: PaymentAuditActions::MANUAL_VERIFICATION,
            subject: fn (PaymentVerification $verification): AuditSubject => new AuditSubject('payment_verification', $verification->id),
            outcome: $decision === PaymentVerificationDecision::Approve ? AuditOutcome::Allowed : AuditOutcome::Denied,
            actorRef: $actorRef,
            actorRole: $actorRole,
            source: $source,
            reason: $reason,
            metadata: [
                'previous_state' => PaymentVerificationStatus::Submitted->value,
                'new_state' => $decision->resultingStatus()->value,
            ],
        );
    }
}
