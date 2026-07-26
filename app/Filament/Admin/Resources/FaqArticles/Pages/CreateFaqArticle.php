<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FaqArticles\Pages;

use App\Domain\Faq\Actions\CreateFaqArticleDraft;
use App\Filament\Admin\Resources\FaqArticles\FaqArticleResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Overrides `handleRecordCreation()` (verified against the real installed
 * `Filament\Resources\Pages\CreateRecord::handleRecordCreation(array
 * $data): Model` hook, `filament/filament` v5.7.3) so submission calls
 * `App\Domain\Faq\Actions\CreateFaqArticleDraft` instead of Filament's
 * default `new (Model)($data); $record->save();` — per `app/Domain/
 * README.md`'s binding rule, this Resource must never write `FaqArticle`
 * directly.
 */
final class CreateFaqArticle extends CreateRecord
{
    protected static string $resource = FaqArticleResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return (new CreateFaqArticleDraft)(
            categoryId: (int) $data['category_id'],
            title: (string) $data['title'],
            slug: (string) $data['slug'],
            summary: (string) $data['summary'],
            body: (string) $data['body'],
            actorReference: Auth::id() ?? 0,
            sortOrder: filled($data['sort_order'] ?? null) ? (int) $data['sort_order'] : null,
            relatedArticleIds: array_map('intval', $data['related_article_ids'] ?? []),
        );
    }
}
