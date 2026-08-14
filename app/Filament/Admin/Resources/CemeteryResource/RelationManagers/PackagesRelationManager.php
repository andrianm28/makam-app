<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CemeteryResource\RelationManagers;

use App\Domain\CemeteryCapability\CemeteryPackageAvailabilityStatus;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Inline package/class availability rows for `CemeteryResource` — the
 * `cemetery_packages` rows backing requirements.md AC6 ("present Makam
 * Tumpang availability explicitly at the location/package/class level").
 * Bounded scope per the plan: list + inline create/edit only.
 *
 * The package `name` is deliberately a free-text operator string, NOT a
 * closed list — `CemeteryPackage`'s own doc block explains why this module
 * must not assert a competing service-type catalogue.
 *
 * ---------------------------------------------------------------------------
 * Filament 5 shape: instance methods, not statics
 * ---------------------------------------------------------------------------
 * Unlike `FaqArticleResource`'s static `form()`/`table()`, a Filament 5
 * `RelationManager` (verified against the installed v5.7.3) declares
 * `public function form(Schema $schema)` as an INSTANCE method
 * (`InteractsWithRelationshipTable`) and configures its table by overriding
 * `protected function makeTable()` — both called on the mounted component.
 *
 * ---------------------------------------------------------------------------
 * Write path: the model, not a Domain Action
 * ---------------------------------------------------------------------------
 * No `CemeteryPackage` write Action exists in `CemeteryCapability` — the
 * design doc's "route through domain Actions WHERE THEY EXIST" rule has
 * nothing to route to here, so Filament's relationship-save path is used.
 * `CemeteryPackage::booted()`'s `saving` hook (availability-status closed
 * list assertion) still fires on every write.
 */
final class PackagesRelationManager extends RelationManager
{
    protected static string $relationship = 'packages';

    protected static ?string $title = 'Paket Makam';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->label('Nama paket')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),

                TextInput::make('class_label')
                    ->label('Kelas (opsional)')
                    ->nullable()
                    ->maxLength(255)
                    ->helperText(
                        'Kosongkan untuk baris tingkat paket (tanpa rincian kelas).'
                    ),

                Select::make('availability_status')
                    ->label('Status ketersediaan')
                    ->required()
                    ->native(false)
                    ->options(array_combine(
                        CemeteryPackageAvailabilityStatus::KNOWN_STATUSES,
                        ['Tersedia', 'Terbatas', 'Tidak tersedia'],
                    )),

                Toggle::make('is_active')
                    ->label('Aktif')
                    ->default(true),

                TextInput::make('sort_order')
                    ->label('Urutan')
                    ->numeric()
                    ->default(1)
                    ->minValue(1),

                Textarea::make('description')
                    ->label('Deskripsi')
                    ->nullable()
                    ->rows(3)
                    ->maxLength(500)
                    ->columnSpanFull(),
            ]);
    }

    protected function makeTable(): Table
    {
        return parent::makeTable()
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')
                    ->label('Nama paket')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('class_label')
                    ->label('Kelas')
                    ->placeholder('Paket (tanpa kelas)')
                    ->sortable(),

                TextColumn::make('availability_status')
                    ->label('Ketersediaan')
                    ->badge()
                    ->color(fn (string $state): string => self::availabilityColor($state))
                    ->formatStateUsing(fn (string $state): string => self::availabilityLabel($state))
                    ->sortable(),

                TextColumn::make('sort_order')
                    ->label('Urutan')
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean()
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function availabilityColor(string $state): string
    {
        return match ($state) {
            CemeteryPackageAvailabilityStatus::AVAILABLE => 'success',
            CemeteryPackageAvailabilityStatus::LIMITED => 'warning',
            CemeteryPackageAvailabilityStatus::UNAVAILABLE => 'danger',
            default => 'gray',
        };
    }

    public static function availabilityLabel(string $state): string
    {
        return match ($state) {
            CemeteryPackageAvailabilityStatus::AVAILABLE => 'Tersedia',
            CemeteryPackageAvailabilityStatus::LIMITED => 'Terbatas',
            CemeteryPackageAvailabilityStatus::UNAVAILABLE => 'Tidak tersedia',
            default => $state,
        };
    }
}
