<?php

declare(strict_types=1);

namespace App\Platform\Payment;

use App\Domain\Marketplace\Actions\MarkMarketplaceOrderPaid;
use App\Domain\Marketplace\Models\MarketplaceOrder;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use App\Platform\Payment\Exceptions\PaymentVerificationMissingLinkageException;
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
 * PAY-02: an APPROVE now also marks the linked marketplace order paid, in
 * the SAME transaction
 * ---------------------------------------------------------------------------
 * `payment_verifications.order_id`/`amount_minor` are real now
 * (`2026_08_26_120000_add_order_link_and_amount_to_payment_verifications_
 * table.php`), and that migration's own doc block records the investigation
 * confirming this table's only real caller is the marketplace manual-payment
 * path. So the "still open" trigger site `app/Domain/OrderWorkflow/Actions/
 * ApplyPaidEffects`'s doc block once named is, concretely,
 * `App\Domain\Marketplace\Actions\MarkMarketplaceOrderPaid` — the
 * marketplace-owned analog of `ApplyPaidEffects` for a `MarketplaceOrder`
 * rather than a booking `Order`. `App\Platform\Payment\Actions\
 * ApplyPaymentSettlement` (the webhook leg) already calls into
 * `App\Domain\Marketplace` directly for the same reason, so this is not a
 * new layering decision — it is the established one, applied to the second
 * caller.
 *
 * On `Approve`, AFTER `decide()` succeeds inside this same `Audit::wrap()`
 * transaction, the locked row's linked order is marked paid with the row's
 * OWN recorded `amount_minor` — never re-derived, never re-entered by the
 * admin (the admin's job is to CONFIRM the customer's stated amount against
 * the real bank statement, not to retype it — see `SubmitManualPayment`'s
 * own doc block). `MarkMarketplaceOrderPaid` asserts that amount against the
 * order's real `total_minor` itself
 * (`assertAmountMatchesOrderTotal()`) — that assertion is REUSED, not
 * duplicated here. A mismatch throws `MarketplacePaymentAmountMismatchException`
 * from inside this transaction, so the WHOLE approval — the order-paid
 * write AND the verification's own `decide()` — rolls back together: the
 * verification stays `SUBMITTED` and the order stays unpaid, exactly the
 * same all-or-nothing guarantee `AuditReasonRequiredException` already
 * proves for a blank reason. The controller does not catch this exception
 * (same "no dedicated admin UI/error-mapping exists yet for this endpoint"
 * posture `RecordPaymentReversalController` already documents for its own
 * domain exceptions) — it propagates, and the important property is where
 * it propagates FROM: inside the one transaction, after nothing has
 * committed.
 *
 * A row that predates this change and carries no `order_id`/`amount_minor`
 * cannot be approved at all — `PaymentVerificationMissingLinkageException`
 * fails closed rather than marking an order paid on trust. Such a row can
 * still be REJECTED; rejection never touches an order.
 *
 * `fulfilmentEvidenceAccepted: true` is passed to `MarkMarketplaceOrderPaid`
 * for the same reason `MarkMarketplaceOrderPaidAction` (the panel's existing
 * "Tandai Dibayar" button) already does: a human — the finance/
 * restricted-admin actor this endpoint's `PaymentActionAuthorizer` gate
 * requires — is making the eligibility call here, unlike the webhook leg
 * (`ApplyPaymentSettlement`), which fails closed with `false` because a
 * signed webhook proves only that money arrived, nothing about fulfilment.
 *
 * Re-approving an already-`VERIFIED` row still throws
 * `PaymentVerificationAlreadyDecidedException` before this code is ever
 * reached (see the row-lock section below) — idempotency on the
 * VERIFICATION side is unchanged. Idempotency on the ORDER side, for the
 * case a different path (a late webhook, a manual "Tandai Dibayar" panel
 * click) already paid the same order, is `MarkMarketplaceOrderPaid`'s own
 * existing no-op-when-already-`DIBAYAR` branch — not re-implemented here.
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
 * the `decide()` mutation (and any order-paid mutation) too — the pair can
 * never be committed separately (the same AC4 guarantee `Audit::wrap()`'s
 * own class doc block describes).
 *
 * ---------------------------------------------------------------------------
 * Deliberately does NOT call `Journal::post()` and does NOT touch
 * `payment_sessions`/`PaymentSession`/`SessionState::Paid`/`app/Domain/
 * OrderWorkflow/`
 * ---------------------------------------------------------------------------
 * The original plan's Task 5 text asked the approve path to also transition
 * `payment_sessions` to `PAID` and post a same-transaction journal entry
 * (AC10). The Wave 1c ruling forbade fabricating a session or the journal
 * write, and PAY-02 does not revisit that ruling — it wires the ALREADY-real
 * `marketplace_orders`/`MarkMarketplaceOrderPaid` path, never
 * `payment_sessions` and never a `Journal::post()` call.
 * `tests/Feature/Payment/VerifyManualPaymentTest.php` grep-asserts this file
 * contains none of `payment_sessions`/`PaymentSession`/`SessionState::Paid`/
 * `Journal::post` and behaviourally asserts `payment_sessions` stays
 * untouched across both decisions.
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
            mutation: function () use ($verification, $decision, $reason, $actorRef, $actorRole, $source): PaymentVerification {
                $locked = PaymentVerification::query()
                    ->whereKey($verification->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $locked->decide(
                    decision: $decision,
                    decidedByActorRef: $actorRef !== null ? (string) $actorRef : null,
                    reason: $reason,
                );

                if ($decision === PaymentVerificationDecision::Approve) {
                    $this->markLinkedOrderPaid($locked, $actorRef, $actorRole, $source);
                }

                return $locked;
            },
            action: PaymentAuditActions::MANUAL_VERIFICATION,
            subject: fn (PaymentVerification $verification): AuditSubject => new AuditSubject('payment_verification', $verification->id),
            outcome: $decision === PaymentVerificationDecision::Approve ? AuditOutcome::Allowed : AuditOutcome::Denied,
            actorRef: $actorRef,
            actorRole: $actorRole,
            source: $source,
            reason: $reason,
            correlationId: app(CorrelationContext::class)->current()?->value,
            metadata: [
                'previous_state' => PaymentVerificationStatus::Submitted->value,
                'new_state' => $decision->resultingStatus()->value,
            ],
        );
    }

    /**
     * @throws PaymentVerificationMissingLinkageException when the row has no order/amount to verify.
     */
    private function markLinkedOrderPaid(
        PaymentVerification $verification,
        int|string|null $actorRef,
        string $actorRole,
        AuditSource $source,
    ): void {
        if ($verification->order_id === null || $verification->amount_minor === null) {
            throw PaymentVerificationMissingLinkageException::forVerification((string) $verification->id);
        }

        $order = MarketplaceOrder::query()->findOrFail($verification->order_id);

        app(MarkMarketplaceOrderPaid::class)(
            $order,
            (int) $verification->amount_minor,
            fulfilmentEvidenceAccepted: true,
            actorRef: $actorRef !== null ? (string) $actorRef : null,
            actorRole: $actorRole,
            correlationId: app(CorrelationContext::class)->current()?->value,
            source: $source,
        );
    }
}
