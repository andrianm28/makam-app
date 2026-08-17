<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarePlans\Pages;

use App\Filament\Admin\Resources\CarePlans\CarePlansResource;
use Filament\Resources\Pages\ListRecords;

final class ListCarePlans extends ListRecords
{
    protected static string $resource = CarePlansResource::class;
}
