{{--
    resources/views/livewire/public/auth/forgot-password-page.blade.php

    App\Livewire\Public\Auth\ForgotPasswordPage's view — `/lupa-password`,
    Task 3 of the `/akun` account area
    (`.superpowers/sdd/2026-08-20-akun-auth-foundation/task-3-brief.md`).
    Two states: the email form, and the generic `linkSent` confirmation —
    the confirmation is identical whether or not the email exists, by
    design (see the component's own doc block).

    --- FFI clone restyle (whole-frontend ticket 04, 24 Sep 2026) ---
    Rewired onto <x-mk.auth-shell> — see that component's own doc block.
    Both states below (`$linkSent` true/false) render byte-identical copy
    to before this restyle; only the wrapping shell changed.
--}}
<x-mk.auth-shell title="Lupa Kata Sandi" subtitle="Masukkan email Anda untuk menerima tautan reset kata sandi.">
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
</x-mk.auth-shell>
