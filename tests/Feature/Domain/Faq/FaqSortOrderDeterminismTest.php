<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Faq;

use App\Domain\Faq\Actions\CreateFaqArticleDraft;
use App\Domain\Faq\Actions\PublishFaqArticle;
use App\Domain\Faq\FaqCategoryCode;
use App\Domain\Faq\FaqPublicQuery;
use App\Domain\Faq\Models\FaqCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The `(category_id, sort_order)` uniqueness gap and its public symptom —
 * Task 2 whole-module review findings 4+5.
 *
 * ---------------------------------------------------------------------------
 * What these tests actually prove, stated plainly
 * ---------------------------------------------------------------------------
 * They do NOT prove that two genuinely concurrent `CreateFaqArticleDraft`
 * calls serialize. That is unprovable in this suite for the same structural
 * reason `tests/Feature/Outbox/OutboxPublisherClaimTest.php`'s own doc block
 * sets out at length: `RefreshDatabase` wraps each test method in an outer
 * transaction that is never committed, so a second database session cannot
 * see this test's fixture rows at all and lock contention between sessions
 * cannot be staged. No test anywhere in this repository proves cross-session
 * lock behaviour; that precedent is followed here rather than faked.
 *
 * What IS proved, at the strength each test's own name claims:
 *   - the category lookup really does emit `FOR UPDATE` — the lock is
 *     acquired, so a serialization point exists (query-log assertion,
 *     PostgreSQL only; SQLite's grammar compiles `lockForUpdate()` to an
 *     empty string, so the check is meaningless there and is skipped);
 *   - sequential creates in one category still produce distinct, sequential
 *     `sort_order` values — a weaker, single-session regression guard that
 *     the lock did not break the ordinary write path;
 *   - `FaqPublicQuery`'s read order is deterministic even when two rows DO
 *     share a `sort_order`, via the `orderBy('id')` tiebreaker. This is the
 *     one that matters for the public surface: it holds regardless of
 *     whether a collision was ever prevented.
 *
 * The durable fix — a `(category_id, sort_order)` unique index — is a
 * migration against a table already deployed to dev.makam.co.id, ledgered
 * pending human review per AGENTS.md §Infrastructure-agent execution.
 */
final class FaqSortOrderDeterminismTest extends TestCase
{
    use RefreshDatabase;

    private function categoryId(): int
    {
        return FaqCategory::findByCode(FaqCategoryCode::PERPANJANGAN)->id;
    }

    public function test_the_category_row_is_locked_before_the_max_sort_order_read(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                "SQLite's query grammar compiles lockForUpdate() to an empty string, so this "
                .'assertion cannot distinguish a locked read from an unlocked one. Run with '
                .'DB_CONNECTION=pgsql, as CI does.'
            );
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        (new CreateFaqArticleDraft)(
            categoryId: $this->categoryId(),
            title: 'Judul uji kunci kategori',
            slug: 'uji-kunci-kategori',
            summary: 'Ringkasan.',
            body: 'Isi.',
            actorReference: 1,
        );

        $categoryReads = array_filter(
            DB::getQueryLog(),
            fn (array $entry) => str_contains($entry['query'], 'faq_categories')
                && str_starts_with(strtolower(trim($entry['query'])), 'select')
        );

        DB::disableQueryLog();

        $this->assertNotEmpty($categoryReads, 'CreateFaqArticleDraft did not read faq_categories at all.');

        $locked = array_filter(
            $categoryReads,
            fn (array $entry) => str_contains(strtolower($entry['query']), 'for update')
        );

        $this->assertNotEmpty(
            $locked,
            'The faq_categories lookup in CreateFaqArticleDraft emitted no FOR UPDATE, so two '
            .'concurrent creates in the same category have no serialization point before the '
            .'max(sort_order) read.'
        );
    }

    public function test_two_sequential_creates_in_the_same_category_get_distinct_sequential_sort_orders(): void
    {
        // Single-session only — see this class's doc block. This is a
        // regression guard that adding the lock did not break the ordinary
        // write path, not a concurrency proof.
        $categoryId = $this->categoryId();
        $create = new CreateFaqArticleDraft;

        $first = $create($categoryId, 'Judul A', 'uji-urutan-a', 'Ringkasan A.', 'Isi A.', actorReference: 1);
        $second = $create($categoryId, 'Judul B', 'uji-urutan-b', 'Ringkasan B.', 'Isi B.', actorReference: 1);

        $this->assertNotSame($first->sort_order, $second->sort_order);
        $this->assertSame($first->sort_order + 1, $second->sort_order);
    }

    public function test_public_read_order_is_deterministic_when_two_articles_share_a_sort_order(): void
    {
        $categoryId = $this->categoryId();
        $create = new CreateFaqArticleDraft;
        $publish = new PublishFaqArticle;

        $lower = $publish(
            $create($categoryId, 'Judul seri satu', 'uji-seri-satu', 'Ringkasan satu.', 'Isi satu zzkolisi.', actorReference: 1),
            actorReference: 1,
        );
        $higher = $publish(
            $create($categoryId, 'Judul seri dua', 'uji-seri-dua', 'Ringkasan dua.', 'Isi dua zzkolisi.', actorReference: 1),
            actorReference: 1,
        );

        // Force the collision the missing unique index permits. Written via
        // forceFill()->save() rather than an Action because no Action can
        // produce this state deliberately — that is the point of the finding.
        $collidingSortOrder = $lower->sort_order;
        $higher->forceFill(['sort_order' => $collidingSortOrder])->save();

        $this->assertSame($collidingSortOrder, $lower->refresh()->sort_order);
        $this->assertSame($collidingSortOrder, $higher->refresh()->sort_order);
        $this->assertLessThan($higher->id, $lower->id);

        $expected = [$lower->id, $higher->id];

        $inCategory = FaqPublicQuery::articlesInCategory($categoryId)
            ->whereIn('id', $expected)
            ->pluck('id')
            ->all();
        $this->assertSame($expected, $inCategory);

        $searched = FaqPublicQuery::search('zzkolisi')
            ->whereIn('id', $expected)
            ->pluck('id')
            ->all();
        $this->assertSame($expected, $searched);

        $all = FaqPublicQuery::allPublished()
            ->whereIn('id', $expected)
            ->pluck('id')
            ->all();
        $this->assertSame($expected, $all);
    }
}
