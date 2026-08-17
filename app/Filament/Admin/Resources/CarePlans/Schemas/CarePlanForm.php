<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarePlans\Schemas;

use App\Domain\CareSubscription\CarePlanFrequency;
use App\Domain\Marketplace\Models\Vendor;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * Create form for `CarePlansResource`.
 */
final class CarePlanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->label('Nama rencana')
                    ->required()
                    ->maxLength(255),

                TextInput::make('product_code')
                    ->label('Kode produk')
                    ->required()
                    ->maxLength(64),

                Select::make('frequency')
                    ->label('Frekuensi')
                    ->options([
                        CarePlanFrequency::Monthly->value => 'Bulanan',
                        CarePlanFrequency::Quarterly->value => 'Quarterly',
                        CarePlanFrequency::SemiAnnual->value => 'Semesteran',
                        CarePlanFrequency::Annual->value => 'Tahunan',
                    ])
                    ->required()
                    ->native(false),

                TextInput::make('price_minor')
                    ->label('Harga (dalam satuan kecil)')
                    ->required()
                    ->numeric()
                    ->prefix('Rp')
                    ->helperText('Harga dalam satuan terkecil (misal: 250000 untuk Rp 250.000)'),

                Select::make('vendor_id')
                    ->label('Vendor')
                    ->options(fn () => Vendor::query()->pluck('name', 'id'))
                    ->placeholder('—')
                    ->searchable()
                    ->native(false),

                Textarea::make('description')
                    ->label('Deskripsi')
                    ->nullable()
                    ->columnSpanFull(),

                Repeater::make('checklist_template')
                    ->label('Templat checklist')
                    ->schema([
                        TextInput::make('item')
                            ->label('Item')
                            ->required()
                            ->maxLength(255),
                    ])
                    ->columnSpanFull()
                    ->helperText('Item-item yang harus dikerjakan vendor setiap siklus.'),
            ]);
    }
}
