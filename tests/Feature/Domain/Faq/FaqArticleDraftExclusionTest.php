<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Faq;

use App\Domain\Faq\Actions\CreateFaqArticleDraft;
use App\Domain\Faq\Actions\PublishFaqArticle;
use App\Domain\Faq\Actions\UnpublishFaqArticle;
use App\Domain\Faq\FaqCategoryCode;
use App\Domain\Faq\FaqPublicQuery;
use App\Domain\Faq\Models\FaqArticle;
use App\Domain\Faq\Models\FaqCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * THE single most important test in this batch — requirements.md AC6: "THE
 * SYSTEM SHALL NOT display an unpublished article in any public view or
 * public search result." Mirrors the rigor
 * `tests/Feature/IdentityAccess/Mfa/MfaAuditSafetyTest.php` and
 * `tests/Feature/Outbox/OutboxRecoveryTest.php` set for their own
 * single-most-important guarantees.
 *
 * Proves, against BOTH a real seeded draft row (the seed migration's own
 * deliberate `bagaimana-melaporkan-masalah-vendor-atau-order` article) and
 * fresh `draft`/`unpublished` fixtures created via the real write Actions
 * (not raw `FaqArticle::create()`), that EVERY public-read helper this
 * module exposes excludes them, while the explicit admin escape hatch
 * (`FaqArticle::forAdmin()`) still sees everything.
 */
final class FaqArticleDraftExclusionTest extends TestCase
{
    use RefreshDatabase;

    private FaqArticle $published;

    private FaqArticle $draft;

    private FaqArticle $unpublished;

    protected function setUp(): void
    {
        parent::setUp();

        $category = FaqCategory::findByCode(FaqCategoryCode::CARA_MEMESAN);
        $this->assertNotNull($category);

        $create = new CreateFaqArticleDraft;

        $publishedDraft = $create(
            categoryId: $category->id,
            title: 'Judul artikel yang sudah terbit',
            slug: 'artikel-sudah-terbit-uji-eksklusi-zebrarahasia',
            summary: 'Ringkasan artikel yang sudah terbit, mengandung kata kunci unik zebrarahasia.',
            body: 'Isi artikel yang sudah terbit, mengandung kata kunci unik zebrarahasia untuk pencarian.',
            actorReference: 1,
        );
        $this->published = (new PublishFaqArticle)($publishedDraft, actorReference: 1);

        $this->draft = $create(
            categoryId: $category->id,
            title: 'Judul artikel draft tersembunyi',
            slug: 'artikel-draft-uji-eksklusi-zebrarahasia',
            summary: 'Ringkasan artikel draft, juga mengandung kata kunci unik zebrarahasia.',
            body: 'Isi artikel draft, juga mengandung kata kunci unik zebrarahasia untuk pencarian.',
            actorReference: 1,
        );

        $unpublishedDraft = $create(
            categoryId: $category->id,
            title: 'Judul artikel yang pernah terbit lalu dicabut',
            slug: 'artikel-dicabut-uji-eksklusi-zebrarahasia',
            summary: 'Ringkasan artikel yang dicabut, mengandung kata kunci unik zebrarahasia.',
            body: 'Isi artikel yang dicabut, mengandung kata kunci unik zebrarahasia untuk pencarian.',
            actorReference: 1,
        );
        $publishedThenPulled = (new PublishFaqArticle)($unpublishedDraft, actorReference: 1);
        $this->unpublished = (new UnpublishFaqArticle)($publishedThenPulled, actorReference: 1);

        // Link the draft and the unpublished article as "related" to the
        // published one, to prove publishedRelatedArticles() filters them.
        $this->published->relatedArticles()->sync([$this->draft->id, $this->unpublished->id]);
    }

    public function test_scope_published_excludes_draft_and_unpublished_articles(): void
    {
        $ids = FaqArticle::published()->pluck('id');

        $this->assertTrue($ids->contains($this->published->id));
        $this->assertFalse($ids->contains($this->draft->id));
        $this->assertFalse($ids->contains($this->unpublished->id));
    }

    public function test_scope_in_category_composed_with_published_excludes_draft_and_unpublished(): void
    {
        $ids = FaqArticle::published()->inCategory($this->published->category_id)->pluck('id');

        $this->assertTrue($ids->contains($this->published->id));
        $this->assertFalse($ids->contains($this->draft->id));
        $this->assertFalse($ids->contains($this->unpublished->id));
    }

