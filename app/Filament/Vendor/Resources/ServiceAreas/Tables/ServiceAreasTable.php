<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Resources\ServiceAreas\Tables;

use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * List-page table for `ServiceAreaResource`.
 *
 * No `vendor` column: every row on this page belongs to the acting vendor by
 * construction (`Concerns\ScopesToCurrentVendor`), so a column repeating that
 * would be noise. Sorted by `area_code` ascending because that is the value
 * vendors identify an area by, and it is unique per vendor
 * (`service_areas_vendor_area_unique`).
 */
final class ServiceAreasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('area_code')
                    ->label('Kode area')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('area_label')
                    ->label('Nama area')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('delivery_fee_minor')
                    ->label('Ongkos kirim')
                    ->money('IDR', divideBy: 100)
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
            ->emptyStateHeading('Belum ada area layanan')
            ->emptyStateDescription('Tambahkan area layanan pertama Anda agar pelanggan di wilayah tersebut dapat memesan.')
            ->emptyStateIcon(Heroicon::OutlinedMapPin)
            ->defaultSort('area_code', 'asc');
    }
}
