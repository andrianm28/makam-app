<?php

declare(strict_types=1);

namespace App\Livewire\Public\Auth;

use App\Models\User;
use App\Platform\IdentityAccess\ActorContextResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Livewire\Component;

/**
 * `/daftar` — Task 2 of the `/akun` account area
 * (`.superpowers/sdd/2026-08-20-akun-auth-foundation/task-2-brief.md`).
 * The guest-only registration surface: creates a `users` row and
 * authenticates the new actor immediately via the `web` guard, same
 * same-origin session auth convention as `LoginPage` (AGENTS.md
 * §Authentication).
 *
 * A newly registered user holds zero `ActorRole` grants, so
 * `User::canAccessPanel()` denies both the `admin` and `vendor` Filament
 * panels for it by construction — see `UserCanAccessPanelTest` for the
 * general mechanism this relies on.
 *
 * ---------------------------------------------------------------------------
 * Why `ActorContextResolver::forget()` runs immediately after `auth()->login()`
 * ---------------------------------------------------------------------------
 * Same reasoning as `LoginPage::login()`'s own doc block: `forget()` is the
 * documented escape hatch for a mid-request guard mutation that happens
 * after something earlier in the same request already cached a guest
 * `ActorContext`.
 *
 * ---------------------------------------------------------------------------
 * Accepted email-enumeration exception
 * ---------------------------------------------------------------------------
 * Unlike `LoginPage` and `ForgotPasswordPage`, which deliberately share one
 * generic error/confirmation regardless of whether the email exists, this
 * component's `'unique:users,email'` validation rule necessarily reveals
 * whether an email is already registered. That is a standard, near-
 * universal tradeoff for registration forms (a form has to say "that email
 * is taken" somehow), blunted here by the 3/min/IP rate limit below. This
 * is a deliberate, accepted exception to the rest of this branch's
 * non-enumeration discipline, not an oversight.
 */
final class RegisterPage extends Component
{
    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function register(): void
    {
        $key = 'register:'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 3)) {
            $seconds = RateLimiter::availableIn($key);

            $this->addError('email', "Terlalu banyak percobaan pendaftaran. Coba lagi dalam {$seconds} detik.");
            $this->reset('password', 'password_confirmation');

            return;
        }

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        RateLimiter::hit($key, 60);

        $user = User::query()->create([
            'name' => $this->name,
            'email' => $this->email,
            'password' => $this->password,
        ]);

        auth()->login($user);
        session()->regenerate();
        app(ActorContextResolver::class)->forget();

        // `redirectIntended('/')` — see `LoginPage::login()`'s doc comment
        // for why: `redirect()->intended('/')->getTargetUrl()` throws at
        // runtime inside a Livewire action (the global `redirect()` helper
        // resolves to Livewire's own `Redirector`, which has no
        // `getTargetUrl()` method). `'/'` is the same PR-1 fallback as
        // `LoginPage`, not `route('akun.index')` (that route does not exist
        // yet).
        $this->redirectIntended('/', navigate: false);
    }

    public function render(): View
    {
        return view('livewire.public.auth.register-page')
            ->layout('layouts.app', [
                'title' => 'Daftar - Makam.co.id',
                'active' => null,
            ]);
    }
}
