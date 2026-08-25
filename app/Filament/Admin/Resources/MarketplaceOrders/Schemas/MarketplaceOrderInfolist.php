<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MarketplaceOrders\Schemas;

use App\Domain\Marketplace\Models\MarketplaceOrder;
use App\Filament\Admin\Resources\MarketplaceOrders\MarketplacePaymentStateBadge;
use App\Platform\FinancialLedger\Models\VendorPayable as VendorPayableModel;
use App\Platform\FinancialLedger\VendorPayableState;
use App\Support\Design\StatusIntent;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

/**
 * Read-only detail surface for `MarketplaceOrderResource`'s view page.
 *
 * Sections per the approved design spec §4.4 ("MarketplaceOrderResource —
 * View"): items (`MarketplaceOrderItem` rows), vendor allocation (single
 * vendor per checkout — MVP), payable state, payment status, and vendor
 * processing status. Everything here is read-only: no transition may be
 * initiated from the infolist — the only write surface on the view page is
 * the finance-gated header action.
 *
 * ---------------------------------------------------------------------------
 * Where each section's data comes from (verified against the real tree)
 * ---------------------------------------------------------------------------
 * - Items: `items()` relation (`MarketplaceOrderItem`), product name via
 *   `items.variant.product.name` (`MarketplaceOrderItem::variant()` ->
 *   `ProductVariant::product()`), frozen `quantity` / `unit_price_minor` /
 *   `line_total_minor` snapshots in integer minor units.
 * - Vendor allocation: `vendor()` BelongsTo (name) plus `vendorOrders()`
 *   HasMany (`VendorOrder` rows linked via `marketplace_order_id`) — the
 *   vendor's per-order refs are their `uuid`s.
 * - Payable state: `vendor_payables` rows opened by the placement
 *   assessment, linked by `(vendor_id, source_type = 'marketplace_order',
 *   source_id = order id)` — the exact query `MarkMarketplaceOrderPaid`
 *   uses to release the payable, and `WebhookPaidEffectsTest::payableFor()`.
 *   No Eloquent relation exists on `MarketplaceOrder`, so the entry states
 *   are computed via `state()` closures using that same query — no new
 *   infrastructure invented. `VendorPayableState` colors/labels mirror the
 *   vendor panel's `PayoutStatus` table (gray 'Ditahan' / info 'Dapat
 *   dicairkan' / success 'Sudah dicairkan') so both surfaces agree.
 * - Vendor processing status: `vendorOrders().status`, a
 *   `VendorProcessingStatus` literal, rendered as a badge per vendor order
 *   through `StatusIntent::FAMILY_VENDOR_PROCESSING` (design-system §3.7
 *   normative mapping — the same single place the table's payment badge
 *   uses). A paid order with an unprocessed vendor order must never read as
 *   one "done" indicator: payment and fulfilment stay two separate rows.
 *
 * `items_count` relies on `getEloquentQuery()`'s `withCount('items')`; the
 * `vendorOrders` entries rely on its `with('vendorOrders')` eager load.
 */
final class MarketplaceOrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextEntry::make('order_number')
                    ->label('No. Pesanan'),

                TextEntry::make('vendor.name')
                    ->label('Vendor')
                    ->placeholder('—'),

                TextEntry::make('customer_ref')
                    ->label('Pelanggan')
                    ->placeholder('—'),

                TextEntry::make('entity_ref')
                    ->label('Entitas')
                    ->placeholder('—'),

                TextEntry::make('payment_state')
                    ->label('Status pembayaran')
                    ->badge()
                    ->color(fn (string $state): string => MarketplacePaymentStateBadge::color($state))
                    ->formatStateUsing(fn (string $state): string => MarketplacePaymentStateBadge::label($state)),

                TextEntry::make('items_count')
                    ->label('Jumlah item'),

                TextEntry::make('total_minor')
                    ->label('Total')
                    ->formatStateUsing(fn (int $state): string => self::moneyString($state)),

                TextEntry::make('placed_at')
                    ->label('Ditempatkan')
                    ->dateTime(),

                RepeatableEntry::make('items')
                    ->label('Item pesanan')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('variant.product.name')
                            ->label('Produk')
                            ->placeholder('—'),

                        TextEntry::make('quantity')
                            ->label('Jumlah'),

                        TextEntry::make('unit_price_minor')
                            ->label('Harga satuan')
                            ->formatStateUsing(fn (int $state): string => self::moneyString($state)),

                        TextEntry::make('line_total_minor')
                            ->label('Subtotal')
                            ->formatStateUsing(fn (int $state): string => self::moneyString($state)),
                    ]),

                TextEntry::make('vendorOrders.uuid')
                    ->label('Referensi pesanan vendor')
                    ->listWithLineBreaks()
                    ->placeholder('Belum ada alokasi vendor')
                    ->columnSpanFull(),

                TextEntry::make('vendorOrders.status')
                    ->label('Status pemrosesan vendor')
                    ->badge()
                    ->listWithLineBreaks()
                    ->color(fn (string $state): string => StatusIntent::filamentColor($state, StatusIntent::FAMILY_VENDOR_PROCESSING))
                    ->formatStateUsing(fn (string $state): string => StatusIntent::label($state, StatusIntent::FAMILY_VENDOR_PROCESSING))
                    ->placeholder('Belum ada alokasi vendor')
                    ->columnSpanFull(),

                TextEntry::make('payable_amount')
                    ->label('Kewajiban vendor')
                    ->state(fn (MarketplaceOrder $record): ?string => self::moneyString(self::payableFor($record)?->amount_minor))
                    ->placeholder('Belum ada kewajiban tercatat'),

                TextEntry::make('payable_state')
                    ->label('Status kewajiban')
                    ->badge()
                    ->state(fn (MarketplaceOrder $record): ?string => self::payableFor($record)?->state)
                    ->color(fn (string $state): string => match ($state) {
                        VendorPayableState::HELD => 'gray',
                        VendorPayableState::PAYABLE => 'info',
                        VendorPayableState::PAID => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        VendorPayableState::HELD => 'Ditahan',
                        VendorPayableState::PAYABLE => 'Dapat dicairkan',
                        VendorPayableState::PAID => 'Sudah dicairkan',
                        default => $state,
                    })
                    ->placeholder('Belum ada kewajiban tercatat'),

                TextEntry::make('idempotency_key')
                    ->label('Kunci idempotensi')
                    ->placeholder('—')
                    ->columnSpanFull(),
            ]);
    }

    /**
     * The exact query `MarkMarketplaceOrderPaid::releasePayable()` (and
     * `WebhookPaidEffectsTest::payableFor()`) uses: the placement assessment
     * opens the payable with `(vendor_id, source_type = 'marketplace_order',
     * source_id = order id)`.
     */
    private static function payableFor(MarketplaceOrder $order): ?VendorPayableModel
    {
        return VendorPayableModel::query()
            ->where('vendor_id', $order->vendor_id)
            ->where('source_type', 'marketplace_order')
            ->where('source_id', $order->getKey())
            ->first();
    }

    private static function moneyString(int $amountMinor): string
    {
        return 'Rp '.number_format($amountMinor / 100, 0, ',', '.');
    }
}
