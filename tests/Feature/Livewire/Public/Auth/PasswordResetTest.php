<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Auth;

use App\Livewire\Public\Auth\ForgotPasswordPage;
use App\Livewire\Public\Auth\ResetPasswordPage;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * `ForgotPasswordPage` (`/lupa-password`) and `ResetPasswordPage`
 * (`/reset-password/{token}`) — Task 3 of the `/akun` account area
 * (`.superpowers/sdd/2026-08-20-akun-auth-foundation/task-3-brief.md`).
 *
 * The unknown-email test is the one that actually proves no enumeration:
 * it asserts the rendered `linkSent` confirmation is byte-for-byte
 * identical whether or not the email exists.
 */
final class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_known_email_sends_a_reset_link_and_shows_the_generic_confirmation(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $component = Livewire::test(ForgotPasswordPage::class)
            ->set('email', $user->email)
            ->call('sendResetLink');

        Notification::assertSentTo($user, ResetPassword::class);
        $component->assertSet('linkSent', true);
        $component->assertSee('Jika email terdaftar, tautan reset telah dikirim.');
    }

    public function test_unknown_email_shows_the_identical_generic_confirmation_and_sends_nothing(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $known = Livewire::test(ForgotPasswordPage::class)
            ->set('email', $user->email)
            ->call('sendResetLink');

        $unknown = Livewire::test(ForgotPasswordPage::class)
            ->set('email', 'does-not-exist@example.test')
            ->call('sendResetLink');

        Notification::assertNothingSentTo('does-not-exist@example.test');
        $unknown->assertSet('linkSent', true);

        $this->assertSame(
            $known->html(),
            $unknown->html(),
            'A known and unknown email must render the identical confirmation shape.',
        );
    }

    public function test_valid_token_and_matching_email_resets_the_password_without_auto_login(): void
    {
        $user = User::factory()->create(['password' => 'old-password']);
        $token = Password::broker()->createToken($user);

        Livewire::test(ResetPasswordPage::class, ['token' => $token])
            ->set('email', $user->email)
            ->set('password', 'a-brand-new-password')
            ->set('password_confirmation', 'a-brand-new-password')
            ->call('reset')
            ->assertRedirect(route('login'));

        $user->refresh();

        $this->assertTrue(Hash::check('a-brand-new-password', $user->password));
        $this->assertFalse(auth()->check());
    }

    public function test_invalid_token_shows_a_generic_error_and_leaves_the_password_unchanged(): void
    {
        $user = User::factory()->create(['password' => 'old-password']);
        $originalHash = $user->password;

        Livewire::test(ResetPasswordPage::class, ['token' => 'not-a-real-token'])
            ->set('email', $user->email)
            ->set('password', 'a-brand-new-password')
            ->set('password_confirmation', 'a-brand-new-password')
            ->call('reset')
            ->assertHasErrors(['email']);

        $user->refresh();

        $this->assertSame($originalHash, $user->password);
        $this->assertFalse(auth()->check());
    }

    public function test_the_fourth_send_reset_link_attempt_within_sixty_seconds_is_blocked(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        for ($i = 0; $i < 3; $i++) {
            Livewire::test(ForgotPasswordPage::class)
                ->set('email', $user->email)
                ->call('sendResetLink');
        }

        Notification::assertSentTo($user, ResetPassword::class);
        Notification::fake();

        Livewire::test(ForgotPasswordPage::class)
            ->set('email', $user->email)
            ->call('sendResetLink');

        Notification::assertNothingSent();
    }
}
