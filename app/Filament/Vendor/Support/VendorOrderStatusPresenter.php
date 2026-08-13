<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Support;

use App\Domain\Marketplace\VendorProcessingStatus;

/**
 * Indonesian labels and badge colours for `VendorProcessingStatus`, in one
 * place so `/vendor/orders` and `/vendor/transactions` cannot render the same
 * status differently.
 *
 * `options()` is built by walking `VendorProcessingStatus::KNOWN_STATUSES`
 * rather than by listing the eight codes again here. That closed list is the
 * catalogue-traceable source (see its own class doc block on why it exists
 * ahead of the table it backs); a second hand-written copy in this file would
 * be exactly the "restate the catalogue in code" drift `AGENTS.md`
 * §Documentation warns against, and would silently omit any code added later.
 */
final class VendorOrderStatusPresenter
{
    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (VendorProcessingStatus::KNOWN_STATUSES as $status) {
            $options[$status] = self::label($status);
        }

        return $options;
    }

    public static function label(string $status): string
    {
        return match ($status) {
            VendorProcessingStatus::MENUNGGU_VENDOR => 'Menunggu vendor',
            VendorProcessingStatus::DITERIMA_VENDOR => 'Diterima vendor',
            VendorProcessingStatus::DITOLAK_VENDOR => 'Ditolak vendor',
            VendorProcessingStatus::DIPROSES => 'Diproses',
            VendorProcessingStatus::DIKIRIM_OR_DIJADWALKAN => 'Dikirim / dijadwalkan',
            VendorProcessingStatus::SELESAI => 'Selesai',
            VendorProcessingStatus::KOMPLAIN => 'Komplain',
            VendorProcessingStatus::DIBATALKAN => 'Dibatalkan',
            default => $status,
        };
    }

    /**
     * Filament badge colours. `SELESAI` is `success` and nothing else is —
     * `VendorProcessingStatus`' own doc block is explicit that fulfilment
     * completion is a distinct state from payment (AC12, "SHALL NOT treat a
     * paid vendor order as fulfillment complete"), so no other status may
     * render as if the order were done.
     */
    public static function color(string $status): string
    {
        return match ($status) {
            VendorProcessingStatus::MENUNGGU_VENDOR => 'gray',
            VendorProcessingStatus::DITERIMA_VENDOR => 'info',
            VendorProcessingStatus::DITOLAK_VENDOR => 'danger',
            VendorProcessingStatus::DIPROSES => 'warning',
            VendorProcessingStatus::DIKIRIM_OR_DIJADWALKAN => 'primary',
            VendorProcessingStatus::SELESAI => 'success',
            VendorProcessingStatus::KOMPLAIN => 'danger',
            VendorProcessingStatus::DIBATALKAN => 'gray',
            default => 'gray',
        };
    }
}
