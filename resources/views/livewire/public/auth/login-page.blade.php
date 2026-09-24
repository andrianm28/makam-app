{{--
    resources/views/livewire/public/auth/login-page.blade.php

    App\Livewire\Public\Auth\LoginPage's view — `/masuk`, Task 1 of the
    `/akun` account area (`.superpowers/sdd/2026-08-20-akun-auth-foundation/
    task-1-brief.md`). Guest-only login: email, password, remember-me,
    submit.

    --- Preflight ruling (recorded before Task 1 was dispatched) ---
    The "Daftar" and "Lupa kata sandi?" links below used the LITERAL paths
    `/daftar` and `/lupa-password` in Task 1, NOT `route('register')`/
    `route('password.request')` — those named routes didn't exist until
    Task 2 and Task 3 respectively, and this view is rendered by
    `Livewire::test(LoginPage::class)` before either route is registered.
    Task 2 swapped `/daftar` for `route('register')` once that route
    existed. Task 3 (this change) does the same for `/lupa-password` now
    that `route('password.request')` exists.

    --- FFI clone restyle (whole-frontend ticket 04, 24 Sep 2026) ---
    Rewired onto <x-mk.auth-shell> — see that component's own doc block for
    why. All copy, links, and Livewire wiring below are byte-identical to
    before this restyle; only the wrapping shell changed.
--}}
<x-mk.auth-shell title="Masuk" subtitle="Masuk ke akun Anda untuk melanjutkan.">
    <p class="mb-6 text-center text-sm text-neutral-500">
        Halaman ini untuk masuk sebagai Pelanggan. Vendor Jasa masuk di
        <a href="{{ route('filament.vendor.auth.login') }}" class="font-medium text-primary-700 underline underline-offset-2">sini</a>,
        Pengelola TPU masuk di
        <a href="{{ route('filament.operator.auth.login') }}" class="font-medium text-primary-700 underline underline-offset-2">sini</a>.
    </p>

    @if (session('status'))
        <x-mk.alert intent="success" class="mb-4">{{ session('status') }}</x-mk.alert>
    @endif

    <form wire:submit="login" class="space-y-4" novalidate>
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
            label="Kata Sandi"
            name="password"
            :required="true"
            autocomplete="current-password"
            wire:model="password"
            :error="$errors->first('password')"
        />

        <x-mk.field
            type="checkbox"
            label="Ingat saya"
            name="remember"
            wire:model="remember"
        />

        <div class="flex flex-wrap items-center gap-3">
            <x-mk.button
                type="submit"
                variant="primary"
                full
                wire:loading.attr="disabled"
                wire:target="login"
            >
                Masuk
            </x-mk.button>
            <span wire:loading wire:target="login" role="status" class="flex items-center gap-2 text-sm text-neutral-600">
                <x-mk.spinner class="size-4" aria-hidden="true" />
                Memproses&hellip;
            </span>
        </div>
    </form>

    <div class="flex flex-col items-center gap-2 pt-2 text-sm">
        <a href="{{ route('password.request') }}" class="font-medium text-primary-700 underline underline-offset-2">
            Lupa kata sandi?
        </a>
        <p class="text-neutral-600">
            Belum punya akun?
            <a href="{{ route('register') }}" class="font-medium text-primary-700 underline underline-offset-2">
                Daftar
            </a>
        </p>
    </div>
</x-mk.auth-shell>
