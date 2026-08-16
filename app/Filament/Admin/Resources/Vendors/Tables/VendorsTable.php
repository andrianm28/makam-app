<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Vendors\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * List-page table for `VendorResource` — columns per the P2 design spec
 * §4.3: name, active badge, listings count, members count, updated_at.
 *
 * The two count columns read `listings_count` / `members_count`, so the
 * table query preloads them via `withCount()` — otherwise Filament would
 * run one COUNT query per row (N+1).
 *
 * Active renders as a badge with the same color mapping the codebase uses
 * for the identical boolean vocabulary (success for active, gray for
 * inactive).
 */
final class VendorsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount(['listings', 'members']))
            ->columns([
                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('is_active')
                    ->label('Status')
                    ->badge()
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Aktif' : 'Nonaktif')
                    ->sortable(),

                TextColumn::make('listings_count')
                    ->label('Listing')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('members_count')
                    ->label('Anggota')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('updated_at')
                    ->label('Diperbarui')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ]);
    }
}
