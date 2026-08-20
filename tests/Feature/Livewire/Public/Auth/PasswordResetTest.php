<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Auth;

use App\Livewire\Public\Auth\ForgotPasswordPage;
use App\Livewire\Public\Auth\LoginPage;
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

        Notification::assertSentTo($user, ResetPassword::class);

        // Reset the fake between sub-cases (same pattern the rate-limit
        // test below uses): `NotificationFake::assertNothingSentTo()`
        // requires an actual notifiable (Model/AnonymousNotifiable), not a
        // raw email string — there is no notifiable to assert against for
        // a nonexistent email, so the ONLY way to prove nothing was sent
        // for the unknown case is a clean fake plus `assertNothingSent()`
        // (no argument).
        Notification::fake();

        $unknown = Livewire::test(ForgotPasswordPage::class)
            ->set('email', 'does-not-exist@example.test')
            ->call('sendResetLink');

        Notification::assertNothingSent();
        $unknown->assertSet('linkSent', true);

        // `wire:id` is a random per-render instance token Livewire embeds
        // in the rendered root element — comparing raw `html()` output
        // would fail on that alone even though every OTHER byte is
        // identical, since `$known` and `$unknown` are two separate
        // component instances. Strip it before comparing so the assertion
        // actually verifies the thing it claims to (identical confirmation
        // content), not "these happen to be the same component instance,"
        // which they never are.
        $stripWireId = static fn (string $html): string => preg_replace('/wire:id="[^"]*"/', 'wire:id="STRIPPED"', $html);

        $this->assertSame(
            $stripWireId($known->html()),
            $stripWireId($unknown->html()),
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
            ->call('submitReset')
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
            ->call('submitReset')
            ->assertHasErrors(['email']);

        $user->refresh();

        $this->assertSame($originalHash, $user->password);
        $this->assertFalse(auth()->check());
    }

    /**
     * A stolen `remember_web_...` recaller cookie must stop authenticating
     * the moment its owner resets their password — this branch's own
     * `LoginPage` ships remember-me, so this is a real gap, not a
     * theoretical one. Simplest sufficient proof (per the fix-wave brief):
     * assert the `remember_token` differs after a successful reset from
     * its value before, rather than simulating the full cookie round trip.
     */
    public function test_a_successful_reset_rotates_the_remember_token(): void
    {
        $user = User::factory()->create(['password' => 'old-password']);

        Livewire::test(LoginPage::class)
            ->set('email', $user->email)
            ->set('password', 'old-password')
            ->set('remember', true)
            ->call('login')
            ->assertRedirect('/');

        $user->refresh();
        $originalRememberToken = $user->remember_token;
        $this->assertNotEmpty($originalRememberToken, 'The remember-me login must set a remember_token to rotate.');

        $token = Password::broker()->createToken($user);

        Livewire::test(ResetPasswordPage::class, ['token' => $token])
            ->set('email', $user->email)
            ->set('password', 'a-brand-new-password')
            ->set('password_confirmation', 'a-brand-new-password')
            ->call('submitReset')
            ->assertRedirect(route('login'));

        $user->refresh();

        $this->assertNotEmpty($user->remember_token);
        $this->assertNotSame($originalRememberToken, $user->remember_token);
    }

    public function test_reset_password_mount_does_not_crash_on_an_array_shaped_email_query_parameter(): void
    {
        $response = $this->get('/reset-password/some-token?email[]=x');

        $response->assertOk();
    }

    /**
     * The 4th call landing on "nothing sent" is ambiguous by itself: it
     * could be this component's own `RateLimiter` blocking the attempt, or
     * it could be Laravel's `PasswordBroker`'s own 60s per-user throttle
     * (`config/auth.php`'s `'throttle' => 60`) blocking it instead — both
     * produce the identical "no notification" outcome. `linkSent` is what
     * discriminates them: the component ALWAYS sets it to `true` after
     * calling the broker, regardless of the broker's own result, so
     * `linkSent === false` plus a validation error can only come from THIS
     * component's own limiter short-circuiting before the broker is ever
     * called.
     */
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
            ->call('sendResetLink')
            ->assertHasErrors(['email'])
            ->assertSet('linkSent', false);

        Notification::assertNothingSent();
    }
}
