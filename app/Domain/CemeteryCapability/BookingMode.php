<?php

declare(strict_types=1);

namespace App\Domain\CemeteryCapability;

use InvalidArgumentException;

/**
 * The closed list of `cemetery_capability_profiles.booking_mode` values —
 * one of the six modes `docs/architecture/overview.md` §6 names, and one of
 * the four `docs/contracts/openapi.yaml` `PublicCapabilityProfile` exposes
 * publicly.
 *
 * `AGENTS.md` §Domain and financial invariants: "Package/class confirmation
 * is default; specific plot requires authoritative inventory and atomic
 * reservation." `DEFAULT` below (`REQUEST_CONFIRMATION`) is the same safe
 * default `overview.md` §6 states literally.
 *
 * Plain string column with application-layer validation, not a Postgres
 * enum type — same established convention as every other closed-list
 * string column in this codebase.
 */
final class BookingMode
{
    /**
     * A booking request is submitted and an operator confirms it
     * out-of-band before anything is guaranteed. The safe default.
     */
    public const string REQUEST_CONFIRMATION = 'REQUEST_CONFIRMATION';

    /**
     * A specific plot can be held with locking/expiry
     * (`PlotReservation` module, not owned by this spec). Requires the
     * same authoritative-registry precondition `AvailabilityMode::
     * SPECIFIC_PLOT` does.
     */
    public const string RESERVE_PLOT = 'RESERVE_PLOT';

    /**
     * Immediate purchase without a hold/reservation step. The strongest
     * commitment mode; never set by this batch's seed data.
     */
    public const string DIRECT_PURCHASE = 'DIRECT_PURCHASE';

    public const string DEFAULT = self::REQUEST_CONFIRMATION;

    /**
     * @var list<string>
     */
    public const array KNOWN_MODES = [
        self::REQUEST_CONFIRMATION,
        self::RESERVE_PLOT,
        self::DIRECT_PURCHASE,
    ];

    public static function isKnown(string $mode): bool
    {
        return in_array($mode, self::KNOWN_MODES, true);
    }

    /**
     * @throws InvalidArgumentException when `$mode` is not one of
     *                                  `self::KNOWN_MODES`.
     */
    public static function assertKnown(string $mode): void
    {
        if (! self::isKnown($mode)) {
            throw new InvalidArgumentException(
                "Unknown booking mode [{$mode}]. Known modes: ".implode(', ', self::KNOWN_MODES).'.'
            );
        }
    }
}
