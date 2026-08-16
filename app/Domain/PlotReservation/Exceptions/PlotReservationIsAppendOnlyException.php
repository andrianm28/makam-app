<?php

declare(strict_types=1);

namespace App\Domain\PlotReservation\Exceptions;

use RuntimeException;

/**
 * A `plot_reservations` row is evidence that a hold/confirm/release/
 * expire happened, who caused it, and why. Revising or deleting one
 * rewrites that evidence — and deleting a `held` row additionally
 * releases `plot_reservations_active_hold`, the partial unique index
 * that is this lane's load-bearing "one active hold per plot" guarantee.
 *
 * Same append-only accounting as
 * `App\Domain\OrderWorkflow\Exceptions\OrderStatusEventIsAppendOnlyException`,
 * for the same underlying reason: this project has one PostgreSQL role
 * per environment, which both owns the schema and runs the application,
 * so there is no lower-privileged role to REVOKE UPDATE/DELETE from.
 *
 * `create()` is deliberately NOT guarded — see the model's class doc
 * block: the append-only guarantee's database backstop is proven by
 * inserting duplicate `held` rows directly in tests.
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
