<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Resources\WorkOrders\Pages;

use App\Domain\VendorFulfillment\Models\WorkOrder;
use App\Filament\Vendor\Resources\WorkOrders\Actions\UploadEvidenceAction;
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
     * `$record` is captured here (not injected into the action closure) to
     * match `App\Filament\Vendor\Resources\VendorOrders\Pages
     * \EditVendorOrder::getHeaderActions()`'s own convention for this panel
     * — see that class and `UploadEvidenceAction`'s doc blocks.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        /** @var WorkOrder $record */
        $record = $this->getRecord();

        return [
            Action::make('unggahBukti')
                ->label('Unggah Bukti')
                ->icon(Heroicon::OutlinedCamera)
                ->color('primary')
                ->schema(UploadEvidenceAction::schema())
                ->action(fn (array $data) => UploadEvidenceAction::run($record, $data)),
        ];
    }
}
