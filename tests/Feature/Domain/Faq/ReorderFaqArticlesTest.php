<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Faq;

use App\Domain\Faq\Actions\CreateFaqArticleDraft;
use App\Domain\Faq\Actions\ReorderFaqArticles;
use App\Domain\Faq\FaqCategoryCode;
use App\Domain\Faq\Models\FaqArticle;
use App\Domain\Faq\Models\FaqCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * `ReorderFaqArticles` — accepts an ordered id list and assigns sequential
 * `1..N` sort values atomically. See that Action's own doc block for why
 * this shape (rather than a per-article "move" primitive) was chosen.
 */
final class ReorderFaqArticlesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<FaqArticle>
     */
    private function createThreeArticlesIn(int $categoryId): array
    {
        $create = new CreateFaqArticleDraft;

        return [
            $create($categoryId, 'Artikel A', 'reorder-artikel-a', 'Ringkasan A.', 'Isi A.', actorReference: 1),
            $create($categoryId, 'Artikel B', 'reorder-artikel-b', 'Ringkasan B.', 'Isi B.', actorReference: 1),
            $create($categoryId, 'Artikel C', 'reorder-artikel-c', 'Ringkasan C.', 'Isi C.', actorReference: 1),
        ];
    }

    public function test_reorder_assigns_sequential_sort_order_matching_the_requested_order(): void
    {
        $categoryId = FaqCategory::findByCode(FaqCategoryCode::PEMBAYARAN)->id;
        [$a, $b, $c] = $this->createThreeArticlesIn($categoryId);

        (new ReorderFaqArticles)([$c->id, $a->id, $b->id], actorReference: 7, reason: 'Menyesuaikan urutan tampilan.');

        $this->assertSame(1, $c->refresh()->sort_order);
        $this->assertSame(2, $a->refresh()->sort_order);
        $this->assertSame(3, $b->refresh()->sort_order);
    }

    public function test_reorder_is_atomic_no_duplicate_or_gapped_sort_orders_result(): void
    {
        $categoryId = FaqCategory::findByCode(FaqCategoryCode::PEMBAYARAN)->id;
        $articles = $this->createThreeArticlesIn($categoryId);
        $ids = array_map(fn (FaqArticle $article) => $article->id, $articles);

        (new ReorderFaqArticles)($ids, actorReference: 7);

        $sortOrders = FaqArticle::forAdmin()->whereIn('id', $ids)->orderBy('sort_order')->pluck('sort_order')->all();
        $this->assertSame([1, 2, 3], $sortOrders);
    }

    public function test_reorder_rejects_an_empty_list(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ReorderFaqArticles)([], actorReference: 7);
    }

    public function test_reorder_rejects_duplicate_ids_in_the_list(): void
    {
        $categoryId = FaqCategory::findByCode(FaqCategoryCode::PEMBAYARAN)->id;
        [$a] = $this->createThreeArticlesIn($categoryId);

        $this->expectException(InvalidArgumentException::class);

        (new ReorderFaqArticles)([$a->id, $a->id], actorReference: 7);
    }

    public function test_reorder_rejects_an_id_that_does_not_exist(): void
    {
        $categoryId = FaqCategory::findByCode(FaqCategoryCode::PEMBAYARAN)->id;
        [$a] = $this->createThreeArticlesIn($categoryId);

        $this->expectException(InvalidArgumentException::class);

        (new ReorderFaqArticles)([$a->id, 999999], actorReference: 7);
    }

    public function test_reorder_rejects_ids_spanning_more_than_one_category(): void
    {
        $categoryA = FaqCategory::findByCode(FaqCategoryCode::PEMBAYARAN)->id;
        $categoryB = FaqCategory::findByCode(FaqCategoryCode::DOKUMEN)->id;

        $create = new CreateFaqArticleDraft;
        $inA = $create($categoryA, 'Artikel A', 'reorder-lintas-kategori-a', 'Ringkasan.', 'Isi.', actorReference: 1);
        $inB = $create($categoryB, 'Artikel B', 'reorder-lintas-kategori-b', 'Ringkasan.', 'Isi.', actorReference: 1);

        $this->expectException(InvalidArgumentException::class);

        (new ReorderFaqArticles)([$inA->id, $inB->id], actorReference: 7);
    }

    public function test_a_failed_reorder_leaves_sort_orders_unchanged(): void
    {
        $categoryId = FaqCategory::findByCode(FaqCategoryCode::PEMBAYARAN)->id;
        [$a, $b, $c] = $this->createThreeArticlesIn($categoryId);
        $originalOrders = [$a->sort_order, $b->sort_order, $c->sort_order];

        try {
            (new ReorderFaqArticles)([$c->id, $a->id, 999999], actorReference: 7);
            $this->fail('Expected the invalid id to reject the whole reorder.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame($originalOrders[0], $a->refresh()->sort_order);
        $this->assertSame($originalOrders[1], $b->refresh()->sort_order);
        $this->assertSame($originalOrders[2], $c->refresh()->sort_order);
    }
}
