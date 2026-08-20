{{--
    resources/views/livewire/public/auth/forgot-password-page.blade.php

    App\Livewire\Public\Auth\ForgotPasswordPage's view — `/lupa-password`,
    Task 3 of the `/akun` account area
    (`.superpowers/sdd/2026-08-20-akun-auth-foundation/task-3-brief.md`).
    Two states: the email form, and the generic `linkSent` confirmation —
    the confirmation is identical whether or not the email exists, by
    design (see the component's own doc block).
--}}
<div class="py-8 md:py-12">
    <div class="mx-auto max-w-content px-4">
        <div class="mx-auto w-full max-w-sm">
            <div class="mb-8 text-center">
                <h1 class="text-3xl font-semibold tracking-tight text-neutral-900">
                    Lupa Kata Sandi
                </h1>
                <p class="mt-2 text-base text-neutral-600">
                    Masukkan email Anda untuk menerima tautan reset kata sandi.
                </p>
            </div>

            <x-mk.card class="flex flex-col gap-4 p-6">
                @if ($linkSent)
                    <p class="text-center text-base text-neutral-800">
                        Jika email terdaftar, tautan reset telah dikirim.
                    </p>
                @else
                    <form wire:submit="sendResetLink" class="space-y-4" novalidate>
                        <x-mk.field
                            type="email"
                            label="Email"
                            name="email"
                            :required="true"
                            autocomplete="username"
                            wire:model="email"
                            :error="$errors->first('email')"
                        />

                        <div class="flex flex-wrap items-center gap-3">
                            <x-mk.button
                                type="submit"
                                variant="primary"
                                full
                                wire:loading.attr="disabled"
                                wire:target="sendResetLink"
                            >
                                Kirim Tautan Reset
                            </x-mk.button>
                            <span wire:loading wire:target="sendResetLink" role="status" class="flex items-center gap-2 text-sm text-neutral-600">
                                <x-mk.spinner class="size-4" aria-hidden="true" />
                                Memproses&hellip;
                            </span>
                        </div>
                    </form>
                @endif

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
