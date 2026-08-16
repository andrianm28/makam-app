<?php

declare(strict_types=1);

namespace App\Domain\PlotReservation\Exceptions;

use RuntimeException;

/**
 * The domain translation of "this plot is not in a reservable state" —
 * thrown by `App\Domain\PlotReservation\Actions\ReservePlot` when the
 * plot re-read under `lockForUpdate()` is not `available`.
 *
 * The plot id is a reference, never content (`AGENTS.md` §Observability).
 */
final class PlotNotAvailableException extends RuntimeException
{
    public static function forPlot(int|string $plotId): self
    {
        return new self(
            "Plot [{$plotId}] is not available for reservation; only plots in the available state can be reserved."
        );
    }
}
