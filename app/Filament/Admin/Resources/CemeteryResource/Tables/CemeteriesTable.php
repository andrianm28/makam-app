<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CemeteryResource\Tables;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\PlotTrackingMode;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * List-page table for `CemeteryResource` — columns per the plan: name, type,
 * city, publication_status, price_min, updated_at. Closed-list values render
 * through their canonical enum vocabularies (`CemeteryType::KNOWN_TYPES`,
 * `LaunchCityCode::KNOWN_CODES`, `CemeteryPublicationStatus::KNOWN_STATUSES`)
 * so the panel can never display a label no source of truth defines.
 *
 * Publication status renders as a badge with the same color mapping the
 * sibling `FaqArticles` resource chose for its identical
 * draft/published/unpublished vocabulary (draft -> gray, published ->
 * success, unpublished -> warning; see `FaqArticleStatusBadge`'s doc block
 * for why this does not route through `StatusIntent` — its scope is order
 * lifecycle and vendor processing only, and this vocabulary is neither).
 */
final class CemeteriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable()
                    ->description(fn (mixed $record): string => (string) $record->slug),

                TextColumn::make('type')
                    ->label('Jenis')
                    ->badge()
                    ->color(fn (string $state): string => $state === CemeteryType::TPU ? 'gray' : 'info')
                    ->formatStateUsing(fn (string $state): string => $state === CemeteryType::TPU
                        ? 'TPU'
                        : 'TPS')
                    ->sortable(),

                TextColumn::make('city')
                    ->label('Kota')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state): string => ucfirst(strtolower($state)))
                    ->sortable(),

                TextColumn::make('publication_status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => self::publicationColor($state))
                    ->formatStateUsing(fn (string $state): string => self::publicationLabel($state))
                    ->sortable(),

                TextColumn::make('plot_tracking_mode')
                    ->label('Pelacakan petak')
                    ->badge()
                    ->color(fn (string $state): string => self::trackingModeColor($state))
                    ->formatStateUsing(fn (string $state): string => self::trackingModeLabel($state))
                    ->sortable(),

                TextColumn::make('price_min')
                    ->label('Harga mulai')
                    ->numeric(decimalPlaces: 0)
                    ->prefix('Rp ')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('updated_at')
                    ->label('Diperbarui')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ]);
    }

    public static function publicationColor(string $state): string
    {
        return match ($state) {
            CemeteryPublicationStatus::PUBLISHED => 'success',
            CemeteryPublicationStatus::UNPUBLISHED => 'warning',
            default => 'gray',
        };
    }

    public static function publicationLabel(string $state): string
    {
        return match ($state) {
            CemeteryPublicationStatus::PUBLISHED => 'Dipublikasikan',
            CemeteryPublicationStatus::UNPUBLISHED => 'Tidak dipublikasikan',
            default => 'Draf',
        };
    }

    public static function trackingModeColor(string $state): string
    {
        return $state === PlotTrackingMode::GRANULAR ? 'info' : 'gray';
    }

    public static function trackingModeLabel(string $state): string
    {
        return $state === PlotTrackingMode::GRANULAR
            ? 'Granular (per petak)'
            : 'Agregat (kuota per paket)';
    }
}
