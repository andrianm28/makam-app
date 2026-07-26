<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FaqArticles\Pages;

use App\Domain\Faq\Actions\UpdateFaqArticleContent;
use App\Domain\Faq\Models\FaqArticle;
use App\Filament\Admin\Resources\FaqArticles\FaqArticleResource;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Overrides `handleRecordUpdate()` (verified against the real installed
 * `Filament\Resources\Pages\EditRecord::handleRecordUpdate(Model $record,
 * array $data): Model` hook, `filament/filament` v5.7.3) so submission
 * calls `App\Domain\Faq\Actions\UpdateFaqArticleContent` instead of
 * Filament's default `$record->update($data)` — per `app/Domain/
 * README.md`'s binding rule.
 *
 * Also overrides `mutateFormDataBeforeFill()` to seed the form's
 * `related_article_ids` field, which has no matching `FaqArticle` column
 * (`$record->attributesToArray()`, what the base class fills from, never
 * includes it) — it is read from the `relatedArticles()` pivot instead.
 */
final class EditFaqArticle extends EditRecord
{
    protected static string $resource = FaqArticleResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        // No `DeleteAction` -- deliberately. There is no `DeleteFaqArticle`
        // Domain Action (Batch 4.1's five Actions are create/update/
        // publish/unpublish/reorder only; deletion was never part of AC5's
        // capability list), and this Resource must never fall back to
        // Filament's default `$record->delete()` for an action the Domain
        // layer does not itself support.
        return [
            ViewAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var FaqArticle $record */
        $record = $this->getRecord();

        return [
            ...$data,
            'related_article_ids' => $record->relatedArticles()->pluck('id')->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var FaqArticle $record */
        return (new UpdateFaqArticleContent)(
            article: $record,
            categoryId: (int) $data['category_id'],
            title: (string) $data['title'],
            slug: (string) $data['slug'],
            summary: (string) $data['summary'],
            body: (string) $data['body'],
            // `UpdateFaqArticleContent` requires a definite int (unlike
            // `CreateFaqArticleDraft`'s nullable-means-auto-append
            // `$sortOrder`). The shared `FaqArticleForm` schema leaves this
            // field optional so create's auto-append helper text holds; on
            // edit the field is pre-filled from the record itself
            // (`mutateFormDataBeforeFill()` is not needed for this one --
            // the base class already fills any column matching the
            // record's own attribute name), so it is blank only if an
            // admin deliberately clears it. Falling back to the record's
            // current `sort_order` in that case avoids silently zeroing it.
            sortOrder: filled($data['sort_order'] ?? null) ? (int) $data['sort_order'] : $record->sort_order,
            actorReference: Auth::id() ?? 0,
            relatedArticleIds: array_map('intval', $data['related_article_ids'] ?? []),
        );
    }
}
