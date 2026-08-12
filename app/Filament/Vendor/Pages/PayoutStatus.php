<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Pages;

use App\Platform\FinancialLedger\Models\VendorPayable;
use App\Platform\FinancialLedger\VendorPayableState;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Resources\Pages\ListRecords;

class PayoutStatus extends ListRecords
{
    protected static ?string $title = 'Status Pencairan';
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';
    protected static ?string $navigationLabel = 'Pencairan';

    protected static string $model = VendorPayable::class;

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('id')
                ->label('ID')
                ->truncate(8),
            TextColumn::make('amount_minor')
                ->label('Jumlah')
                ->money('IDR'),
            BadgeColumn::make('state')
                ->label('Status')
                ->color(fn (string $state): string => match ($state) {
                    VendorPayableState::HELD => 'gray',
                    VendorPayableState::PAYABLE => 'info',
                    VendorPayableState::PAID => 'success',
                    default => 'gray',
                })
                ->formatStateUsing(fn (string $state): string => match ($state) {
                    VendorPayableState::HELD => 'Ditahan',
                    VendorPayableState::PAYABLE => 'Dapat Dicairkan',
                    VendorPayableState::PAID => 'Sudah Dicairkan',
                    default => $state,
                }),
            TextColumn::make('eligible_at')
                ->label('Bebas Pada')
                ->dateTime(),
            TextColumn::make('paid_at')
                ->label('Dicairkan Pada')
                ->dateTime(),
            TextColumn::make('payout.reference')
                ->label('Referensi Pencairan'),
        ];
    }
}
