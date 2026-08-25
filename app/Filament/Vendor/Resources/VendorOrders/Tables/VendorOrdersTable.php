<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Resources\VendorOrders\Tables;

use App\Filament\Vendor\Support\VendorOrderStatusPresenter;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * List-page table for `VendorOrderResource`.
 *
 * No `vendor` column: every row on this page belongs to the acting vendor by
 * construction (`Concerns\ScopesToCurrentVendor`), so a column repeating that
 * would be noise.
 *
 * Columns and their labels mirror `App\Filament\Vendor\Pages\TransactionHistory`
 * on purpose — that page reads the same `vendor_orders` rows as history, and a
 * vendor moving between the two screens should not have to re-learn what a
 * column means. Status label and badge colour both come from
 * `VendorOrderStatusPresenter` for the same reason: it is the single place that
 * decides how a `VendorProcessingStatus` code renders, so the two screens
 * cannot drift.
 *
 * The difference from that page is the row action: this is the vendor's work
 * queue, so each row can be opened for edit. There is no `CreateAction` header
 * action and no create route — see `VendorOrderResource`'s doc block.
 */
final class VendorOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('uuid')
                    ->label('ID Pesanan')
                    ->limit(8)
                    ->copyable()
                    ->searchable(),

                TextColumn::make('created_at')
                    ->label('Tanggal')
                    ->dateTime('d M Y H:i')
                    ->sortable(),

                TextColumn::make('customer_name')
                    ->label('Pelanggan')
                    ->searchable(),

                TextColumn::make('listing.product.name')
                    ->label('Produk'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => VendorOrderStatusPresenter::color($state))
                    ->formatStateUsing(fn (string $state): string => VendorOrderStatusPresenter::label($state)),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(VendorOrderStatusPresenter::options()),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->emptyStateHeading('Belum ada pesanan')
            ->emptyStateDescription('Pesanan pelanggan untuk produk toko Anda akan muncul di sini begitu masuk.')
            ->emptyStateIcon(Heroicon::OutlinedShoppingCart)
            ->defaultSort('created_at', 'desc');
    }
}
