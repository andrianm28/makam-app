<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ServiceDefinitionResource\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * List-page table for `ServiceDefinitionResource` — the six columns the
 * Task 6 brief names: code, name, category, fulfillment_owner,
 * requires_schedule, is_active. The two closed-list columns render their
 * canonical value verbatim (never an invented Indonesian label — the
 * canonical values are `basic`/`additional` and `platform`/
 * `cemetery_operator`/`vendor`); the two boolean columns render as icons so
 * a scan of the catalogue reads as checkmarks rather than "1"/"0".
 *
 * The only row action is Edit. There is deliberately no DeleteAction: no
 * Domain Action deletes a `service_definitions` row (the model's own doc
 * block frames these as seed-protected master data), and the resource must
 * not fall back to Filament's default `$record->delete()` for an operation
 * the Domain layer does not itself support — the same discipline
 * `FaqArticles` applies to its own absence of deletion.
 *
 * Authorization of the Edit row action routes through the Resource's
 * `getAuthorizationResponse()` override — `Filament\Resources\Pages\Page
 * ::getDefaultActionAuthorizationResponse()` resolves row actions through
 * the resource's own authorization responses, so the master-data gate holds
 * here without any per-action `authorize()` call.
 */
final class ServiceDefinitionsTable
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
                    ->label('Nama layanan')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('category')
                    ->label('Kategori')
                    ->sortable(),

                TextColumn::make('fulfillment_owner')
                    ->label('Pihak pemenuhan')
                    ->placeholder('Belum ditentukan')
                    ->sortable(),

                IconColumn::make('requires_schedule')
                    ->label('Perlu jadwal')
                    ->boolean(),

                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('code');
    }
}
