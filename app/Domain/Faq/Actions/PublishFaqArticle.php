<?php

declare(strict_types=1);

namespace App\Domain\Faq\Actions;

use App\Domain\Faq\FaqArticlePublishState;
use App\Domain\Faq\FaqAuditActions;
use App\Domain\Faq\Models\FaqArticle;
use App\Domain\Faq\Models\FaqArticleVersion;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Publishes (or republishes) an article — the ONLY path that appends a
 * `faq_article_versions` row and the ONLY path that moves `publish_state`
 * INTO `published`. requirements.md AC5's "publish" and "version"
 * capabilities are the same call: see `2026_07_26_170200_create_faq_article
 * _versions_table.php`'s own doc block for the full "version = a
 * publish-time snapshot" reasoning this batch settled on.
 *
 * One transaction: row-lock the article, compute the next version number,
 * insert the snapshot, update the article's own state/pointer columns, and
 * write the audit record — all four commit together or not at all, mirroring
 * `App\Platform\FeatureGate\GateActivationRecorder`'s own shape (append
 * history row + update owning row + audit, one transaction).
 *
 * Works from ANY starting state (`draft` or `unpublished`) and can be called
 * again on an already-`published` article to record a fresh version after a
 * content edit (`UpdateFaqArticleContent`) — every call appends exactly one
 * new version row, never reuses or overwrites the previous one, even if the
 * content is byte-for-byte identical to the last publish.
 *
 * Audited via `Audit::record()` but not `SensitiveActions`-listed — see
 * `App\Domain\Faq\FaqAuditActions`'s own doc block.
 */
final readonly class PublishFaqArticle
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

            $previousState = $article->publish_state;
            $newVersionNumber = $article->current_version + 1;
            $now = CarbonImmutable::now();

            FaqArticleVersion::create([
                'faq_article_id' => $article->id,
                'version_number' => $newVersionNumber,
                'category_id' => $article->category_id,
                'title' => $article->title,
                'slug' => $article->slug,
                'summary' => $article->summary,
                'body' => $article->body,
                'published_at' => $now,
                'published_by' => (string) $actorReference,
            ]);

            $article->forceFill([
                'publish_state' => FaqArticlePublishState::PUBLISHED,
                'current_version' => $newVersionNumber,
                'published_at' => $now,
                'unpublished_at' => null,
            ])->save();

            Audit::record(
                action: FaqAuditActions::PUBLISHED,
                subject: new AuditSubject('faq_article', $article->id, $newVersionNumber),
                outcome: AuditOutcome::Allowed,
                actorRef: $actorReference,
                actorRole: $actorRole,
                source: $auditSource,
                reason: $reason,
                metadata: [
                    'previous_state' => $previousState,
                    'new_state' => FaqArticlePublishState::PUBLISHED,
                ],
            );

            return $article;
        });
    }
}
