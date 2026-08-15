<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MarketplaceOrders\Schemas;

use App\Filament\Admin\Resources\MarketplaceOrders\MarketplacePaymentStateBadge;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

/**
 * Read-only detail surface for `MarketplaceOrderResource`'s view page.
 * Same `Rp` minor-unit presentation and payment-state badge as the table;
 * the `items_count` entry relies on `getEloquentQuery()`'s `withCount('items')`.
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
                    ->formatStateUsing(fn (int $state): string => 'Rp '.number_format($state / 100, 0, ',', '.')),

                TextEntry::make('placed_at')
                    ->label('Ditempatkan')
                    ->dateTime(),

                TextEntry::make('idempotency_key')
                    ->label('Kunci idempotensi')
                    ->placeholder('—')
                    ->columnSpanFull(),
            ]);
    }
}
