<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarePlans\Schemas;

use App\Filament\Admin\Resources\CarePlans\Tables\CarePlansTable;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * View-page read-only schema for `CarePlansResource`.
 */
final class CarePlanInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Rencana Perawatan')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('reference')->label('Nomor referensi'),

                        TextEntry::make('name')->label('Nama'),

                        TextEntry::make('product_code')
                            ->label('Kode produk')
                            ->placeholder('—'),

                        TextEntry::make('frequency')
                            ->label('Frekuensi')
                            ->formatStateUsing(fn (string $state): string => CarePlansTable::frequencyLabel($state)),

                        TextEntry::make('price_minor')
                            ->label('Harga')
                            ->formatStateUsing(fn ($state): string => 'Rp '.number_format((int) $state, 0, ',', '.')),

                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => CarePlansTable::statusLabel($state))
                            ->color(fn (string $state): string => CarePlansTable::statusColor($state)),

                        TextEntry::make('vendor.name')
                            ->label('Vendor')
                            ->placeholder('—'),

                        TextEntry::make('description')
                            ->label('Deskripsi')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),

                Section::make('Templat Checklist')
                    ->schema([
                        TextEntry::make('checklist_template')
                            ->label('Item checklist')
                            ->placeholder('Tidak ada templat checklist')
                            ->columnSpanFull()
                            ->formatStateUsing(function (?array $state): string {
                                if ($state === null || $state === []) {
                                    return '—';
                                }

                                return implode("\n", array_map(
                                    fn (int $i, string $item) => ($i + 1).'. '.$item,
                                    array_keys($state),
                                    $state,
                                ));
                            }),
                    ]),
            ]);
    }
}
