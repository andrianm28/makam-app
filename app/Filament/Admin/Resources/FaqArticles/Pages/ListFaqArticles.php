<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FaqArticles\Pages;

use App\Filament\Admin\Resources\FaqArticles\FaqArticleResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListFaqArticles extends ListRecords
{
    protected static string $resource = FaqArticleResource::class;

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
