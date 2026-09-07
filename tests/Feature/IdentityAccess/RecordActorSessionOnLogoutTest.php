<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess;

use App\Livewire\Public\Auth\LoginPage;
use App\Models\User;
use App\Platform\IdentityAccess\Models\ActorSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * SEC-06 regression: `RecordActorSessionOnLogout` used to match on
 * `$request->session()->getId()`, which is stale by the time `/keluar`
 * fires `Logout` — `LoginPage::login()` calls `session()->regenerate()`
 * AFTER `auth()->attempt()` already fired `Login` (and therefore
 * `RecordActorSessionOnLogin`) under the pre-regeneration session id. A
 * unit test that constructs the `Logout` event and calls `handle()`
 * directly (the previous version of this file) can set up a session id
 * that happens to match and never observe that bug at all — only a real
 * login through `LoginPage` (so `auth()->attempt()` and
 * `session()->regenerate()` run in their real order) followed by a real
 * `POST /keluar` exercises the actual event ordering that broke revocation.
 */
final class RecordActorSessionOnLogoutTest extends TestCase
{
    use RefreshDatabase;

    private const string PASSWORD = 'correct-horse-battery-staple';

    public function test_logging_out_after_a_real_login_revokes_that_logins_actor_session_row(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);

        Livewire::test(LoginPage::class)
            ->set('email', $user->email)
            ->set('password', self::PASSWORD)
            ->call('login')
            ->assertRedirect(route('akun.index'));

        $this->assertTrue(auth()->check());

        $row = ActorSession::query()
            ->where('user_id', $user->id)
            ->where('guard', 'web')
            ->firstOrFail();

        $this->assertNull($row->revoked_at, 'A fresh login must not already be revoked.');

        $this->post(route('logout'))->assertRedirect(route('login'));

        $this->assertFalse(auth()->check());

        $row->refresh();

        $this->assertNotNull(
            $row->revoked_at,
            'Logging out must revoke the actor_sessions row this login actually created, '.
            'even though the framework session id rotated between login and logout.',
        );
    }

    public function test_logging_out_does_not_touch_another_users_actor_session_row(): void
    {
        $loggingOutUser = User::factory()->create(['password' => self::PASSWORD]);
        $otherUser = User::factory()->create();

        ActorSession::query()->create([
            'user_id' => $otherUser->id,
            'session_id' => 'other-users-session',
            'guard' => 'web',
            'last_authenticated_at' => now(),
        ]);

        Livewire::test(LoginPage::class)
            ->set('email', $loggingOutUser->email)
            ->set('password', self::PASSWORD)
            ->call('login')
            ->assertRedirect(route('akun.index'));

        $this->post(route('logout'))->assertRedirect(route('login'));

        $otherRow = ActorSession::query()->where('user_id', $otherUser->id)->firstOrFail();

        $this->assertNull($otherRow->revoked_at);
    }

    public function test_a_guest_hitting_the_logout_route_is_a_no_op(): void
    {
        // No authenticated user at all — Laravel's own `auth:web` middleware
        // on `/keluar` refuses this before the Logout event can even fire,
        // but the listener itself must also survive a null-user event
        // without throwing (Laravel's Logout event permits a null user).
        $this->post(route('logout'))->assertRedirect(route('login'));

        $this->assertSame(0, ActorSession::query()->count());
    }
}
