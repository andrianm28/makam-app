{{--
    resources/views/filament/admin/pages/mfa-settings.blade.php

    View for App\Filament\Admin\Pages\MfaSettings. Same component choice as
    mfa-challenge.blade.php (Task 4): Filament's own Blade component set
    (`<x-filament-panels::page>`, `<x-filament::input.*>`,
    `<x-filament::button>`) rather than the public site's `<x-mk.*>`
    primitives, for the identical reason that file's own doc block already
    records — design-system.md has no Filament-specific form/field guidance
    (checked §8.3), and `<x-mk.*>` component files live outside this
    panel's own Tailwind `@source` scan. Colour utilities used below trace
    to the same tokens.css this panel's theme already `@import`s (see e.g.
    resources/views/components/mk/modal.blade.php's own `bg-warning-600`/
    `bg-success-600` usage for the same class names) — no hardcoded value,
    no arbitrary Tailwind bracket value.

    No QR-rendering package is a dependency of this app (checked
    composer.json before writing this view) and none was added for this
    plan — the `otpauth://` URI below is rendered as selectable plain text
    with a copy-to-clipboard affordance (Alpine.js, already bundled by
    Filament's panel JS) instead of a scanned QR image. A deliberate
    scope-minimizing choice, not an oversight.
--}}
@php
    $isPending = $enrolmentStatus === \App\Platform\IdentityAccess\Mfa\MfaEnrolmentStatus::PENDING;
    $isConfirmed = $enrolmentStatus === \App\Platform\IdentityAccess\Mfa\MfaEnrolmentStatus::CONFIRMED;
@endphp
<x-filament-panels::page>
    <div class="grid gap-y-8">
        <div class="grid gap-y-1.5">
            <p class="text-sm font-medium text-neutral-800">
                Status: {{ $isConfirmed ? 'Aktif' : 'Menunggu konfirmasi' }}
            </p>
            <p class="text-sm text-neutral-600">
                @if ($isConfirmed)
                    Autentikasi dua faktor aktif untuk akun Anda.
                @else
                    Pindai kode di bawah dengan aplikasi authenticator Anda, lalu masukkan kode 6 digit untuk mengaktifkan.
                @endif
            </p>
        </div>

        @if ($isPending)
            <div class="grid gap-y-6" x-data="{ copied: false }">
                <div class="grid gap-y-1.5">
                    <label for="mfa-settings-otpauth-uri" class="text-sm font-medium text-neutral-800">
                        URI pengaturan
                    </label>

                    <p class="text-sm text-neutral-600">
                        Aplikasi authenticator Anda tidak dapat memindai kode QR di sini. Salin URI ini dan tambahkan secara manual, atau tempelkan pada aplikasi yang mendukung impor URI.
                    </p>

                    <x-filament::input.wrapper>
                        <x-filament::input
                            id="mfa-settings-otpauth-uri"
                            type="text"
                            value="{{ $otpauthUri }}"
                            readonly
                            x-ref="otpauthUri"
                        />
                    </x-filament::input.wrapper>

                    <div>
                        <x-filament::button
                            type="button"
                            color="gray"
                            x-on:click="navigator.clipboard.writeText($refs.otpauthUri.value); copied = true; setTimeout(() => copied = false, 2000)"
                        >
                            <span x-show="!copied">Salin URI</span>
                            <span x-show="copied" x-cloak>Tersalin!</span>
                        </x-filament::button>
                    </div>
                </div>

                <form wire:submit="confirmEnrolment" class="grid gap-y-6">
                    <div class="grid gap-y-1.5">
                        <label for="mfa-settings-confirmation-code" class="text-sm font-medium text-neutral-800">
                            Kode verifikasi
                        </label>

                        <x-filament::input.wrapper :valid="! $errors->has('confirmationCode')">
                            <x-filament::input
                                id="mfa-settings-confirmation-code"
                                type="text"
                                wire:model="confirmationCode"
                                autocomplete="one-time-code"
                                required
                            />
                        </x-filament::input.wrapper>

                        @error('confirmationCode')
                            <p class="text-sm text-danger-700">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <x-filament::button type="submit">
                            Konfirmasi
                        </x-filament::button>
                    </div>
                </form>
            </div>
        @endif

        @if ($displayedRecoveryCodes !== [])
            <div class="grid gap-y-3 rounded-lg border border-warning-600 bg-neutral-0 p-4">
                <p class="text-sm font-medium text-warning-700">
                    Simpan kode pemulihan ini sekarang — kode ini tidak akan ditampilkan lagi.
                </p>

                <ul class="grid grid-cols-2 gap-2 font-mono text-sm text-neutral-900">
                    @foreach ($displayedRecoveryCodes as $code)
                        <li>{{ $code }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($isConfirmed)
            <div class="grid gap-y-4 border-t border-neutral-200 pt-6">
                <div class="grid gap-y-1.5">
                    <p class="text-sm font-medium text-neutral-800">
                        Kode pemulihan
                    </p>
                    <p class="text-sm text-neutral-600">
                        Membuat kode pemulihan baru akan membatalkan semua kode pemulihan yang belum digunakan.
                    </p>

                    <div>
                        <x-filament::button type="button" color="gray" wire:click="regenerateRecoveryCodes">
                            Buat ulang kode pemulihan
                        </x-filament::button>
                    </div>
                </div>

                <div class="grid gap-y-1.5">
                    <p class="text-sm font-medium text-neutral-800">
                        Nonaktifkan MFA
                    </p>
                    <p class="text-sm text-neutral-600">
                        Menonaktifkan MFA memerlukan autentikasi ulang.
                    </p>

                    {{--
                        Plain HTML `<form method="POST">`, not
                        `<x-filament::button tag="a">` or a Livewire
                        `wire:click` — `admin.mfa.disable` (Task 6) is
                        registered as `Route::post(...)`, and disabling is
                        deliberately a real HTTP round trip through
                        `RequireRecentAuthentication`, not a Livewire action
                        this page performs itself (see this page's own class
                        doc block). Styled with the same `bg-danger-600` /
                        `hover:bg-danger-700` / `active:bg-danger-800` classes
                        resources/views/components/mk/button.blade.php's own
                        'danger' variant uses — tokens.css-derived, not invented here.
                    --}}
                    <div>
                        <form method="POST" action="{{ route('admin.mfa.disable') }}">
                            @csrf
                            <button
                                type="submit"
                                class="inline-flex h-11 select-none items-center justify-center gap-2 rounded-md border border-transparent bg-danger-600 px-4 text-base font-medium text-neutral-0 transition-[color,background-color,border-color,box-shadow] duration-fast ease-standard hover:bg-danger-700 active:bg-danger-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-danger-600 focus-visible:ring-offset-2"
                            >
                                Nonaktifkan MFA
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
