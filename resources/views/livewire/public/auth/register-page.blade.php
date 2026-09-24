{{--
    resources/views/livewire/public/auth/register-page.blade.php

    App\Livewire\Public\Auth\RegisterPage's view — `/daftar`, Task 2 of the
    `/akun` account area (`.superpowers/sdd/2026-08-20-akun-auth-foundation/
    task-2-brief.md`). Guest-only registration: name, email, password,
    password confirmation, submit.

    --- FFI clone restyle (whole-frontend ticket 04, 24 Sep 2026) ---
    Rewired onto <x-mk.auth-shell> — see that component's own doc block.
    All copy, links, and Livewire wiring below are byte-identical to
    before this restyle; only the wrapping shell changed.
--}}
<x-mk.auth-shell title="Daftar" subtitle="Buat akun untuk melanjutkan.">
    <p class="mb-6 text-center text-sm text-neutral-500">
        Akun yang dibuat di halaman ini adalah akun Pelanggan.
    </p>

    <form wire:submit="register" class="space-y-4" novalidate>
        <x-mk.field
            type="text"
            label="Nama"
            name="name"
            :required="true"
            autocomplete="name"
            wire:model="name"
            :error="$errors->first('name')"
        />

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
            autocomplete="new-password"
            wire:model="password"
            :error="$errors->first('password')"
        />

        <x-mk.field
            type="password"
            label="Konfirmasi Kata Sandi"
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
                wire:target="register"
            >
                Daftar
            </x-mk.button>
            <span wire:loading wire:target="register" role="status" class="flex items-center gap-2 text-sm text-neutral-600">
                <x-mk.spinner class="size-4" aria-hidden="true" />
                Memproses&hellip;
            </span>
        </div>
    </form>

    <div class="flex flex-col items-center gap-2 pt-2 text-sm">
        <p class="text-neutral-600">
            Sudah punya akun?
            <a href="{{ route('login') }}" class="font-medium text-primary-700 underline underline-offset-2">
                Masuk
            </a>
        </p>
    </div>
</x-mk.auth-shell>
