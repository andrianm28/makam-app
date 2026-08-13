<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Resources\VendorListings\Pages;

use App\Filament\Vendor\Concerns\StampsCurrentVendor;
use App\Filament\Vendor\Resources\VendorListings\VendorListingResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateVendorListing extends CreateRecord
{
    use StampsCurrentVendor;

    protected static string $resource = VendorListingResource::class;
}
