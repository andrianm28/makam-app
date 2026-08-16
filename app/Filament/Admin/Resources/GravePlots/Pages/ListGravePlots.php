<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\GravePlots\Pages;

use App\Filament\Admin\Resources\GravePlots\GravePlotsResource;
use Filament\Resources\Pages\ListRecords;

final class ListGravePlots extends ListRecords
{
    protected static string $resource = GravePlotsResource::class;
}
