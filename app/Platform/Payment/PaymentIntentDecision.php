<?php

declare(strict_types=1);

namespace App\Platform\Payment;

/**
 * The closed list of `payment_intents.decision` values.
 *
 * ---------------------------------------------------------------------------
 * `Allowed` exists ONLY because a real pass path exists
 * ---------------------------------------------------------------------------
 * Wave 1b ruling 1b-L3-01 shipped this enum with a single `Denied` case:
 * no guard evaluation could produce an allowed decision, and an enum case
 * nothing can construct was worse than no case at all — it would have
 * invited a caller to write `PaymentIntentDecision::Allowed` and believe
 * the resulting row meant a guard actually passed.
 *
 * The online-payment gateway task is that deliberate, reviewed change: the
 * session-creation path is now reachable when `G-PAY-01` is open in dev
 * (see `Models\PaymentSession`'s gate-conditional `creating` hook), so the
 * decision a session's authorization record carries must be expressible.
 * `Allowed` is written ONLY by the guard-composed session-opening path, and
 * only while the gate is open — never by a caller that reached a payment
 * screen without passing the guard.
 *
 * The widening is enforced in the same two places the absence was: this
 * enum's `::values()` now returns `['denied', 'allowed']`, and the
 * `payment_intents.decision` CHECK constraint (Postgres) is re-created to
 * admit both values — automatically in fresh migrations (the CHECK is
 * generated from `::values()`) and by the additive
 * `2026_08_14_100000_allow_online_payment_decision` migration on
 * already-applied databases.
 */
enum PaymentIntentDecision: string
{
    case Denied = 'denied';

    case Allowed = 'allowed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
