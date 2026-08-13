<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Resources\ServiceAreas\Pages;

use App\Filament\Vendor\Resources\ServiceAreas\ServiceAreaResource;
use Filament\Resources\Pages\EditRecord;

/**
 * No `vendor_id` handling here, deliberately. The record was resolved through
 * `ServiceAreaResource::getEloquentQuery()`, which is vendor-scoped, so an
 * actor can only ever reach a service area already inside their own scope.
 * `VendorPicker` renders on edit too, but only for multi-grant actors, and it
 * offers granted vendors only. Filament's Select derives an `in:` validation
 * rule from those options, so a forged `vendor_id` for a vendor the actor does
 * NOT hold fails validation and no write occurs — moving an area between two
 * vendors the actor already holds is inside their scope either way.
 */
final class EditServiceArea extends EditRecord
{
    protected static string $resource = ServiceAreaResource::class;
}
