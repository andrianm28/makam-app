<?php

declare(strict_types=1);

namespace App\Domain\CemeteryCapability;

use InvalidArgumentException;

/**
 * The closed list of `cemetery_capability_profiles.registry_mode` values —
 * one of the six modes `docs/architecture/overview.md` §6 names. Not part
 * of `docs/contracts/openapi.yaml`'s `PublicCapabilityProfile` schema
 * (that schema exposes only `availability_mode`/`booking_mode`/`map_mode`/
 * `visitation_mode`) — this batch checked the contract directly rather than
 * guess, so a later public-projection batch (S4-T6) should NOT surface this
 * value on a public API response without first updating that contract, per
 * requirements.md's negative criterion "No public exposure of restricted
 * plot data."
 *
 * Plain string column with application-layer validation, not a Postgres
 * enum type — same established convention as every other closed-list
 * string column in this codebase.
 */
final class RegistryMode
{
    /**
     * No grave/plot registry data source is connected. The safe default.
     */
    public const string NONE = 'NONE';

    /**
     * A basic, unauthoritative registry exists (e.g. manually maintained,
     * no freshness guarantee).
     */
    public const string BASIC = 'BASIC';

    /**
     * An authoritative, evidenced registry exists — the precondition
     * requirements.md AC7 requires before `AvailabilityMode::
     * SPECIFIC_PLOT`/`BookingMode::RESERVE_PLOT` may be enabled. Never set
     * by this batch's seed data.
     */
    public const string AUTHORITATIVE = 'AUTHORITATIVE';

    public const string DEFAULT = self::NONE;

    /**
     * @var list<string>
     */
    public const array KNOWN_MODES = [
        self::NONE,
        self::BASIC,
        self::AUTHORITATIVE,
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
                "Unknown registry mode [{$mode}]. Known modes: ".implode(', ', self::KNOWN_MODES).'.'
            );
        }
    }
}
