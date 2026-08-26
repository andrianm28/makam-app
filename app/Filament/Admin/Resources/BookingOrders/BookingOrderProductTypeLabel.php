<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BookingOrders;

use App\Domain\OrderWorkflow\ProductType;

/**
 * Indonesian display label for `orders.product_type` — the "Jenis Layanan"
 * column/entry on `BookingOrderResource`'s table and infolist. Same shape as
 * `BookingOrderStatusBadge` for `OrderStatus`: the single place this
 * Resource maps the enum onto presentation copy, never matched on the raw
 * string in a component (UI audit fix, 26 Aug 2026 — the raw enum value,
 * e.g. `AT_NEED_SERVICE_ORDER`, was leaking straight into the admin UI).
 *
 * `BookingOrderResource::getEloquentQuery()` does not scope `product_type`,
 * so this table can show any of `ProductType`'s six cases, not only the two
 * reachable from booking submission today — the match below is therefore
 * exhaustive over all six, not just the booking-submission subset.
 *
 * "At-Need" and "Pre-Need" are kept as the established English product
 * terms `App\Domain\Booking\BookingServiceType::LABELS`'s own doc block
 * already explains are the stakeholder's product copy, not invented
 * translations — `docs/product/product-brief.md`:114 mixes the same terms
 * into Indonesian prose ("Pre-Need tetap dapat dipilih..."). "Langganan
 * Perawatan" and "Perpanjangan" are reused verbatim from
 * `docs/product/screen-inventory.md` (PUB-095) and `Reports::reportTabs()`
 * ("Laporan Perpanjangan") respectively, not invented here.
 */
final class BookingOrderProductTypeLabel
{
    public static function label(ProductType $type): string
    {
        return match ($type) {
            ProductType::AT_NEED_SERVICE_ORDER => 'Pesanan Layanan At-Need',
            ProductType::PRE_NEED_PLOT_PURCHASE => 'Pembelian Plot Pre-Need',
            ProductType::FUNERAL_PROTECTION_MEMBERSHIP => 'Keanggotaan Perlindungan Pemakaman',
            ProductType::CARE_SUBSCRIPTION => 'Langganan Perawatan',
            ProductType::MARKETPLACE_PRODUCT_ORDER => 'Pesanan Produk Marketplace',
            ProductType::RENEWAL_ORDER => 'Pesanan Perpanjangan',
        };
    }
}
