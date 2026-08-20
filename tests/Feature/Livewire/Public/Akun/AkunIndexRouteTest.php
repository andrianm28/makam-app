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
 * rendered content is exercised elsewhere (none yet — this task's brief
 * scopes the component to greeting + one tile, both covered indirectly by
 * this file's `assertOk()`).
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
     * hitting the guarded route as a guest, then completing login in the
     * SAME test session, must land back on `/akun` — `LoginPage::login()`'s
     * `redirectIntended(route('akun.index'), ...)` call consuming the very
     * `url.intended` key `Authenticate` middleware wrote above.
     */
    public function test_logging_in_after_being_redirected_from_akun_returns_there(): void
    {
        $user = User::factory()->create(['password' => 'password']);

        $this->get('/akun');

        Livewire::test(LoginPage::class)
            ->set('email', $user->email)
            ->set('password', 'password')
            ->call('login')
            ->assertRedirect(url('/akun'));
    }

    public function test_an_authenticated_user_can_view_akun(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/akun')->assertOk();
    }
}
