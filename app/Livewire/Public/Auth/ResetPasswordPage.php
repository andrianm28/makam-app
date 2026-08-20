<?php

declare(strict_types=1);

namespace App\Livewire\Public\Auth;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Password;
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
        $this->email = request()->query('email', '');
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
                $user->forceFill(['password' => $password])->save();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            session()->flash('status', 'Kata sandi berhasil direset. Silakan masuk.');
            $this->redirect(route('login'), navigate: false);

            return;
        }

        $this->addError('email', 'Tautan reset tidak valid atau sudah kedaluwarsa.');
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
