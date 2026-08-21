<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\WorkOrders\Pages;

use App\Filament\Admin\Resources\WorkOrders\WorkOrdersResource;
use Filament\Resources\Pages\ListRecords;

final class ListWorkOrders extends ListRecords
{
    protected static string $resource = WorkOrdersResource::class;
}
