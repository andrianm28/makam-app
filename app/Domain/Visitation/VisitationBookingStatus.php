<?php

declare(strict_types=1);

namespace App\Domain\Visitation;

use InvalidArgumentException;

/**
 * The closed list of `visitation_bookings.status` values — Task 1 of
 * `docs/superpowers/plans/2026-08-16-p4-memorial-qr-visitation.md`
 * (Lane 1 — Visitation).
 *
 * Plain string constants class, not a backed enum — the same choice
 * `App\Domain\PlotReservation\PlotReservationState` makes, for the same
 * reason: the constant must BE the string stored in the raw `status`
 * column (`assertSame` against a cast-less attribute in the operator
 * resource's filters), not an enum object.
 *
 * `requested` is the default — a booking is created by
 * `RequestVisitation` in `requested` state and confirmed/cancelled by
 * the operator lane's status transitions, which are Task 1's successors,
 * not this task.
 */
final class VisitationBookingStatus
{
    public const string REQUESTED = 'requested';

    public const string CONFIRMED = 'confirmed';

    public const string CANCELLED = 'cancelled';

    public const string NO_SHOW = 'no_show';

    /**
     * @var list<string>
     */
    public const array KNOWN_STATUSES = [
        self::REQUESTED,
        self::CONFIRMED,
        self::CANCELLED,
        self::NO_SHOW,
    ];

    public static function isKnown(string $status): bool
    {
        return in_array($status, self::KNOWN_STATUSES, true);
    }

    /**
     * @throws InvalidArgumentException when `$status` is not one of
     *                                  `self::KNOWN_STATUSES`.
     */
    public static function assertKnown(string $status): void
    {
        if (! self::isKnown($status)) {
            throw new InvalidArgumentException(
                "Unknown visitation booking status [{$status}]. Known statuses: ".implode(', ', self::KNOWN_STATUSES).'.'
            );
        }
    }
}
