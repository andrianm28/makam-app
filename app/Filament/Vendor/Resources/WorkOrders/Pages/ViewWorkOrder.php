<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Resources\WorkOrders\Pages;

use App\Domain\VendorFulfillment\Models\WorkOrder;
use App\Filament\Vendor\Resources\WorkOrders\WorkOrdersResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

/**
 * View page for `WorkOrdersResource` — checklist tasks (checkboxes),
 * evidence upload area, complete-task actions.
 * 44px row height for mobile outdoor use.
 */
final class ViewWorkOrder extends ViewRecord
{
    protected static string $resource = WorkOrdersResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
