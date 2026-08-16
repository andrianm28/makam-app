<?php

declare(strict_types=1);

namespace App\Domain\Visitation\Exceptions;

use RuntimeException;

/**
 * The domain translation of "this weekday is outside the policy's
 * operating hours" — thrown by `App\Domain\Visitation\Actions\
 * RequestVisitation` when the requested date's weekday key in the
 * policy's `operating_hours` template is `null` (closed that weekday).
 *
 * A closed weekday and a blackout are deliberately distinct failures
 * (`VisitationClosedDayException` vs `VisitationBlackoutDateException`):
 * one says "this cemetery is never open this weekday", the other "this
 * specific date is closed for a surfaced reason". The public page and
 * the operator tooling copy differ accordingly.
 */
final class VisitationClosedDayException extends RuntimeException
{
    public static function forDate(string $date): self
    {
        return new self(
            "Cemetery is closed for visitation on {$date}; no booking can be created."
        );
    }
}
