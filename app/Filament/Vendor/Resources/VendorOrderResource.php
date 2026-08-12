<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Resources;

use App\Domain\Marketplace\Models\VendorOrder;
use App\Domain\Marketplace\VendorProcessingStatus;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class VendorOrderResource extends Resource
{
    protected static ?string $model = VendorOrder::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';
    protected static ?string $navigationLabel = 'Pesanan';
    protected static ?string $label = 'Pesanan';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('uuid')
                ->disabled(),
            Forms\Components\TextInput::make('customer_name')
                ->label('Nama Pelanggan')
                ->disabled(),
            Forms\Components\TextInput::make('customer_phone')
                ->label('Telepon')
                ->disabled(),
            Forms\Components\TextInput::make('customer_email')
                ->label('Email')
                ->disabled(),
            Forms\Components\TextInput::make('listing.product.name')
                ->label('Produk')
                ->disabled(),
            Forms\Components\Select::make('status')
                ->label('Status')
                ->options(self::statusLabels())
                ->required(),
            Forms\Components\Textarea::make('notes')
                ->label('Catatan')
                ->nullable(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('uuid')
                ->label('ID Pesanan')
                ->truncate(8),
            Tables\Columns\TextColumn::make('customer_name')
                ->label('Pelanggan'),
            Tables\Columns\TextColumn::make('listing.product.name')
                ->label('Produk'),
            Tables\Columns\TextColumn::make('status')
                ->label('Status')
                ->badge()
                ->color(fn (string $status): string => self::statusColor($status)),
            Tables\Columns\TextColumn::make('created_at')
                ->label('Tanggal')
                ->dateTime(),
        ])->filters([
            Tables\Filters\SelectFilter::make('status')
                ->label('Status')
                ->options(self::statusLabels()),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return [
            VendorProcessingStatus::MENUNGGU_VENDOR => 'Menunggu Vendor',
            VendorProcessingStatus::DITERIMA_VENDOR => 'Diterima',
            VendorProcessingStatus::DITOLAK_VENDOR => 'Ditolak',
            VendorProcessingStatus::DIPROSES => 'Diproses',
            VendorProcessingStatus::DIKIRIM_OR_DIJADWALKAN => 'Dikirim/Dijadwalkan',
            VendorProcessingStatus::SELESAI => 'Selesai',
            VendorProcessingStatus::KOMPLAIN => 'Komplain',
            VendorProcessingStatus::DIBATALKAN => 'Dibatalkan',
        ];
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            VendorProcessingStatus::MENUNGGU_VENDOR => 'gray',
            VendorProcessingStatus::DITERIMA_VENDOR => 'info',
            VendorProcessingStatus::DITOLAK_VENDOR => 'danger',
            VendorProcessingStatus::DIPROSES => 'warning',
            VendorProcessingStatus::DIKIRIM_OR_DIJADWALKAN => 'primary',
            VendorProcessingStatus::SELESAI => 'success',
            VendorProcessingStatus::KOMPLAIN => 'danger',
            VendorProcessingStatus::DIBATALKAN => 'gray',
            default => 'gray',
        };
    }
}
