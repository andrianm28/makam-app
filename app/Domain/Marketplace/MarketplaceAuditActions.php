<?php

declare(strict_types=1);

namespace App\Domain\Marketplace;

/**
 * The action names this module writes to `audit_events` via
 * `App\Platform\Audit\Audit::record()`. Named constants (not inline string
 * literals scattered across the module's Action classes) so tests and any
 * future caller reference the same values the Actions actually emit — mirrors
 * `App\Domain\Faq\FaqAuditActions`'s own doc block and naming shape.
 *
 * ---------------------------------------------------------------------------
 * Audited, but deliberately NOT added to `App\Platform\Audit\
 * SensitiveActions::ACTIONS`
 * ---------------------------------------------------------------------------
 * `SensitiveActions`'s own doc block defines that list narrowly: actions with
 * real fraud/harm risk if performed without a recorded justification (vendor
 * payout, plot override, manual payment verification, gate change, ...). A
 * vendor setting an order's processing status is a routine fulfilment-state
 * change — an error is fixable by setting the status again, it touches no
 * money, and there is no human-authored "reason" a mandatory-reason gate
 * would meaningfully extract beyond the `notes` field the order already
 * carries. The same reasoning `FaqAuditActions` applies to FAQ content
 * management applies here. The transition still calls `Audit::record()` (not
 * skipped) so a complete "who moved which order to what status, when" history
 * exists — the order-status change is the vendor-side record of fulfilment
 * progress, exactly the kind of mutation this codebase audits everywhere.
 *
 * The module cannot edit `app/Platform/Audit/SensitiveActions.php` anyway
 * (app/Platform/** is a read-only dependency for this lane); the analysis
 * above concludes this action does not belong there regardless.
 */
final class MarketplaceAuditActions
{
    public const string ORDER_STATUS_CHANGED = 'VENDOR_ORDER_STATUS_CHANGED';

    /**
     * Added by Task 5 of the online-payment-gateway lane
     * (`docs/superpowers/plans/2026-08-14-online-payment-gateway.md`).
     * Written by `Actions\MarkMarketplaceOrderPaid` — the only writer of
     * `marketplace_orders.payment_state = DIBAYAR` — with `previous_state` /
     * `new_state` metadata, in the same transaction as the transition.
     *
     * Deliberately NOT added to `SensitiveActions::ACTIONS`, for the same
     * category of reason as `ORDER_STATUS_CHANGED` above, stated for this
     * case: the webhook path is machine-decided (the signed webhook is the
     * payment authority), there is no human-authored justification to extract,
     * and the webhook claim plus the audit row are the complete record. The
     * manual payment decision a human DOES take is already listed under
     * `PAYMENT_MANUAL_VERIFICATION` elsewhere.
     */
    public const string ORDER_PAYMENT_STATE_CHANGED = 'MARKETPLACE_ORDER_PAYMENT_STATE_CHANGED';
}
