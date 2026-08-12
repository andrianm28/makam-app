<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Pages;

use App\Domain\Marketplace\Models\VendorOrder;
use App\Filament\Vendor\Resources\VendorOrderResource;
use Filament\Resources\Pages\ListRecords;

class TransactionHistory extends ListRecords
{
    protected static ?string $title = 'Riwayat Transaksi';
    protected static ?string $navigationIcon = 'heroicon-o-clock';
    protected static ?string $navigationLabel = 'Riwayat Transaksi';

    protected static string $resource = VendorOrderResource::class;
}
