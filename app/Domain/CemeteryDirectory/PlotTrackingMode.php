<?php

declare(strict_types=1);

namespace App\Domain\CemeteryDirectory;

use InvalidArgumentException;

/**
 * The closed list of `cemeteries.plot_tracking_mode` values —
 * `docs/superpowers/plans/2026-08-26-cemetery-plot-tracking-mode.md` §"Cemetery
 * tracking-tier concept": a cemetery is permanently AGGREGATE (only
 * class-level `cemetery_packages.availability_status` capacity is
 * tracked) or GRANULAR (real per-plot inventory exists — `CemeteryBlock` +
 * `GravePlot` rows). Neither is a transitional state; this is a business
 * classification an admin explicitly sets via
 * `App\Domain\CemeteryDirectory\Actions\SetCemeteryPlotTrackingMode`, not
 * a fact derived from whether blocks happen to exist yet.
 *
 * Plain string column with application-layer validation, not a Postgres
 * enum type — this codebase's established convention for closed-list
 * string columns (`App\Domain\PlotInventory\PlotState`,
 * `App\Domain\CemeteryDirectory\CemeteryType`).
 */
final class PlotTrackingMode
{
    /**
     * Only class-level capacity (`cemetery_packages.availability_status`)
     * is tracked for this cemetery — no individual `GravePlot` rows exist
     * or are expected to. The default for every existing cemetery, which
     * has no blocks today.
     */
    public const string AGGREGATE = 'aggregate';

    /**
     * Real per-plot inventory exists for this cemetery — `CemeteryBlock` +
     * `GravePlot` rows are the authoritative availability record.
     * `App\Domain\PlotInventory\Actions\CreateCemeteryBlock` refuses to
     * create a block unless the cemetery is already in this mode.
     */
    public const string GRANULAR = 'granular';

    /**
     * @var list<string>
     */
    public const array KNOWN_MODES = [
        self::AGGREGATE,
        self::GRANULAR,
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
                "Unknown plot tracking mode [{$mode}]. Known modes: ".implode(', ', self::KNOWN_MODES).'.'
            );
        }
    }
}
