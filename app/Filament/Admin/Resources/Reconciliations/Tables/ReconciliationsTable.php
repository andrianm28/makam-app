<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Reconciliations\Tables;

use App\Platform\FinancialLedger\Money;
use App\Platform\FinancialLedger\ReconciliationStatus;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * List table for `ReconciliationsResource` — one row per (badan usaha,
 * period) conclusion `Actions\RunReconciliation` wrote. Read-only: this
 * module's reconciliation rows are never edited or deleted through the
 * admin panel, only produced by the run and read here.
 */
final class ReconciliationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('entity_ref')
                    ->label('Badan usaha')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('period')
                    ->label('Periode')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::statusLabel($state))
                    ->color(fn (string $state): string => self::statusColor($state)),

                TextColumn::make('statement_reference')
                    ->label('Referensi pernyataan')
                    ->placeholder('—'),

                TextColumn::make('statement_total_minor')
                    ->label('Total pernyataan')
                    ->formatStateUsing(
                        fn (?int $state): string => $state === null ? '—' : (new Money($state))->format(),
                    ),

                TextColumn::make('journal_total_minor')
                    ->label('Total jurnal')
                    ->formatStateUsing(
                        fn (?int $state): string => $state === null ? '—' : (new Money($state))->format(),
                    ),

                TextColumn::make('ran_at')
                    ->label('Dijalankan pada')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('ran_at', 'desc');
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            ReconciliationStatus::MATCHED => 'Cocok',
            ReconciliationStatus::EXCEPTIONS_OPEN => 'Ada Selisih',
            ReconciliationStatus::STATEMENT_MISSING => 'Pernyataan Belum Ada',
            default => $status,
        };
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            ReconciliationStatus::MATCHED => 'success',
            ReconciliationStatus::EXCEPTIONS_OPEN => 'danger',
            ReconciliationStatus::STATEMENT_MISSING => 'warning',
            default => 'gray',
        };
    }
}