    public function test_scope_matching_composed_with_published_never_returns_a_draft_or_unpublished_match(): void
    {
        // All three fixtures share the same unique needle in title/summary/
        // body — proving the filter, not the needle choice, is what excludes
        // the draft/unpublished rows.
        $ids = FaqArticle::published()->matching('zebrarahasia')->pluck('id');

        $this->assertTrue($ids->contains($this->published->id));
        $this->assertFalse($ids->contains($this->draft->id));
        $this->assertFalse($ids->contains($this->unpublished->id));
    }

    public function test_published_by_slug_resolves_the_published_article_but_returns_null_for_draft_or_unpublished_slugs(): void
    {
        $this->assertSame($this->published->id, FaqArticle::publishedBySlug($this->published->slug)?->id);
        $this->assertNull(FaqArticle::publishedBySlug($this->draft->slug));
        $this->assertNull(FaqArticle::publishedBySlug($this->unpublished->slug));
    }

    public function test_published_related_articles_excludes_a_draft_or_unpublished_related_link(): void
    {
        $relatedIds = $this->published->publishedRelatedArticles()->pluck('id');

        $this->assertFalse($relatedIds->contains($this->draft->id));
        $this->assertFalse($relatedIds->contains($this->unpublished->id));

        // The admin-facing relation, in contrast, legitimately sees both —
        // proving the exclusion above is `publishedRelatedArticles()`
        // filtering, not the pivot rows failing to exist.
        $adminRelatedIds = $this->published->relatedArticles()->pluck('id');
        $this->assertTrue($adminRelatedIds->contains($this->draft->id));
        $this->assertTrue($adminRelatedIds->contains($this->unpublished->id));
    }

    public function test_the_real_seeded_draft_article_is_excluded_from_every_public_helper(): void
    {
        $seededDraft = FaqArticle::forAdmin()->where('slug', 'bagaimana-melaporkan-masalah-vendor-atau-order')->sole();
        $this->assertTrue($seededDraft->isDraft());

        $this->assertFalse(FaqArticle::published()->pluck('id')->contains($seededDraft->id));
        $this->assertNull(FaqArticle::publishedBySlug($seededDraft->slug));
        $this->assertFalse(
            FaqPublicQuery::articlesInCategory($seededDraft->category_id)->pluck('id')->contains($seededDraft->id)
        );
        $this->assertFalse(FaqPublicQuery::allPublished()->pluck('id')->contains($seededDraft->id));
        $this->assertNull(FaqPublicQuery::findBySlug($seededDraft->slug));
    }

    public function test_faq_public_query_helpers_exclude_draft_and_unpublished_articles(): void
    {
        $categoryIds = FaqPublicQuery::articlesInCategory($this->published->category_id)->pluck('id');
        $this->assertTrue($categoryIds->contains($this->published->id));
        $this->assertFalse($categoryIds->contains($this->draft->id));
        $this->assertFalse($categoryIds->contains($this->unpublished->id));

        $allPublishedIds = FaqPublicQuery::allPublished()->pluck('id');
        $this->assertTrue($allPublishedIds->contains($this->published->id));
        $this->assertFalse($allPublishedIds->contains($this->draft->id));
        $this->assertFalse($allPublishedIds->contains($this->unpublished->id));

        $searchIds = FaqPublicQuery::search('zebrarahasia')->pluck('id');
        $this->assertTrue($searchIds->contains($this->published->id));
        $this->assertFalse($searchIds->contains($this->draft->id));
        $this->assertFalse($searchIds->contains($this->unpublished->id));

        $this->assertSame($this->published->id, FaqPublicQuery::findBySlug($this->published->slug)?->id);
        $this->assertNull(FaqPublicQuery::findBySlug($this->draft->slug));
        $this->assertNull(FaqPublicQuery::findBySlug($this->unpublished->slug));
    }

    public function test_for_admin_is_the_explicit_escape_hatch_that_sees_everything_including_drafts(): void
    {
        $ids = FaqArticle::forAdmin()->pluck('id');

        $this->assertTrue($ids->contains($this->published->id));
        $this->assertTrue($ids->contains($this->draft->id));
        $this->assertTrue($ids->contains($this->unpublished->id));
    }
}
