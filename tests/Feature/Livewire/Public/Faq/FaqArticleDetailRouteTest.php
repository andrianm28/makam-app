<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Faq;

use App\Domain\Faq\Actions\CreateFaqArticleDraft;
use App\Domain\Faq\Actions\PublishFaqArticle;
use App\Domain\Faq\Actions\UnpublishFaqArticle;
use App\Domain\Faq\FaqCategoryCode;
use App\Domain\Faq\Models\FaqCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `/faq/{articleSlug}` — Sprint 4 S4-T2 `public-faq`. requirements.md AC4
 * (title, body, updated date, related articles, customer-service CTA) and
 * AC6 (draft/unpublished never public — a real 404, indistinguishable from
 * an unknown slug).
 */
final class FaqArticleDetailRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Real HTTP requests below render the full layout (`@vite(...)` in
        // layouts/app.blade.php); this host's CI `php` job has no prior
        // frontend build. Same requirement/reasoning as the admin Faq
        // Filament tests (e.g. FaqArticleListPageTest) and
        // AdminPanelHttpAccessTest.
        $this->withoutVite();
    }

    public function test_a_real_seeded_published_article_renders_title_body_updated_date_and_cs_cta(): void
    {
        $response = $this->get('/faq/bagaimana-cara-memesan-makam');

        $response->assertOk();
        $response->assertSee('Bagaimana cara memesan makam?');
        $response->assertSee('sembilan langkah');
        $response->assertSee('Diperbarui');
        $response->assertSee('Hubungi Customer Service');
        $response->assertSee('/bantuan');
    }

    public function test_the_real_seeded_draft_article_slug_404s_not_a_gated_or_hidden_page(): void
    {
        $response = $this->get('/faq/bagaimana-melaporkan-masalah-vendor-atau-order');

        $response->assertNotFound();
    }

    public function test_an_unknown_slug_404s_identically_to_a_draft_slug(): void
    {
        $response = $this->get('/faq/slug-yang-sama-sekali-tidak-pernah-ada');

        $response->assertNotFound();
    }

    public function test_a_freshly_unpublished_article_404s(): void
    {
        $category = FaqCategory::findByCode(FaqCategoryCode::CARA_MEMESAN);
        $this->assertNotNull($category);

        $create = new CreateFaqArticleDraft;
        $draft = $create(
            categoryId: $category->id,
            title: 'Artikel yang akan dicabut zztestdetail',
            slug: 'artikel-yang-akan-dicabut-zztestdetail',
            summary: 'Ringkasan zztestdetail.',
            body: 'Isi zztestdetail.',
            actorReference: 1,
        );
        $published = (new PublishFaqArticle)($draft, actorReference: 1);
        (new UnpublishFaqArticle)($published, actorReference: 1);

        $response = $this->get('/faq/artikel-yang-akan-dicabut-zztestdetail');

        $response->assertNotFound();
    }

    public function test_a_never_published_draft_article_404s(): void
    {
        $category = FaqCategory::findByCode(FaqCategoryCode::CARA_MEMESAN);
        $this->assertNotNull($category);

        (new CreateFaqArticleDraft)(
            categoryId: $category->id,
            title: 'Artikel draft murni zztestdetail',
            slug: 'artikel-draft-murni-zztestdetail',
            summary: 'Ringkasan draft murni zztestdetail.',
            body: 'Isi draft murni zztestdetail.',
            actorReference: 1,
        );

        $response = $this->get('/faq/artikel-draft-murni-zztestdetail');

        $response->assertNotFound();
    }

    public function test_related_articles_render_the_published_link_but_never_a_draft_or_unpublished_one(): void
    {
        $category = FaqCategory::findByCode(FaqCategoryCode::CARA_MEMESAN);
        $this->assertNotNull($category);

        $create = new CreateFaqArticleDraft;

        $mainDraft = $create(
            categoryId: $category->id,
            title: 'Artikel utama zztestrelated',
            slug: 'artikel-utama-zztestrelated',
            summary: 'Ringkasan utama zztestrelated.',
            body: 'Isi utama zztestrelated.',
            actorReference: 1,
        );
        $main = (new PublishFaqArticle)($mainDraft, actorReference: 1);

        $relatedPublishedDraft = $create(
            categoryId: $category->id,
            title: 'Artikel terkait terbit zztestrelated',
            slug: 'artikel-terkait-terbit-zztestrelated',
            summary: 'Ringkasan terkait terbit zztestrelated.',
            body: 'Isi terkait terbit zztestrelated.',
            actorReference: 1,
        );
        $relatedPublished = (new PublishFaqArticle)($relatedPublishedDraft, actorReference: 1);

        $relatedDraft = $create(
            categoryId: $category->id,
            title: 'Artikel terkait draft zztestrelated',
            slug: 'artikel-terkait-draft-zztestrelated',
            summary: 'Ringkasan terkait draft zztestrelated.',
            body: 'Isi terkait draft zztestrelated.',
            actorReference: 1,
        );

        // Defence in depth (backend already guarantees this via
        // publishedRelatedArticles()) — proving THIS view template does
        // not accidentally bypass it and render the admin-facing
        // relatedArticles() relation instead.
        $main->relatedArticles()->sync([$relatedPublished->id, $relatedDraft->id]);

        $response = $this->get('/faq/artikel-utama-zztestrelated');

        $response->assertOk();
        $response->assertSee('Artikel terkait terbit zztestrelated');
        $response->assertDontSee('Artikel terkait draft zztestrelated');
    }
}
