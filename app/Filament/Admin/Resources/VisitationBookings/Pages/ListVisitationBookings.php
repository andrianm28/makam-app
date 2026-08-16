<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VisitationBookings\Pages;

use App\Filament\Admin\Resources\VisitationBookings\VisitationBookingsResource;
use Filament\Resources\Pages\ListRecords;

final class ListVisitationBookings extends ListRecords
{
    protected static string $resource = VisitationBookingsResource::class;
}
