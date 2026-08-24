<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Faq;

use App\Domain\Faq\Actions\CreateFaqArticleDraft;
use App\Domain\Faq\Actions\PublishFaqArticle;
use App\Domain\Faq\Authorization\RoleBasedFaqAuthorizer;
use App\Domain\Faq\Contracts\FaqAuthorizer;
use App\Domain\Faq\Exceptions\FaqActionNotAuthorisedException;
use App\Domain\Faq\FaqArticlePublishState;
use App\Domain\Faq\FaqCategoryCode;
use App\Domain\Faq\Models\FaqArticle;
use App\Domain\Faq\Models\FaqCategory;
use App\Filament\Admin\Resources\FaqArticles\Pages\ListFaqArticles;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * THIS FILE WAS A CHARACTERIZATION TEST THAT PINNED A KNOWN GAP, AND ITS
 * HISTORY IS THE POINT — do not "tidy" it into an ordinary authorization test
 * and do not delete it.
 *
 * Until Task 1 of the L9 `admin-operations` lane, every method below asserted
 * the OPPOSITE of what it asserts now: that a bare `User` with no role, grant,
 * or permission of any kind could open the FAQ article list and publish,
 * unpublish, and reorder articles. That was real, shipped behaviour — no
 * `FaqArticlePolicy` existed, and the four custom row actions (`publish`,
 * `unpublish`, `moveUp`, `moveDown`) carried no authorization call at all, only
 * record-state `->visible()`/`->hidden()` guards. It was graded **Critical** by
 * the whole-module review of the `Faq` retrofit and ledgered pending a human
 * ruling at `docs/planning/retrofit-backlog.md:88` ("No `FaqArticlePolicy`; 4
 * custom Filament row actions … bypass authorization entirely"), because
 * creating an authorization boundary is exactly what `AGENTS.md`
 * §Infrastructure-agent execution puts behind mandatory human review.
 *
 * The old file said of itself: "Its entire job is to fail loudly at that
 * moment, proving the policy actually changed behaviour rather than being
 * registered and silently inert." It did. Each `test_gap_*` method was run
 * against the inverted assertion BEFORE `Contracts\FaqAuthorizer` was wired
 * into any surface, watched fail, and only then made to pass — so nothing here
 * is a test that would pass with the authorization removed.
 *
 * ---------------------------------------------------------------------------
 * Why every action has THREE tests, not one
 * ---------------------------------------------------------------------------
 * - **roleless denied** — preserves the exact actor of the original gap, so
 *   the evidence trail from `retrofit-backlog.md:88` stays executable.
 * - **`operator` denied** — a deliberately panel-authorized-but-FAQ-
 *   unauthorized actor. A follow-up task tightens
 *   `Panel\AdminPanelAccessPolicy::allows()` from bare `isAuthenticated()` to a
 *   real role check that admits `operator`. After that lands, the roleless
 *   actor is refused at the PANEL boundary and never reaches this resource at
 *   all — so a roleless-only suite would keep passing with the FAQ
 *   authorization deleted entirely, verifying the panel gate instead of this
 *   module. The `operator` cases cannot be satisfied that way: they clear the
 *   panel and are refused here. **Do not "simplify" them away.**
 * - **`admin` allowed** — a denial-only suite passes just as well against a
 *   resource that refuses everyone, which is a vacuous suite. Each positive
 *   case proves the boundary still admits the actor it is supposed to.
 *
 * The role granted is `admin` and only `admin` — see
 * `App\Domain\Faq\Authorization\RoleBasedFaqAuthorizer`'s class doc block for
 * why that narrowness is a decision (`docs/security/rbac-matrix.md` has no FAQ
 * row) rather than an omission.
 *
 * ---------------------------------------------------------------------------
 * The stale note this file used to carry, corrected
 * ---------------------------------------------------------------------------
 * The old doc block closed with "nothing is actually exploitable today.
 * `ActorContext::$roles` is always `[]`, so a policy could presently only
 * re-express 'is authenticated' anyway." That stopped being true when lane L5
 * landed the identity seam: `$roles` is now populated for real from
 * `actor_role_assignments` via `Roles\ActorRoleReader`, which is precisely why
 * a role check became implementable — and why the reassurance had to go rather
 * than be left to mislead the next reader into thinking this boundary is
 * decorative.
 */
final class FaqArticleAuthorizationCharacterizationTest extends TestCase
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
        return FaqCategory::findByCode(FaqCategoryCode::DOKUMEN)->id;
    }

    private function rolelessUser(): User
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        return $user;
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();

        $this->grantRoleTo($user, $role);
        $this->actingAs($user);

        return $user;
    }

    private function draft(string $title, string $slug): FaqArticle
    {
        return (new CreateFaqArticleDraft)(
            categoryId: $this->categoryId(),
            title: $title,
            slug: $slug,
            summary: 'Ringkasan.',
            body: 'Isi.',
            // Authorship attribution, not authorization: the Domain Action
            // records who wrote the row. Fixtures are always created BY the
            // actor that is about to be refused, so a refusal can never be
            // mistaken for "this actor did not own the record" — the boundary
            // under test is role-based, not ownership-based.
            actorReference: Auth::id() ?? 0,
        );
    }

    /**
     * Attempt a row action as the currently-acting actor and tolerate — but
     * require — a refusal, in whichever of its legitimate shapes arrives.
     *
     * There are two, because there are two enforcement layers:
     *
     *  - **The page refuses first.** `FaqArticleResource::getAuthorizationResponse()`
     *    denies, so Filament's `Resources\Pages\Concerns\CanAuthorizeResourceAccess`
     *    mount hook `abort(403)`s before the table is ever built. Livewire's test
     *    harness turns that into a 403 response with NO component instance, so
     *    there is no table to call an action on — `callTableAction()` would fail
     *    on `$this->instance()->mountedActions` with a null-property error, which
     *    is a harness artefact of the refusal, not evidence about the action.
     *    Asserted explicitly as a 403 rather than shrugged at.
     *  - **The row action refuses.** The page is reachable but the action's own
     *    `->authorize()` closure denies, so
     *    `Filament\Actions\Concerns\InteractsWithActions::mountAction()` unmounts
     *    and returns `null` (via `CanBeDisabled::isDisabled()`) — silently, no
     *    throw — or, if the closure were reached directly, the in-closure
     *    `authorizeManage()` throws `FaqActionNotAuthorisedException`.
     *
     * Deliberately does NOT assert which layer fired: pinning that would make
     * every test here a test of Filament's internal ordering, and it would have
     * to be rewritten the next time a layer is added. The assertion that matters
     * is the one each caller makes immediately after — THE RECORD DID NOT CHANGE
     * — which is invariant across both shapes.
     *
     * Only the three authorization-shaped exception types are swallowed;
     * anything else propagates, so a genuine error inside an action can never
     * masquerade as a refusal.
     */
    private function attemptRefusedTableAction(string $action, FaqArticle $record): void
    {
        $component = Livewire::test(ListFaqArticles::class);

        if ($component->instance() === null) {
            $component->assertForbidden();

            return;
        }

        try {
            $component->callTableAction($action, $record);
        } catch (HttpException|AuthorizationException|FaqActionNotAuthorisedException) {
            // Expected: this is what a refusal looks like from the outside.
        }
    }

    // -----------------------------------------------------------------------
    // The list page
    // -----------------------------------------------------------------------

    /**
     * Previously `test_gap_any_authenticated_user_can_open_the_faq_article_list`,
     * which asserted `assertOk()`. See `docs/planning/retrofit-backlog.md:88`.
     */
    public function test_a_roleless_user_cannot_open_the_faq_article_list(): void
    {
        $this->rolelessUser();

        $this->get('/admin/artikel-faq')->assertForbidden();
    }

    /**
     * Panel-authorized, FAQ-unauthorized. `operator` is a role the follow-up
     * `AdminPanelAccessPolicy` tightening will admit to `/admin`, so this
     * assertion cannot be satisfied by the panel gate alone — it can only pass
     * because this resource makes its own decision.
     */
    public function test_an_operator_cannot_open_the_faq_article_list(): void
    {
        $this->userWithRole(ActorRole::OPERATOR);

        $this->get('/admin/artikel-faq')->assertForbidden();
    }

    public function test_an_admin_can_open_the_faq_article_list(): void
    {
        $this->userWithRole(ActorRole::ADMIN);

        $this->get('/admin/artikel-faq')->assertOk();
    }

    // -----------------------------------------------------------------------
    // publish
    // -----------------------------------------------------------------------

    /**
     * Previously `test_gap_any_authenticated_user_can_publish_an_article`,
     * which asserted the article reached `PUBLISHED`. See
     * `docs/planning/retrofit-backlog.md:88`.
     */
    public function test_a_roleless_user_cannot_publish_an_article(): void
    {
        $this->rolelessUser();
        $article = $this->draft('Artikel otorisasi terbit', 'otorisasi-terbit-tanpa-peran');

        $this->attemptRefusedTableAction('publish', $article);

        $this->assertSame(FaqArticlePublishState::DRAFT, $article->refresh()->publish_state);
    }

    /**
     * Panel-authorized, FAQ-unauthorized — see
     * `test_an_operator_cannot_open_the_faq_article_list`. Deliberately not
     * redundant with the roleless case: it survives the panel-gate tightening
     * that will make the roleless case stop exercising this module.
     */
    public function test_an_operator_cannot_publish_an_article(): void
    {
        $this->userWithRole(ActorRole::OPERATOR);
        $article = $this->draft('Artikel otorisasi terbit operator', 'otorisasi-terbit-operator');

        $this->attemptRefusedTableAction('publish', $article);

        $this->assertSame(FaqArticlePublishState::DRAFT, $article->refresh()->publish_state);
    }

    public function test_an_admin_can_publish_an_article(): void
    {
        $this->userWithRole(ActorRole::ADMIN);
        $article = $this->draft('Artikel otorisasi terbit admin', 'otorisasi-terbit-admin');

        Livewire::test(ListFaqArticles::class)
            ->callTableAction('publish', $article);

        $this->assertSame(FaqArticlePublishState::PUBLISHED, $article->refresh()->publish_state);
    }

    // -----------------------------------------------------------------------
    // unpublish
    // -----------------------------------------------------------------------

    /**
     * Previously `test_gap_any_authenticated_user_can_unpublish_an_article`,
     * which asserted the article reached `UNPUBLISHED`. See
     * `docs/planning/retrofit-backlog.md:88`.
     */
    public function test_a_roleless_user_cannot_unpublish_an_article(): void
    {
        $this->rolelessUser();
        $published = (new PublishFaqArticle)(
            $this->draft('Artikel otorisasi cabut', 'otorisasi-cabut-tanpa-peran'),
            actorReference: Auth::id() ?? 0,
        );

        $this->attemptRefusedTableAction('unpublish', $published);

        $this->assertSame(FaqArticlePublishState::PUBLISHED, $published->refresh()->publish_state);
    }

    /**
     * Panel-authorized, FAQ-unauthorized — see
     * `test_an_operator_cannot_open_the_faq_article_list`.
     */
    public function test_an_operator_cannot_unpublish_an_article(): void
    {
        $this->userWithRole(ActorRole::OPERATOR);
        $published = (new PublishFaqArticle)(
            $this->draft('Artikel otorisasi cabut operator', 'otorisasi-cabut-operator'),
            actorReference: Auth::id() ?? 0,
        );

        $this->attemptRefusedTableAction('unpublish', $published);

        $this->assertSame(FaqArticlePublishState::PUBLISHED, $published->refresh()->publish_state);
    }

    public function test_an_admin_can_unpublish_an_article(): void
    {
        $this->userWithRole(ActorRole::ADMIN);
        $published = (new PublishFaqArticle)(
            $this->draft('Artikel otorisasi cabut admin', 'otorisasi-cabut-admin'),
            actorReference: Auth::id() ?? 0,
        );

        Livewire::test(ListFaqArticles::class)
            ->callTableAction('unpublish', $published);

        $this->assertSame(FaqArticlePublishState::UNPUBLISHED, $published->refresh()->publish_state);
    }

    // -----------------------------------------------------------------------
    // moveUp / moveDown
    //
    // The gap-era file covered both directions in one
    // `test_gap_any_authenticated_user_can_reorder_articles` method. They are
    // split here because they are two separately-registered row actions, each
    // needing its own `->authorize()` — one method covering both could pass
    // with one of the two guards missing.
    // -----------------------------------------------------------------------

    /**
     * @return array{0: FaqArticle, 1: FaqArticle}
     */
    private function twoOrderedArticles(string $slugSuffix): array
    {
        return [
            $this->draft('Urutan satu', "otorisasi-urutan-satu-{$slugSuffix}"),
            $this->draft('Urutan dua', "otorisasi-urutan-dua-{$slugSuffix}"),
        ];
    }

    /**
     * Previously half of `test_gap_any_authenticated_user_can_reorder_articles`,
     * which asserted the two articles swapped `sort_order`. See
     * `docs/planning/retrofit-backlog.md:88`.
     */
    public function test_a_roleless_user_cannot_move_an_article_down(): void
    {
        $this->rolelessUser();
        [$first, $second] = $this->twoOrderedArticles('turun-tanpa-peran');
        $firstOrder = $first->sort_order;
        $secondOrder = $second->sort_order;

        $this->attemptRefusedTableAction('moveDown', $first);

        $this->assertSame($firstOrder, $first->refresh()->sort_order);
        $this->assertSame($secondOrder, $second->refresh()->sort_order);
    }

    /**
     * Panel-authorized, FAQ-unauthorized — see
     * `test_an_operator_cannot_open_the_faq_article_list`.
     */
    public function test_an_operator_cannot_move_an_article_down(): void
    {
        $this->userWithRole(ActorRole::OPERATOR);
        [$first, $second] = $this->twoOrderedArticles('turun-operator');
        $firstOrder = $first->sort_order;
        $secondOrder = $second->sort_order;

        $this->attemptRefusedTableAction('moveDown', $first);

        $this->assertSame($firstOrder, $first->refresh()->sort_order);
        $this->assertSame($secondOrder, $second->refresh()->sort_order);
    }

    public function test_an_admin_can_move_an_article_down(): void
    {
        $this->userWithRole(ActorRole::ADMIN);
        [$first, $second] = $this->twoOrderedArticles('turun-admin');
        $firstOrder = $first->sort_order;
        $secondOrder = $second->sort_order;

        Livewire::test(ListFaqArticles::class)
            ->callTableAction('moveDown', $first);

        $this->assertSame($secondOrder, $first->refresh()->sort_order);
        $this->assertSame($firstOrder, $second->refresh()->sort_order);
    }

    /**
     * Previously the other half of
     * `test_gap_any_authenticated_user_can_reorder_articles`. See
     * `docs/planning/retrofit-backlog.md:88`.
     */
    public function test_a_roleless_user_cannot_move_an_article_up(): void
    {
        $this->rolelessUser();
        [$first, $second] = $this->twoOrderedArticles('naik-tanpa-peran');
        $firstOrder = $first->sort_order;
        $secondOrder = $second->sort_order;

        $this->attemptRefusedTableAction('moveUp', $second);

        $this->assertSame($firstOrder, $first->refresh()->sort_order);
        $this->assertSame($secondOrder, $second->refresh()->sort_order);
    }

    /**
     * Panel-authorized, FAQ-unauthorized — see
     * `test_an_operator_cannot_open_the_faq_article_list`.
     */
    public function test_an_operator_cannot_move_an_article_up(): void
    {
        $this->userWithRole(ActorRole::OPERATOR);
        [$first, $second] = $this->twoOrderedArticles('naik-operator');
        $firstOrder = $first->sort_order;
        $secondOrder = $second->sort_order;

        $this->attemptRefusedTableAction('moveUp', $second);

        $this->assertSame($firstOrder, $first->refresh()->sort_order);
        $this->assertSame($secondOrder, $second->refresh()->sort_order);
    }

    public function test_an_admin_can_move_an_article_up(): void
    {
        $this->userWithRole(ActorRole::ADMIN);
        [$first, $second] = $this->twoOrderedArticles('naik-admin');
        $firstOrder = $first->sort_order;
        $secondOrder = $second->sort_order;

        Livewire::test(ListFaqArticles::class)
            ->callTableAction('moveUp', $second);

        $this->assertSame($secondOrder, $first->refresh()->sort_order);
        $this->assertSame($firstOrder, $second->refresh()->sort_order);
    }

    // -----------------------------------------------------------------------
    // The action layer, isolated from the page layer
    // -----------------------------------------------------------------------

    /**
     * `FaqArticleResource::canViewAny()` and each row action's `->authorize()`
     * ask the SAME authorizer, so every test above is satisfied the moment
     * either layer refuses — none of them can tell whether the row actions
     * carry their own guard. This one can.
     *
     * It resolves the four actions while the actor still holds `admin`, then
     * revokes the grant for real and re-asks each action directly. No page
     * mount or hydration happens in between, so
     * `CanAuthorizeResourceAccess`'s 403 hook never runs and cannot account
     * for the result: a `false` here can only come from the action's own
     * `->authorize()` closure reading the freshly-resolved `ActorContext`.
     * Delete `->authorize()` from any one of the four and this fails, while
     * every other test in this file would still pass.
     */
    public function test_each_managed_row_action_authorizes_independently_of_the_page_gate(): void
    {
        $admin = $this->userWithRole(ActorRole::ADMIN);

        $table = Livewire::test(ListFaqArticles::class)->instance()->getTable();

        $actions = [];

        foreach (['publish', 'unpublish', 'moveUp', 'moveDown'] as $name) {
            $action = $table->getAction($name);

            $this->assertNotNull($action, "The [{$name}] row action is missing from the FAQ table.");
            $this->assertTrue(
                $action->hasAuthorization(),
                "The [{$name}] row action carries no authorization; a record-state visible()/hidden() guard is not one.",
            );

            $actions[$name] = $action;
        }

        $this->revokeRoleFrom($admin, ActorRole::ADMIN);

        foreach ($actions as $name => $action) {
            $this->assertFalse(
                $action->isAuthorized(),
                "The [{$name}] row action stayed authorized after the actor's admin grant was revoked.",
            );
        }
    }

    /**
     * The innermost layer, which nothing above can reach.
     *
     * `->authorize()` stops the control being rendered or mounted, so every
     * other test in this file is satisfied before an `->action()` closure ever
     * runs — verified by mutation: deleting the in-closure
     * `authorizeManage()` call from an action leaves the entire rest of this
     * suite green. That is precisely why the in-closure call exists (a Livewire
     * method is addressable over the wire; "the button was not rendered" is not
     * a security property) and precisely why it needs a test that bypasses the
     * mount path the way a hand-rolled request would.
     *
     * `Filament\Actions\Concerns\HasAction::getActionFunction()` hands back the
     * raw closure, so calling it invokes the write path with no Filament
     * authorization in front of it at all. Each closure is captured while the
     * actor still holds `admin` — capture is not evaluation — and then invoked
     * after the grant is revoked for real.
     *
     * Every fixture is in the state that would let its action SUCCEED if the
     * guard were missing (a draft to publish, a published article to unpublish,
     * two ordered siblings to swap), so a missing guard shows up as a completed
     * write and an explicit `fail()`, never as an unrelated domain exception.
     */
    public function test_each_managed_row_action_closure_enforces_authorization_when_invoked_directly(): void
    {
        $admin = $this->userWithRole(ActorRole::ADMIN);

        $first = $this->draft('Artikel panggilan langsung satu', 'panggilan-langsung-satu');
        $second = (new PublishFaqArticle)(
            $this->draft('Artikel panggilan langsung dua', 'panggilan-langsung-dua'),
            actorReference: Auth::id() ?? 0,
        );

        $table = Livewire::test(ListFaqArticles::class)->instance()->getTable();

        /** @var array<string, array{0: \Closure, 1: list<mixed>}> $invocations */
        $invocations = [];

        foreach ([
            'publish' => [$first, []],
            'unpublish' => [$second, []],
            'moveDown' => [$first],
            'moveUp' => [$second],
        ] as $name => $arguments) {
            $closure = $table->getAction($name)?->getActionFunction();

            $this->assertNotNull($closure, "The [{$name}] row action has no action closure to enforce anything in.");

            $invocations[$name] = [$closure, $arguments];
        }

        $this->revokeRoleFrom($admin, ActorRole::ADMIN);

        foreach ($invocations as $name => [$closure, $arguments]) {
            try {
                $closure(...$arguments);

                $this->fail("The [{$name}] action closure ran to completion for an actor holding no FAQ authority.");
            } catch (FaqActionNotAuthorisedException) {
                $this->addToAssertionCount(1);
            }
        }

        // Belt and braces on the above: nothing was written by any of the four
        // refused invocations.
        $this->assertSame(FaqArticlePublishState::DRAFT, $first->refresh()->publish_state);
        $this->assertSame(FaqArticlePublishState::PUBLISHED, $second->refresh()->publish_state);
    }

    /**
     * The seam is bound, and bound to the role-based implementation. Without
     * this, a lost binding would surface as a `BindingResolutionException` deep
     * inside an unrelated Filament test rather than as a named failure here.
     */
    public function test_the_faq_authorizer_seam_is_bound(): void
    {
        $this->assertInstanceOf(
            RoleBasedFaqAuthorizer::class,
            app(FaqAuthorizer::class),
        );
    }
}
