<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger;

use InvalidArgumentException;

/**
 * How a payout was made. Exactly one value, and the emptiness of this list is
 * the requirement, not an omission.
 *
 * AC9: "WHILE `G-PAYOUT-01` is closed THE SYSTEM SHALL require manual payout
 * — recording amount, proof, approver, and reference — and THE SYSTEM SHALL
 * NOT perform an automated transfer." The plan's Global Constraints sharpen
 * that into a structural rule: "no automated transfer code path exists at all
 * (structural, not just gated)."
 *
 * So there is no `provider_transfer`, no `bank_api`, no `disbursement` member
 * here — not commented out, not behind a flag, not "reserved for later." A
 * member on this list would need an implementation behind it, and a config
 * switch that turns an automated transfer on is precisely the thing AC9
 * forbids existing. Whoever opens `G-PAYOUT-01` adds the member, the code path
 * and the approval trail together, as one reviewed change.
 */
final class PayoutMethod
{
    /**
     * A human moved the money through a bank, outside this system, and
     * recorded the transfer's proof reference afterwards.
     */
    public const string MANUAL_BANK_TRANSFER = 'manual_bank_transfer';

    /**
     * @var list<string>
     */
    public const array KNOWN_METHODS = [
        self::MANUAL_BANK_TRANSFER,
    ];

    public static function isKnown(string $method): bool
    {
        return in_array($method, self::KNOWN_METHODS, true);
    }

    /**
     * @throws InvalidArgumentException when `$method` is not one of
     *                                  `self::KNOWN_METHODS`.
     */
    public static function assertKnown(string $method): void
    {
        if (! self::isKnown($method)) {
            throw new InvalidArgumentException(
                "Unknown payout method [{$method}]. Known methods: ".implode(', ', self::KNOWN_METHODS).'.'
            );
        }
    }
}
