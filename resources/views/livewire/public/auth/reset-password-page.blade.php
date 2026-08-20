{{--
    resources/views/livewire/public/auth/reset-password-page.blade.php

    App\Livewire\Public\Auth\ResetPasswordPage's view —
    `/reset-password/{token}`, Task 3 of the `/akun` account area
    (`.superpowers/sdd/2026-08-20-akun-auth-foundation/task-3-brief.md`).
    Email (pre-filled from the `?email=` query string when present),
    new password, confirmation, submit. No remember-me, no auto-login.
--}}
<div class="py-8 md:py-12">
    <div class="mx-auto max-w-content px-4">
        <div class="mx-auto w-full max-w-sm">
            <div class="mb-8 text-center">
                <h1 class="text-3xl font-semibold tracking-tight text-neutral-900">
                    Reset Kata Sandi
                </h1>
                <p class="mt-2 text-base text-neutral-600">
                    Masukkan kata sandi baru Anda.
                </p>
            </div>

            <x-mk.card class="flex flex-col gap-4 p-6">
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
