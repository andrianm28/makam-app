<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger;

use Carbon\CarbonImmutable;

/**
 * The AC8 eligibility rule, as a value rather than as a condition buried in an
 * Action.
 *
 * ---------------------------------------------------------------------------
 * Why this is its own type
 * ---------------------------------------------------------------------------
 * The whole point of AC8 is that payable eligibility is a rule SEPARATE from
 * paid state. A rule that only exists as an `if` inside
 * `Actions\VendorPayable::assess()` is a rule anyone can quietly widen by
 * adding an `|| $orderPaid` to it. Naming it makes the three conditions
 * enumerable, testable on their own, and impossible to satisfy by accident:
 * a caller cannot construct "eligible" without stating all three facts.
 *
 * Passing three loose booleans into `assess()` would have been the obvious
 * alternative and is rejected deliberately — at a call site
 * `assess(..., true, false, $t)` reads as nothing at all, and the failure mode
 * that matters here (two conditions swapped, so a paid-but-unfulfilled order
 * becomes payable) is exactly the one positional booleans hide.
 *
 * ---------------------------------------------------------------------------
 * Every unknown is NOT eligible
 * ---------------------------------------------------------------------------
 * `$disputeWindowEndsAt` is nullable because callers genuinely may not have a
 * dispute window recorded yet. Null means NOT elapsed, never "no window to
 * wait for" — the same fail-closed reading
 * `App\Http\Middleware\RequireRecentAuthentication` applies to a null
 * `lastAuthenticatedAt`. A missing record of a control is not the control
 * being satisfied.
 *
 * ---------------------------------------------------------------------------
 * Why the three facts arrive as arguments instead of being looked up here
 * ---------------------------------------------------------------------------
 * There is no order aggregate, no fulfilment-evidence record and no dispute
 * table in this repository yet — `app/Domain/**` holds the grave registry,
 * the service catalogue, the FAQ and booking drafts, and nothing that owns any
 * of these three facts. Inventing tables for them here would create a second,
 * rival source of truth for records that belong to
 * `funeral-marketplace-and-vendor-portal` and
 * `booking-and-order-orchestration`. This type is therefore the seam those
 * specs will hand their answers across, and this module deliberately does not
 * guess at what it cannot see.
 */
final readonly class VendorPayableEligibility
{
    /**
     * @param  bool  $orderPaid  The customer's money arrived. ONE of three
     *                           conditions — on its own it never makes anything payable.
     * @param  bool  $fulfilmentEvidenceAccepted  Vendor work-completed evidence
     *                                            was accepted (`AGENTS.md` §Authorization: work evidence is stored
     *                                            privately; this flag is the accepted/not decision, never the
     *                                            evidence itself).
     * @param  CarbonImmutable|null  $disputeWindowEndsAt  When the dispute window
     *                                                     closes. Null is treated as "not elapsed" — see the class note.
     */
    public function __construct(
        public bool $orderPaid,
        public bool $fulfilmentEvidenceAccepted,
        public ?CarbonImmutable $disputeWindowEndsAt,
    ) {}

    public function disputeWindowHasElapsed(CarbonImmutable $now): bool
    {
        return $this->disputeWindowEndsAt !== null
            && ! $this->disputeWindowEndsAt->isAfter($now);
    }

    /**
     * All three conditions, ANDed. There is deliberately no shortcut branch,
     * no early return on `$orderPaid`, and no "or" anywhere in this method.
     */
    public function isEligibleAt(CarbonImmutable $now): bool
    {
        return $this->fulfilmentEvidenceAccepted
            && $this->disputeWindowHasElapsed($now)
            && $this->orderPaid;
    }

    /**
     * The conditions that are NOT met, as stable machine-readable reasons.
     * Used for a legible refusal and for observability (payable ageing needs
     * to distinguish "waiting for evidence" from "waiting for the window").
     * Carries no customer, vendor, or monetary detail.
     *
     * @return list<string>
     */
    public function unmetConditions(CarbonImmutable $now): array
    {
        $unmet = [];

        if (! $this->fulfilmentEvidenceAccepted) {
            $unmet[] = 'fulfilment_evidence_not_accepted';
        }

        if (! $this->disputeWindowHasElapsed($now)) {
            $unmet[] = 'dispute_window_not_elapsed';
        }

        if (! $this->orderPaid) {
            $unmet[] = 'order_not_paid';
        }

        return $unmet;
    }
}
