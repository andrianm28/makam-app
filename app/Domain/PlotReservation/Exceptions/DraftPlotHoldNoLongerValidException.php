<?php

declare(strict_types=1);

namespace App\Domain\PlotReservation\Exceptions;

use RuntimeException;

/**
 * Thrown by `Actions\ConvertDraftHoldToOrderReservation` when the draft
 * hold it was asked to convert is no longer a valid, live `held` row —
 * expired, already converted, or superseded by another transition since
 * the caller last read it.
 *
 * Per the roadmap's decision #7
 * (`/home/ubuntu/.claude/plans/swirling-cooking-umbrella.md`): on this
 * failure, `SubmitBookingDraft` does NOT fall back to submitting without a
 * reservation. The whole submission transaction rolls back and the wizard
 * routes the customer back to Step 2 to re-pick a plot — see
 * `docs/superpowers/plans/2026-08-29-customer-plot-picker-hold.md` Task 5.
 */
final class DraftPlotHoldNoLongerValidException extends RuntimeException
{
    public static function forHold(string $reservationId, string $reason): self
    {
        return new self("Draft plot hold [{$reservationId}] is no longer valid: {$reason}.");
    }
}
