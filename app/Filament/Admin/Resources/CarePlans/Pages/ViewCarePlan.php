<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarePlans\Pages;

use App\Filament\Admin\Resources\CarePlans\CarePlansResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewCarePlan extends ViewRecord
{
    protected static string $resource = CarePlansResource::class;
}
