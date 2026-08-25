<?php

declare(strict_types=1);

namespace App\Domain\CemeteryCapability;

use InvalidArgumentException;

/**
 * The closed list of `cemetery_packages.availability_status` values —
 * backs requirements.md AC6: "THE SYSTEM SHALL present Makam Tumpang
 * availability explicitly at the location/package/class level."
 *
 * Deliberately has NO "confirmed"/guaranteed case. Every value here is
 * indicative by construction — the owning cemetery's own
 * `cemetery_capability_profiles.availability_mode` (see `AvailabilityMode`)
 * is the single source of truth for whether ANY availability claim is a
 * guarantee, and it never is under the safe default (`INDICATIVE`).
 * Negative criterion: "No guaranteed availability from indicative data."
 * Repeating an "INDICATIVE_" prefix on every case here would conflate two
 * separate concerns (this table only needs to say which of three states a
 * given package/class is in; the honesty qualifier belongs once, on the
 * profile).
 *
 * Plain string column with application-layer validation, not a Postgres
 * enum type — same established convention as every other closed-list
 * string column in this codebase.
 */
final class CemeteryPackageAvailabilityStatus
{
    /**
     * The safe default for a newly-recorded package/class row.
     */
    public const string AVAILABLE = 'AVAILABLE';

    public const string LIMITED = 'LIMITED';

    public const string UNAVAILABLE = 'UNAVAILABLE';

    public const string DEFAULT = self::AVAILABLE;

    /**
     * @var list<string>
     */
    public const array KNOWN_STATUSES = [
        self::AVAILABLE,
        self::LIMITED,
        self::UNAVAILABLE,
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
                "Unknown cemetery package availability status [{$status}]. Known statuses: ".implode(', ', self::KNOWN_STATUSES).'.'
            );
        }
    }
}
