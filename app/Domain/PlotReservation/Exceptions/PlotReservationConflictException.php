<?php

declare(strict_types=1);

namespace App\Domain\PlotReservation\Exceptions;

use RuntimeException;

/**
 * The domain translation of a `plot_reservations_active_hold` violation —
 * the partial unique index on `plot_reservations (plot_id) WHERE
 * state = 'held'` that makes "one active hold per plot" a database
 * guarantee rather than an application convention.
 *
 * ---------------------------------------------------------------------------
 * Why this exists rather than letting the `QueryException` propagate —
 * identical accounting to `App\Domain\OrderWorkflow\Exceptions::
 * OrderAlreadyPaidException`, including why `$previous` is NOT chained
 * ---------------------------------------------------------------------------
 * `Illuminate\Database\QueryException::formatMessage()` appends the full
 * INSERT statement with its BINDINGS INTERPOLATED, and on this INSERT the
 * bindings include `reserved_by_ref` (an actor reference) and `reason`
 * (free operator text). An uncaught throw is logged verbatim, so the raw
 * exception puts caller-supplied content into the log —
 * `AGENTS.md` §Observability: "Never place restricted data in logs,
 * Pulse, Horizon tags, or error trackers." Chaining it as `$previous`
 * would reintroduce exactly that content through the framework's
 * exception-handler chain logging, so the originating `QueryException` is
 * deliberately discarded. The plot id below is a reference, never
 * content.
 */
final class PlotReservationConflictException extends RuntimeException
{
    public static function forPlot(int|string $plotId): self
    {
        return new self(
            "Plot [{$plotId}] already has an active hold; a plot can be held at most once."
        );
    }
}
