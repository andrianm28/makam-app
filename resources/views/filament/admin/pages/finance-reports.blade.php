{{--
    resources/views/filament/admin/pages/finance-reports.blade.php

    View for App\Filament\Admin\Pages\FinanceReports. Same component choice
    as mfa-settings.blade.php / mfa-challenge.blade.php: Filament's own
    Blade components rather than the public site's `<x-mk.*>` primitives, for
    the identical reason those files record — `<x-mk.*>` component files live
    outside this panel's own Tailwind `@source` scan, and design-system.md has
    no Filament-specific form/field guidance.

    Design-system mapping (`.kiro/specs/platform-financial-ledger/tasks.md`
    §Design system):
      - all money is right-aligned, `tabular-nums`, `font-mono`, integer
        minor units only — never a float;
      - the TOTAL row uses `font-bold` (the `--font-weight-bold` token);
      - the bulk export button is `variant=secondary` (§3.5: never primary,
        never adjacent to a benign action). `<x-filament::button color="gray"`
        is this panel's faithful mapping of `secondary` — the panel's Tailwind
        scan does not see `<x-mk.button>`'s variant classes, and the
        gray/neutral non-primary button is the same visual weight the
        secondary variant exists to produce. It sits alone in its own section,
        at the bottom of the page, away from the report controls.
      - required states (§6): loading (spinner during a report load), empty
        (the exact required copy "Belum ada transaksi pada periode ini"),
        validation error (inline, period refused by LedgerReport::assertPeriod),
        success (quiet — the table itself), and support (a line explaining the
        export's re-authentication requirement).
--}}
<x-filament-panels::page>
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
                                <th scope="col" class="px-4 py-2 text-right font-medium text-neutral-800">Debit (IDR)</th>
                                <th scope="col" class="px-4 py-2 text-right font-medium text-neutral-800">Kredit (IDR)</th>
                                <th scope="col" class="px-4 py-2 text-right font-medium text-neutral-800">Selisih (IDR)</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-200">
                            @foreach ($reportRows as $row)
                                <tr class="tabular-nums">
                                    <td class="px-4 py-2 font-mono text-neutral-900">{{ $row['account_code'] }}</td>
                                    <td class="px-4 py-2 text-right font-mono text-neutral-900">{{ number_format($row['debit_total'], 0, ',', '.') }}</td>
                                    <td class="px-4 py-2 text-right font-mono text-neutral-900">{{ number_format($row['credit_total'], 0, ',', '.') }}</td>
                                    <td class="px-4 py-2 text-right font-mono text-neutral-900">{{ number_format($row['net'], 0, ',', '.') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-neutral-200 font-bold tabular-nums">
                                <td class="px-4 py-2 text-neutral-900">TOTAL</td>
                                <td class="px-4 py-2 text-right font-mono text-neutral-900">{{ number_format($debitTotal, 0, ',', '.') }}</td>
                                <td class="px-4 py-2 text-right font-mono text-neutral-900">{{ number_format($creditTotal, 0, ',', '.') }}</td>
                                <td class="px-4 py-2 text-right font-mono text-neutral-900">{{ number_format($debitTotal - $creditTotal, 0, ',', '.') }}</td>
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
                    :href="route('admin.finance.exports', ['period' => $period])"
                >
                    Ekspor CSV
                </x-filament::button>
            </div>
        </div>
    </div>
</x-filament-panels::page>
