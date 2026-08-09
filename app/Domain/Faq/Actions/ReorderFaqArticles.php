<?php

declare(strict_types=1);

namespace App\Domain\Faq\Actions;

use App\Domain\Faq\FaqAuditActions;
use App\Domain\Faq\Models\FaqArticle;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Reorders every article in one category atomically. The ONLY way
 * `faq_articles.sort_order` is written by more than one row at a time.
 *
 * ---------------------------------------------------------------------------
 * Approach: accept the FULL ordered id list for one category, assign
 * sequential 1..N — not a per-article "move up/down"/"insert before"
 * primitive
 * ---------------------------------------------------------------------------
 * A caller (a future admin drag-and-drop UI) always knows the complete
 * resulting order after a reorder gesture — that is what "drag article X to
 * position 3" already computes client-side. Accepting that whole list here
 * and reassigning `1..N` by array position:
 *   - leaves this call's own output gapless and duplicate-free (every id in
 *     the list gets exactly one sequential integer);
 *   - needs no separate "insert/shift everything after this point" logic
 *     that a partial-move primitive would require and could get wrong under
 *     concurrent edits;
 *   - is a single, easily-testable operation: "these ids, in this order, in,
 *     these ids in that exact order out."
 *
 * ---------------------------------------------------------------------------
 * All ids must belong to exactly one category — validated, not assumed
 * ---------------------------------------------------------------------------
 * `sort_order` is only ever compared within a single category's listing
 * (`FaqArticle::scopeInCategory()`), so silently accepting a cross-category
 * id list would let a caller corrupt two categories' orderings at once
 * without any visible error. Rejected outright instead.
 *
 * ---------------------------------------------------------------------------
 * What is actually guaranteed about `sort_order` uniqueness — CORRECTED
 * 09 Aug 2026 (retrofit-faq fix wave)
 * ---------------------------------------------------------------------------
 * The bullet above used to end "…nothing else touches `sort_order` for this
 * category between calls", which is false: `CreateFaqArticleDraft` and
 * `UpdateFaqArticleContent` both write `sort_order` too. What this Action
 * really guarantees is reorder-vs-reorder serialization — it row-locks every
 * article in its own id list for the duration of the transaction, so two
 * concurrent reorders over an overlapping id set cannot interleave their
 * writes.
 *
 * What it does NOT guarantee: serialization against a concurrent create or
 * content edit. `CreateFaqArticleDraft` now takes a lock on the category row
 * before its `max('sort_order')` read, which narrows that race to the window
 * this Action's own article-level locks do not cover — it does not eliminate
 * it, because the two Actions lock different rows. The durable fix is a
 * `(category_id, sort_order)` unique index; that is a migration against a
 * table already deployed to dev.makam.co.id, so it is ledgered pending human
 * review per AGENTS.md §Infrastructure-agent execution rather than applied
 * here. Public read order is meanwhile made deterministic regardless, by the
 * `orderBy('id')` tiebreaker in `FaqPublicQuery`.
 *
 * Audited via `Audit::record()` but not `SensitiveActions`-listed — see
 * `App\Domain\Faq\FaqAuditActions`'s own doc block.
 */
final readonly class ReorderFaqArticles
{
    /**
     * @param  list<int>  $orderedArticleIds  Every article id in the target
     *                                        category, in the desired final display order. Must be the COMPLETE
     *                                        set for that category — an id belonging to the category but omitted
     *                                        from this list is left with whatever `sort_order` it already had,
     *                                        which a caller should treat as a bug in its own list-building, not a
     *                                        supported "partial reorder" feature.
     */
    public function __invoke(
        array $orderedArticleIds,
        int|string $actorReference,
        string $actorRole = 'admin',
        AuditSource $auditSource = AuditSource::Panel,
        ?string $reason = null,
    ): void {
        if ($orderedArticleIds === []) {
            throw new InvalidArgumentException('Reorder requires at least one article id.');
        }

        if (count($orderedArticleIds) !== count(array_unique($orderedArticleIds))) {
            throw new InvalidArgumentException('Reorder list contains duplicate article ids.');
        }

        DB::transaction(function () use ($orderedArticleIds, $actorReference, $actorRole, $auditSource, $reason): void {
            $articles = FaqArticle::query()
                ->whereIn('id', $orderedArticleIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($articles->count() !== count($orderedArticleIds)) {
                throw new InvalidArgumentException('Reorder list references an article id that does not exist.');
            }

            $categoryIds = $articles->pluck('category_id')->unique();
            if ($categoryIds->count() > 1) {
                throw new InvalidArgumentException('Reorder list must contain articles from exactly one category.');
            }

            foreach (array_values($orderedArticleIds) as $index => $articleId) {
                $articles[$articleId]->forceFill(['sort_order' => $index + 1])->save();
            }

            Audit::record(
                action: FaqAuditActions::REORDERED,
                subject: new AuditSubject('faq_category', $categoryIds->first()),
                outcome: AuditOutcome::Allowed,
                actorRef: $actorReference,
                actorRole: $actorRole,
                source: $auditSource,
                reason: $reason,
                metadata: ['note' => 'Reordered '.count($orderedArticleIds).' article(s).'],
            );
        });
    }
}
