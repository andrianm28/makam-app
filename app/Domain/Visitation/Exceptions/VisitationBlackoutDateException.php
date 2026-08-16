<?php

declare(strict_types=1);

namespace App\Domain\Visitation\Exceptions;

use RuntimeException;

/**
 * The domain translation of "this date is on the policy's blackout list" —
 * thrown by `App\Domain\Visitation\Actions\RequestVisitation` for a date
 * the policy's `visitation_blackout_dates` marks closed.
 *
 * Carries the blackout's visitor-visible `reason` (kiro `visitation-
 * booking` AC2; design spec §6.2: a refusal surfaces a specific reason,
 * never a bare "tidak tersedia") — the reason is surfaced verbatim to
 * the requesting family, so it must be written operator-side as visitor-
 * appropriate copy.
 */
final class VisitationBlackoutDateException extends RuntimeException
{
    public static function forDate(string $date, string $reason): self
    {
        return new self(
            "Visitation on {$date} is not available: {$reason}"
        );
    }
}
