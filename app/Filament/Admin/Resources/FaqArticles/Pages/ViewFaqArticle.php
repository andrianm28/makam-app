<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FaqArticles\Pages;

use App\Filament\Admin\Resources\FaqArticles\FaqArticleResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * The "preview" page — renders `Schemas\FaqArticleInfolist` (a read-only
 * view of the article's current row, reachable regardless of
 * `publish_state`). See that schema class's own doc block for how this
 * satisfies requirements.md AC5's "preview" capability.
 */
final class ViewFaqArticle extends ViewRecord
{
    protected static string $resource = FaqArticleResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
