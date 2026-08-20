{{--
    resources/views/livewire/public/auth/register-page.blade.php

    App\Livewire\Public\Auth\RegisterPage's view — `/daftar`, Task 2 of the
    `/akun` account area (`.superpowers/sdd/2026-08-20-akun-auth-foundation/
    task-2-brief.md`). Guest-only registration: name, email, password,
    password confirmation, submit.
--}}
<div class="py-8 md:py-12">
    <div class="mx-auto max-w-content px-4">
        <div class="mx-auto w-full max-w-sm">
            <div class="mb-8 text-center">
                <h1 class="text-3xl font-semibold tracking-tight text-neutral-900">
                    Daftar
                </h1>
                <p class="mt-2 text-base text-neutral-600">
                    Buat akun untuk melanjutkan.
                </p>
            </div>

            <x-mk.card class="flex flex-col gap-4 p-6">
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
