<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Subscriptions\Tables;

use App\Domain\CareSubscription\CarePlanFrequency;
use App\Domain\CareSubscription\SubscriptionStatus;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * List table for `SubscriptionsResource`.
 */
final class SubscriptionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label('Nomor referensi')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('grave.slot')
                    ->label('Makam')
                    ->placeholder('—'),

                TextColumn::make('carePlan.name')
                    ->label('Rencana perawatan')
                    ->placeholder('—'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::statusLabel($state))
                    ->color(fn (string $state): string => self::statusColor($state)),

                TextColumn::make('frequency')
                    ->label('Frekuensi')
                    ->formatStateUsing(fn (string $state): string => self::frequencyLabel($state)),

                TextColumn::make('current_cycle_number')
                    ->label('Siklus saat ini')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(self::statusOptions()),
            ])
            ->emptyStateHeading('Belum ada langganan')
            ->emptyStateDescription('Langganan perawatan makam akan muncul di sini.')
            ->emptyStateIcon(Heroicon::OutlinedArrowPathRoundedSquare)
            ->defaultSort('created_at', 'desc');
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            SubscriptionStatus::Draft->value => 'Draf',
            SubscriptionStatus::Active->value => 'Aktif',
            SubscriptionStatus::Paused->value => 'Dijeda',
            SubscriptionStatus::Ended->value => 'Berakhir',
            SubscriptionStatus::Cancelled->value => 'Dibatalkan',
            default => $status,
        };
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            SubscriptionStatus::Draft->value => 'gray',
            SubscriptionStatus::Active->value => 'success',
            SubscriptionStatus::Paused->value => 'warning',
            SubscriptionStatus::Ended->value => 'gray',
            SubscriptionStatus::Cancelled->value => 'danger',
            default => 'gray',
        };
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

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            SubscriptionStatus::Draft->value => 'Draf',
            SubscriptionStatus::Active->value => 'Aktif',
            SubscriptionStatus::Paused->value => 'Dijeda',
            SubscriptionStatus::Ended->value => 'Berakhir',
            SubscriptionStatus::Cancelled->value => 'Dibatalkan',
        ];
    }
}
