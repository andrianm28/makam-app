{{--
    The Floor/Block Map — one section per CemeteryBlock, its plots as cells
    in slot order. Cell colour comes from StatusIntent::FAMILY_PLOT_STATE
    (design-system.md §3.7): components must not switch on enum strings,
    so there is no `match` anywhere in this file.

    Cells are real <button> elements (x-filament::button), not coloured
    divs, so they are keyboard-reachable and screen-reader-announced —
    design-system.md §7.4. §7.5 ("beyond colour") is satisfied by the
    always-visible legend below the blocks and by the accessible name on
    each cell, which states the slot AND its status in words.
--}}
@php
    use App\Domain\PlotInventory\PlotState;
    use App\Support\Design\StatusIntent;

    $blocks = $this->blocks();
    $activePlot = $this->activePlot();
@endphp

<div class="grid gap-y-6">
    @forelse ($blocks as $block)
        <x-filament::section>
            <x-slot name="heading">{{ $block->code }} — {{ $block->name }}</x-slot>
            <x-slot name="description">
                Kapasitas {{ $block->capacity }} · {{ $block->plots->count() }} plot
            </x-slot>

            @if ($block->plots->isEmpty())
                <p class="text-sm text-neutral-600">Blok ini belum memiliki plot.</p>
            @else
                <div class="flex flex-wrap gap-2">
                    @foreach ($block->plots as $plot)
                        <x-filament::button
                            size="sm"
                            :color="StatusIntent::filamentColor($plot->plot_state, StatusIntent::FAMILY_PLOT_STATE)"
                            :aria-label="'Plot ' . $block->code . ' ' . $plot->slot . ' — ' . StatusIntent::label($plot->plot_state, StatusIntent::FAMILY_PLOT_STATE)"
                            wire:click="openPlot('{{ $plot->getKey() }}')"
                            x-on:click="$dispatch('open-modal', { id: 'plot-floor-map-cell' })"
                        >
                            {{ $plot->slot }}
                        </x-filament::button>
                    @endforeach
                </div>
            @endif
        </x-filament::section>
    @empty
        <p class="text-sm text-neutral-600">
            Makam ini belum memiliki blok. Tambahkan blok melalui halaman Makam terlebih dahulu.
        </p>
    @endforelse

    <div class="flex flex-wrap items-center gap-2">
        <span class="text-sm text-neutral-600">Keterangan:</span>
        @foreach (PlotState::KNOWN_STATES as $legendState)
            <x-filament::badge :color="StatusIntent::filamentColor($legendState, StatusIntent::FAMILY_PLOT_STATE)">
                {{ StatusIntent::label($legendState, StatusIntent::FAMILY_PLOT_STATE) }}
            </x-filament::badge>
        @endforeach
    </div>

    <x-filament::modal id="plot-floor-map-cell" width="md">
        <x-slot name="heading">
            @if ($activePlot !== null)
                Plot {{ $activePlot->block?->code }} — {{ $activePlot->slot }}
            @else
                Plot
            @endif
        </x-slot>

        @if ($activePlot === null)
            <p class="text-sm text-neutral-600">Plot tidak ditemukan pada makam yang dipilih.</p>
        @else
            <div class="grid gap-y-3">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm text-neutral-600">Status:</span>
                    <x-filament::badge :color="StatusIntent::filamentColor($activePlot->plot_state, StatusIntent::FAMILY_PLOT_STATE)">
                        {{ StatusIntent::label($activePlot->plot_state, StatusIntent::FAMILY_PLOT_STATE) }}
                    </x-filament::badge>
                </div>
                @php $modalReservation = $this->activeReservation(); @endphp
                @if ($modalReservation !== null)
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-sm text-neutral-600">Reservasi:</span>
                        <x-filament::badge :color="StatusIntent::filamentColor($modalReservation->state, StatusIntent::FAMILY_PLOT_RESERVATION)">
                            {{ StatusIntent::label($modalReservation->state, StatusIntent::FAMILY_PLOT_RESERVATION) }}
                        </x-filament::badge>
                        <span class="text-sm text-neutral-600">
                            {{ $modalReservation->reserved_at?->format('Y-m-d H:i') ?? '—' }}
                        </span>
                    </div>
                @endif
                <p class="text-sm text-neutral-600">
                    Paket / Kelas: {{ $activePlot->cemeteryPackage?->name ?? 'Tanpa paket' }}
                </p>
            </div>
        @endif

        <x-slot name="footer">
            @if ($this->mayReserveActivePlot())
                <x-filament::button
                    color="success"
                    wire:click="reserveForOrder"
                    x-on:click="$dispatch('close-modal', { id: 'plot-floor-map-cell' })"
                >
                    Reservasi untuk pesanan #{{ $this->linkedOrder()?->reference }}
                </x-filament::button>
            @endif

            @foreach ($this->availableReservationActions() as $reservationAction => $reservationLabel)
                <x-filament::button
                    color="gray"
                    wire:click="runReservationAction('{{ $reservationAction }}')"
                    x-on:click="$dispatch('close-modal', { id: 'plot-floor-map-cell' })"
                >
                    {{ $reservationLabel }}
                </x-filament::button>
            @endforeach

            @foreach ($this->availableOverrides() as $overrideState => $overrideLabel)
                <x-filament::button
                    color="primary"
                    wire:click="markPlotState('{{ $overrideState }}')"
                    x-on:click="$dispatch('close-modal', { id: 'plot-floor-map-cell' })"
                >
                    {{ $overrideLabel }}
                </x-filament::button>
            @endforeach

            <x-filament::button
                color="gray"
                wire:click="closePlot"
                x-on:click="$dispatch('close-modal', { id: 'plot-floor-map-cell' })"
            >
                Tutup
            </x-filament::button>
        </x-slot>
    </x-filament::modal>
</div>
