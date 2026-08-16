<?php

declare(strict_types=1);

namespace App\Domain\PlotReservation;

use InvalidArgumentException;

/**
 * The closed list of `plot_reservations.state` values — Task 3 of
 * `docs/superpowers/plans/2026-08-16-p3-plot-inventory-reservation.md`.
 *
 * The state machine is append-only: every transition INSERTS a new row
 * (the model's `update()`/`delete()` throw), and `held` is the only
 * state the partial unique index `plot_reservations_active_hold`
 * guards — one active hold per plot.
 *
 * Plain string constants class, not a backed enum — the same choice
 * `App\Domain\CemeteryDirectory\CemeteryType` makes for its closed list,
 * and the one the plan's tests require: `PlotReservationState::HELD` is
 * compared with `assertSame` against the model's RAW `state` column
 * (`$reservation->state`, no Eloquent cast), so the constant must BE the
 * string 'held', not an enum object.
 */
final class PlotReservationState
{
    public const string HELD = 'held';

    public const string CONFIRMED = 'confirmed';

    public const string RELEASED = 'released';

    public const string EXPIRED = 'expired';

    /**
     * @var list<string>
     */
    public const array KNOWN_STATES = [
        self::HELD,
        self::CONFIRMED,
        self::RELEASED,
        self::EXPIRED,
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
                "Unknown plot reservation state [{$state}]. Known states: ".implode(', ', self::KNOWN_STATES).'.'
            );
        }
    }
}
