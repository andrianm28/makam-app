<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BookingOrders;

use App\Domain\OrderWorkflow\ProductType;

/**
 * Indonesian display label for `orders.product_type` — the "Jenis Layanan"
 * column/entry on `BookingOrderResource`'s table and infolist, never
 * matched on the raw string in a component (UI audit fix, 26 Aug 2026 — the
 * raw enum value, e.g. `AT_NEED_SERVICE_ORDER`, was leaking straight into
 * the admin UI).
 *
 * A thin delegate to `ProductType::label()` (2 Sep 2026 UAT finding) — the
 * copy moved one layer down so `/akun/pesanan` could reuse it too, without
 * this Admin-namespaced class becoming a dependency of a public Livewire
 * view. Kept as a delegate rather than deleted so this Resource's existing
 * call sites need no change.
 */
final class BookingOrderProductTypeLabel
{
    public static function label(ProductType $type): string
    {
        return $type->label();
    }
}
