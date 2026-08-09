<?php

declare(strict_types=1);

namespace App\Platform\Payment;

/**
 * The six payment-guard conditions, in the FIXED order
 * `.kiro/specs/platform-payment-adapter/design.md` §Payment guard prints
 * them:
 *
 * ```text
 * product gate open
 * confirmation valid OR reservation active
 * quote accepted and not expired
 * admin/case permission
 * amount == quote total
 * merchant/badan_usaha bound
 * ```
 *
 * "All six must hold. The guard is the only path to a payment session; a
 * denial returns an explanatory result, never a silent no-op."
 *
 * This enum names the conditions only. Which of them can actually be
 * evaluated against a real record today — exactly one, the first — and what
 * the other five do instead is `GuardPaymentSession`'s concern, recorded
 * there with the Wave 1b ruling that decided it. Keeping the "what is
 * missing" mapping out of this enum keeps the closed list a stable contract:
 * when the orchestration spec lands its records, `GuardPaymentSession`
 * changes and this file does not.
 */
enum GuardCondition: string
{
    /** Condition 1 — REAL: `ModeResolver::paymentMode()`, backed by `G-PAY-01`. */
    case ProductGateOpen = 'product_gate_open';

    /** Condition 2 — a valid `Confirmation` OR an active `PlotReservation`. */
    case ConfirmationOrReservation = 'confirmation_valid_or_reservation_active';

    /** Condition 3 — an accepted, unexpired `Quote`. */
    case QuoteAcceptedAndUnexpired = 'quote_accepted_and_unexpired';

    /** Condition 4 — the actor holds opening permission for the order/case. */
    case AuthorizedOpening = 'authorized_opening';

    /** Condition 5 — requested amount equals the quote total, in integer minor units. */
    case AmountMatchesQuoteTotal = 'amount_matches_quote_total';

    /** Condition 6 — merchant and `badan_usaha` bound and explicit (AC13). */
    case MerchantAndBadanUsahaBound = 'merchant_and_badan_usaha_bound';

    /**
     * The evaluation order, as a plain list of values — the shape both the
     * guard's own iteration and `payment_intents.denied_conditions` use, so
     * a reordering shows up as a test failure rather than as silently
     * different observability data.
     *
     * @var list<string>
     */
    public const array ORDER = [
        'product_gate_open',
        'confirmation_valid_or_reservation_active',
        'quote_accepted_and_unexpired',
        'authorized_opening',
        'amount_matches_quote_total',
        'merchant_and_badan_usaha_bound',
    ];

    /**
     * The enum cases in `self::ORDER`, guaranteed to be a complete cover of
     * the enum — `cases()` already returns declaration order, and the
     * `ORDER` constant restates it as values for the callers that need
     * strings.
     *
     * @return list<self>
     */
    public static function inEvaluationOrder(): array
    {
        return self::cases();
    }

    /**
     * 1-based position in design.md's printed order. Used in denial
     * messages and reports so a human reading "condition 4" and a human
     * reading `authorized_opening` are talking about the same thing.
     */
    public function position(): int
    {
        return (int) array_search($this->value, self::ORDER, true) + 1;
    }
}
