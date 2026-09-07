<?php

declare(strict_types=1);

namespace App\Domain\PlotReservation\Exceptions;

use RuntimeException;

/**
 * Batch M3b (DOM-08): `ReleasePlotReservation`/`ExpirePlotReservation`
 * refuse to release/expire a reservation whose owning order is already
 * `DIBAYAR` or later (`OrderStatus::isPaidOrLater()`) unless the caller
 * explicitly passes `overridePaidOrder: true` — an operator (or a future
 * scheduled sweep) must not be able to silently return a paid-for plot to
 * available inventory. The override path is
 * `PlotReservationLifecycleActions::releasePaidOrderOverride()`, gated on a
 * mandatory reason and `ReauthenticationGuard::assertFresh()`.
 */
final class PlotReservationOrderAlreadyPaidException extends RuntimeException
{
    public static function forReservation(string $reservationId, string $orderId): self
    {
        return new self(
            "Cannot release or expire plot reservation [{$reservationId}]: its order [{$orderId}] ".
            'is already paid. Use the paid-order override action with a recorded reason instead.'
        );
    }
}
