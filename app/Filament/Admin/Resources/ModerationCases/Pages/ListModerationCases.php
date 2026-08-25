<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ModerationCases\Pages;

use App\Filament\Admin\Resources\ModerationCases\ModerationCaseResource;
use Filament\Resources\Pages\ListRecords;

final class ListModerationCases extends ListRecords
{
    protected static string $resource = ModerationCaseResource::class;
}
