<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The five `/akun` account-area GET routes (`routes/web.php`'s "Account"
 * block) — Tasks 1-3 of the `/akun` account area
 * (`.superpowers/sdd/2026-08-20-akun-auth-foundation/task-1-brief.md`
 * through `task-3-brief.md`). Mirrors `HomePageRouteTest`'s pattern: a
 * thin, real-HTTP-request check that each route actually renders (or
 * redirects, for the guest-only routes hit by an authenticated actor)
 * rather than 404ing or 500ing, distinct from the Livewire-component-level
 * behaviour already covered by `LoginPageTest`, `RegisterPageTest`, and
 * `PasswordResetTest`.
 */
final class AuthRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Real HTTP requests below render the full layout (`@vite(...)` in
        // layouts/app.blade.php); this host's CI `php` job has no prior
        // frontend build. Same requirement/reasoning as every other public
        // Livewire route test in this repo (e.g. HomePageRouteTest).
        $this->withoutVite();
    }

    public function test_login_route_returns_ok_for_a_guest(): void
    {
        $this->get('/masuk')->assertOk();
    }

    public function test_register_route_returns_ok_for_a_guest(): void
    {
        $this->get('/daftar')->assertOk();
    }

    public function test_forgot_password_route_returns_ok_for_a_guest(): void
    {
        $this->get('/lupa-'.'password')->assertOk();
    }

    public function test_reset_password_route_returns_ok_for_a_guest_even_with_an_invalid_token(): void
    {
        // The token isn't validated until submit — a guest landing here
        // with any placeholder token string, valid or not, must still get
        // a real rendered page, never a 500.
        $this->get('/reset-'.'password/any-placeholder-token')->assertOk();
    }

    public function test_login_route_redirects_an_authenticated_user_away(): void
    {
        // `guest` is Laravel 13's own default middleware alias
        // (`Illuminate\Auth\Middleware\RedirectIfAuthenticated`); this PR
        // does not override `redirectUsersTo()` in `bootstrap/app.php`, so
        // the framework's own default fallback destination applies. Assert
        // only that a redirect happens — not a specific target URL, which
        // would pin an implementation detail this PR does not own.
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->get('/masuk')->assertRedirect();
    }
}
