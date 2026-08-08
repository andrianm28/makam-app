<?php

declare(strict_types=1);

namespace App\Domain\Booking\Exceptions;

use RuntimeException;

/**
 * Thrown by `App\Domain\Booking\Actions\SaveBookingDraftStep` when a caller
 * supplies `$expectedVersion` and it no longer matches the draft's current
 * `version` — `booking-wizard-fields.md` §Global behavior: "Every save is
 * idempotent and versioned." An optimistic-concurrency signal for the
 * presentation layer to surface "this draft changed elsewhere, reloading" —
 * see `public-booking-wizard/design.md`'s "Components" section: "Autosave
 * uses optimistic version and idempotency key."
 */
final class BookingDraftVersionConflictException extends RuntimeException
{
    public function __construct(public readonly int $expectedVersion, public readonly int $actualVersion)
    {
        parent::__construct("Booking draft version conflict: expected [{$expectedVersion}], found [{$actualVersion}].");
    }
}
