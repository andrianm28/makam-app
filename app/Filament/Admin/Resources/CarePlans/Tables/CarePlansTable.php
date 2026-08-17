<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarePlans\Tables;

use App\Domain\CareSubscription\CarePlanFrequency;
use App\Domain\CareSubscription\CarePlanStatus;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * List table for `CarePlansResource` — reference, name, frequency, price,
 * status badge, and vendor.
 */
final class CarePlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label('Nomor referensi')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('product_code')
                    ->label('Kode produk')
                    ->placeholder('—'),

                TextColumn::make('frequency')
                    ->label('Frekuensi')
                    ->formatStateUsing(fn (string $state): string => self::frequencyLabel($state)),

                TextColumn::make('price_minor')
                    ->label('Harga')
                    ->formatStateUsing(fn ($state): string => 'Rp '.number_format((int) $state, 0, ',', '.'))
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::statusLabel($state))
                    ->color(fn (string $state): string => self::statusColor($state)),

                TextColumn::make('vendor.name')
                    ->label('Vendor')
                    ->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function frequencyLabel(string $frequency): string
    {
        return match ($frequency) {
            CarePlanFrequency::Monthly->value => 'Bulanan',
            CarePlanFrequency::Quarterly->value => 'Quarterly',
            CarePlanFrequency::SemiAnnual->value => 'Semesteran',
            CarePlanFrequency::Annual->value => 'Tahunan',
            default => $frequency,
        };
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            CarePlanStatus::Active->value => 'Aktif',
            CarePlanStatus::Inactive->value => 'Nonaktif',
            default => $status,
        };
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            CarePlanStatus::Active->value => 'success',
            CarePlanStatus::Inactive->value => 'gray',
            default => 'gray',
        };
    }
}
