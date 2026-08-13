<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Resources\VendorListings\Pages;

use App\Filament\Vendor\Resources\VendorListings\VendorListingResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListVendorListings extends ListRecords
{
    protected static string $resource = VendorListingResource::class;

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
