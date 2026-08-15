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
 * (AC10). The Wave 1c ruling forbade fabricating a session or the journal
 * write, and the online-payment-gateway lane did not change that: the
 * webhook path is what now transitions sessions to `PAID`
 * (`Actions\ApplyPaymentSettlement`), and this manual path still writes only
 * its own `payment_verifications` row and its own audit event.
 * `tests/Feature/Payment/VerifyManualPaymentTest.php` grep-asserts this file
 * contains none of those references and behaviourally asserts
 * `payment_sessions` stays untouched across both decisions. Wiring the
 * manual path into `SessionState::Paid` belongs to the task that adds the
 * `payment_verifications` -> order linkage (`ApplyPaidEffects`'s doc block
 * names it as the still-open trigger site).
 *
 * ---------------------------------------------------------------------------
 * "Exactly once" is enforced with a row lock, not the caller's in-memory
 * object — same convention as `ProcessWebhookEvent::lockClaimScope()`
 * (`ProviderEvent::query()->whereKey(...)->lockForUpdate()->first()`) and
 * `OutboxPublisher`'s claim query
 * ---------------------------------------------------------------------------
 * The controller's `$verification` parameter is loaded unlocked
 * (`PaymentVerification::query()->findOrFail(...)`). Calling
 * `Models\PaymentVerification::decide()` directly on that instance would
 * only check the calling PHP object's in-memory `status` — two concurrent
 * requests for the same row (a double-click, a retried form submit) could
 * both read `SUBMITTED` before either commits, and the second would
 * silently overwrite the first's decision instead of throwing
 * `PaymentVerificationAlreadyDecidedException`. To close that gap, the
 * mutation closure below re-fetches the row via
 * `PaymentVerification::query()->whereKey(...)->lockForUpdate()->firstOrFail()`
 * from inside `Audit::wrap()`'s transaction and calls `decide()` on THAT
 * freshly-locked instance, never on the controller's `$verification`
 * parameter. The row lock blocks a second concurrent transaction until the
 * first commits, so the second transaction's own re-fetch sees the
 * already-decided status and correctly throws.
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
                $locked = PaymentVerification::query()
                    ->whereKey($verification->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $locked->decide(
                    decision: $decision,
                    decidedByActorRef: $actorRef !== null ? (string) $actorRef : null,
                    reason: $reason,
                );

                return $locked;
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
