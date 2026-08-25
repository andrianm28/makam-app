<?php

declare(strict_types=1);

namespace App\Domain\Renewal\Exceptions;

use RuntimeException;

/**
 * Thrown by `Actions\MarkRenewalPaidOnline` when the settled amount does not
 * EXACTLY equal the renewal's latest quote `amount_minor`, or when the
 * renewal carries no quote to check against.
 *
 * Mirrors `Domain\CareSubscription\Exceptions\CyclePaymentAmountMismatchException`
 * / `Domain\Marketplace\Exceptions\MarketplacePaymentAmountMismatchException`:
 * the quote is the authoritative money fact for a renewal (the same one
 * `Actions\GuardRenewalPaymentOpening`'s condition 4 checks at session-opening
 * time), so a settlement of the wrong amount must never mark the renewal
 * `DIBAYAR` — including if the quote drifted (a re-quote) between session
 * opening and settlement. The assert runs before the status guard, the same
 * ordering `CyclePaymentAmountMismatchException`'s own doc block documents,
 * so a mismatched replay is refused even against an already-settled renewal.
 */
final class RenewalPaymentAmountMismatchException extends RuntimeException
{
    public static function forRenewal(string $renewalId, int $expectedMinor, int $arrivedMinor): self
    {
        return new self(
            "Cannot mark renewal [{$renewalId}] paid: arrived amount [{$arrivedMinor}] "
            ."minor units does not equal the quoted amount [{$expectedMinor}] minor units."
        );
    }

    public static function becauseNoQuote(string $renewalId): self
    {
        return new self(
            "Cannot mark renewal [{$renewalId}] paid: it carries no quote to verify "
            .'the paid amount against.'
        );
    }
}
