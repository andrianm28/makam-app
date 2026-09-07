<?php

declare(strict_types=1);

namespace App\Domain\Renewal;

/**
 * Audit action vocabulary for the renewal domain — the same one-class-per-
 * domain convention `App\Domain\CareSubscription\CareSubscriptionAuditActions`,
 * `App\Domain\Marketplace\MarketplaceAuditActions` and every other
 * `<Domain>AuditActions` class in `app/Domain/**` already follow.
 *
 * This class did not exist before `Actions\MarkRenewalPaidOnline` (Task 2,
 * `docs/superpowers/plans/2026-08-25-renewal-online-payment.md`) — the two
 * earlier renewal actions that write audit rows
 * (`Actions\MarkExternalRenewal`, `Actions\MarkRenewalPaidExternally`,
 * `Actions\ExpireRenewal`) each spell their action name as a literal string
 * inline (`'RENEWAL_EXTERNAL_MARKING'`, `'RENEWAL_EXPIRED'`) rather than
 * through a shared class. Those call sites are left untouched — this task's
 * scope is the new online-settlement action only, not a retrofit of
 * unrelated, already-shipped code. `RENEWAL_PAID_ONLINE` is declared here,
 * in the domain-class shape, because it is new code and the rest of the
 * codebase's domains already establish this as the convention going
 * forward.
 *
 * ---------------------------------------------------------------------------
 * `RENEWAL_PAID_ONLINE` is deliberately NOT on `SensitiveActions::ACTIONS`
 * ---------------------------------------------------------------------------
 * `SensitiveActions` governs actions with a HUMAN-authored justification —
 * `RENEWAL_EXTERNAL_MARKING` is listed there because an admin marking a
 * renewal paid off-platform is exactly that kind of decision
 * (`SensitiveActions`'s own list entry comment). A validated webhook
 * confirming an online payment has no human decision behind it — the same
 * reasoning `App\Platform\Payment\PaymentAuditActions::SESSION_OPENED`'s doc
 * block gives for staying off that list, and the same reasoning
 * `App\Domain\CareSubscription\Actions\MarkCyclePaid`'s `CYCLE_PAID` and
 * `App\Domain\Marketplace\Actions\MarkMarketplaceOrderPaid`'s
 * `ORDER_PAYMENT_STATE_CHANGED` both follow: a machine-driven payment
 * confirmation is a recorded fact, not a decision, so a mandatory free-text
 * reason would be either boilerplate or a place for a careless caller to
 * paste restricted data.
 */
final class RenewalAuditActions
{
    /**
     * Written by `Actions\MarkRenewalPaidOnline` with `AuditOutcome::Allowed`,
     * subject = the `Renewal` row, when a validated `payment.completed`
     * webhook settles a renewal opened through the online journey.
     */
    public const string RENEWAL_PAID_ONLINE = 'RENEWAL_PAID_ONLINE';

    /**
     * Whole-branch review fix wave (25 Aug 2026) — written by
     * `Actions\MarkRenewalPaidOnline` with `AuditOutcome::Denied`, subject =
     * the `Renewal` row, on the swallowed "duplicate arrival" branch: a
     * second, independent settling provider transaction resolves to a
     * renewal that is already `DIBAYAR` with the same (asserted) amount.
     * `App\Platform\Payment\ProcessWebhookEvent`'s `(provider,
     * provider_transaction_id)` claim already guarantees this is a
     * DIFFERENT provider transaction than the one that settled the renewal
     * first, so every arrival here is a genuine second collection, never a
     * replay of the same webhook delivery. No state change and no second
     * outbox row accompany this — see that Action's own "Idempotency"
     * doc-block section for why the swallow itself is correct — but this
     * audit row is the one durable trace an operator can find to drive a
     * refund decision, mirroring `App\Platform\Payment\PaymentAuditActions::
     * DUPLICATE_ARRIVAL`'s reasoning for the booking leg.
     *
     * Not on `SensitiveActions::ACTIONS`, for the same reason as
     * `RENEWAL_PAID_ONLINE` above: machine-decided, closed-list `note`, no
     * free-text reason for a careless caller to fill with restricted data.
     */
    public const string RENEWAL_PAID_ONLINE_DUPLICATE_ARRIVAL = 'RENEWAL_PAID_ONLINE_DUPLICATE_ARRIVAL';

    /**
     * Whole-branch review fix wave (25 Aug 2026) — written by
     * `Actions\MarkRenewalPaidOnline` with `AuditOutcome::Denied`, subject =
     * the `Renewal` row, immediately before it throws
     * `RenewalAlreadySettledException` on the genuine-anomaly branch: a
     * settlement arrived for a renewal whose status is neither
     * `MENUNGGU_PEMBAYARAN` (open) nor `DIBAYAR` (already settled) — today
     * that is only reachable at `KEDALUWARSA`, a REAL, live status written
     * by `Actions\ExpireRenewal` (wired to a real Filament admin action). An
     * operator expiring a renewal while the customer's checkout is still
     * live, followed by the customer completing that payment, is the
     * concrete race this branch fails closed on.
     *
     * **FIXED by Batch M1b (PAY-03, 7 Sep 2026)** — the known gap recorded
     * here since 24 Aug 2026 (this row written inside a SAVEPOINT nested in
     * `ProcessWebhookEvent`'s own outer transaction, erased by that outer
     * transaction's rollback when the exception propagated) is closed:
     * `MarkRenewalPaidOnline` no longer throws for this branch. It returns a
     * `App\Platform\Payment\SettlementAnomaly`, and THIS action is now
     * written by `ProcessWebhookEvent::auditSettlementAnomaly()` from inside
     * the transaction that actually commits — see that class's own doc
     * block and `SettlementAnomaly`'s doc block for the full shape.
     *
     * Not on `SensitiveActions::ACTIONS`, for the same reason as
     * `RENEWAL_PAID_ONLINE_DUPLICATE_ARRIVAL` above.
     */
    public const string RENEWAL_PAID_ONLINE_REFUSED = 'RENEWAL_PAID_ONLINE_REFUSED';

    /**
     * Batch M1b (PAY-03, 7 Sep 2026) — written by
     * `App\Platform\Payment\ProcessWebhookEvent::auditSettlementAnomaly()`,
     * subject = the `Renewal` row, when a settlement's arrived amount does
     * not EXACTLY equal the renewal's latest quote. Before this fix the
     * amount-mismatch branch threw `RenewalPaymentAmountMismatchException`
     * with NO audit row at all — an operator had no durable trace of a
     * mismatched arrival beyond a failed queue job. `MarkRenewalPaidOnline`
     * no longer throws for this branch; it returns a
     * `App\Platform\Payment\SettlementAnomaly` instead, and this action is
     * recorded from the call site that is actually going to commit — see
     * that class's own doc block for the full "record-and-return" shape.
     *
     * Not on `SensitiveActions::ACTIONS`, for the same reason as the two
     * actions above: machine-decided, closed-list `note`, no free-text
     * reason for a careless caller to fill with restricted data.
     */
    public const string RENEWAL_PAID_ONLINE_AMOUNT_MISMATCH = 'RENEWAL_PAID_ONLINE_AMOUNT_MISMATCH';
}
