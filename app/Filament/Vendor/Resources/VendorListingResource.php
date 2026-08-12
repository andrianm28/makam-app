<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Resources;

use App\Domain\Marketplace\AvailabilityMode;
use App\Domain\Marketplace\EvidenceRequirement;
use App\Domain\Marketplace\Models\Vendor;
use App\Domain\Marketplace\Models\VendorListing;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class VendorListingResource extends Resource
{
    protected static ?string $model = VendorListing::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';
    protected static ?string $navigationLabel = 'Produk';
    protected static ?string $label = 'Produk';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('product_id')
                ->relationship('product', 'name')
                ->required()
                ->disabledOn('edit'),
            Forms\Components\TextInput::make('price_minor')
                ->label('Harga (Rp)')
                ->numeric()
                ->required()
                ->minValue(1),
            Forms\Components\Select::make('availability_mode')
                ->label('Mode Ketersediaan')
                ->options([
                    'STOCKED' => 'Stok',
                    'MADE_TO_ORDER' => 'Pesan Lebih Dahulu',
                    'SCHEDULED' => 'Jadwalkan',
                ])
                ->required(),
            Forms\Components\TextInput::make('stock_quantity')
                ->label('Stok')
                ->numeric()
                ->nullable(),
            Forms\Components\TextInput::make('production_lead_time_days')
                ->label('Lead Time (hari)')
                ->numeric()
                ->nullable(),
            Forms\Components\Textarea::make('cancellation_policy')
                ->label('Kebijakan Pembatalan')
                ->nullable(),
            Forms\Components\Select::make('evidence_requirement')
                ->label('Persyaratan Bukti')
                ->options([
                    'NONE' => 'Tidak Ada',
                    'PHOTO' => 'Foto',
                    'DOCUMENT' => 'Dokumen',
                ])
                ->required(),
            Forms\Components\Toggle::make('is_active')
                ->label('Aktif')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('product.name')
                ->label('Produk'),
            Tables\Columns\TextColumn::make('product.category')
                ->label('Kategori'),
            Tables\Columns\TextColumn::make('price_minor')
                ->label('Harga')
                ->money('IDR'),
            Tables\Columns\TextColumn::make('availability_mode')
                ->label('Mode')
                ->badge(),
            Tables\Columns\TextColumn::make('stock_quantity')
                ->label('Stok'),
            Tables\Columns\BooleanColumn::make('is_active')
                ->label('Aktif'),
            Tables\Columns\TextColumn::make('created_at')
                ->label('Dibuat')
                ->dateTime(),
        ])->filters([
            Tables\Filters\SelectFilter::make('is_active')
                ->label('Status Aktif')
                ->options(['1' => 'Aktif', '0' => 'Nonaktif']),
        ]);
    }
}
