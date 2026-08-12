<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Resources;

use App\Domain\Marketplace\Models\ServiceArea;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ServiceAreaResource extends Resource
{
    protected static ?string $model = ServiceArea::class;

    protected static ?string $navigationIcon = 'heroicon-o-map-pin';
    protected static ?string $navigationLabel = 'Area Layanan';
    protected static ?string $label = 'Area Layanan';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('area_code')
                ->label('Kode Area')
                ->required(),
            Forms\Components\TextInput::make('area_label')
                ->label('Nama Area')
                ->required(),
            Forms\Components\TextInput::make('delivery_fee_minor')
                ->label('Biaya Pengiriman (Rp)')
                ->numeric()
                ->nullable(),
            Forms\Components\Toggle::make('is_active')
                ->label('Aktif')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('area_code')
                ->label('Kode'),
            Tables\Columns\TextColumn::make('area_label')
                ->label('Area'),
            Tables\Columns\TextColumn::make('delivery_fee_minor')
                ->label('Biaya')
                ->money('IDR'),
            Tables\Columns\BooleanColumn::make('is_active')
                ->label('Aktif'),
        ]);
    }
}
