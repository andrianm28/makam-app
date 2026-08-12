<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Pages;

use App\Platform\FinancialLedger\Models\VendorPayable;
use App\Platform\FinancialLedger\VendorPayableState;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Resources\Pages\Page;

class PayoutStatus extends Page
{
    protected static ?string $title = 'Status Pencairan';
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';
    protected static ?string $navigationLabel = 'Pencairan';

    protected static string $view = 'filament-vendor::pages.payout-status';

    /**
     * @return array<int, \Filament\Tables\Columns\Column|\Filament\Tables\Columns\BadgeColumn>
     */
    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('uuid')
                ->label('ID'),
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
