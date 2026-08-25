<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Resources\VendorAvailabilities\Pages;

use App\Filament\Vendor\Concerns\StampsCurrentVendor;
use App\Filament\Vendor\Resources\VendorAvailabilities\VendorAvailabilityResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateVendorAvailability extends CreateRecord
{
    use StampsCurrentVendor;

    protected static string $resource = VendorAvailabilityResource::class;
}
