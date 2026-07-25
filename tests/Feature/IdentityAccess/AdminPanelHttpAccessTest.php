<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sprint 3 Batch 3.5b (S3-T12 follow-up). Fills the one real HTTP-level gap
 * `UserCanAccessPanelTest` and `AdminPanelAccessPolicyTest` both leave open:
 * both prove `User::canAccessPanel()` / `AdminPanelAccessPolicy::allows()`
 * in isolation (the first via `Mockery::mock(Filament\Panel::class)`, the
 * second at the unit level) — neither sends a real HTTP request through the
 * actual `/admin` route, the actual `Filament\Http\Middleware\Authenticate`
 * middleware, and the actual panel boot sequence
 * (`app/Providers/Filament/AdminPanelProvider.php`). This test does.
 *
 * ---------------------------------------------------------------------------
 * VERIFICATION STATUS
 * ---------------------------------------------------------------------------
 * Same constraint `AdminPanelProvider`'s and `UserCanAccessPanelTest`'s own
 * doc blocks already state: this host has no installed
 * `vendor/filament/filament` (`composer install` cannot run here — this
 * repo's CLAUDE.md/CI wiring say builds happen in CI only), so this test
 * cannot be executed locally, only `php -l`-checked. It is written to run
 * for real in CI, which does install `filament/filament` (composer.lock
 * pins `v5.7.3`).
 *
 * Unlike `AdminPanelProvider`'s and `UserCanAccessPanelTest`'s doc blocks,
 * which had no installed copy of Filament to check their API usage against,
 * this file's specific claims below (redirect target, which route the panel
 * root maps to, which middleware group each route sits inside) were traced
 * against a REAL installed copy of `filament/filament` `v5.7.3` — the exact
 * version this repo's `composer.lock` pins — found on this host at
 * `/home/ubuntu/platform-galang-dana-app/vendor/filament/filament` (a
 * sibling project, not this repo). Specifically, read directly from that
 * package's source:
 *   - `src/Http/Middleware/Authenticate.php`: `redirectTo()` returns
 *     `Filament::getLoginUrl()`; `authenticate()` calls
 *     `$user->canAccessPanel($panel)` when the user implements
 *     `FilamentUser` (our `App\Models\User` does) and aborts 403 if it
 *     returns false — it does NOT redirect an authenticated-but-denied user
 *     to login, it 403s. An unauthenticated user never reaches that check;
 *     `unauthenticated()` (inherited from Laravel's own
 *     `Illuminate\Auth\Middleware\Authenticate`) fires first and redirects.
 *   - `src/Panel/Concerns/HasAuth.php`: `getLoginUrl()` returns
 *     `$this->route('auth.login', ...)` only `if ($this->hasLogin())` —
 *     true here, since `AdminPanelProvider` calls `->login()`. The login
 *     route slug defaults to `'login'` (`HasAuth::$loginRouteSlug`,
 *     un-overridden by `AdminPanelProvider`).
 *   - `routes/web.php` (the package's own route file, loaded via
 *     `->hasRoutes('web')` in `FilamentServiceProvider`): registers routes
 *     inside `Route::domain(...)->prefix($panel->getPath())`
 *     (`getPath()` is `'admin'`, set by `AdminPanelProvider`'s `->path('admin')`)
 *     and names them `filament.{panelId}.` + relative name — the login
 *     route is `Route::get($panel->getLoginRouteSlug(), ...)->name('login')`
 *     nested inside `Route::name('auth.')`, giving the full route name
 *     `filament.admin.auth.login` at path `admin/login`. That SAME file
 *     registers every page (`foreach ($panel->getPages() as $page) {
 *     $page::registerRoutes($panel); }`, including `Pages\Dashboard`)
 *     inside `Route::middleware($panel->getAuthMiddleware())->group(...)` —
 *     i.e. behind `Authenticate::class`, exactly as `AdminPanelProvider`
 *     configures via `->authMiddleware([Authenticate::class])`. The login
 *     route registers OUTSIDE that authenticated group, as it must.
 *   - `src/Pages/Dashboard.php`: `protected static string $routePath = '/'`
 *     — Dashboard overrides `getRoutePath()` to the panel's own root rather
 *     than a `/dashboard` slug-derived path. Combined with the `admin`
 *     prefix above, `GET /admin` itself IS the Dashboard page's route (not
 *     a redirect-to-home fallback controller) — so a successful authenticated
 *     request to `/admin` is a direct 200, not a further redirect.
 *
 * From this: an unauthenticated `GET /admin` should redirect (302) to
 * `/admin/login` (asserted here via the `filament.admin.auth.login` route
 * name, not a hardcoded string, so it stays correct even if a future change
 * moves the panel path). An authenticated `GET /admin` should render the
 * Dashboard directly (200), because `AdminPanelAccessPolicy::allows()` is
 * `$actor->isAuthenticated()` today (see that class's own doc block — no
 * role check yet, by design, until S3-T2/T3 populate `ActorContext::$roles`)
 * so ANY authenticated user passes. This test proves the CURRENT,
 * intentionally coarse "authenticated = allowed" boundary — it is not
 * proof of role-based authorization, which does not exist in this codebase
 * yet.
 */
