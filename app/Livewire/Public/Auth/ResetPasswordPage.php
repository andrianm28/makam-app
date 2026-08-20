<?php

declare(strict_types=1);

namespace App\Livewire\Public\Auth;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Livewire\Component;

/**
 * `/reset-password/{token}` — Task 3 of the `/akun` account area
 * (`.superpowers/sdd/2026-08-20-akun-auth-foundation/task-3-brief.md`).
 * The guest-only password-reset surface, reached via the link
 * `Illuminate\Auth\Notifications\ResetPassword::toMail()` builds for the
 * already-wired `Password::` broker.
 *
 * No `auth()->login()` call after a successful reset — a hard requirement
 * (see the brief's own reasoning), not a style preference. No
 * `ActorContextResolver::forget()` either — no guard mutation happens
 * anywhere in this component.
 *
 * Laravel's broker fails closed on an invalid, expired, or
 * email-mismatched token, and every one of those outcomes surfaces here
 * as the same generic error — no enumeration of which specific thing was
 * wrong.
 *
 * No `RateLimiter` call in `reset()`, unlike the other three auth
 * components — deliberate, not an oversight: the token itself is the rate
 * limit. It is a high-entropy value the broker generates and hashes (see
 * `config/auth.php`'s `password_reset_tokens` wiring), so brute-forcing a
 * valid `{token}`/email pair off this route is infeasible regardless of
 * attempt count. The group-level `throttle:public-guest` limiter
 * (`bootstrap/app.php`) still backstops this route the same as every other
 * public route, so it is never fully unthrottled.
 */
final class ResetPasswordPage extends Component
{
    public string $token = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;

        // request()->query('email', ...) returns an array, not a string,
        // when a visitor supplies a crafted `?email[]=x` query string.
        // Assigning that straight into the typed `public string $email`
        // property would throw a TypeError -> uncaught 500 on this
        // unauthenticated public route, so the array shape is rejected in
        // favour of the default instead of being assigned.
        $queryEmail = request()->query('email');
        $this->email = is_string($queryEmail) ? $queryEmail : '';
    }

    public function reset(): void
    {
        $this->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset(
            [
                'token' => $this->token,
                'email' => $this->email,
                'password' => $this->password,
                'password_confirmation' => $this->password_confirmation,
            ],
            function ($user, $password): void {
                // Rotating the remember token here means a `remember_web_...`
                // recaller cookie captured before this reset (this branch's
                // own `LoginPage` ships remember-me) stops authenticating the
                // instant the password changes — a real, not theoretical,
                // gap otherwise: a stolen recaller cookie would keep working
                // even after the account holder "secured" their account by
                // resetting the password.
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            session()->flash('status', 'Kata sandi berhasil direset. Silakan masuk.');
            $this->redirect(route('login'), navigate: false);

            return;
        }

        $this->addError('email', 'Tautan reset tidak valid atau sudah kedaluwarsa.');

        // Not `$this->reset(...)` — this class's own submit action is named
        // `reset()` (matching the blade's `wire:submit="reset"`), which
        // shadows `Livewire\Component::reset()`. Calling `$this->reset(...)`
        // here would recurse into THIS method instead of clearing the
        // properties. `parent::reset(...)` reaches the real one.
        parent::reset('password', 'password_confirmation');
    }

    public function render(): View
    {
        return view('livewire.public.auth.reset-password-page')
            ->layout('layouts.app', [
                'title' => 'Reset Kata Sandi - Makam.co.id',
                'active' => null,
            ]);
    }
}
