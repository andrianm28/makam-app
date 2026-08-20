<?php

declare(strict_types=1);

namespace App\Livewire\Public\Auth;

use App\Platform\IdentityAccess\ActorContextResolver;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * `/masuk` — Task 1 of the `/akun` account area
 * (`.superpowers/sdd/2026-08-20-akun-auth-foundation/task-1-brief.md`).
 * The guest-only login surface: same-origin session auth via the `web`
 * guard (AGENTS.md §Authentication: "Use same-origin session auth for
 * MVP"), with a Laravel-Breeze-shaped rate limit and a no-enumeration
 * error message shared by both "unknown email" and "wrong password".
 *
 * ---------------------------------------------------------------------------
 * Why `ActorContextResolver::forget()` runs immediately after `auth()->attempt()`
 * ---------------------------------------------------------------------------
 * `ActorContextResolver` caches its resolution for the lifetime of the
 * request (see that class's own doc block). If anything earlier in this
 * request already resolved a guest `ActorContext` (e.g. a future header
 * component), that cached guest value would otherwise survive past this
 * mid-request guard mutation and keep being served to every later consumer
 * in the same request — including the very redirect response this action
 * returns. `forget()` is the documented escape hatch for exactly this case;
 * dropping it silently reintroduces a stale-actor bug this class's own test
 * suite is written to catch.
 */
final class LoginPage extends Component
{
    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    public function login(): void
    {
        $this->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $key = 'login:'.Str::lower($this->email).'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            Event::dispatch(new Lockout(request()));

            $seconds = RateLimiter::availableIn($key);

            $this->addError('email', "Terlalu banyak percobaan masuk. Coba lagi dalam {$seconds} detik.");
            $this->reset('password');

            return;
        }

        if (! auth()->attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            RateLimiter::hit($key, 60);

            $this->addError('email', 'Email atau kata sandi salah.');
            $this->reset('password');

            return;
        }

        session()->regenerate();
        app(ActorContextResolver::class)->forget();
        RateLimiter::clear($key);

        // `redirect()->intended('/')` (not a literal `$this->redirect('/')`)
        // is what makes this work for both a plain `/masuk` visit and a
        // guest bounced here by `Authenticate` from a protected route:
        // Laravel's `Redirector::intended()` reads and consumes the
        // `url.intended` session key that middleware sets, falling back to
        // the given default. Its target URL is handed to Livewire's own
        // `redirect()` (the house convention every other component here
        // uses) rather than returned directly, since `login()` keeps the
        // brief's `void` signature.
        $this->redirect(redirect()->intended('/')->getTargetUrl(), navigate: false);
    }

    public function render(): View
    {
        return view('livewire.public.auth.login-page')
            ->layout('layouts.app', [
                'title' => 'Masuk - Makam.co.id',
                'active' => null,
            ]);
    }
}
