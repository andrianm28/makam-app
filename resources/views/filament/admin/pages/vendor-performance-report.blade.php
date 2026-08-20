{{--
    resources/views/filament/admin/pages/vendor-performance-report.blade.php

    View for App\Filament\Admin\Pages\VendorPerformanceReport. Same
    component and required-states conventions as finance-reports.blade.php.
--}}
<x-filament-panels::page>
    <div class="grid gap-y-6">
        <div class="grid gap-y-3">
            <div class="grid gap-y-1.5">
                <label for="vendor-performance-report-period" class="text-sm font-medium text-neutral-800">
                    Periode
                </label>
                <p class="text-sm text-neutral-600">
                    Ringkasan pesanan vendor per vendor untuk periode tersebut (format YYYY-MM), berdasarkan tanggal dibuat.
                </p>
                <div class="flex flex-wrap items-center gap-2">
                    <x-filament::input.wrapper :valid="! $errors->has('period')">
                        <x-filament::input
                            id="vendor-performance-report-period"
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
                                <th scope="col" class="px-4 py-2 font-medium text-neutral-800">Vendor</th>
                                <th scope="col" class="px-4 py-2 text-right font-medium text-neutral-800">Total</th>
                                <th scope="col" class="px-4 py-2 text-right font-medium text-neutral-800">Selesai</th>
                                <th scope="col" class="px-4 py-2 text-right font-medium text-neutral-800">Dibatalkan</th>
                                <th scope="col" class="px-4 py-2 text-right font-medium text-neutral-800">Komplain</th>
                                <th scope="col" class="px-4 py-2 text-right font-medium text-neutral-800">Tingkat penyelesaian</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-200">
                            @foreach ($reportRows as $row)
                                <tr class="tabular-nums">
                                    <td class="px-4 py-2 text-neutral-900">{{ $row['vendor_name'] }}</td>
                                    <td class="px-4 py-2 text-right font-mono text-neutral-900">{{ $row['total'] }}</td>
                                    <td class="px-4 py-2 text-right font-mono text-neutral-900">{{ $row['completed'] }}</td>
                                    <td class="px-4 py-2 text-right font-mono text-neutral-900">{{ $row['cancelled'] }}</td>
                                    <td class="px-4 py-2 text-right font-mono text-neutral-900">{{ $row['complaints'] }}</td>
                                    <td class="px-4 py-2 text-right font-mono text-neutral-900">{{ number_format($row['completion_rate'] * 100, 1) }}%</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @elseif ($error === '')
            <div class="rounded-lg border border-neutral-200 bg-neutral-0 p-4">
                <p class="text-sm text-neutral-600">
                    Belum ada pesanan vendor pada periode ini
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
</x-filament-panels::page>
