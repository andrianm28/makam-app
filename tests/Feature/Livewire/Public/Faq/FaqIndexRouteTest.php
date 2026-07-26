<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Faq;

use App\Domain\Faq\Actions\CreateFaqArticleDraft;
use App\Domain\Faq\Actions\PublishFaqArticle;
use App\Domain\Faq\Actions\UnpublishFaqArticle;
use App\Domain\Faq\FaqCategoryCode;
use App\Domain\Faq\Models\FaqArticle;
use App\Domain\Faq\Models\FaqCategory;
use App\Livewire\Public\Faq\FaqIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * `/faq` and `/faq/kategori/{categorySlug}` — Sprint 4 S4-T2 `public-faq`,
 * presentation-only batch. Exercises requirements.md AC1/AC2/AC3/AC6/AC8
 * against the REAL seeded catalogue (`2026_07_26_170400_seed_faq_
 * categories_and_articles.php`) wherever possible, mirroring
 * `tests/Feature/Domain/Faq/FaqArticleDraftExclusionTest.php`'s own
 * discipline of using real seeded data and real domain Actions rather than
 * raw `FaqArticle::create()`.
 */
final class FaqIndexRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_faq_index_returns_ok_and_lists_seeded_published_articles(): void
    {
        $response = $this->get('/faq');

        $response->assertOk();
        // A real seeded, published article title (Cara Memesan category).
        $response->assertSee('Bagaimana cara memesan makam?');
        // The real seeded draft must never appear on the public index (AC6).
        $response->assertDontSee('Bagaimana melaporkan masalah vendor atau order?');
    }

    public function test_faq_category_route_narrows_the_list_to_that_category_only(): void
    {
        $response = $this->get('/faq/kategori/dokumen');

        $response->assertOk();
        // DOKUMEN's own seeded article.
        $response->assertSee('Dokumen apa yang diperlukan?');
        // A CARA_MEMESAN-only article must not appear when filtered to DOKUMEN.
        $response->assertDontSee('Bagaimana cara memesan makam?');
    }

    public function test_unknown_category_slug_404s(): void
    {
        $response = $this->get('/faq/kategori/tidak-ada-kategori-seperti-ini');

        $response->assertNotFound();
    }

    public function test_search_finds_matching_published_articles_across_categories(): void
    {
        // "invoice" only appears in the Pembayaran category's seeded body/summary.
        $response = $this->get('/faq?q=invoice');

        $response->assertOk();
        $response->assertSee('Kapan invoice diterbitkan?');
        $response->assertDontSee('Bagaimana cara memesan makam?');
    }

    public function test_search_never_returns_the_real_seeded_draft_article(): void
    {
        // The draft's own title/summary/body contain "vendor" — a search for
        // it must not surface the draft (AC6), even though the term matches.
        $response = $this->get('/faq?q=vendor');

        $response->assertOk();
        $response->assertDontSee('Bagaimana melaporkan masalah vendor atau order?');
    }

    public function test_search_with_no_results_shows_related_categories_and_support_path_not_a_bare_empty_state(): void
    {
        $response = $this->get('/faq?q=xyzxyznotarealfaqtermxyz');

        $response->assertOk();
        // Heading unique to this branch (§6.2's "what is empty").
        $response->assertSee('Tidak ada hasil');
        // "why" + "next action" wording unique to this branch — not the
        // persistent header/footer nav, which never contains this sentence.
        $response->assertSee('jelajahi berdasarkan kategori di bawah ini, atau hubungi customer service');
        // AC8: related categories offered, not a bare "no results" message.
        $response->assertSee('Cara Memesan');
        $response->assertSee('Customer Service');
        // AC8: a customer-service path is offered alongside.
        $response->assertSee('Hubungi Customer Service');
        $response->assertSee('/bantuan');
    }

    public function test_empty_category_state_renders_when_a_category_has_zero_published_articles(): void
    {
        // DOKUMEN has exactly 3 seeded published articles — pull all three
        // via the real UnpublishFaqArticle action (a legitimate, realistic
        // "admin unpublished everything in this category" scenario) to
        // genuinely exercise the empty-category state against real data,
        // rather than only reasoning about it. See this batch's final
        // report for why this state is otherwise hard to trigger against
        // the seed data as shipped (every category starts with >=1
        // published article).
        $category = FaqCategory::findByCode(FaqCategoryCode::DOKUMEN);
        $this->assertNotNull($category);

        $unpublish = new UnpublishFaqArticle;
        FaqArticle::published()->inCategory($category->id)->get()->each(
            fn (FaqArticle $article) => $unpublish($article, actorReference: 1)
        );

        $response = $this->get('/faq/kategori/dokumen');

        $response->assertOk();
        $response->assertSee('Belum ada artikel di kategori ini.');
        $response->assertSee('Lihat kategori lain');
    }

    public function test_livewire_component_validation_error_on_overlong_search_term(): void
    {
        Livewire::test(FaqIndex::class)
            ->set('term', str_repeat('x', 201))
            ->call('search')
            ->assertHasErrors(['term' => 'max']);
    }

    public function test_reset_search_clears_term_and_validation_errors(): void
    {
        Livewire::test(FaqIndex::class)
            ->set('term', str_repeat('x', 201))
            ->call('search')
            ->assertHasErrors(['term'])
            ->call('resetSearch')
            ->assertSet('term', '')
            ->assertHasNoErrors();
    }

    public function test_search_and_reset_are_idempotent_and_never_mutate_data(): void
    {
        // §6.6 duplicate/retry-safe — a plain read query has no
        // state-mutating side effect, so repeated submits are naturally
        // idempotent. Verified here by asserting the published article
        // count is unchanged after multiple repeated searches.
        $countBefore = FaqArticle::published()->count();

        $test = Livewire::test(FaqIndex::class)->set('term', 'invoice');
        $test->call('search');
        $test->call('search');
        $test->call('search');

        $this->assertSame($countBefore, FaqArticle::published()->count());
    }

    public function test_category_chip_links_never_include_the_real_seeded_draft_articles_category_as_leaking_its_content(): void
    {
        // Defense in depth: hitting every one of the six category routes
        // must never surface the draft, regardless of which category it
        // belongs to.
        foreach (['cara-memesan', 'dokumen', 'pembayaran', 'perpanjangan', 'pembayaran-gagal', 'customer-service'] as $slug) {
            $response = $this->get("/faq/kategori/{$slug}");
            $response->assertOk();
            $response->assertDontSee('Bagaimana melaporkan masalah vendor atau order?');
        }
    }

    public function test_a_freshly_created_draft_and_unpublished_article_never_appear_on_the_index(): void
    {
        $category = FaqCategory::findByCode(FaqCategoryCode::CARA_MEMESAN);
        $this->assertNotNull($category);

        $create = new CreateFaqArticleDraft;

        $draft = $create(
            categoryId: $category->id,
            title: 'Judul draft unik zztestindex',
            slug: 'judul-draft-unik-zztestindex',
            summary: 'Ringkasan draft unik zztestindex.',
            body: 'Isi draft unik zztestindex.',
            actorReference: 1,
        );

        $unpublishedSource = $create(
            categoryId: $category->id,
            title: 'Judul dicabut unik zztestindex',
            slug: 'judul-dicabut-unik-zztestindex',
            summary: 'Ringkasan dicabut unik zztestindex.',
            body: 'Isi dicabut unik zztestindex.',
            actorReference: 1,
        );
        $published = (new PublishFaqArticle)($unpublishedSource, actorReference: 1);
        (new UnpublishFaqArticle)($published, actorReference: 1);

        $response = $this->get('/faq');
        $response->assertOk();
        $response->assertDontSee('Judul draft unik zztestindex');
        $response->assertDontSee('Judul dicabut unik zztestindex');

        $searchResponse = $this->get('/faq?q=zztestindex');
        $searchResponse->assertOk();
        $searchResponse->assertDontSee('Judul draft unik zztestindex');
        $searchResponse->assertDontSee('Judul dicabut unik zztestindex');
    }
}
