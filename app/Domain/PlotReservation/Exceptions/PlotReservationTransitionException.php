<?php

declare(strict_types=1);

namespace App\Domain\PlotReservation\Exceptions;

use RuntimeException;

/**
 * A transition was attempted on a `plot_reservations` row whose state
 * is not an allowed from-state for that transition — Task 4 of
 * `docs/superpowers/plans/2026-08-16-p3-plot-inventory-reservation.md`.
 *
 * The terminal-refusal test drives this exactly: an `expired` row is
 * the latest row for its plot, and a further `ConfirmPlotReservation`
 * must refuse because `held` is the only allowed from-state for
 * confirm. `forTransition($from, $to)` names the refused transition so
 * the operator-facing handler (Lane 3) can show which hop was illegal.
 */
final class PlotReservationTransitionException extends RuntimeException
{
    public static function forTransition(string $from, string $to): self
    {
        return new self(
            "Cannot transition plot reservation from [{$from}] to [{$to}]: the transition is not permitted."
        );
    }
}
