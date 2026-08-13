<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Resources\VendorAvailabilities\Pages;

use App\Filament\Vendor\Concerns\GuardsCurrentVendorOnEdit;
use App\Filament\Vendor\Resources\VendorAvailabilities\VendorAvailabilityResource;
use Filament\Resources\Pages\EditRecord;

/**
 * The read side of edit is closed by
 * `VendorAvailabilityResource::getEloquentQuery()`, which is vendor-scoped: an
 * actor can only ever reach a calendar day already inside their own grants,
 * and another vendor's day is a 404.
 *
 * The write side is guarded by `GuardsCurrentVendorOnEdit`. `vendor_id` is
 * `$fillable`, so a forged `vendor_id` for a vendor the actor does NOT hold
 * would silently move the calendar day into that vendor's calendar. Filament's
 * Select already refuses such a value at the form layer — in v5.7.3
 * `Select::getInValidationRuleValues()` derives the `in:` values from
 * `options()`, which are grant-limited — and `GuardsCurrentVendorOnEdit`
 * re-checks the submitted `vendor_id` against the grant table before the save,
 * the same seam `StampsCurrentVendor` uses on create, so the edit write is
 * correct by construction regardless of the form layer. A move between two
 * vendors the actor already holds is inside its scope and is preserved.
 */
final class EditVendorAvailability extends EditRecord
{
    use GuardsCurrentVendorOnEdit;

    protected static string $resource = VendorAvailabilityResource::class;
}
