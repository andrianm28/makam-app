<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ProductResource\Tables;

use App\Domain\Marketplace\MarketplaceProductCategory;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * List-page table for `ProductResource` — columns per the plan's Task 5:
 * name, code, category (displayed via the canonical catalogue labels),
 * vendor_name, base_price_idr (same `Rp` presentation the public
 * marketplace presenter uses), price_version, is_active.
 */
final class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('code')
                    ->label('Kode')
                    ->sortable(),

                TextColumn::make('category')
                    ->label('Kategori')
                    ->formatStateUsing(fn (string $state): string => MarketplaceProductCategory::label($state))
                    ->sortable(),

                TextColumn::make('vendor_name')
                    ->label('Vendor')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('base_price_idr')
                    ->label('Harga dasar (Rp)')
                    ->formatStateUsing(fn (?int $state): ?string => $state === null
                        ? null
                        : 'Rp '.number_format((float) $state, 0, ',', '.'))
                    ->placeholder('Belum dipatok')
                    ->sortable(),

                TextColumn::make('price_version')
                    ->label('Versi harga')
                    ->sortable(),

                TextColumn::make('is_active')
                    ->label('Aktif')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Aktif' : 'Nonaktif')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
