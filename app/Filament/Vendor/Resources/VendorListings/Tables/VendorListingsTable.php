<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Resources\VendorListings\Tables;

use App\Domain\Marketplace\AvailabilityMode;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * List-page table for `VendorListingResource`.
 *
 * No `vendor` column: every row on this page belongs to the acting vendor by
 * construction (`Concerns\ScopesToCurrentVendor`), so a column repeating that
 * would be noise. The empty state is the one the plan's Task 3 asks for — a
 * "no products yet" heading with the create action as its call to action,
 * rather than Filament's generic default.
 */
final class VendorListingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('product.name')
                    ->label('Produk')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('product.category')
                    ->label('Kategori')
                    ->toggleable(),

                TextColumn::make('price_minor')
                    ->label('Harga')
                    ->money('IDR', divideBy: 100)
                    ->sortable(),

                TextColumn::make('availability_mode')
                    ->label('Ketersediaan')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        AvailabilityMode::STOCKED => 'Stok tersedia',
                        AvailabilityMode::MADE_TO_ORDER => 'Dibuat setelah pesanan',
                        AvailabilityMode::SCHEDULED => 'Dijadwalkan',
                        default => $state,
                    }),

                TextColumn::make('stock_quantity')
                    ->label('Stok')
                    ->placeholder('—')
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),

                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Status aktif')
                    ->trueLabel('Hanya aktif')
                    ->falseLabel('Hanya nonaktif'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->emptyStateHeading('Belum ada produk')
            ->emptyStateDescription('Tambahkan produk pertama Anda agar dapat dipesan pelanggan.')
            ->emptyStateIcon(Heroicon::OutlinedShoppingBag)
            ->defaultSort('created_at', 'desc');
    }
}
