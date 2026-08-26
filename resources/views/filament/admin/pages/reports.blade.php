{{--
    resources/views/filament/admin/pages/reports.blade.php

    View for App\Filament\Admin\Pages\Reports — the single consolidated
    "Laporan" admin page. Renders one tab strip (Filament's own
    <x-filament::tabs> component) and, below it, ONLY the active tab's
    content, mounted as a nested Livewire component by fully-qualified class
    name via @livewire().

    Security-load-bearing: the @if ($tab['visible']) guard around BOTH the
    tab button and the @livewire() call is not a display nicety. A tab this
    actor is not authorized for is never mounted — see
    App\Filament\Admin\Pages\Reports's own doc block for why that, not a
    hidden-but-rendered element, is what keeps a lesser-privileged admin from
    ever receiving the finance-tier tabs' data in the page's HTML.
--}}
<x-filament-panels::page>
    <div class="grid gap-y-6">
        <x-filament::tabs contained label="Jenis laporan">
            @foreach ($this->reportTabs() as $tab)
                @if ($tab['visible'])
                    <x-filament::tabs.item
                        :active="$activeTab === $tab['key']"
                        wire:click="setActiveTab('{{ $tab['key'] }}')"
                    >
                        {{ $tab['label'] }}
                    </x-filament::tabs.item>
                @endif
            @endforeach
        </x-filament::tabs>

        @foreach ($this->reportTabs() as $tab)
            @if ($tab['visible'] && $activeTab === $tab['key'])
                @livewire($tab['component'], key('reports-tab-'.$tab['key']))
            @endif
        @endforeach
    </div>
</x-filament-panels::page>
