<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PreNeedCases\Tables;

use App\Domain\PreNeed\PreNeedCaseStatus;
use App\Filament\Admin\Resources\PreNeedCases\PreNeedCaseStatusBadge;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class PreNeedCasesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->orderByDesc('created_at'))
            ->columns([
                TextColumn::make('interest.id')
                    ->label('Referensi Minat')
                    ->searchable(),
                TextColumn::make('interest.bookingDraft.customer_full_name')
                    ->label('Pemohon')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => PreNeedCaseStatusBadge::color(PreNeedCaseStatus::from($state)))
                    ->formatStateUsing(fn (string $state): string => PreNeedCaseStatusBadge::label(PreNeedCaseStatus::from($state))),
                TextColumn::make('cemetery.name')
                    ->label('Lokasi')
                    ->placeholder('Belum dipilih'),
                TextColumn::make('cemetery_package_id')
                    ->label('Paket')
                    ->formatStateUsing(fn (?string $state): string => $state === null || $state === '' ? '—' : (string) $state),
                TextColumn::make('agreement_id')
                    ->label('Kesepakatan')
                    ->placeholder('—'),
                TextColumn::make('quote_id')
                    ->label('Penawaran')
                    ->placeholder('—'),
                TextColumn::make('created_at')->label('Dibuat')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(PreNeedCaseStatus::cases())->mapWithKeys(
                        fn (PreNeedCaseStatus $status): array => [$status->value => PreNeedCaseStatusBadge::label($status)]
                    )->all()),
            ])
            ->recordActions([
                ViewAction::make()->label('Lihat'),
            ]);
    }
}
