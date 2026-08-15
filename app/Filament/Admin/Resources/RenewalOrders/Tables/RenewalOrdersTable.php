<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RenewalOrders\Tables;

use App\Domain\Renewal\Models\Renewal;
use App\Domain\Renewal\Models\RenewalQuote;
use App\Domain\Renewal\RenewalStatus;
use App\Filament\Admin\Resources\RenewalOrders\RenewalStatusBadge;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class RenewalOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->orderByDesc('created_at'))
            ->columns([
                TextColumn::make('reference')
                    ->label('Referensi')
                    ->searchable(),

                TextColumn::make('graveRecord.deceased_name')
                    ->label('Almarhum')
                    ->searchable(),

                TextColumn::make('target_due_period')
                    ->label('Periode Jatuh Tempo')
                    ->date()
                    ->sortable(),

                TextColumn::make('amount')
                    ->label('Nominal')
                    ->state(fn (Renewal $record): string => self::amountFor($record)),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => RenewalStatusBadge::color($state))
                    ->formatStateUsing(fn (string $state): string => RenewalStatusBadge::label($state)),

                TextColumn::make('source')
                    ->label('Sumber'),

                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(array_combine(
                        RenewalStatus::KNOWN_STATUSES,
                        array_map(
                            fn (string $status): string => RenewalStatusBadge::label($status),
                            RenewalStatus::KNOWN_STATUSES,
                        ),
                    )),
            ])
            ->recordActions([
                ViewAction::make()->label('Lihat'),
            ]);
    }

    /**
     * The latest `renewal_quotes` row's amount — a renewal may accumulate
     * more than one quote over its lifetime, and "which quote is current"
     * is decided at read time by the tariff layer. The list page shows the
     * newest cut, formatted from minor units; a renewal with no quote yet
     * reads as "Belum ada penawaran".
     */
    private static function amountFor(Renewal $record): string
    {
        $quote = $record->quotes
            ->sortByDesc('created_at')
            ->first();

        if ($quote === null) {
            return 'Belum ada penawaran';
        }

        return 'Rp '.number_format(self::minorAmountOf($quote) / 100, 0, ',', '.');
    }

    private static function minorAmountOf(RenewalQuote $quote): int
    {
        return $quote->amount_minor;
    }
}
