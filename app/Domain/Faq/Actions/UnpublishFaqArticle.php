<?php

declare(strict_types=1);

namespace App\Domain\Faq\Actions;

use App\Domain\Faq\FaqArticlePublishState;
use App\Domain\Faq\FaqAuditActions;
use App\Domain\Faq\Models\FaqArticle;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Pulls a `published` article from public visibility. Moves `publish_state`
 * to `unpublished` — deliberately NOT back to `draft` — see
 * `App\Domain\Faq\FaqArticlePublishState`'s own doc block for why that
 * distinction matters.
 *
 * Does NOT touch `current_version`, `title`/`slug`/`summary`/`body`, or any
 * `faq_article_versions` row — unpublishing is a pure visibility change, not
 * a content change, so nothing about "what was published" needs to be
 * recorded or altered. `published_at` (the most-recent-publish timestamp) is
 * also left untouched, so "when was this last live" remains answerable even
 * after it is pulled; `unpublished_at` is set to record when the pull
 * happened.
 *
 * Only callable on a currently-`published` article — unpublishing a `draft`
 * (which was never public) or an already-`unpublished` article is a caller
 * error, not a state this method silently no-ops through, since either case
 * means the caller's own understanding of the article's state is stale.
 *
 * Audited via `Audit::record()` but not `SensitiveActions`-listed — see
 * `App\Domain\Faq\FaqAuditActions`'s own doc block.
 */
final readonly class UnpublishFaqArticle
{
    public function __invoke(
        FaqArticle $article,
        int|string $actorReference,
        string $actorRole = 'admin',
        AuditSource $auditSource = AuditSource::Panel,
        ?string $reason = null,
    ): FaqArticle {
        return DB::transaction(function () use ($article, $actorReference, $actorRole, $auditSource, $reason): FaqArticle {
            /** @var FaqArticle $article */
            $article = FaqArticle::query()->lockForUpdate()->findOrFail($article->id);

            if ($article->publish_state !== FaqArticlePublishState::PUBLISHED) {
                throw new InvalidArgumentException(
                    "FAQ article [{$article->id}] cannot be unpublished from state [{$article->publish_state}]; only a [".
                    FaqArticlePublishState::PUBLISHED.'] article can be unpublished.'
                );
            }

            $previousState = $article->publish_state;

            $article->forceFill([
                'publish_state' => FaqArticlePublishState::UNPUBLISHED,
                'unpublished_at' => CarbonImmutable::now(),
            ])->save();

            Audit::record(
                action: FaqAuditActions::UNPUBLISHED,
                subject: new AuditSubject('faq_article', $article->id, $article->current_version),
                outcome: AuditOutcome::Allowed,
                actorRef: $actorReference,
                actorRole: $actorRole,
                source: $auditSource,
                reason: $reason,
                metadata: [
                    'previous_state' => $previousState,
                    'new_state' => FaqArticlePublishState::UNPUBLISHED,
                ],
            );

            return $article;
        });
    }
}
