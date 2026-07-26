<?php

declare(strict_types=1);

namespace App\Domain\CemeteryCapability;

use InvalidArgumentException;

/**
 * The closed list of `cemetery_capability_profiles.availability_mode`
 * values — one of the six modes `docs/architecture/overview.md` §6 names
 * for every cemetery's capability profile, and one of the four modes
 * `docs/contracts/openapi.yaml`'s `PublicCapabilityProfile` schema exposes
 * publicly (the other three: `booking_mode`, `map_mode`, `visitation_mode`
 * — see the sibling classes in this namespace).
 *
 * requirements.md AC5: "THE SYSTEM SHALL present default availability as
 * indicative/package-class and visibly labeled `Perlu konfirmasi`" and AC7:
 * "THE SYSTEM SHALL NOT enable `SPECIFIC_PLOT` unless an authoritative
 * registry, freshness evidence, and a reservation contract are present."
 * `DEFAULT` below is `INDICATIVE`, not `PACKAGE_CLASS` — AC5's
 * "indicative/package-class" phrase names the shared PRESENTATION label
 * both states carry (`.kiro/specs/cemetery-directory-and-availability/
 * design.md`'s UI mapping: both render `neutral` + `Perlu konfirmasi`), not
 * an alternate default value; `overview.md` §6 states the literal default
 * as `INDICATIVE` explicitly.
 *
 * Plain string column with application-layer validation, not a Postgres
 * enum type — same established convention as every other closed-list
 * string column in this codebase (`App\Domain\CemeteryDirectory\
 * CemeteryType` et al.).
 */
final class AvailabilityMode
{
    /**
     * Cemetery-wide indicative signal only — no package/class or plot
     * granularity. The safe default for every cemetery until a real
     * capability profile activates something more specific.
     */
    public const string INDICATIVE = 'INDICATIVE';

    /**
     * Availability expressed per package/class (requirements.md AC6's
     * "Makam Tumpang ... at the ... package/class level") — still
     * indicative, not a guarantee.
     */
    public const string PACKAGE_CLASS = 'PACKAGE_CLASS';

    /**
     * Availability expressed per specific plot. requirements.md AC7 gates
     * this hard: never enabled without an authoritative registry,
     * freshness evidence, and a reservation contract — this batch (master
     * data/seeds only) never sets this value on any seeded row.
     */
    public const string SPECIFIC_PLOT = 'SPECIFIC_PLOT';

    public const string DEFAULT = self::INDICATIVE;

    /**
     * @var list<string>
     */
    public const array KNOWN_MODES = [
        self::INDICATIVE,
        self::PACKAGE_CLASS,
        self::SPECIFIC_PLOT,
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
                "Unknown availability mode [{$mode}]. Known modes: ".implode(', ', self::KNOWN_MODES).'.'
            );
        }
    }
}
