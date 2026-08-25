<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Resources\ServiceAreas\Pages;

use App\Filament\Vendor\Resources\ServiceAreas\ServiceAreaResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListServiceAreas extends ListRecords
{
    protected static string $resource = ServiceAreaResource::class;

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
