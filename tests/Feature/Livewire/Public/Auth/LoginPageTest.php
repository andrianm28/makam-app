<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Auth;

use App\Livewire\Public\Auth\LoginPage;
use App\Models\User;
use App\Platform\IdentityAccess\ActorContextResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * `LoginPage` (`/masuk`) — Task 1 of the `/akun` account area
 * (`docs/superpowers/plans/2026-08-20-p-akun-auth-foundation`... see
 * `.superpowers/sdd/2026-08-20-akun-auth-foundation/task-1-brief.md`).
 *
 * The regression test at the bottom of this file
 * (`test_a_stale_cached_actor_context_is_forgotten_after_a_successful_login`)
 * is the single most important test in the whole PR — it fails loudly if
 * `ActorContextResolver::forget()` is ever dropped from `LoginPage::login()`.
 */
final class LoginPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_correct_credentials_authenticate_and_redirect_to_home(): void
    {
        $user = User::factory()->create(['password' => 'password']);

        Livewire::test(LoginPage::class)
            ->set('email', $user->email)
            ->set('password', 'password')
            ->call('login')
            ->assertRedirect('/');

        $this->assertTrue(auth()->check());
        $this->assertSame($user->id, auth()->id());
    }

    public function test_wrong_password_shows_a_generic_error_and_does_not_authenticate(): void
    {
        $user = User::factory()->create(['password' => 'password']);

        Livewire::test(LoginPage::class)
            ->set('email', $user->email)
            ->set('password', 'wrong-password')
            ->call('login')
            ->assertHasErrors(['email']);

        $this->assertFalse(auth()->check());
    }

    public function test_unknown_email_shows_the_exact_same_generic_error_as_a_wrong_password(): void
    {
        $user = User::factory()->create(['password' => 'password']);

        $wrongPassword = Livewire::test(LoginPage::class)
            ->set('email', $user->email)
            ->set('password', 'wrong-password')
            ->call('login');

        $unknownEmail = Livewire::test(LoginPage::class)
            ->set('email', 'does-not-exist@example.test')
            ->set('password', 'wrong-password')
            ->call('login');

        $this->assertFalse(auth()->check());
        $this->assertSame(
            $wrongPassword->errors()->first('email'),
            $unknownEmail->errors()->first('email'),
            'A wrong password and an unknown email must be indistinguishable to the caller.',
        );
    }

    public function test_the_sixth_attempt_within_sixty_seconds_is_blocked_even_with_correct_credentials(): void
    {
        $user = User::factory()->create(['password' => 'password']);

        for ($i = 0; $i < 5; $i++) {
            Livewire::test(LoginPage::class)
                ->set('email', $user->email)
                ->set('password', 'wrong-password')
                ->call('login');
        }

        $this->assertFalse(auth()->check());

        // 6th attempt, this time with the CORRECT password — still blocked,
        // proving the rate limit is checked before credentials are ever
        // verified.
        Livewire::test(LoginPage::class)
            ->set('email', $user->email)
            ->set('password', 'password')
            ->call('login')
            ->assertHasErrors(['email']);

        $this->assertFalse(auth()->check());
    }

    public function test_the_remember_checkbox_authenticates_via_remember_token(): void
    {
        $user = User::factory()->create(['password' => 'password']);

        Livewire::test(LoginPage::class)
            ->set('email', $user->email)
            ->set('password', 'password')
            ->set('remember', true)
            ->call('login')
            ->assertRedirect('/');

        $this->assertTrue(auth()->check());
        $this->assertTrue(auth()->viaRemember());
    }

    public function test_without_the_remember_checkbox_the_session_is_not_authenticated_via_remember_token(): void
    {
        $user = User::factory()->create(['password' => 'password']);

        Livewire::test(LoginPage::class)
            ->set('email', $user->email)
            ->set('password', 'password')
            ->set('remember', false)
            ->call('login')
            ->assertRedirect('/');

        $this->assertTrue(auth()->check());
        $this->assertFalse(auth()->viaRemember());
    }

    /**
     * The regression test that matters most (see brief). Forces
     * `ActorContextResolver` to cache a GUEST resolution before login runs
     * — matching a real page load that already touched `ActorContext` (e.g.
     * via the header) — then proves the cache reflects the newly
     * authenticated actor in the SAME request/test, without a new request.
     * This fails loudly the moment `LoginPage::login()` stops calling
     * `ActorContextResolver::forget()` after `auth()->attempt()`.
     */
    public function test_a_stale_cached_actor_context_is_forgotten_after_a_successful_login(): void
    {
        $user = User::factory()->create(['password' => 'password']);

        $resolver = app(ActorContextResolver::class);

        $this->assertFalse($resolver->resolve()->isAuthenticated());

        Livewire::test(LoginPage::class)
            ->set('email', $user->email)
            ->set('password', 'password')
            ->call('login')
            ->assertRedirect('/');

        $this->assertTrue($resolver->resolve()->isAuthenticated());
    }
}
