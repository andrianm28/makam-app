<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Resources\ServiceAreas\Pages;

use App\Filament\Vendor\Concerns\StampsCurrentVendor;
use App\Filament\Vendor\Resources\ServiceAreas\ServiceAreaResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateServiceArea extends CreateRecord
{
    use StampsCurrentVendor;

    protected static string $resource = ServiceAreaResource::class;
}
