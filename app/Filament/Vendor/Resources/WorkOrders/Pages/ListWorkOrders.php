<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Resources\WorkOrders\Pages;

use App\Filament\Vendor\Resources\WorkOrders\WorkOrdersResource;
use Filament\Resources\Pages\ListRecords;

final class ListWorkOrders extends ListRecords
{
    protected static string $resource = WorkOrdersResource::class;
}
