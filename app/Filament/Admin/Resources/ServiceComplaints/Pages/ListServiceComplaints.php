<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ServiceComplaints\Pages;

use App\Filament\Admin\Resources\ServiceComplaints\ServiceComplaintsResource;
use Filament\Resources\Pages\ListRecords;

final class ListServiceComplaints extends ListRecords
{
    protected static string $resource = ServiceComplaintsResource::class;
}
