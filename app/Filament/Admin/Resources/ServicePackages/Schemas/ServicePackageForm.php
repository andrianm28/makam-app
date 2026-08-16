<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ServicePackages\Schemas;

use App\Domain\ServiceCatalog\FulfillmentOwner;
use App\Domain\ServiceCatalog\Models\ServiceDefinition;
use App\Domain\ServiceCatalog\ServicePackageItemType;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

/**
 * Create form for `ServicePackageResource` — code, name, description and
 * the first version's item lines, which `Actions\DefineServicePackage`
 * REQUIRES to be non-empty (`items === []` throws). The items repeater is
 * therefore the load-bearing part of this form: at least one item must be
 * supplied, matching the action's own contract.
 *
 * ---------------------------------------------------------------------------
 * No `is_active` field here — and why
 * ---------------------------------------------------------------------------
 * `DefineServicePackage` hardcodes `is_active => true` (see its own doc
 * block: "`is_active` is forced true"). A toggle that the create action
 * silently ignores would be a UI lie, so the create form deliberately omits
 * it — every package is born active, and the list table still displays the
 * column. Deactivating a package is out of scope for this task (no edit
 * page exists in the task's file list).
 *
 * ---------------------------------------------------------------------------
 * Closed-list fields
 * ---------------------------------------------------------------------------
 * `item_type` and `fulfillment_owner` are drawn ONLY from the canonical
 * vocabularies (`ServicePackageItemType::KNOWN_TYPES`,
 * `FulfillmentOwner::KNOWN_OWNERS`) with Indonesian display labels —
 * `AGENTS.md`: "do not invent alternate labels". `service_definition_id`
 * is a select over the seeded service definitions.
 *
 * The repeater's dehydrated shape is exactly the `items` array
 * `DefineServicePackage` consumes:
 * `list<array{service_definition_id, item_type, quantity, unit,
 * fulfillment_owner, requires_schedule_window?, evidence_required?, notes?}>`.
 */
final class ServicePackageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('code')
                    ->label('Kode paket')
                    ->required()
                    ->maxLength(64)
                    ->unique(table: 'service_packages', column: 'code')
                    ->columnSpan(1),

                TextInput::make('name')
                    ->label('Nama paket')
                    ->required()
                    ->maxLength(255)
                    ->columnSpan(1),

                Textarea::make('description')
                    ->label('Deskripsi')
                    ->rows(3)
                    ->maxLength(1000)
                    ->columnSpanFull(),

                Repeater::make('items')
                    ->label('Item versi 1')
                    ->minItems(1)
                    ->required()
                    ->defaultItems(0)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('service_definition_id')
                            ->label('Layanan')
                            ->options(ServiceDefinition::query()->orderBy('name')->pluck('name', 'id'))
                            ->required()
                            ->searchable()
                            ->columnSpan(3),

                        Select::make('item_type')
                            ->label('Jenis item')
                            ->options([
                                ServicePackageItemType::INCLUDED => 'Termasuk',
                                ServicePackageItemType::OPTIONAL => 'Opsional',
                                ServicePackageItemType::EXCLUDED => 'Tidak termasuk',
                            ])
                            ->required()
                            ->native(false)
                            ->columnSpan(2),

                        TextInput::make('quantity')
                            ->label('Jumlah')
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->columnSpan(1),

                        TextInput::make('unit')
                            ->label('Satuan')
                            ->required()
                            ->maxLength(32)
                            ->columnSpan(2),

                        Select::make('fulfillment_owner')
                            ->label('Pihak pemenuhan')
                            ->options([
                                FulfillmentOwner::PLATFORM => 'Platform',
                                FulfillmentOwner::CEMETERY_OPERATOR => 'Pengelola TPU',
                                FulfillmentOwner::VENDOR => 'Vendor',
                            ])
                            ->required()
                            ->native(false)
                            ->columnSpan(2),

                        Toggle::make('requires_schedule_window')
                            ->label('Perlu jendela jadwal')
                            ->columnSpan(2),

                        Toggle::make('evidence_required')
                            ->label('Perlu bukti')
                            ->columnSpan(2),

                        Textarea::make('notes')
                            ->label('Catatan')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
