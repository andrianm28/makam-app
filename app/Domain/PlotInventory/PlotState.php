<?php

declare(strict_types=1);

namespace App\Domain\PlotInventory;

use InvalidArgumentException;

/**
 * The closed list of `grave_plots.plot_state` values —
 * `docs/superpowers/specs/2026-08-16-plot-inventory-reservation-design.md`
 * §4.1: "per-plot state (`available`/`reserved`/`occupied`/`maintenance`)".
 * The authoritative per-plot availability that the reservation module
 * (P3 lane 2) flips; the public package/class default stays, this state
 * is the operational truth behind specific-plot selection.
 *
 * `AVAILABLE` is the only state in which a plot may be deleted (see
 * `GravePlot::booted()`); the state changes are written by the
 * reservation actions (lane 2) and by the admin state-override actions
 * (lane 1's admin surfaces, Task 2).
 *
 * Plain string column with application-layer validation, not a Postgres
 * enum type — this codebase's established convention for closed-list
 * string columns (`CemeteryType`, `CemeteryPackageAvailabilityStatus`).
 */
final class PlotState
{
    /**
     * No active reservation, not occupied, not under maintenance — the
     * only state in which a plot may be reserved or deleted.
     */
    public const string AVAILABLE = 'available';

    /**
     * Held or confirmed by an active reservation row (P3 lane 2).
     */
    public const string RESERVED = 'reserved';

    /**
     * A burial has taken place in this plot.
     */
    public const string OCCUPIED = 'occupied';

    /**
     * Operator-declared unavailable (e.g. ground work).
     */
    public const string MAINTENANCE = 'maintenance';

    /**
     * @var list<string>
     */
    public const array KNOWN_STATES = [
        self::AVAILABLE,
        self::RESERVED,
        self::OCCUPIED,
        self::MAINTENANCE,
    ];

    public static function isKnown(string $state): bool
    {
        return in_array($state, self::KNOWN_STATES, true);
    }

    /**
     * @throws InvalidArgumentException when `$state` is not one of
     *                                  `self::KNOWN_STATES`.
     */
    public static function assertKnown(string $state): void
    {
        if (! self::isKnown($state)) {
            throw new InvalidArgumentException(
                "Unknown plot state [{$state}]. Known states: ".implode(', ', self::KNOWN_STATES).'.'
            );
        }
    }
}
