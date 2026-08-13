<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Resources\VendorOrders\Pages;

use App\Filament\Vendor\Resources\VendorOrders\VendorOrderResource;
use Filament\Resources\Pages\ListRecords;

/**
 * No `getHeaderActions()` override, deliberately — unlike
 * `Pages\ListVendorListings` this page offers no `CreateAction`. Orders arrive
 * from customer checkout; `VendorOrderResource` registers no create route for
 * an action to point at, so offering the button would render a dead control.
 */
final class ListVendorOrders extends ListRecords
{
    protected static string $resource = VendorOrderResource::class;
}
