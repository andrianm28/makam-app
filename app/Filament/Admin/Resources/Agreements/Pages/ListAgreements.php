<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Agreements\Pages;

use App\Filament\Admin\Resources\Agreements\AgreementsResource;
use Filament\Resources\Pages\ListRecords;

final class ListAgreements extends ListRecords
{
    protected static string $resource = AgreementsResource::class;
}
