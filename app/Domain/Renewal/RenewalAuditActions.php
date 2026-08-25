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
}
