<?php

declare(strict_types=1);

namespace App\Domain\PreNeed;

use App\Domain\PreNeed\Exceptions\IllegalPreNeedCaseTransitionException;

/**
 * The Pre-Need CASE chain the plan's Task 3 names:
 * `interest -> proposal -> reserved -> quoted -> agreed -> scheduled ->
 * settled -> activated`, with the reservation deliberately OPTIONAL
 * (the plan: "optional P3 reservation") — a proposal may go straight to a
 * quote.
 *
 * A fourth vocabulary, separate from `PreNeedInterestStatus`,
 * `App\Domain\OrderWorkflow\OrderStatus` and
 * `App\Domain\FuneralCase\FuneralCaseStatus`, for the same reason
 * `PreNeedInterestStatus`'s doc block gives: the commercial order, the
 * at-need case, and the pre-need contract each move on their own axis.
 * The case status says nothing about the ORDER's status — a case can be
 * `settled` while the order is `DIBAYAR`, and the two are read
 * independently (SettlePreNeed asserts the ORDER's status, never the
 * other way around).
 *
 * The transitions are enforced by the seven paid-flow actions via
 * `assertAllows()`, under the case-row lock (`lockForUpdate()`), and the
 * case never moves backward and is never deleted.
 */
enum PreNeedCaseStatus: string
{
    case INTEREST = 'interest';

    case PROPOSAL = 'proposal';

    case RESERVED = 'reserved';

    case QUOTED = 'quoted';

    case AGREED = 'agreed';

    case SCHEDULED = 'scheduled';

    case SETTLED = 'settled';

    case ACTIVATED = 'activated';

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::INTEREST => [self::PROPOSAL],
            // The reservation is optional — a proposal may be quoted
            // directly (plan Task 3: "optional P3 reservation").
            self::PROPOSAL => [self::RESERVED, self::QUOTED],
            self::RESERVED => [self::QUOTED],
            self::QUOTED => [self::AGREED],
            self::AGREED => [self::SCHEDULED],
            self::SCHEDULED => [self::SETTLED],
            self::SETTLED => [self::ACTIVATED],
            self::ACTIVATED => [],
        };
    }

    public function allows(self $to): bool
    {
        return in_array($to, $this->allowedNext(), true);
    }

    /**
     * @throws IllegalPreNeedCaseTransitionException
     */
    public function assertAllows(self $to): void
    {
        if (! $this->allows($to)) {
            throw IllegalPreNeedCaseTransitionException::between($this, $to);
        }
    }
}
