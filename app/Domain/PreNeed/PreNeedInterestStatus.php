<?php

declare(strict_types=1);

namespace App\Domain\PreNeed;

use App\Domain\PreNeed\Exceptions\IllegalPreNeedInterestTransitionException;

/**
 * The Pre-Need interest chain named in the plan's Task 3 routing rule:
 * `INTEREST_REGISTERED -> CONTACTED -> CLOSED`.
 *
 * A third vocabulary, separate from both `App\Domain\OrderWorkflow\
 * OrderStatus` and `App\Domain\FuneralCase\FuneralCaseStatus`, for the
 * reason `FuneralCaseStatus`'s doc block gives at length. It is
 * deliberately short: while `G-LEGAL-01` is closed there is no Pre-Need
 * CASE to run — only an interest record and a follow-up call
 * (design-system §6.9, "registers interest; no payment created").
 */
enum PreNeedInterestStatus: string
{
    case INTEREST_REGISTERED = 'INTEREST_REGISTERED';
    case CONTACTED = 'CONTACTED';
    case CLOSED = 'CLOSED';

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::INTEREST_REGISTERED => [self::CONTACTED, self::CLOSED],
            self::CONTACTED => [self::CLOSED],
            self::CLOSED => [],
        };
    }

    public function allows(self $to): bool
    {
        return in_array($to, $this->allowedNext(), true);
    }

    public function assertAllows(self $to): void
    {
        if (! $this->allows($to)) {
            throw IllegalPreNeedInterestTransitionException::between($this, $to);
        }
    }
}
