{{--
    resources/views/filament/admin/pages/password-reauthentication.blade.php

    View for App\Filament\Admin\Pages\PasswordReauthentication — replaces
    mfa-challenge.blade.php. Same Filament Blade component set and the same
    tokens.css-traced colour utilities that view already established as
    correct for this panel (see that file's own header comment for why
    <x-mk.*> primitives do not apply here).
--}}
<x-filament-panels::page>
    <form wire:submit="submit" class="grid gap-y-6">
        <p class="text-sm text-neutral-600">
            Masukkan kata sandi Anda untuk melanjutkan tindakan ini.
        </p>

        <div class="grid gap-y-1.5">
            <label for="password-reauthentication-password" class="text-sm font-medium text-neutral-800">
                Kata sandi
            </label>

            <x-filament::input.wrapper :valid="! $errors->has('password')">
                <x-filament::input
                    id="password-reauthentication-password"
                    type="password"
                    wire:model="password"
                    autocomplete="current-password"
                    autofocus
                    required
                />
            </x-filament::input.wrapper>

            @error('password')
                <p class="text-sm text-danger-700">{{ $message }}</p>
            @enderror
        </div>

        <x-filament::button type="submit">
            Verifikasi
        </x-filament::button>
    </form>
</x-filament-panels::page>
