<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\WorkOrders\Pages;

use App\Domain\VendorFulfillment\Models\WorkOrder;
use App\Filament\Admin\Resources\WorkOrders\Actions\ReplaceVendorAction;
use App\Filament\Admin\Resources\WorkOrders\WorkOrdersResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

/**
 * View page for the admin `WorkOrdersResource` — the 'Ganti Vendor' header
 * action (AC7). `$record` is captured here rather than injected into the
 * action closure, matching `App\Filament\Vendor\Resources\VendorOrders
 * \Pages\EditVendorOrder` and `App\Filament\Vendor\Resources\WorkOrders
 * \Pages\ViewWorkOrder`'s own convention for a record-bound header action.
 */
final class ViewWorkOrder extends ViewRecord
{
    protected static string $resource = WorkOrdersResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        /** @var WorkOrder $record */
        $record = $this->getRecord();

        return [
            Action::make('gantiVendor')
                ->label('Ganti Vendor')
                ->icon(Heroicon::OutlinedArrowsRightLeft)
                ->color('warning')
                ->authorize(fn (): bool => ReplaceVendorAction::isAuthorized())
                ->modalHeading('Ganti vendor pesanan kerja ini?')
                ->schema(ReplaceVendorAction::schema($record))
                ->action(fn (array $data) => ReplaceVendorAction::run($record, $data)),
        ];
    }
}
