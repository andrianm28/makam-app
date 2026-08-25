<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Schemas;

use App\Domain\Marketplace\Access\CurrentVendorScope;
use Filament\Forms\Components\Select;

/**
 * The `vendor_id` field shared by every `/vendor` create form.
 *
 * It only renders for an actor holding more than one vendor grant, because
 * only then is "which vendor does this belong to" a real question. The common
 * single-vendor actor never sees it and `StampsCurrentVendor` fills the value
 * from their sole grant.
 *
 * The options list is limited to granted vendors, but that limit is presentation
 * only — `StampsCurrentVendor::mutateFormDataBeforeCreate()` re-checks the
 * submitted value against the grant table before it reaches the database. See
 * that trait's doc block for why the rendered options cannot be the control.
 */
final class VendorPicker
{
    public static function make(): Select
    {
        return Select::make('vendor_id')
            ->label('Vendor')
            ->options(fn (): array => app(CurrentVendorScope::class)->grantedVendorOptions())
            ->default(fn (): ?string => app(CurrentVendorScope::class)->defaultVendorId())
            ->visible(fn (): bool => count(app(CurrentVendorScope::class)->grantedVendorIds()) > 1)
            ->required(fn (): bool => count(app(CurrentVendorScope::class)->grantedVendorIds()) > 1)
            ->native(false)
            ->searchable();
    }
}
