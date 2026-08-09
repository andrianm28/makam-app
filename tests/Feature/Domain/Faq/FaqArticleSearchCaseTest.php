<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Faq;

use App\Domain\Faq\Actions\CreateFaqArticleDraft;
use App\Domain\Faq\Actions\PublishFaqArticle;
use App\Domain\Faq\FaqCategoryCode;
use App\Domain\Faq\FaqPublicQuery;
use App\Domain\Faq\Models\FaqArticle;
use App\Domain\Faq\Models\FaqCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AC3's case dimension — Task 2 whole-module review finding 8.
 *
 * `FaqArticle::scopeMatching()` is case-insensitive twice over: it lowers
 * the NEEDLE (`mb_strtolower($term)`) and it lowers the COLUMN
 * (`LOWER(title) LIKE ?`, and the same for summary/body). Every search
 * needle elsewhere in this suite is already lowercase (`q=invoice`,
 * `q=vendor`, `matching('zebrarahasia')`), so a regression deleting either
 * half would leave the whole suite green on PostgreSQL while a user typing
 * "Invoice" got zero results. Each half is pinned separately below.
 *
 * No production code changed for this finding — the behaviour is already
 * correct. AGENTS.md §Testing's "Every traceability item marked `Covered`
 * needs test evidence" is what was unmet.
 */
final class FaqArticleSearchCaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_uppercase_needle_finds_the_same_seeded_articles_as_a_lowercase_one(): void
    {
        // Pins `mb_strtolower($term)`. "invoice" appears in the real seeded
        // Pembayaran article "Kapan invoice diterbitkan?".
        $lowercase = FaqPublicQuery::search('invoice')->pluck('id')->sort()->values()->all();

        $this->assertNotEmpty($lowercase, 'The seeded catalogue no longer contains "invoice"; pick another real term.');
        $this->assertContains(
            FaqArticle::publishedBySlug('kapan-invoice-diterbitkan')?->id,
            $lowercase
        );

        foreach (['INVOICE', 'Invoice', 'InVoIcE'] as $needle) {
            $this->assertSame(
                $lowercase,
                FaqPublicQuery::search($needle)->pluck('id')->sort()->values()->all(),
                "Search for [{$needle}] did not match the lowercase search."
            );
        }
    }

    public function test_a_lowercase_needle_finds_content_stored_in_mixed_case(): void
    {
        // Pins `LOWER(title)`/`LOWER(summary)`/`LOWER(body)`. The token is
        // stored capitalised and nowhere else in the catalogue, so a lowercase
        // search can only match it if the COLUMN side is lowered too — the
        // half the uppercase-needle test above cannot reach. Written through
        // the real Actions, matching FaqArticleDraftExclusionTest's own
        // "zebrarahasia" fixture discipline.
        $categoryId = FaqCategory::findByCode(FaqCategoryCode::CUSTOMER_SERVICE)->id;

        $article = (new PublishFaqArticle)(
            (new CreateFaqArticleDraft)(
                categoryId: $categoryId,
                title: 'Judul dengan token ZzKapitalUnik',
                slug: 'uji-pencarian-kapital-unik',
                summary: 'Ringkasan biasa.',
                body: 'Isi biasa.',
                actorReference: 1,
            ),
            actorReference: 1,
        );

        foreach (['zzkapitalunik', 'ZZKAPITALUNIK', 'ZzKapitalUnik'] as $needle) {
            $ids = FaqPublicQuery::search($needle)->pluck('id')->all();

            $this->assertContains(
                $article->id,
                $ids,
                "Search for [{$needle}] did not find the article whose title stores it as [ZzKapitalUnik]."
            );
        }
    }
}
