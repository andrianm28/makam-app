<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RenewalOrders\Pages;

use App\Filament\Admin\Resources\RenewalOrders\Actions\ExpireRenewalAction;
use App\Filament\Admin\Resources\RenewalOrders\Actions\RecordExternalRenewalPaymentAction;
use App\Filament\Admin\Resources\RenewalOrders\RenewalOrderResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

final class ViewRenewalOrder extends ViewRecord
{
    protected static string $resource = RenewalOrderResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            RecordExternalRenewalPaymentAction::make($this->record),
            ExpireRenewalAction::make($this->record),
        ];
    }
}
