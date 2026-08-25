<?php

declare(strict_types=1);

namespace App\Domain\CareSubscription\Exceptions;

use RuntimeException;

/**
 * Thrown by `Actions\MarkCyclePaid` when the paid amount stated by the
 * caller does not EXACTLY equal the cycle's invoice `amount_minor`, or when
 * the cycle carries no invoice to check against.
 *
 * Mirrors `Domain\Marketplace\Exceptions\MarketplacePaymentAmountMismatchException`:
 * the invoice is the authoritative money fact for a cycle, so a payment
 * arrival of the wrong amount must never mark the cycle PAID. The assert
 * runs before any state, outbox or audit write, and applies even to an
 * already-PAID cycle — a mismatched replay is refused, never silently
 * accepted because the cycle already settled.
 */
final class CyclePaymentAmountMismatchException extends RuntimeException
{
    public static function forCycle(string $cycleId, int $expectedMinor, int $arrivedMinor): self
    {
        return new self(
            "Cannot mark subscription cycle [{$cycleId}] paid: arrived amount [{$arrivedMinor}] "
            ."minor units does not equal the invoice amount [{$expectedMinor}] minor units."
        );
    }

    public static function becauseNoInvoice(string $cycleId): self
    {
        return new self(
            "Cannot mark subscription cycle [{$cycleId}] paid: it carries no invoice to verify "
            .'the paid amount against.'
        );
    }
}