final class AdminPanelHttpAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * FIXED 26 Jul 2026 — first real CI run: an authenticated `GET /admin`
     * renders the Dashboard page for real, and its layout (`filament/
     * filament`'s `resources/views/components/layout/base.blade.php`) uses
     * `@vite(...)`. This repo's `php` CI job runs PHPUnit without a prior
     * frontend build (that's a SEPARATE job, `frontend`, in `ci.yml`, with
     * no shared artifact), so no `public/build/manifest.json` exists when
     * these tests run — real, working authorization logic failed on a
     * missing, unrelated frontend asset manifest ("Vite manifest not
     * found"), not a real access-control problem. `withoutVite()` is a
     * built-in Laravel test helper (`Illuminate\Foundation\Testing\
     * Concerns\InteractsWithContainer`, present on this repo's Laravel 13)
     * that swaps in a no-op Vite instance for exactly this situation —
     * confirmed against the same installed `laravel/framework` v13.22.0
     * copy (a sibling project on this host) already used to verify this
     * file's Filament source trail. The guest-redirect test never reaches
     * view rendering (redirects short-circuit before the layout renders),
     * so it was unaffected — this only needed to cover the two tests that
     * actually render the dashboard.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_a_guest_is_redirected_away_from_the_admin_panel(): void
    {
        $response = $this->get('/admin');

        // Filament's Authenticate middleware (Laravel's own unauthenticated()
        // path, since Filament's subclass does not override it) redirects a
        // guest rather than rendering the panel or a bare 401/403 — see this
        // class's doc block for the exact source trail confirming the
        // target is the panel's own login route, not Laravel's default
        // `/login`.
        $response->assertRedirect(route('filament.admin.auth.login'));
    }

    public function test_an_authenticated_user_can_enter_the_admin_panel(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/admin');

        // AdminPanelAccessPolicy::allows() is today just
        // $actor->isAuthenticated() — no role/scope check. This assertion
        // is therefore proof of THAT boundary specifically: any
        // authenticated user reaches the dashboard. Tightening this to a
        // real role check is S3-T2/T3's job, not this batch's.
        $response->assertOk();
    }

    public function test_a_different_authenticated_user_can_also_enter_confirming_no_hidden_allowlist(): void
    {
        // Two independently-created users both getting in (not just the
        // same fixture twice) rules out an accidental allowlist keyed on a
        // specific id/email rather than the documented "any authenticated
        // actor" rule.
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->actingAs($first)->get('/admin')->assertOk();
        $this->actingAs($second)->get('/admin')->assertOk();
    }
}
