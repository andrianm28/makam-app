<?php

declare(strict_types=1);

namespace App\Domain\CemeteryCapability;

use InvalidArgumentException;

/**
 * The closed list of `cemetery_capability_profiles.visitation_mode` values
 * — one of the six modes `docs/architecture/overview.md` §6 names, and one
 * of the four `docs/contracts/openapi.yaml` `PublicCapabilityProfile`
 * exposes publicly.
 *
 * Plain string column with application-layer validation, not a Postgres
 * enum type — same established convention as every other closed-list
 * string column in this codebase.
 */
final class VisitationMode
{
    /**
     * No visitation information/booking is offered through the platform
     * for this cemetery. The safe default.
     */
    public const string NONE = 'NONE';

    /**
     * Informational only — hours/access instructions, no booking
     * (`Visitation` module, not owned by this spec).
     */
    public const string INFORMATION_ONLY = 'INFORMATION_ONLY';

    /**
     * Visit slots can be booked with capacity tracking. Never set by this
     * batch's seed data.
     */
    public const string BOOKABLE = 'BOOKABLE';

    public const string DEFAULT = self::NONE;

    /**
     * @var list<string>
     */
    public const array KNOWN_MODES = [
        self::NONE,
        self::INFORMATION_ONLY,
        self::BOOKABLE,
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
                "Unknown visitation mode [{$mode}]. Known modes: ".implode(', ', self::KNOWN_MODES).'.'
            );
        }
    }
}
