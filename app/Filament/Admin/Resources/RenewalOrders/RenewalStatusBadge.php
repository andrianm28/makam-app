<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RenewalOrders;

use App\Domain\Renewal\Models\Renewal;
use App\Domain\Renewal\RenewalStatus;
use App\Support\Design\StatusIntent;

/**
 * Status-badge colours/labels for a `renewals` row, keyed off the
 * `RenewalStatus` string constants.
 *
 * `RenewalStatus`'s own doc block points here: the three statuses are the
 * same strings `StatusIntent::FAMILY_ORDER_LIFECYCLE` already maps
 * (`MENUNGGU_PEMBAYARAN` → pending, `DIBAYAR` → success, `KEDALUWARSA` →
 * neutral), so this class resolves through that existing map instead of
 * inventing a second renewal-specific intent table — the same shape as
 * `BookingOrderStatusBadge` on the sibling order resource.
 */
final class RenewalStatusBadge
{
    /** @var array<string, string> intent → Filament color */
    private const array INTENT_COLORS = [
        'negative' => 'danger',
        'pending' => 'warning',
        'in_progress' => 'info',
        'confirmed' => 'primary',
        'completed' => 'success',
    ];

    public static function color(string $status): string
    {
        $intent = StatusIntent::intent($status, StatusIntent::FAMILY_ORDER_LIFECYCLE);

        return self::INTENT_COLORS[$intent] ?? 'gray';
    }

    public static function label(string $status): string
    {
        return match ($status) {
            RenewalStatus::MENUNGGU_PEMBAYARAN => 'Menunggu Pembayaran',
            RenewalStatus::DIBAYAR => 'Dibayar',
            RenewalStatus::KEDALUWARSA => 'Kedaluwarsa',
            default => $status,
        };
    }
}
