<?php

declare(strict_types=1);

namespace App\Platform\Payment;

use InvalidArgumentException;

/**
 * One failing payment-guard condition, with the reason it failed and a
 * public-safe explanation. `GuardResult` carries a list of these.
 *
 * `$publicMessage` is public-safe by construction: it names a CONDITION, not
 * a record, a person, an amount, or an identifier. `AGENTS.md`
 * §Observability forbids restricted data in logs and audit payloads, and
 * this string travels into both (`payment_intents.public_message` and, in
 * summary form, the `PAYMENT_GUARD_DENIED` audit `note`). It is deliberately
 * NOT customer-facing product copy: `docs/design/design-system.md` §6.9 owns
 * the Indonesian banner a closed payment gate renders, and inventing a
 * second Indonesian string here would fork that copy away from its canonical
 * source. A UI task renders §6.9's banner; it does not echo this field.
 */
final readonly class ConditionDenial
{
    /**
     * @param  string|null  $missingUpstream  The name of the domain record
     *                                        this condition needed and could not find — required for, and
     *                                        only meaningful on, `GuardDenialReason::UnavailableUpstream`.
     *                                        A record NAME (e.g. `Quote`), never an instance reference.
     *
     * @throws InvalidArgumentException when the reason and the
     *                                  missing-upstream name disagree, which would let an
     *                                  "unavailable upstream" denial ship without naming what is
     *                                  missing — the one thing ruling 1b-L3-01 requires every such
     *                                  denial to state.
     */
    public function __construct(
        public GuardCondition $condition,
        public GuardDenialReason $reason,
        public string $publicMessage,
        public ?string $missingUpstream = null,
    ) {
        if ($reason === GuardDenialReason::UnavailableUpstream && ($missingUpstream === null || trim($missingUpstream) === '')) {
            throw new InvalidArgumentException(
                "An UnavailableUpstream denial for condition [{$condition->value}] must name the missing upstream."
            );
        }

        if ($reason === GuardDenialReason::DomainDenied && $missingUpstream !== null) {
            throw new InvalidArgumentException(
                "A DomainDenied denial for condition [{$condition->value}] must not name a missing upstream — "
                .'the condition was genuinely evaluated.'
            );
        }

        if (trim($publicMessage) === '') {
            throw new InvalidArgumentException(
                "A denial for condition [{$condition->value}] must carry a public message — design.md: "
                .'"a denial returns an explanatory result, never a silent no-op".'
            );
        }
    }
}
