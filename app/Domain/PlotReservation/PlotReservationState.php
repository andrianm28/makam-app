<?php

declare(strict_types=1);

namespace App\Domain\PlotReservation;

use InvalidArgumentException;

/**
 * The closed list of `plot_reservations.state` values — Task 3 of
 * `docs/superpowers/plans/2026-08-16-p3-plot-inventory-reservation.md`.
 *
 * The state machine is append-only: every transition INSERTS a new row
 * (the model's `update()`/`delete()` throw). `held` and `confirmed` are
 * the active states — "one active hold per plot" is enforced by the
 * plot-row lock + `plot_state` aggregate (`Actions\ReservePlot`'s class
 * doc block records why the partial unique index was rejected and
 * removed).
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
     * The draft hold's own chain ends here when
     * `Actions\ConvertDraftHoldToOrderReservation` succeeds — the plot's
     * claim moves to a NEW order-anchored row (still `held`), and this row
     * is what closes the draft-scoped chain. NOT an active state: the
     * draft hold no longer holds the plot in its own right once converted
     * — the new order-anchored row does.
     */
    public const string CONVERTED = 'converted';

    /**
     * @var list<string>
     */
    public const array KNOWN_STATES = [
        self::HELD,
        self::CONFIRMED,
        self::RELEASED,
        self::EXPIRED,
        self::CONVERTED,
    ];

    /**
     * The states in which a reservation still holds its plot. The class doc
     * block above has always said "`held` and `confirmed` are the active
     * states" in prose; this constant is that sentence as code, so the
     * pair lives in exactly one place instead of being re-typed at each
     * call site.
     *
     * @var list<string>
     */
    public const array ACTIVE_STATES = [
        self::HELD,
        self::CONFIRMED,
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
