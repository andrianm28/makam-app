<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Faq;

use App\Domain\Faq\Actions\CreateFaqArticleDraft;
use App\Domain\Faq\Actions\PublishFaqArticle;
use App\Domain\Faq\FaqArticlePublishState;
use App\Domain\Faq\FaqCategoryCode;
use App\Domain\Faq\Models\FaqCategory;
use App\Filament\Admin\Resources\FaqArticles\Pages\ListFaqArticles;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * THIS TEST PINS A KNOWN GAP. Every assertion below records behaviour that
 * is WRONG and is deliberately left in place — none of it is desired
 * behaviour, and none of it may be cited as evidence that authorization
 * works.
 *
 * The gap: no `FaqArticlePolicy` exists (`find app -iname '*Policy*'` returns
 * nothing for `FaqArticle`), and the four custom row actions — `publish`,
 * `unpublish`, `moveUp`, `moveDown` — carry no `->authorize()`, so they would
 * bypass a policy even after one is added. `AGENTS.md` §Authorization and
 * files reads "Policies and query-level scope are mandatory", and
 * `requirements.md` AC5's own subject is "an AUTHORIZED admin". Graded
 * Critical by the Task 2 whole-module review of the `Faq` retrofit and
 * ledgered pending human ruling in `docs/planning/retrofit-backlog.md`:
 * creating an authorization boundary is exactly what `AGENTS.md`
 * §Infrastructure-agent execution puts behind mandatory human review, so the
 * retrofit's bounded fix wave could not land the policy itself.
 *
 * WHEN A POLICY LANDS, UPDATE THIS TEST — DO NOT DELETE IT. Its entire job
 * is to fail loudly at that moment, proving the policy actually changed
 * behaviour rather than being registered and silently inert. A green run of
 * this file after a policy ships means the policy does not work.
 *
 * Honest scope note, recorded so this is not read as alarmism: nothing is
 * actually exploitable today. `ActorContext::$roles` is always `[]`, so a
 * policy could presently only re-express "is authenticated" anyway; that
 * root cause is tracked outside this module (S3-T2/T3, Batch 3.2). Critical
 * here means "must not be closed silently", not "the panel is open to the
 * internet".
 */
final class FaqArticleAuthorizationCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    private function categoryId(): int
    {
        return FaqCategory::findByCode(FaqCategoryCode::DOKUMEN)->id;
    }

    /**
     * KNOWN GAP. A bare `User` with no role, permission, or grant of any kind
     * reaches the FAQ article list. Correct behaviour is a policy decision.
     */
    public function test_gap_any_authenticated_user_can_open_the_faq_article_list(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin/faq-articles')->assertOk();
    }

    /**
     * KNOWN GAP. The same bare `User` can publish an article — a write to the
     * public surface — with no authorization check anywhere in the path.
     */
    public function test_gap_any_authenticated_user_can_publish_an_article(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $article = (new CreateFaqArticleDraft)(
            categoryId: $this->categoryId(),
            title: 'Artikel karakterisasi otorisasi terbit',
            slug: 'karakterisasi-otorisasi-terbit',
            summary: 'Ringkasan.',
            body: 'Isi.',
            actorReference: $user->id,
        );

        Livewire::test(ListFaqArticles::class)
            ->callTableAction('publish', $article);

        $this->assertSame(FaqArticlePublishState::PUBLISHED, $article->refresh()->publish_state);
    }

    /**
     * KNOWN GAP. The same bare `User` can pull a live article off the public
     * surface.
     */
    public function test_gap_any_authenticated_user_can_unpublish_an_article(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $published = (new PublishFaqArticle)(
            (new CreateFaqArticleDraft)(
                categoryId: $this->categoryId(),
                title: 'Artikel karakterisasi otorisasi cabut',
                slug: 'karakterisasi-otorisasi-cabut',
                summary: 'Ringkasan.',
                body: 'Isi.',
                actorReference: $user->id,
            ),
            actorReference: $user->id,
        );

        Livewire::test(ListFaqArticles::class)
            ->callTableAction('unpublish', $published);

        $this->assertSame(FaqArticlePublishState::UNPUBLISHED, $published->refresh()->publish_state);
    }

    /**
     * KNOWN GAP. The same bare `User` can reorder the public display order
     * via both movement actions.
     */
    public function test_gap_any_authenticated_user_can_reorder_articles(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $create = new CreateFaqArticleDraft;
        $categoryId = $this->categoryId();

        $first = $create($categoryId, 'Urutan satu', 'karakterisasi-urutan-satu', 'R.', 'I.', actorReference: $user->id);
        $second = $create($categoryId, 'Urutan dua', 'karakterisasi-urutan-dua', 'R.', 'I.', actorReference: $user->id);

        $firstOrder = $first->sort_order;
        $secondOrder = $second->sort_order;

        Livewire::test(ListFaqArticles::class)
            ->callTableAction('moveDown', $first);

        $this->assertSame($secondOrder, $first->refresh()->sort_order);
        $this->assertSame($firstOrder, $second->refresh()->sort_order);

        Livewire::test(ListFaqArticles::class)
            ->callTableAction('moveUp', $first);

        $this->assertSame($firstOrder, $first->refresh()->sort_order);
        $this->assertSame($secondOrder, $second->refresh()->sort_order);
    }
}
