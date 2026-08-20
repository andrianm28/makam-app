{{--
    resources/views/livewire/public/auth/login-page.blade.php

    App\Livewire\Public\Auth\LoginPage's view — `/masuk`, Task 1 of the
    `/akun` account area (`.superpowers/sdd/2026-08-20-akun-auth-foundation/
    task-1-brief.md`). Guest-only login: email, password, remember-me,
    submit.

    --- Preflight ruling (recorded before Task 1 was dispatched) ---
    The "Daftar" and "Lupa kata sandi?" links below use the LITERAL paths
    `/daftar` and `/lupa-password`, NOT `route('register')`/
    `route('password.request')` — those named routes don't exist until
    Task 2 and Task 3 respectively, and this view is rendered by
    `Livewire::test(LoginPage::class)` before either route is registered.
    Task 2 and Task 3 each swap their own literal path here for the real
    named-route call once that route exists; same destination URL either
    way.
--}}
<div class="py-8 md:py-12">
    <div class="mx-auto max-w-content px-4">
        <div class="mx-auto w-full max-w-sm">
            <div class="mb-8 text-center">
                <h1 class="text-3xl font-semibold tracking-tight text-neutral-900">
                    Masuk
                </h1>
                <p class="mt-2 text-base text-neutral-600">
                    Masuk ke akun Anda untuk melanjutkan.
                </p>
            </div>

            <x-mk.card class="flex flex-col gap-4 p-6">
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
                    <a href="/lupa-password" class="font-medium text-primary-700 underline underline-offset-2">
                        Lupa kata sandi?
                    </a>
                    <p class="text-neutral-600">
                        Belum punya akun?
                        <a href="/daftar" class="font-medium text-primary-700 underline underline-offset-2">
                            Daftar
                        </a>
                    </p>
                </div>
            </x-mk.card>

            {{-- §6.10 support escape hatch — required on every transactional
                 screen. --}}
            <p class="mt-10 text-center text-sm text-neutral-600">
                Butuh bantuan?
                <a href="/bantuan" class="font-medium underline underline-offset-2">Hubungi Bantuan</a>.
            </p>
        </div>
    </div>
</div>
