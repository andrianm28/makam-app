<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Faq;

use App\Domain\Faq\Actions\CreateFaqArticleDraft;
use App\Domain\Faq\FaqAuditActions;
use App\Domain\Faq\FaqCategoryCode;
use App\Domain\Faq\Models\FaqArticle;
use App\Domain\Faq\Models\FaqCategory;
use App\Filament\Admin\Resources\FaqArticles\Pages\ListFaqArticles;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Proves the list table's "Naikkan urutan" (move up) / "Turunkan urutan"
 * (move down) row actions are wired to `App\Domain\Faq\Actions\
 * ReorderFaqArticles` with a COMPLETE, correctly-reordered id list for the
 * record's own category — not a bare `sort_order` column edit. See
 * `Tables\FaqArticlesTable`'s own doc block for why this batch built these
 * explicit actions instead of wiring Filament's native `Table::
 * reorderable()` drag feature (traced against the real installed
 * `filament/filament` v5.7.3: that feature's write path bypasses both the
 * Domain Action and the audit trail entirely).
 *
 * This batch's job is only to prove the UI wires to `ReorderFaqArticles`
 * correctly — the sequential 1..N guarantee itself is already proven at the
 * Action level by Batch 4.1's `tests/Feature/Domain/Faq/
 * ReorderFaqArticlesTest.php`.
 */
final class ReorderFaqArticleActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * @return list<FaqArticle>
     */
    private function createThreeArticlesInPembayaran(): array
    {
        $categoryId = FaqCategory::findByCode(FaqCategoryCode::PEMBAYARAN)->id;
        $create = new CreateFaqArticleDraft;

        return [
            $create($categoryId, 'Artikel A', 'reorder-ui-artikel-a', 'R.', 'I.', actorReference: 1),
            $create($categoryId, 'Artikel B', 'reorder-ui-artikel-b', 'R.', 'I.', actorReference: 1),
            $create($categoryId, 'Artikel C', 'reorder-ui-artikel-c', 'R.', 'I.', actorReference: 1),
        ];
    }

    public function test_move_down_swaps_the_record_with_its_next_sibling_in_the_same_category(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        [$a, $b, $c] = $this->createThreeArticlesInPembayaran();
        // PEMBAYARAN already holds real seeded articles (the data migration
        // seeds 4), so these three are contiguous but NOT sort_order 1/2/3 —
        // capture the actual starting values rather than assume a pristine
        // category.
        [$aOrder, $bOrder, $cOrder] = [$a->sort_order, $b->sort_order, $c->sort_order];

        Livewire::test(ListFaqArticles::class)
            ->callTableAction('moveDown', $a);

        $this->assertSame($bOrder, $a->refresh()->sort_order);
        $this->assertSame($aOrder, $b->refresh()->sort_order);
        $this->assertSame($cOrder, $c->refresh()->sort_order);

        $this->assertDatabaseHas('audit_events', [
            'action' => FaqAuditActions::REORDERED,
            'subject_type' => 'faq_category',
        ]);
    }

    public function test_move_up_swaps_the_record_with_its_previous_sibling(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        [$a, $b, $c] = $this->createThreeArticlesInPembayaran();
        [$aOrder, $bOrder, $cOrder] = [$a->sort_order, $b->sort_order, $c->sort_order];

        Livewire::test(ListFaqArticles::class)
            ->callTableAction('moveUp', $c);

        $this->assertSame($aOrder, $a->refresh()->sort_order);
        $this->assertSame($cOrder, $b->refresh()->sort_order);
        $this->assertSame($bOrder, $c->refresh()->sort_order);
    }

    public function test_move_up_is_hidden_for_the_first_article_and_move_down_is_hidden_for_the_last(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->createThreeArticlesInPembayaran();
        // PEMBAYARAN already holds real seeded articles, so the three just
        // created are not necessarily the category's actual first/last —
        // query the real boundary records rather than assume it.
        $categoryId = FaqCategory::findByCode(FaqCategoryCode::PEMBAYARAN)->id;
        $first = FaqArticle::forAdmin()->inCategory($categoryId)->orderBy('sort_order')->firstOrFail();
        $last = FaqArticle::forAdmin()->inCategory($categoryId)->orderByDesc('sort_order')->firstOrFail();

        $component = Livewire::test(ListFaqArticles::class);

        $component->assertTableActionHidden('moveUp', $first);
        $component->assertTableActionVisible('moveDown', $first);

        $component->assertTableActionHidden('moveDown', $last);
        $component->assertTableActionVisible('moveUp', $last);
    }

    public function test_moving_never_leaves_a_duplicate_or_gapped_sort_order_within_the_category(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        [$a, $b, $c] = $this->createThreeArticlesInPembayaran();
        // The three occupy a contiguous sort_order range (not necessarily
        // starting at 1, since PEMBAYARAN already has real seeded articles)
        // — after moving within that range, they must still occupy exactly
        // that same set of values, just permuted, proving no gap/duplicate
        // was introduced.
        $originalOrders = [$a->sort_order, $b->sort_order, $c->sort_order];
        sort($originalOrders);

        Livewire::test(ListFaqArticles::class)
            ->callTableAction('moveDown', $a)
            ->callTableAction('moveDown', $a);

        $sortOrders = FaqArticle::forAdmin()
            ->whereIn('id', [$a->id, $b->id, $c->id])
            ->orderBy('sort_order')
            ->pluck('sort_order')
            ->all();

        $this->assertSame($originalOrders, $sortOrders);
    }

    public function test_a_reorder_action_never_moves_an_article_from_a_different_category(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        [$a, $b] = $this->createThreeArticlesInPembayaran();

        $otherCategoryId = FaqCategory::findByCode(FaqCategoryCode::DOKUMEN)->id;
        $inOtherCategory = (new CreateFaqArticleDraft)(
            categoryId: $otherCategoryId,
            title: 'Artikel kategori lain',
            slug: 'reorder-ui-kategori-lain',
            summary: 'R.',
            body: 'I.',
            actorReference: 1,
        );
        $otherCategoryOrder = $inOtherCategory->sort_order;

        Livewire::test(ListFaqArticles::class)
            ->callTableAction('moveDown', $a);

        // The other category's article is completely untouched -- proving
        // the row action built a category-scoped id list, not e.g. every
        // visible row on the page (which spans multiple categories).
        $this->assertSame($otherCategoryOrder, $inOtherCategory->refresh()->sort_order);
        $this->assertSame($otherCategoryId, $inOtherCategory->category_id);
    }
}
