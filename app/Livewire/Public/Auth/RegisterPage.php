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

            return;
        }

        RateLimiter::hit($key, 60);

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = User::query()->create([
            'name' => $this->name,
            'email' => $this->email,
            'password' => $this->password,
        ]);

        auth()->login($user);
        session()->regenerate();
        app(ActorContextResolver::class)->forget();

        // `redirect()->intended('/')` — same PR-1 fallback as `LoginPage`,
        // not `route('akun.index')` (that route does not exist yet). Its
        // target URL is handed to Livewire's own `redirect()` (the house
        // convention every other component here uses) rather than returned
        // directly, since `register()` keeps the brief's `void` signature.
        $this->redirect(redirect()->intended('/')->getTargetUrl(), navigate: false);
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
