<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Resources\VendorAvailabilities\Tables;

use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * List-page table for `VendorAvailabilityResource`.
 *
 * No `vendor` column: every row on this page belongs to the acting vendor by
 * construction (`Concerns\ScopesToCurrentVendor`), so a column repeating that
 * would be noise. Newest date first, because a vendor maintaining a calendar
 * works forward from today rather than back through days already past.
 */
final class VendorAvailabilitiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('available_date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('capacity')
                    ->label('Kapasitas')
                    ->sortable(),

                IconColumn::make('is_blocked')
                    ->label('Diblokir')
                    ->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->emptyStateHeading('Belum ada jadwal')
            ->emptyStateDescription('Tambahkan tanggal pertama Anda agar pelanggan dapat memesan sesuai jadwal.')
            ->emptyStateIcon(Heroicon::OutlinedCalendar)
            ->defaultSort('available_date', 'desc');
    }
}
