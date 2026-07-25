<?php

declare(strict_types=1);

namespace App\Domain\Faq\Actions;

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
 * Edits an existing article's current content — title, slug, summary, body,
 * category, sort order, and related-article links. The ONLY way this
 * module's content columns are edited; never `$article->update(...)`
 * directly from outside this class.
 *
 * ---------------------------------------------------------------------------
 * PUT-style full replacement, not PATCH-style partial update — a
 * deliberate, documented choice
 * ---------------------------------------------------------------------------
 * Every editable field is required on every call, mirroring
 * `CreateFaqArticleDraft`'s own parameter shape exactly, rather than
 * accepting a partial `array $attributes` bag where a missing key could
 * mean either "leave unchanged" or "clear to null/empty" depending on how a
 * caller built it. A caller building an edit form already has the article's
 * current values to prefill the form with, so resupplying all of them is no
 * real burden and removes an entire class of ambiguity bugs.
 *
 * ---------------------------------------------------------------------------
 * Runs regardless of `publish_state` — content edits go live immediately if
 * the article is already `published`
 * ---------------------------------------------------------------------------
 * See `FaqArticle`'s own class-level doc block ("current row = current
 * content"): this module keeps no separate draft-buffer-vs-live-copy. An
 * edit to an already-`published` article is visible to the public
 * immediately through the SAME row — it does not wait for a fresh
 * `PublishFaqArticle` call. `PublishFaqArticle`/`faq_article_versions`
 * exist to record HISTORY of what went live and when, not to gate whether
 * an edit takes effect.
 *
 * Audited via `Audit::record()` but not `SensitiveActions`-listed — see
 * `App\Domain\Faq\FaqAuditActions`'s own doc block.
 */
final readonly class UpdateFaqArticleContent
{
    /**
     * @param  list<int>  $relatedArticleIds  Full replacement set —
     *                                        `BelongsToMany::sync()` semantics, not an incremental add/remove list.
     *                                        The article's own id is filtered out defensively (an article cannot
     *                                        be related to itself).
     */
    public function __invoke(
        FaqArticle $article,
        int $categoryId,
        string $title,
        string $slug,
        string $summary,
        string $body,
        int $sortOrder,
        int|string $actorReference,
        string $actorRole = 'admin',
        AuditSource $auditSource = AuditSource::Panel,
        array $relatedArticleIds = [],
        ?string $reason = null,
    ): FaqArticle {
        self::assertNonBlank($title, 'title');
        self::assertNonBlank($slug, 'slug');
        self::assertNonBlank($summary, 'summary');
        self::assertNonBlank($body, 'body');

        return DB::transaction(function () use (
            $article,
            $categoryId,
            $title,
            $slug,
            $summary,
            $body,
            $sortOrder,
            $actorReference,
            $actorRole,
            $auditSource,
            $relatedArticleIds,
            $reason,
        ): FaqArticle {
            FaqCategory::query()->findOrFail($categoryId);

            $article = FaqArticle::query()->lockForUpdate()->findOrFail($article->id);

            $article->forceFill([
                'category_id' => $categoryId,
                'title' => trim($title),
                'slug' => trim($slug),
                'summary' => trim($summary),
                'body' => $body,
                'sort_order' => $sortOrder,
            ])->save();

            $relatedIds = array_values(array_unique(array_diff($relatedArticleIds, [$article->id])));
            $article->relatedArticles()->sync($relatedIds);

            Audit::record(
                action: FaqAuditActions::UPDATED,
                subject: new AuditSubject('faq_article', $article->id, $article->current_version),
                outcome: AuditOutcome::Allowed,
                actorRef: $actorReference,
                actorRole: $actorRole,
                source: $auditSource,
                reason: $reason,
                metadata: ['note' => 'Content edited.'],
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
