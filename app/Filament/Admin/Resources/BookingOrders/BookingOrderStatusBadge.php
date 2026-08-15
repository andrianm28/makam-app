<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BookingOrders;

use App\Domain\OrderWorkflow\OrderStatus;
use App\Support\Design\StatusIntent;

/**
 * Status badge presentation for the booking-order table column, status
 * filter options, and the infolist's status entries. The single place this
 * Resource maps an `OrderStatus` onto a Filament color and an Indonesian
 * label — never matched on the enum string in a component
 * (`design-system.md` §3.7's "resolve status -> intent in ONE place" rule).
 *
 * The intent comes from `StatusIntent`'s order-lifecycle family; the color
 * is the design-system §8.3 bridge intent → Filament palette key. The label
 * table is this Resource's own copy of the canonical Indonesian order
 * labels (the same table `docs/domain/order-lifecycle.md` drives), kept
 * here because `StatusIntent::label()` deliberately humanises enum strings
 * structurally and this Resource's presentation needs the exact prose
 * labels.
 */
final class BookingOrderStatusBadge
{
    /** @var array<string, string> intent → Filament color */
    private const array INTENT_COLORS = [
        'negative' => 'danger',
        'pending' => 'warning',
        'in_progress' => 'info',
        'confirmed' => 'primary',
        'completed' => 'success',
    ];

    public static function color(OrderStatus $status): string
    {
        $intent = StatusIntent::intent($status->value, StatusIntent::FAMILY_ORDER_LIFECYCLE);

        return self::INTENT_COLORS[$intent] ?? 'gray';
    }

    public static function label(OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::MASUK => 'Masuk',
            OrderStatus::DIVERIFIKASI => 'Diverifikasi',
            OrderStatus::MENUNGGU_KETERSEDIAAN => 'Menunggu Ketersediaan',
            OrderStatus::PENAWARAN_TERKIRIM => 'Penawaran Terkirim',
            OrderStatus::DISETUJUI_PEMESAN => 'Disetujui Pemesan',
            OrderStatus::MENUNGGU_PEMBAYARAN => 'Menunggu Pembayaran',
            OrderStatus::MENUNGGU_VERIFIKASI_PEMBAYARAN => 'Menunggu Verifikasi Pembayaran',
            OrderStatus::DIBAYAR => 'Dibayar',
            OrderStatus::DIPROSES => 'Diproses',
            OrderStatus::SELESAI => 'Selesai',
            OrderStatus::DITOLAK => 'Ditolak',
            OrderStatus::DIBATALKAN => 'Dibatalkan',
            OrderStatus::KEDALUWARSA => 'Kedaluwarsa',
        };
    }
}
