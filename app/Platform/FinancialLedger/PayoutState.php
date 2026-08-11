<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger;

use InvalidArgumentException;

/**
 * `payouts.state`. One value, for a structural reason worth stating rather
 * than leaving as a curiosity.
 *
 * A payout row is only ever written after a human has already moved the money
 * and has the transfer's proof reference in hand
 * (`Actions\ManualPayout::pay()` refuses without one). There is therefore
 * nothing to wait on and nothing to poll: no `pending`, no `processing`, no
 * `submitted`, no `failed`, no `settled`.
 *
 * Those states only exist in a system that hands a transfer to a provider and
 * watches it — which is exactly the automated path AC9 forbids while
 * `G-PAYOUT-01` is closed. Shipping the state vocabulary for an automated
 * transfer would be shipping the shape of the thing that must not exist, and
 * would invite a later reader to "just" fill in the transitions.
 *
 * The row's own existence is the claim, and the proof reference plus the
 * approver are what back it — see `Models\VendorPayable::isPaidOut()`, which
 * requires both the payable's `paid` state and this row, because a payout is
 * never implied complete merely by having been created.
 */
final class PayoutState
{
    /**
     * A manual transfer happened outside this system and its proof reference
     * and approver are recorded here.
     */
    public const string RECORDED = 'recorded';

    /**
     * @var list<string>
     */
    public const array KNOWN_STATES = [
        self::RECORDED,
    ];

    public static function isKnown(string $state): bool
    {
        return in_array($state, self::KNOWN_STATES, true);
    }

    /**
     * @throws InvalidArgumentException when `$state` is not one of
     *                                  `self::KNOWN_STATES`.
     */
    public static function assertKnown(string $state): void
    {
        if (! self::isKnown($state)) {
            throw new InvalidArgumentException(
                "Unknown payout state [{$state}]. Known states: ".implode(', ', self::KNOWN_STATES).'.'
            );
        }
    }
}
