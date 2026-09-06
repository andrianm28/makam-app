<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Finding SEC-04 (6 Sep 2026 audit): before this fix, `\Illuminate\Session\
 * Middleware\AuthenticateSession` was registered on the three Filament
 * panel middleware stacks but never on the plain `web` group — the group
 * `/akun`, `/masuk`, `/daftar`, and the password-reset flow all run
 * through. A session authenticated BEFORE a password reset kept
 * authenticating the whole `/akun` area indefinitely afterward: a stolen
 * session cookie survived the account holder "securing" their account.
 *
 * This proves the real middleware, attached to the real `web` group, via a
 * real authenticated GET to an existing `/akun` route — not a synthetic
 * test route — followed by a direct password change (standing in for
 * `ResetPasswordPage::submitReset()`'s own `$user->forceFill(['password'
 * => ...])->save()`, the exact write this middleware reacts to) and a
 * second request on the SAME session.
 */
final class AuthenticateSessionOnPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_a_session_authenticated_before_a_password_change_is_logged_out_on_its_next_request(): void
    {
        $user = User::factory()->create(['password' => bcrypt('OldPassword!123')]);

        $this->actingAs($user)
            ->get(route('akun.index'))
            ->assertOk();

        // Stand-in for ResetPasswordPage::submitReset()'s own write.
        $user->forceFill(['password' => bcrypt('NewPassword!456')])->save();

        $this->get(route('akun.index'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_a_session_with_no_intervening_password_change_stays_authenticated(): void
    {
        $user = User::factory()->create(['password' => bcrypt('SamePassword!123')]);

        $this->actingAs($user)
            ->get(route('akun.index'))
            ->assertOk();

        $this->get(route('akun.index'))
            ->assertOk();

        $this->assertAuthenticatedAs($user);
    }
}
