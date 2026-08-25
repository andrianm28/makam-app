<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ServicePackages\Tables;

use App\Domain\ServiceCatalog\Models\ServicePackage;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * List-page table for `ServicePackageResource` — the columns the Task 5
 * brief names: code, name, active, versions count, and a badge for the
 * current published version. The published-version badge resolves through
 * `ServicePackage::currentPublishedVersion()` (the model's own
 * newest-published-version lookup), rendering e.g. "v2" for a package whose
 * latest published version is 2, and "Belum terbit" for one that has never
 * been published.
 *
 * The only row action is View (opens the View page, which hosts the two
 * relation managers). There is deliberately no Edit/Delete row action: this
 * task's file list ships no Edit page, and package deletion is offered only
 * from the View page where the published-version guard is surfaced as an
 * honest notification.
 */
final class ServicePackagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Kode')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Nama paket')
                    ->searchable()
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('versions_count')
                    ->label('Jumlah versi')
                    ->counts('versions')
                    ->sortable(),

                TextColumn::make('current_published_version')
                    ->label('Versi terbit')
                    ->state(fn (ServicePackage $record): ?string => $record->currentPublishedVersion() !== null
                        ? 'v'.$record->currentPublishedVersion()->version_number
                        : null)
                    ->badge()
                    ->color(fn (?string $state): string => $state === null ? 'gray' : 'success')
                    ->placeholder('Belum terbit'),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('code');
    }
}
