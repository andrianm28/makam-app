{{--
    resources/views/filament/admin/pages/receipts-report.blade.php

    View for App\Filament\Admin\Pages\ReceiptsReport. Same component,
    money-formatting, and required-states conventions as
    finance-reports.blade.php — see that view's own doc block.
--}}
<x-filament-panels::page>
    <div class="grid gap-y-6">
        <div class="grid gap-y-3">
            <div class="grid gap-y-1.5">
                <label for="receipts-report-period" class="text-sm font-medium text-neutral-800">
                    Periode
                </label>
                <p class="text-sm text-neutral-600">
                    Penerimaan kas/bank yang tercatat di jurnal untuk periode tersebut (format YYYY-MM).
                </p>
                <div class="flex flex-wrap items-center gap-2">
                    <x-filament::input.wrapper :valid="! $errors->has('period')">
                        <x-filament::input
                            id="receipts-report-period"
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
                    Sumber: journal · Dibuat pada: {{ $generatedAt }}
                    @if ($entityRef !== '')
                        · Badan usaha: {{ $entityRef }}
                    @endif
                </p>

                <div class="overflow-x-auto rounded-lg border border-neutral-200">
                    <table class="min-w-full divide-y divide-neutral-200 text-sm">
                        <thead class="bg-neutral-50 text-left">
                            <tr>
                                <th scope="col" class="px-4 py-2 font-medium text-neutral-800">Referensi jurnal</th>
                                <th scope="col" class="px-4 py-2 font-medium text-neutral-800">Sumber</th>
                                <th scope="col" class="px-4 py-2 font-medium text-neutral-800">Badan usaha</th>
                                <th scope="col" class="px-4 py-2 font-medium text-neutral-800">Waktu</th>
                                <th scope="col" class="px-4 py-2 text-right font-medium text-neutral-800">Jumlah</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-200">
                            @foreach ($reportRows as $row)
                                <tr class="tabular-nums">
                                    <td class="px-4 py-2 font-mono text-neutral-900">{{ $row['business_key'] }}</td>
                                    <td class="px-4 py-2 text-neutral-900">{{ $row['source_type'] }}</td>
                                    <td class="px-4 py-2 text-neutral-900">{{ $row['entity_ref'] }}</td>
                                    <td class="px-4 py-2 text-neutral-900">{{ $row['occurred_at'] }}</td>
                                    <td class="px-4 py-2 text-right font-mono text-neutral-900">{{ (new \App\Platform\FinancialLedger\Money($row['amount_minor']))->format() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-neutral-200 font-bold tabular-nums">
                                <td class="px-4 py-2 text-neutral-900" colspan="4">TOTAL</td>
                                <td class="px-4 py-2 text-right font-mono text-neutral-900">{{ (new \App\Platform\FinancialLedger\Money($totalMinor))->format() }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        @elseif ($error === '')
            <div class="rounded-lg border border-neutral-200 bg-neutral-0 p-4">
                <p class="text-sm text-neutral-600">
                    Belum ada penerimaan pada periode ini
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
