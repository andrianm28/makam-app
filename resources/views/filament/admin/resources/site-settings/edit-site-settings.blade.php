{{--
    resources/views/filament/admin/resources/site-settings/edit-site-settings.blade.php

    View for App\Filament\Admin\Resources\SiteSettings\Pages\EditSiteSettings.
    Filament's own Blade component set, same as the other admin pages
    (password-reauthentication, finance-reports): `<x-filament-panels::page>` wrapper,
    `{{ $this->form }}` for the schema, and `<x-filament::button>` for the
    save action (wire:click drives the page's `save()` Livewire method).
--}}
<x-filament-panels::page>
    {{ $this->form }}

    <div class="flex justify-end">
        <x-filament::button wire:click="save">
            Simpan Pengaturan
        </x-filament::button>
    </div>
</x-filament-panels::page>
