<?php

declare(strict_types=1);

namespace App\Domain\Faq\Actions;

use App\Domain\Faq\FaqArticlePublishState;
use App\Domain\Faq\FaqAuditActions;
use App\Domain\Faq\Models\FaqArticle;
use App\Domain\Faq\Models\FaqCategory;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Creates a new `faq_articles` row in `draft` state — the ONLY way this
 * module creates an article. Never `FaqArticle::create()` directly from a
 * Filament Resource/Livewire component/controller (`app/Domain/README.md`:
 * "Domain logic lives here ... not in controllers, Livewire components, or
 * Filament Resources").
 *
 * Writes zero `faq_article_versions` rows — a draft has never been public,
 * so there is nothing to snapshot yet (see `PublishFaqArticle`'s own doc
 * block for the versioning-timing decision this mirrors).
 *
 * Audited via `Audit::record()` but not `SensitiveActions`-listed — see
 * `App\Domain\Faq\FaqAuditActions`'s own doc block for the full reasoning.
 */
final readonly class CreateFaqArticleDraft
{
    /**
     * @param  list<int>  $relatedArticleIds  Existing `faq_articles.id`
     *                                        values. The new article's own (not-yet-known) id is filtered out
     *                                        defensively even though it cannot legitimately appear here yet.
     */
    public function __invoke(
        int $categoryId,
        string $title,
        string $slug,
        string $summary,
        string $body,
        int|string $actorReference,
        string $actorRole = 'admin',
        AuditSource $auditSource = AuditSource::Panel,
        ?int $sortOrder = null,
        array $relatedArticleIds = [],
        ?string $reason = null,
    ): FaqArticle {
        self::assertNonBlank($title, 'title');
        self::assertNonBlank($slug, 'slug');
        self::assertNonBlank($summary, 'summary');
        self::assertNonBlank($body, 'body');

        return DB::transaction(function () use (
            $categoryId,
            $title,
            $slug,
            $summary,
            $body,
            $actorReference,
            $actorRole,
            $auditSource,
            $sortOrder,
            $relatedArticleIds,
            $reason,
        ): FaqArticle {
            // findOrFail: a category id that does not exist is a caller
            // error, not a state this module tolerates silently.
            FaqCategory::query()->findOrFail($categoryId);

            $resolvedSortOrder = $sortOrder ?? (int) (
                FaqArticle::query()->where('category_id', $categoryId)->max('sort_order') ?? 0
            ) + 1;

            $article = FaqArticle::create([
                'category_id' => $categoryId,
                'title' => trim($title),
                'slug' => trim($slug),
                'summary' => trim($summary),
                'body' => $body,
                'publish_state' => FaqArticlePublishState::DRAFT,
                'sort_order' => $resolvedSortOrder,
                'current_version' => 0,
            ]);

            $relatedIds = array_values(array_unique(array_diff($relatedArticleIds, [$article->id])));
            if ($relatedIds !== []) {
                $article->relatedArticles()->sync($relatedIds);
            }

            Audit::record(
                action: FaqAuditActions::CREATED,
                subject: new AuditSubject('faq_article', $article->id, $article->current_version),
                outcome: AuditOutcome::Allowed,
                actorRef: $actorReference,
                actorRole: $actorRole,
                source: $auditSource,
                reason: $reason,
                metadata: ['new_state' => $article->publish_state],
            );

            return $article->refresh();
        });
    }

    private static function assertNonBlank(string $value, string $field): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException("FAQ article [{$field}] must not be blank.");
        }
    }
}
