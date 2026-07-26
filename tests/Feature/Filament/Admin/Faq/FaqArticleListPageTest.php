<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Faq;

use App\Domain\Faq\Actions\CreateFaqArticleDraft;
use App\Domain\Faq\Actions\PublishFaqArticle;
use App\Domain\Faq\FaqCategoryCode;
use App\Domain\Faq\Models\FaqCategory;
use App\Filament\Admin\Resources\FaqArticles\FaqArticleResource;
use App\Filament\Admin\Resources\FaqArticles\Pages\ListFaqArticles;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Proves `FaqArticleResource`'s list page (a) is reachable over real HTTP at
 * `/admin/faq-articles` for an authenticated user, and (b) renders a
 * draft/unpublished article's `publish_state` badge visibly differently
 * from a published one.
 *
 * `withoutVite()` requirement and reasoning: same as
 * `tests/Feature/IdentityAccess/AdminPanelHttpAccessTest.php`'s own doc
 * block — the panel's real layout renders `@vite(...)`, and this host's CI
 * `php` job has no prior frontend build. Reused verbatim here rather than
 * re-derived.
 *
 * `/admin/faq-articles` is not a hardcoded guess: `FaqArticleResource`'s
 * default slug was traced against the real installed `filament/filament`
 * v5.7.3 (`Resources\Resource\Concerns\HasRoutes::resolveDefaultSlug()`) —
 * for a resource class `App\Filament\Admin\Resources\FaqArticles\
 * FaqArticleResource` (directory basename `FaqArticles` matches the
 * pluralized model-basename-before-"Resource", so the "collapsed"
 * branch applies), the resolved slug is `kebab('FaqArticles')` =
 * `faq-articles`.
 */
final class FaqArticleListPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    private function categoryId(): int
    {
        return FaqCategory::findByCode(FaqCategoryCode::CUSTOMER_SERVICE)->id;
    }

    public function test_a_guest_is_redirected_away_from_the_faq_article_list_page(): void
    {
        $response = $this->get('/admin/faq-articles');

        $response->assertRedirect(route('filament.admin.auth.login'));
    }

    public function test_an_authenticated_user_can_open_the_faq_article_list_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/admin/faq-articles');

        $response->assertOk();
    }

    public function test_a_draft_articles_badge_reads_differently_from_a_published_ones(): void
    {
        $user = User::factory()->create();

        $draft = (new CreateFaqArticleDraft)(
            categoryId: $this->categoryId(),
            title: 'Artikel draf',
            slug: 'list-page-artikel-draf',
            summary: 'Ringkasan.',
            body: 'Isi.',
            actorReference: $user->id,
        );

        $publishedSource = (new CreateFaqArticleDraft)(
            categoryId: $this->categoryId(),
            title: 'Artikel terbit',
            slug: 'list-page-artikel-terbit',
            summary: 'Ringkasan.',
            body: 'Isi.',
            actorReference: $user->id,
        );
        $published = (new PublishFaqArticle)($publishedSource, actorReference: $user->id);

        $this->actingAs($user);

        Livewire::test(ListFaqArticles::class)
            ->assertCanSeeTableRecords([$draft, $published])
            // Raw column state is the untouched `publish_state` value —
            // proves the two rows are backed by genuinely different data,
            // not just differently-labelled by accident.
            ->assertTableColumnStateSet('publish_state', 'draft', $draft)
            ->assertTableColumnStateSet('publish_state', 'published', $published)
            // Formatted state is what actually renders in the badge —
            // `FaqArticleStatusBadge::label()` — proving the two rows are
            // VISIBLY distinguishable, not just internally different.
            ->assertTableColumnFormattedStateSet('publish_state', 'Draf', $draft)
            ->assertTableColumnFormattedStateSet('publish_state', 'Dipublikasikan', $published);
    }

    public function test_faq_article_resource_slug_resolves_to_faq_articles(): void
    {
        $this->assertSame('faq-articles', FaqArticleResource::getSlug());
    }
}
