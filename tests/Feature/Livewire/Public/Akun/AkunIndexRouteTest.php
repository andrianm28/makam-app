<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Akun;

use App\Livewire\Public\Auth\LoginPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * `/akun` — the account-area home shell, Task 2 of the `/akun` account area
 * (`.superpowers/sdd/2026-08-20-akun-shell-and-drafts/task-2-brief.md`).
 * Route-level behaviour only: the `auth` middleware guard and the
 * intended-URL round-trip through `LoginPage::login()`'s
 * `redirectIntended(route('akun.index'), ...)` fallback. `AkunIndex`'s own
 * rendered content — the draft-tile copy and its per-user scoping — is
 * exercised in `AkunIndexTest`.
 */
final class AkunIndexRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_a_guest_is_redirected_to_login_with_the_intended_url_preserved(): void
    {
        $response = $this->get('/akun');

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('url.intended', url('/akun'));
    }

    /**
     * Proves the whole round-trip, not just that the session key was set:
     * hitting a guarded route as a guest, then completing login in the SAME
     * test session, must land back on that exact route —
     * `LoginPage::login()`'s `redirectIntended(route('akun.index'), ...)`
     * call consuming the very `url.intended` key `Authenticate` middleware
     * wrote above. Visits `/akun/draft`, not `/akun`, on purpose:
     * `redirectIntended()`'s OWN fallback (when no intended URL was ever set)
     * is also `route('akun.index')` (`/akun`), so asserting a redirect to
     * `/akun` would pass identically whether the `url.intended` mechanism
     * fired or never ran at all. `/akun/draft` differs from that fallback,
     * so the assertion actually discriminates a working round-trip from a
     * silently-skipped one.
     */
    public function test_logging_in_after_being_redirected_from_akun_returns_there(): void
    {
        $user = User::factory()->create(['password' => 'password']);

        $this->get('/akun/draft');

        Livewire::test(LoginPage::class)
            ->set('email', $user->email)
            ->set('password', 'password')
            ->call('login')
            ->assertRedirect(url('/akun/draft'));
    }

    public function test_an_authenticated_user_can_view_akun(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/akun')->assertOk();
    }
}
