{{--
    resources/views/filament/admin/pages/mfa-challenge.blade.php

    View for App\Filament\Admin\Pages\MfaChallenge. Uses Filament's own
    Blade component set (`<x-filament-panels::page>`, `<x-filament::input.*>`,
    `<x-filament::button>`) rather than the public site's `<x-mk.*>`
    primitives — design-system.md has no Filament-specific form/field
    guidance (checked §8.3), and the public `<x-mk.*>` component files live
    outside this panel's own Tailwind `@source` scan
    (resources/css/filament/admin/theme.css only scans
    `resources/views/filament/admin/**/*.blade.php` and
    `app/Filament/Admin/**/*.php`), so their utility classes would not
    compile into this panel's CSS build. Colour utilities used below
    (`text-neutral-*`, `text-danger-*`) trace to the same `tokens.css` this
    theme already `@import`s — no hardcoded value, no arbitrary Tailwind
    bracket value.
--}}
<x-filament-panels::page>
    <form wire:submit="submit" class="grid gap-y-6">
        <p class="text-sm text-neutral-600">
            Masukkan kode 6 digit dari aplikasi authenticator Anda, atau kode pemulihan (format XXXXX-XXXXX) bila autentikator tidak tersedia.
        </p>

        <div class="grid gap-y-1.5">
            <label for="mfa-challenge-code" class="text-sm font-medium text-neutral-800">
                Kode verifikasi
            </label>

            <x-filament::input.wrapper :valid="! $errors->has('code')">
                <x-filament::input
                    id="mfa-challenge-code"
                    type="text"
                    wire:model="code"
                    autocomplete="one-time-code"
                    autofocus
                    required
                />
            </x-filament::input.wrapper>

            @error('code')
                <p class="text-sm text-danger-700">{{ $message }}</p>
            @enderror
        </div>

        <x-filament::button type="submit">
            Verifikasi
        </x-filament::button>
    </form>
</x-filament-panels::page>
