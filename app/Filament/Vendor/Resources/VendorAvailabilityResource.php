<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Resources;

use App\Domain\Marketplace\Models\VendorAvailability;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class VendorAvailabilityResource extends Resource
{
    protected static ?string $model = VendorAvailability::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar';
    protected static ?string $navigationLabel = 'Kalender';
    protected static ?string $label = 'Kalender';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\DatePicker::make('available_date')
                ->label('Tanggal')
                ->required(),
            Forms\Components\TextInput::make('capacity')
                ->label('Kapasitas')
                ->numeric()
                ->minValue(0)
                ->required(),
            Forms\Components\Toggle::make('is_blocked')
                ->label('Diblockir')
                ->default(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('available_date')
                ->label('Tanggal')
                ->date(),
            Tables\Columns\TextColumn::make('capacity')
                ->label('Kapasitas'),
            Tables\Columns\BooleanColumn::make('is_blocked')
                ->label('Diblockir'),
        ]);
    }
}
