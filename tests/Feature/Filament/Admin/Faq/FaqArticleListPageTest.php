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
use App\Platform\IdentityAccess\Roles\ActorRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * Proves `FaqArticleResource`'s list page (a) is reachable over real HTTP at
 * `/admin/artikel-faq` for an authenticated user, and (b) renders a
 * draft/unpublished article's `publish_state` badge visibly differently
 * from a published one.
 *
 * `withoutVite()` requirement and reasoning: same as
 * `tests/Feature/IdentityAccess/AdminPanelHttpAccessTest.php`'s own doc
 * block — the panel's real layout renders `@vite(...)`, and this host's CI
 * `php` job has no prior frontend build. Reused verbatim here rather than
 * re-derived.
 *
 * `/admin/artikel-faq` is the explicit `$slug` set on `FaqArticleResource`
 * (url-indonesianization Task 2) — before that it was Filament's derived
 * default (`kebab('FaqArticles')` = `faq-articles`).
 *
 * ---------------------------------------------------------------------------
 * Every actor here is granted `ActorRole::ADMIN` first, and that is not
 * boilerplate
 * ---------------------------------------------------------------------------
 * Task 1 of the L9 `admin-operations` lane closed the Critical authorization
 * gap ledgered at `docs/planning/retrofit-backlog.md:88`: the FAQ admin
 * surface now refuses every actor without the `admin` role
 * (`App\Domain\Faq\Contracts\FaqAuthorizer`). A bare `User::factory()` actor
 * gets a 403 before this file's subject is reached, so the grant is what makes
 * these tests exercise their subject at all rather than the authorization
 * boundary. The refusal side is proved separately and deliberately, in
 * `FaqArticleAuthorizationCharacterizationTest` — do not add denial cases here
 * or weaken the boundary to keep these green.
 */
final class FaqArticleListPageTest extends TestCase
{
    use GrantsActorRoles;
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
        $response = $this->get('/admin/artikel-faq');

        $response->assertRedirect(route('filament.admin.auth.login'));
    }

    /**
     * Renamed from `test_an_authenticated_user_can_open_the_faq_article_list_page`
     * by Task 1 of the L9 `admin-operations` lane. "Authenticated" stopped being
     * the operative condition when the FAQ resource gained a role check — the
     * old name would have described a boundary that no longer exists, which is
     * exactly the kind of stale claim a passing test makes hardest to notice.
     * A roleless actor's 403 is asserted in
     * `FaqArticleAuthorizationCharacterizationTest`.
     */
    public function test_an_admin_can_open_the_faq_article_list_page(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);

        $response = $this->actingAs($user)->get('/admin/artikel-faq');

        $response->assertOk();
    }

    public function test_a_draft_articles_badge_reads_differently_from_a_published_ones(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);

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

        // The real seed migration already populates 23 articles across every
        // category, so with the table's default page size these two
        // freshly-created (highest-id) records are not guaranteed to land on
        // the first page. Search down to just these two ("Artikel ..." does
        // not collide with any real seeded title) before asserting
        // visibility, rather than assuming a pristine, single-page table.
        Livewire::test(ListFaqArticles::class)
            ->searchTable('Artikel')
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

    public function test_faq_article_resource_slug_resolves_to_artikel_faq(): void
    {
        $this->assertSame('artikel-faq', FaqArticleResource::getSlug());
    }
}
