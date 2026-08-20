<?php

declare(strict_types=1);

namespace App\Livewire\Public\Auth;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * `/lupa-password` — Task 3 of the `/akun` account area
 * (`.superpowers/sdd/2026-08-20-akun-auth-foundation/task-3-brief.md`).
 * The guest-only forgot-password surface: dispatches Laravel's built-in
 * password-reset broker (already wired to `password_reset_tokens` via
 * `config/auth.php`) and always shows the same generic confirmation,
 * regardless of whether the submitted email exists — this is what gives
 * the no-enumeration property for free (AGENTS.md's non-enumeration
 * convention, matching `LoginPage`'s shared error message for wrong
 * password vs. unknown email).
 *
 * Rate limiting mirrors `RegisterPage::register()`'s idiom, not
 * `LoginPage::login()`'s: the hit is unconditional on every VALIDATED
 * attempt (there is no success/failure branch to hit on here, unlike a
 * login attempt) — the lockout check runs before validation, but the hit
 * itself runs after `validate()` succeeds, so an ordinary typo (a
 * malformed email) does not burn the caller's limited attempt budget.
 *
 * No `ActorContextResolver::forget()` here — no guard mutation happens
 * anywhere in this component.
 */
final class ForgotPasswordPage extends Component
{
    public string $email = '';

    public bool $linkSent = false;

    public function sendResetLink(): void
    {
        $key = 'password-reset:'.Str::lower($this->email).'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 3)) {
            $seconds = RateLimiter::availableIn($key);

            $this->addError('email', "Terlalu banyak percobaan. Coba lagi dalam {$seconds} detik.");

            return;
        }

        $this->validate([
            'email' => ['required', 'email'],
        ]);

        RateLimiter::hit($key, 60);

        // The return value is deliberately never branched on for display —
        // see this class's own doc block. Both a known and an unknown email
        // land on the exact same `linkSent` confirmation state.
        Password::sendResetLink(['email' => $this->email]);

        $this->linkSent = true;
    }

    public function render(): View
    {
        return view('livewire.public.auth.forgot-password-page')
            ->layout('layouts.app', [
                'title' => 'Lupa Kata Sandi - Makam.co.id',
                'active' => null,
            ]);
    }
}
