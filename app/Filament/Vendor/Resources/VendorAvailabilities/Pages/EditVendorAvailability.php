<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Resources\VendorAvailabilities\Pages;

use App\Filament\Vendor\Resources\VendorAvailabilities\VendorAvailabilityResource;
use Filament\Resources\Pages\EditRecord;

/**
 * No `vendor_id` handling here, deliberately. The record was resolved through
 * `VendorAvailabilityResource::getEloquentQuery()`, which is vendor-scoped, so
 * an actor can only ever reach a calendar day already inside their own scope.
 *
 * `VendorPicker` renders on edit too, but only for an actor holding more than
 * one vendor grant, and its options are limited to granted vendors. Filament's
 * Select derives an `in:` validation rule from those options, so a forged
 * `vendor_id` for a vendor the actor does NOT hold fails validation and no
 * write occurs — the move is confined to the actor's own grants either way.
 * There is no second gate here for the same reason there is none on the header
 * actions: a multi-grant actor moving a record between two vendors it already
 * holds is inside its scope by construction.
 */
final class EditVendorAvailability extends EditRecord
{
    protected static string $resource = VendorAvailabilityResource::class;
}
