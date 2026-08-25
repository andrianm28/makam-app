<?php

declare(strict_types=1);

namespace App\Domain\Visitation\Exceptions;

use RuntimeException;

/**
 * The domain translation of "this date cannot take this many visitors" —
 * thrown by `App\Domain\Visitation\Actions\RequestVisitation` when
 * `booked_count + visitor_count` (read against the ledger row locked with
 * `lockForUpdate()`, or re-read after a lost `firstOrCreate` race) exceeds
 * the policy's `daily_capacity`.
 *
 * The date and the capacity are references, never visitor content
 * (`AGENTS.md` §Observability).
 */
final class VisitationCapacityExceededException extends RuntimeException
{
    public static function forDate(string $date, int $capacity): self
    {
        return new self(
            "Visitation capacity for {$date} is exhausted; daily capacity is {$capacity} visitors."
        );
    }
}
