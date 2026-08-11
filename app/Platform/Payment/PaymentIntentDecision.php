<?php

declare(strict_types=1);

namespace App\Platform\Payment;

/**
 * The closed list of `payment_intents.decision` values.
 *
 * ---------------------------------------------------------------------------
 * `Allowed` is deliberately absent
 * ---------------------------------------------------------------------------
 * Wave 1b ruling 1b-L3-01 permits no reachable pass outcome, so no guard
 * evaluation in this codebase can produce an allowed decision — and an enum
 * case nothing can construct is worse than no case at all: it invites a
 * caller to write `PaymentIntentDecision::Allowed` and believe the resulting
 * row means a guard actually passed.
 *
 * The absence is enforced twice over: this enum has one case, and the
 * `payment_intents.decision` CHECK constraint (Postgres) admits only
 * `'denied'`. The task that lands the missing upstream records and
 * implements a real pass path adds the case AND widens that constraint in
 * its own migration — a deliberate, reviewed change, which is exactly the
 * bar `AGENTS.md` sets for anything on the financial path.
 */
enum PaymentIntentDecision: string
{
    case Denied = 'denied';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
