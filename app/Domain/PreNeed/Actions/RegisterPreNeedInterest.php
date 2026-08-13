<?php

declare(strict_types=1);

namespace App\Domain\PreNeed\Actions;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\PreNeed\Models\PreNeedInterest;
use App\Domain\PreNeed\PreNeedInterestStatus;
use App\Platform\FeatureGate\ModeResolver;

/**
 * AC5, Pre-Need arm: registers interest for a `PRE_NEED_PLOT_PURCHASE`
 * order, and creates NO payment object, NO invoice, and NO financial
 * obligation of any kind. The ONLY writer of `pre_need_interests` rows.
 *
 * Called from inside `App\Domain\OrderWorkflow\Actions\SubmitBookingDraft`'s
 * transaction and opens none of its own — same reasoning as
 * `App\Domain\FuneralCase\Actions\OpenFuneralCase`.
 *
 * ---------------------------------------------------------------------------
 * `G-LEGAL-01`, and how the restriction is ENFORCED rather than documented
 * ---------------------------------------------------------------------------
 * The gate is read server-side, through `App\Platform\FeatureGate\
 * ModeResolver::preNeedMode()` — the one place in this codebase that pairs
 * `G-LEGAL-01` with a mode value, and which resolves through
 * `FeatureGateResolver`, whose own doc block states it is "the ONLY
 * server-side entry point Domain Actions and UI should call" and that
 * "nothing about this class reads a request header, query string, or
 * cookie". Requirements AC2: "evaluate every gate check server-side";
 * Negative criteria: "No client-side gate as the enforcement point."
 *
 * The enforcement itself has three parts, none of which is a comment:
 *
 *   1. This Action creates no financial object on ANY branch. There is no
 *      `if (gate open) { …create a payment… }` here to get wrong, because
 *      the paid Pre-Need path is not built in this lane at all —
 *      `docs/domain/financial-model.md` §4: "A missing decision closes the
 *      relevant payment/settlement gate; it does not authorize a guessed
 *      implementation."
 *   2. `pre_need_interests` has nowhere to record money — no amount, no
 *      currency, no invoice or payment reference. See that migration.
 *   3. The resolved mode is WRITTEN to the row, which is what gives the
 *      server-side read teeth. A hardcoded `interest_only` would be
 *      indistinguishable from a real read without it, and is killed by
 *      `SubmitBookingDraftTest::test_the_pre_need_gate_is_read_server_side_
 *      and_still_creates_no_financial_obligation_when_open`.
 *
 * Opening the gate deliberately does NOT stop interest being registered.
 * `App\Platform\FeatureGate\Modes\PreNeedMode`'s doc block is explicit that
 * `feature.preneed_interest` is gate-independent and "the interest flow
 * itself is never gated by `G-LEGAL-01`"; design-system §6.9's Negative
 * criteria say the interest-registration step is never removed. What an
 * open gate would change is whether a payment may FOLLOW — and that
 * capability does not exist yet, which the recorded `gate_mode` makes
 * visible to whoever builds it.
 */
final readonly class RegisterPreNeedInterest
{
    public function __construct(
        private ModeResolver $modes,
    ) {}

    public function __invoke(BookingDraft $draft): PreNeedInterest
    {
        return PreNeedInterest::query()->create([
            'status' => PreNeedInterestStatus::INTEREST_REGISTERED->value,
            'gate_mode' => $this->modes->preNeedMode()->value,
            'service_area' => $draft->city_code,
            'contacted_at' => null,
            'closed_at' => null,
            'booking_draft_id' => $draft->getKey(),
        ]);
    }
}
