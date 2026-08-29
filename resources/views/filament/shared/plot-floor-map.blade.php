{{--
    resources/views/filament/shared/plot-floor-map.blade.php

    View for App\Filament\Shared\PlotFloorMap\BasePlotFloorMapPage and both
    of its panel subclasses. ONE view for both panels: they render
    identically and differ only in which cemeteries `cemeteryOptions()`
    offers.

    Component choice follows feature-gate-admin.blade.php: Filament's own
    Blade components plus the utility vocabulary that page already ships.
    Neither panel carries a custom theme CSS file (vite.config.js, 26 Aug
    2026), so the page renders against Filament's precompiled stylesheet —
    introducing a new Tailwind utility family here risks a class that was
    never compiled.

    Required states (design-system.md §6): loading (none — every arm is one
    query over a small per-cemetery set), empty (no cemeteries available /
    no cemetery selected / granular cemetery with no blocks / block with no
    plots / aggregate cemetery with no packages), success (the map or the
    cards themselves), error (the honest "unrecognised tracking mode" arm),
    support (the legend explaining what each colour means).
--}}
@php
    $trackingModeGranular = \App\Domain\CemeteryDirectory\PlotTrackingMode::GRANULAR;
    $trackingModeAggregate = \App\Domain\CemeteryDirectory\PlotTrackingMode::AGGREGATE;

    $cemeteryOptions = $this->cemeteryOptions();
    $selectedCemetery = $this->selectedCemetery();
    $trackingMode = $this->trackingMode();
    $linkedOrder = $this->linkedOrder();
@endphp

<x-filament-panels::page>
    <div class="grid gap-y-6">
        <div class="grid gap-y-1.5">
            <label for="plot-floor-map-cemetery" class="text-sm font-medium text-neutral-800">
                Makam
            </label>
            <select
                id="plot-floor-map-cemetery"
                wire:model.live="cemeteryId"
                class="fi-input w-full max-w-md"
            >
                <option value="">— Pilih makam —</option>
                @foreach ($cemeteryOptions as $optionId => $optionName)
                    <option value="{{ $optionId }}">{{ $optionName }}</option>
                @endforeach
            </select>

            @if ($cemeteryOptions === [])
                <p class="text-sm text-neutral-600">
                    Belum ada makam yang dapat Anda akses. Hubungi admin untuk meminta akses makam.
                </p>
            @endif
        </div>

        @if ($linkedOrder !== null)
            <p class="text-sm text-neutral-600">
                Mode reservasi pesanan: pilih plot yang tersedia untuk pesanan
                <span class="font-mono">#{{ $linkedOrder->reference }}</span>.
            </p>
        @endif

        @if ($selectedCemetery === null)
            <p class="text-sm text-neutral-600">
                Pilih makam untuk melihat ketersediaan plot.
            </p>
        @elseif ($trackingMode === $trackingModeGranular)
            @include('filament.shared.plot-floor-map.granular')
        @elseif ($trackingMode === $trackingModeAggregate)
            @include('filament.shared.plot-floor-map.aggregate')
        @else
            {{--
                Unreachable while `Cemetery::booted()` guards the column, but
                guessing an arm would silently show an operator the wrong
                availability truth. Say so instead.
            --}}
            <p class="text-sm text-neutral-600">
                Mode pelacakan plot makam ini tidak dikenali. Hubungi admin sebelum menggunakan data ketersediaan.
            </p>
        @endif
    </div>
</x-filament-panels::page>
