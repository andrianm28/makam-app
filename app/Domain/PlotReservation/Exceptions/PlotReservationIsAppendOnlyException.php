<?php

declare(strict_types=1);

namespace App\Domain\PlotReservation\Exceptions;

use RuntimeException;

/**
 * A `plot_reservations` row is evidence that a hold/confirm/release/
 * expire happened, who caused it, and why. Revising or deleting one
 * rewrites that evidence — the append-only model's "one active hold per
 * plot" guarantee is enforced by the plot-row lock + `plot_state`
 * aggregate (see `Actions\ReservePlot`'s class doc block), and the
 * deleted `plot_reservations_active_hold` index is NOT a reason to
 * allow writes: the evidence rationale stands alone.
 *
 * Same append-only accounting as
 * `App\Domain\OrderWorkflow\Exceptions\OrderStatusEventIsAppendOnlyException`,
 * for the same underlying reason: this project has one PostgreSQL role
 * per environment, which both owns the schema and runs the application,
 * so there is no lower-privileged role to REVOKE UPDATE/DELETE from.
 *
 * `create()` is deliberately NOT guarded — see the model's class doc
 * block.
 */
final class PlotReservationIsAppendOnlyException extends RuntimeException
{
    public static function forOperation(string $operation): self
    {
        return new self(
            "plot_reservations rows are append-only; [{$operation}] is not permitted on a PlotReservation."
        );
    }
}
