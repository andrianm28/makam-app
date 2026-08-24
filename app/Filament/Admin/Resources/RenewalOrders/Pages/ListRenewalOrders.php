<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RenewalOrders\Pages;

use App\Filament\Admin\Resources\RenewalOrders\Actions\MarkExternalRenewalAction;
use App\Filament\Admin\Resources\RenewalOrders\RenewalOrderResource;
use Filament\Resources\Pages\ListRecords;

final class ListRenewalOrders extends ListRecords
{
    protected static string $resource = RenewalOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            MarkExternalRenewalAction::make(),
        ];
    }
}
