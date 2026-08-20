{{--
    resources/views/filament/vendor/pages/profile.blade.php

    View for App\Filament\Vendor\Pages\Profile. Same component choice as
    edit-site-settings.blade.php: `<x-filament-panels::page>` wrapper,
    `{{ $this->form }}` for the schema, `<x-filament::button>` for the save
    action (wire:click drives the page's `save()` Livewire method). No
    hardcoded value, no arbitrary Tailwind bracket value — the layout class
    below is the same `flex justify-end` edit-site-settings.blade.php already
    uses for its own save button.
--}}
<x-filament-panels::page>
    {{ $this->form }}

    <div class="flex justify-end">
        <x-filament::button wire:click="save">
            Simpan Perubahan
        </x-filament::button>
    </div>
</x-filament-panels::page>
