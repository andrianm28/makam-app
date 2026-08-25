<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\LaunchCities\Pages;

use App\Filament\Admin\Resources\LaunchCities\LaunchCityResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * List page for `LaunchCityResource` — the `CemeteryResource` ground-truth
 * shape (`Pages\ListCemeteries`): `CreateAction` in the header, the table
 * comes from `LaunchCityResource::table()`.
 */
final class ListLaunchCities extends ListRecords
{
    protected static string $resource = LaunchCityResource::class;

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
