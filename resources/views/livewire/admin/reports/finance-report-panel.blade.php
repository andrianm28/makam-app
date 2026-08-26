{{--
    resources/views/livewire/admin/reports/finance-report-panel.blade.php

    View for App\Livewire\Admin\Reports\FinanceReportPanel — the "Laporan
    Keuangan" tab inside App\Filament\Admin\Pages\Reports. Content moved
    verbatim from the former finance-reports.blade.php (the standalone
    FinanceReports Filament page); only the outer <x-filament-panels::page>
    wrapper was dropped, because that chrome belongs to the top-level
    Reports page this now renders inside, not to a nested Livewire
    component — double-wrapping it produced a second, broken page shell.

    Design-system mapping (`.kiro/specs/platform-financial-ledger/tasks.md`
    §Design system):
      - all money is right-aligned, `tabular-nums`, `font-mono`, and rendered
        through `Money::format()` — the module's canonical presenter, which
        divides `amount_minor` by `config('money.minor_units')`. The
        underlying value stays an integer minor-unit amount end to end; only
        the presentation is formatted, so AC11's never-a-float rule is
        untouched;
      - the TOTAL row uses `font-bold` (the `--font-weight-bold` token);
      - the bulk export button is `variant=secondary` (§3.5: never primary,
        never adjacent to a benign action). `<x-filament::button color="gray"`
        is this panel's faithful mapping of `secondary`;
      - required states (§6): loading (spinner during a report load), empty
        (the exact required copy "Belum ada transaksi pada periode ini"),
        validation error (inline, period refused by LedgerReport::assertPeriod),
        success (quiet — the table itself), and support (a line explaining the
        export's re-authentication requirement).
--}}
<div class="grid gap-y-6">
    <div class="grid gap-y-3">
        <div class="grid gap-y-1.5">
            <label for="finance-reports-period" class="text-sm font-medium text-neutral-800">
                Periode
            </label>
            <p class="text-sm text-neutral-600">
                Laporan diringkas dari jurnal untuk periode tersebut (format YYYY-MM).
            </p>
            <div class="flex flex-wrap items-center gap-2">
                <x-filament::input.wrapper :valid="! $errors->has('period')">
                    <x-filament::input
                        id="finance-reports-period"
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
                Sumber: {{ $source }} · Dibuat pada: {{ $generatedAt }}
                @if ($entityRef !== '')
                    · Badan usaha: {{ $entityRef }}
                @endif
            </p>

            <div class="overflow-x-auto rounded-lg border border-neutral-200">
                <table class="min-w-full divide-y divide-neutral-200 text-sm">
                    <thead class="bg-neutral-50 text-left">
                        <tr>
                            <th scope="col" class="px-4 py-2 font-medium text-neutral-800">Kode akun</th>
                            <th scope="col" class="px-4 py-2 text-right font-medium text-neutral-800">Debit</th>
                            <th scope="col" class="px-4 py-2 text-right font-medium text-neutral-800">Kredit</th>
                            <th scope="col" class="px-4 py-2 text-right font-medium text-neutral-800">Selisih</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200">
                        @foreach ($reportRows as $row)
                            <tr class="tabular-nums">
                                <td class="px-4 py-2 font-mono text-neutral-900">{{ $row['account_code'] }}</td>
                                <td class="px-4 py-2 text-right font-mono text-neutral-900">{{ (new \App\Platform\FinancialLedger\Money($row['debit_total']))->format() }}</td>
                                <td class="px-4 py-2 text-right font-mono text-neutral-900">{{ (new \App\Platform\FinancialLedger\Money($row['credit_total']))->format() }}</td>
                                <td class="px-4 py-2 text-right font-mono text-neutral-900">{{ (new \App\Platform\FinancialLedger\Money($row['net']))->format() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t border-neutral-200 font-bold tabular-nums">
                            <td class="px-4 py-2 text-neutral-900">TOTAL</td>
                            <td class="px-4 py-2 text-right font-mono text-neutral-900">{{ (new \App\Platform\FinancialLedger\Money($debitTotal))->format() }}</td>
                            <td class="px-4 py-2 text-right font-mono text-neutral-900">{{ (new \App\Platform\FinancialLedger\Money($creditTotal))->format() }}</td>
                            <td class="px-4 py-2 text-right font-mono text-neutral-900">{{ (new \App\Platform\FinancialLedger\Money($debitTotal - $creditTotal))->format() }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    @elseif ($error === '')
        <div class="rounded-lg border border-neutral-200 bg-neutral-0 p-4">
            <p class="text-sm text-neutral-600">
                Belum ada transaksi pada periode ini
            </p>
        </div>
    @endif

    <div class="grid gap-y-3 border-t border-neutral-200 pt-6">
        <div class="grid gap-y-1.5">
            <p class="text-sm font-medium text-neutral-800">
                Ekspor data
            </p>
            <p class="text-sm text-neutral-600">
                Unduh ringkasan periode ini sebagai berkas CSV. Ekspor memerlukan autentikasi ulang — bila sesi Anda tidak lagi baru, Anda akan diminta konfirmasi sebelum berkas diunduh.
            </p>
        </div>

        <div>
            <x-filament::button
                color="gray"
                tag="a"
                :href="route('admin.finance.exports', $this->exportQuery())"
            >
                Ekspor CSV
            </x-filament::button>
        </div>
    </div>
</div>
