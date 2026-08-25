<?php

declare(strict_types=1);

namespace App\Domain\CemeteryCapability;

use InvalidArgumentException;

/**
 * The closed list of `cemetery_capability_profiles.map_mode` values — one
 * of the six modes `docs/architecture/overview.md` §6 names, and one of the
 * four `docs/contracts/openapi.yaml` `PublicCapabilityProfile` exposes
 * publicly.
 *
 * Plain string column with application-layer validation, not a Postgres
 * enum type — same established convention as every other closed-list
 * string column in this codebase.
 */
final class MapMode
{
    /**
     * A single location point/address only — no internal block or plot
     * layout. The safe default; requirements.md AC11's textual-address
     * fallback always works regardless of this mode.
     */
    public const string LOCATION_ONLY = 'LOCATION_ONLY';

    /**
     * A block-level layout is available (read-only projection of
     * `PlotInventory`'s `blocks`, not owned by this spec).
     */
    public const string BLOCK_MAP = 'BLOCK_MAP';

    /**
     * Individual plot-level map is available. Requires the same
     * authoritative-source precondition the other "most granular" modes
     * do; never set by this batch's seed data.
     */
    public const string PLOT_MAP = 'PLOT_MAP';

    public const string DEFAULT = self::LOCATION_ONLY;

    /**
     * @var list<string>
     */
    public const array KNOWN_MODES = [
        self::LOCATION_ONLY,
        self::BLOCK_MAP,
        self::PLOT_MAP,
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
                "Unknown map mode [{$mode}]. Known modes: ".implode(', ', self::KNOWN_MODES).'.'
            );
        }
    }
}
