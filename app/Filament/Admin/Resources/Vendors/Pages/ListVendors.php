<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Vendors\Pages;

use App\Filament\Admin\Resources\Vendors\VendorResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * List page for `VendorResource` — the `CemeteryResource` ground-truth
 * shape (`Pages\ListCemeteries`): `CreateAction` in the header, the table
 * comes from `VendorResource::table()`.
 */
final class ListVendors extends ListRecords
{
    protected static string $resource = VendorResource::class;

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
