{{--
    resources/views/livewire/admin/reports/orders-report-panel.blade.php

    View for App\Livewire\Admin\Reports\OrdersReportPanel — the "Laporan
    Pesanan" tab inside App\Filament\Admin\Pages\Reports. Content moved
    verbatim from the former orders-report.blade.php (the standalone
    OrdersReport Filament page); only the outer <x-filament-panels::page>
    wrapper was dropped — see finance-report-panel.blade.php's doc block for
    why.

    Status is rendered through `StatusIntent`, never a raw string comparison
    — design-system.md §3.7's normative order-lifecycle badge mapping.
--}}
<div class="grid gap-y-6">
    <div class="grid gap-y-3">
        <div class="grid gap-y-1.5">
            <label for="orders-report-period" class="text-sm font-medium text-neutral-800">
                Periode
            </label>
            <p class="text-sm text-neutral-600">
                Jumlah pesanan per status untuk periode tersebut (format YYYY-MM), berdasarkan tanggal dibuat.
            </p>
            <div class="flex flex-wrap items-center gap-2">
                <x-filament::input.wrapper :valid="! $errors->has('period')">
                    <x-filament::input
                        id="orders-report-period"
                        type="text"
                        inputmode="numeric"
                        wire:model="period"
                        maxlength="7"
                    />
                </x-filament::input.wrapper>

                <x-filament::button type="button" color="gray" wire:click="loadReport">
                    <span wire:loading.remove wire:target="loadReport">Tampilkan</span>
                    <span wire:loading wire:target="loadReport">Memuat…</span>
                </x-filament::button>
            </div>
        </div>

        @error('period')
            <p class="text-sm text-danger-700">{{ $message }}</p>
        @enderror

        @if ($error !== '')
            <div class="rounded-lg border border-danger-600 bg-neutral-0 p-4" role="alert">
                <p class="text-sm text-danger-700">{{ $error }}</p>
            </div>
        @endif
    </div>

    @if ($reportRows !== [])
        <div class="grid gap-y-1.5">
            <p class="text-sm text-neutral-600">
                Dibuat pada: {{ $generatedAt }}
            </p>

            <div class="overflow-x-auto rounded-lg border border-neutral-200">
                <table class="min-w-full divide-y divide-neutral-200 text-sm">
                    <thead class="bg-neutral-50 text-left">
                        <tr>
                            <th scope="col" class="px-4 py-2 font-medium text-neutral-800">Status</th>
                            <th scope="col" class="px-4 py-2 text-right font-medium text-neutral-800">Jumlah</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200">
                        @foreach ($reportRows as $row)
                            <tr class="tabular-nums">
                                <td class="px-4 py-2">
                                    <x-filament::badge :color="\App\Support\Design\StatusIntent::filamentColor($row['status'], \App\Support\Design\StatusIntent::FAMILY_ORDER_LIFECYCLE)">
                                        {{ \App\Support\Design\StatusIntent::label($row['status']) }}
                                    </x-filament::badge>
                                </td>
                                <td class="px-4 py-2 text-right font-mono text-neutral-900">{{ $row['total'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t border-neutral-200 font-bold tabular-nums">
                            <td class="px-4 py-2 text-neutral-900">TOTAL</td>
                            <td class="px-4 py-2 text-right font-mono text-neutral-900">{{ $total }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    @elseif ($error === '')
        <div class="rounded-lg border border-neutral-200 bg-neutral-0 p-4">
            <p class="text-sm text-neutral-600">
                Belum ada pesanan pada periode ini
            </p>
        </div>
    @endif

    <div class="grid gap-y-3 border-t border-neutral-200 pt-6">
        <div class="grid gap-y-1.5">
            <p class="text-sm font-medium text-neutral-800">
                Ekspor data
            </p>
            <p class="text-sm text-neutral-600">
                Unduh ringkasan periode ini sebagai berkas CSV.
            </p>
        </div>

        <div>
            <x-filament::button color="gray" wire:click="exportCsv">
                Ekspor CSV
            </x-filament::button>
        </div>
    </div>
</div>
