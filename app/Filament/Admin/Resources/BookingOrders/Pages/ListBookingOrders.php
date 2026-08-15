<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BookingOrders\Pages;

use App\Filament\Admin\Resources\BookingOrders\BookingOrderResource;
use Filament\Resources\Pages\ListRecords;

final class ListBookingOrders extends ListRecords
{
    protected static string $resource = BookingOrderResource::class;
}
