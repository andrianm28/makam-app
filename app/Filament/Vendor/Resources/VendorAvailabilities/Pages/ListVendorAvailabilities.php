<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Resources\VendorAvailabilities\Pages;

use App\Filament\Vendor\Resources\VendorAvailabilities\VendorAvailabilityResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListVendorAvailabilities extends ListRecords
{
    protected static string $resource = VendorAvailabilityResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
