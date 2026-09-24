{{--
    resources/views/livewire/public/auth/reset-password-page.blade.php

    App\Livewire\Public\Auth\ResetPasswordPage's view —
    `/reset-password/{token}`, Task 3 of the `/akun` account area
    (`.superpowers/sdd/2026-08-20-akun-auth-foundation/task-3-brief.md`).
    Email (pre-filled from the `?email=` query string when present),
    new password, confirmation, submit. No remember-me, no auto-login.

    --- FFI clone restyle (whole-frontend ticket 04, 24 Sep 2026) ---
    Rewired onto <x-mk.auth-shell> — see that component's own doc block.
    All copy and Livewire wiring below are byte-identical to before this
    restyle; only the wrapping shell changed.
--}}
<x-mk.auth-shell title="Reset Kata Sandi" subtitle="Masukkan kata sandi baru Anda.">
    <form wire:submit="submitReset" class="space-y-4" novalidate>
        <x-mk.field
            type="email"
            label="Email"
            name="email"
            :required="true"
            autocomplete="username"
            wire:model="email"
            :error="$errors->first('email')"
        />

        <x-mk.field
            type="password"
            label="Kata Sandi Baru"
            name="password"
            :required="true"
            autocomplete="new-password"
            wire:model="password"
            :error="$errors->first('password')"
        />

        <x-mk.field
            type="password"
            label="Konfirmasi Kata Sandi Baru"
            name="password_confirmation"
            :required="true"
            autocomplete="new-password"
            wire:model="password_confirmation"
        />

        <div class="flex flex-wrap items-center gap-3">
            <x-mk.button
                type="submit"
                variant="primary"
                full
                wire:loading.attr="disabled"
                wire:target="submitReset"
            >
                Reset Kata Sandi
            </x-mk.button>
            <span wire:loading wire:target="submitReset" role="status" class="flex items-center gap-2 text-sm text-neutral-600">
                <x-mk.spinner class="size-4" aria-hidden="true" />
                Memproses&hellip;
            </span>
        </div>
    </form>

    <div class="flex flex-col items-center gap-2 pt-2 text-sm">
        <p class="text-neutral-600">
            Sudah ingat kata sandi Anda?
            <a href="{{ route('login') }}" class="font-medium text-primary-700 underline underline-offset-2">
                Masuk
            </a>
        </p>
    </div>
</x-mk.auth-shell>
