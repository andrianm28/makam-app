<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Faq;

use App\Domain\Faq\FaqArticlePublishState;
use App\Domain\Faq\FaqCategoryCode;
use App\Domain\Faq\Models\FaqArticle;
use App\Domain\Faq\Models\FaqCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `docs/product/faq-catalog.md`'s "Minimum article questions" section lists
 * 23 questions across the six categories (4 + 3 + 4 + 4 + 4 + 4, counted
 * directly against that file). This proves the seed migration covers EVERY
 * one of them — a count assertion per category, not just "count > 0" — and
 * proves the deliberate single seeded `draft` row this batch's brief
 * requires (see the seed migration's own doc block for why that specific
 * question was chosen).
 */
final class FaqArticleSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_twenty_three_catalogue_questions_are_seeded(): void
    {
        $this->assertDatabaseCount('faq_articles', 23);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function categoryQuestionCounts(): iterable
    {
        yield 'Cara Memesan' => [FaqCategoryCode::CARA_MEMESAN, 4];
        yield 'Dokumen' => [FaqCategoryCode::DOKUMEN, 3];
        yield 'Pembayaran' => [FaqCategoryCode::PEMBAYARAN, 4];
        yield 'Perpanjangan' => [FaqCategoryCode::PERPANJANGAN, 4];
        yield 'Pembayaran Gagal' => [FaqCategoryCode::PEMBAYARAN_GAGAL, 4];
        yield 'Customer Service' => [FaqCategoryCode::CUSTOMER_SERVICE, 4];
    }

    #[DataProvider('categoryQuestionCounts')]
    public function test_each_category_has_its_exact_catalogue_question_count(string $categoryCode, int $expectedCount): void
    {
        $category = FaqCategory::findByCode($categoryCode);
        $this->assertNotNull($category);

        $this->assertSame(
            $expectedCount,
            FaqArticle::forAdmin()->where('category_id', $category->id)->count(),
            "Expected [{$expectedCount}] seeded articles for category [{$categoryCode}]."
        );
    }

    public function test_exactly_one_seeded_article_is_a_draft_and_the_rest_are_published(): void
    {
        $this->assertSame(1, FaqArticle::forAdmin()->where('publish_state', FaqArticlePublishState::DRAFT)->count());
        $this->assertSame(22, FaqArticle::forAdmin()->where('publish_state', FaqArticlePublishState::PUBLISHED)->count());
        $this->assertSame(0, FaqArticle::forAdmin()->where('publish_state', FaqArticlePublishState::UNPUBLISHED)->count());
    }

    public function test_the_deliberately_seeded_draft_is_the_documented_vendor_order_question(): void
    {
        $draft = FaqArticle::forAdmin()->where('publish_state', FaqArticlePublishState::DRAFT)->sole();

        $this->assertSame('bagaimana-melaporkan-masalah-vendor-atau-order', $draft->slug);
        $this->assertSame(0, $draft->current_version);
        $this->assertNull($draft->published_at);
    }

    public function test_every_published_seed_article_has_a_matching_first_version_snapshot(): void
    {
        $published = FaqArticle::forAdmin()->where('publish_state', FaqArticlePublishState::PUBLISHED)->get();

        foreach ($published as $article) {
            $this->assertSame(1, $article->current_version, "Article [{$article->slug}] should be seeded at version 1.");
            $this->assertNotNull($article->published_at);
            $this->assertDatabaseHas('faq_article_versions', [
                'faq_article_id' => $article->id,
                'version_number' => 1,
                'slug' => $article->slug,
            ]);
        }
    }

    public function test_every_article_has_non_blank_title_summary_and_body(): void
    {
        foreach (FaqArticle::forAdmin()->get() as $article) {
            $this->assertNotSame('', trim($article->title), "Article #{$article->id} has a blank title.");
            $this->assertNotSame('', trim($article->summary), "Article #{$article->id} has a blank summary.");
            $this->assertNotSame('', trim($article->body), "Article #{$article->id} has a blank body.");
        }
    }
}
