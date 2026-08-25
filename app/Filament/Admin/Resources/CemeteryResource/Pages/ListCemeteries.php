<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CemeteryResource\Pages;

use App\Filament\Admin\Resources\CemeteryResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * List page for `CemeteryResource` — the `FaqArticles` ground-truth shape
 * (`Pages\ListFaqArticles`): `CreateAction` in the header, the table comes
 * from `CemeteryResource::table()`. Row-level create/delete appear as the
 * relation-manager and edit-page actions land in Tasks 3-4.
 */
final class ListCemeteries extends ListRecords
{
    protected static string $resource = CemeteryResource::class;

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
