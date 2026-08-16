<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MemorialProfiles\Pages;

use App\Filament\Admin\Resources\MemorialProfiles\MemorialProfileResource;
use Filament\Resources\Pages\ListRecords;

final class ListMemorialProfiles extends ListRecords
{
    protected static string $resource = MemorialProfileResource::class;
}
