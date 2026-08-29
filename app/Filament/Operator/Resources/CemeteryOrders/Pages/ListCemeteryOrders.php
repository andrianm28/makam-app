<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\CemeteryOrders\Pages;

use App\Filament\Operator\Resources\CemeteryOrders\CemeteryOrderResource;
use Filament\Resources\Pages\ListRecords;

final class ListCemeteryOrders extends ListRecords
{
    protected static string $resource = CemeteryOrderResource::class;
}
