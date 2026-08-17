<?php

declare(strict_types=1);

namespace App\Domain\CareSubscription\Exceptions;

use DomainException;

/**
 * Thrown when GenerateCycle detects an existing cycle with the same
 * subscription_id + cycle_start + cycle_end — the idempotency backstop.
 */
final class DuplicateCycleException extends DomainException
{
    public static function forCycle(string $subscriptionId, string $cycleStart, string $cycleEnd): self
    {
        return new self(
            "A subscription cycle already exists for subscription [{$subscriptionId}] with dates [{$cycleStart}] to [{$cycleEnd}]."
        );
    }
}
