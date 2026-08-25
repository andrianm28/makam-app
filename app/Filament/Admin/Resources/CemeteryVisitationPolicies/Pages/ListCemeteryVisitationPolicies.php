<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CemeteryVisitationPolicies\Pages;

use App\Filament\Admin\Resources\CemeteryVisitationPolicies\CemeteryVisitationPolicyResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

final class ListCemeteryVisitationPolicies extends ListRecords
{
    protected static string $resource = CemeteryVisitationPolicyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
