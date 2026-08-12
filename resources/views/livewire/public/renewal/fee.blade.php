{{--
    resources/views/livewire/public/renewal/fee.blade.php

    App\Livewire\Public\Renewal\RenewalFee's view — `/perpanjangan/biaya`,
    screen PUB-032 (`docs/product/screen-inventory.md`: "fee | tariff quote,
    source, last-updated"). L8 Task 4, `.kiro/specs/renewal-and-grave-registry`
    AC6 (tariff amount + source + effective time) and AC7 (no invented late
    fine).

    ===========================================================================
    AC7 IS A GATE READ, NOT A CALCULATION
    ===========================================================================
    `G-RATE-01`'s documented closed behavior is literally "No invented fine."
    `late_fine_minor` and `late_fine_basis` are therefore null in every quote
    this screen receives. The view never computes, estimates, or invents a fine
    figure — it renders what the quote carries, and what the quote carries is
    nothing when the gate is closed.

    --- Why the stepper gets renewal's own six labels ---
    `<x-mk.stepper>` defaults to the nine booking labels when `labels` is
    omitted. `RenewalJourneyStep::labels()` supplies the six renewal labels,
    exactly as its own doc block documents as its purpose.
--}}
<div class="py-8 md:py-12">
    <div class="mx-auto max-w-content px-4">

        <x-mk.stepper
            :labels="$stepLabels"
            :step="$currentStep"
            aria-label="Progres perpanjangan makam"
            class="mb-8"
        />

        @if ($errorMessage)
            <div class="flex flex-col items-center gap-3 py-12 text-center">
                <x-dynamic-component component="icon.inbox" class="size-12 text-neutral-400" aria-hidden="true" />
                <h1 class="text-lg font-semibold text-neutral-800">
                    {{ $errorMessage }}
                </h1>
                <x-mk.button variant="primary" href="/bantuan">
                    Hubungi Bantuan
                </x-mk.button>
            </div>
        @elseif ($quoteUnavailable)
            <div class="flex flex-col items-center gap-3 py-12 text-center">
                <x-dynamic-component component="icon.inbox" class="size-12 text-neutral-400" aria-hidden="true" />
                <h1 class="text-lg font-semibold text-neutral-800">
                    Tarif tidak tersedia.
                </h1>
                <p class="max-w-prose text-base text-neutral-600">
                    Kami belum dapat menghitung tarif perpanjangan untuk makam ini.
                    Silakan hubungi petugas kami untuk informasi lebih lanjut.
                </p>
                <x-mk.button variant="primary" href="/bantuan" class="mt-2">
                    Hubungi Bantuan
                </x-mk.button>
            </div>
        @elseif ($grave && $quote)
            <div class="mx-auto mb-8 max-w-prose space-y-2 text-center">
                <h1 class="text-3xl font-semibold tracking-tight text-neutral-900">
                    Biaya Perpanjangan
                </h1>
                <p class="text-base text-neutral-600">
                    Perpanjangan masa sewa makam
                    <span class="font-medium text-neutral-900">{{ $grave->deceased_name }}</span>
                    di
                    <span class="font-medium text-neutral-900">{{ $grave->cemetery->name ?? 'TPU' }}</span>.
                </p>
            </div>

            <div class="mx-auto mb-8 max-w-prose">
                <x-mk.card>
                    <div class="flex flex-col gap-6">
                        {{-- Grave record summary --}}
                        <div class="flex flex-col gap-2 border-b border-neutral-200 pb-4">
                            <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                                <span class="text-neutral-600">Nama almarhum</span>
                                <span class="font-medium text-neutral-900">{{ $grave->deceased_name }}</span>

                                <span class="text-neutral-600">Blok</span>
                                <span class="font-medium text-neutral-900">{{ $grave->block ?? '—' }}</span>

                                <span class="text-neutral-600">Jatuh tempo saat ini</span>
                                <span class="font-medium text-neutral-900">
                                    {{ $grave->due_date?->format('d F Y') ?? '—' }}
                                </span>
                            </div>
                        </div>

                        {{-- AC6 — tariff amount --}}
                        <div class="flex flex-col gap-1">
                            <span class="text-sm text-neutral-600">Estimasi biaya perpanjangan</span>
                            <span class="font-mono text-3xl font-bold text-neutral-900">
                                {{ $quote->amountAsMoney()->format() }}
                            </span>
                            <span class="text-sm text-neutral-500">
                                * Sudah termasuk PPN
                            </span>
                        </div>

                        {{-- AC6 — tariff source and effective time --}}
                        <div class="flex flex-col gap-1 border-t border-neutral-200 pt-4">
                            <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                                <span class="text-neutral-600">Sumber tarif</span>
                                <span class="font-medium text-neutral-900">{{ $quote->tariff_source }}</span>

                                <span class="text-neutral-600">Terakhir diperbarui</span>
                                <span class="font-medium text-neutral-900">
                                    {{ $quote->tariff_effective_at?->format('d F Y') ?? '—' }}
                                </span>
                            </div>
                        </div>

                        {{-- AC7 — no late fine when no written basis (G-RATE-01 closed) --}}
                        @if ($quote->late_fine_minor !== null || $quote->late_fine_basis !== null)
                            <div class="flex flex-col gap-1 border-t border-neutral-200 pt-4">
                                <span class="text-sm text-neutral-600">Denda keterlambatan</span>
                                <span class="font-mono text-lg font-semibold text-neutral-900">
                                    {{ $quote->amountAsMoney()->format() }}
                                </span>
                                <span class="text-sm text-neutral-500">
                                    Dasar: {{ $quote->late_fine_basis ?? 'Informasi petugas' }}
                                </span>
                            </div>
                        @endif

                        {{-- Proceed to payment --}}
                        <div class="flex flex-col gap-2 border-t border-neutral-200 pt-4">
                            <x-mk.button
                                variant="primary"
                                href="/perpanjangan/pembayaran?perpanjangan={{ $quote->renewal->id }}"
                                class="w-full justify-center"
                            >
                                Lanjutkan ke Pembayaran
                            </x-mk.button>
                            <p class="text-center text-sm text-neutral-500">
                                Dengan melanjutkan, Anda menyetujui estimasi biaya di atas.
                            </p>
                        </div>
                    </div>
                </x-mk.card>
            </div>
        @endif

        {{-- §6.10 support escape hatch --}}
        <p class="mt-10 text-center text-sm text-neutral-600">
            Butuh bantuan menanyakan biaya perpanjangan?
            <a href="/bantuan" class="font-medium underline underline-offset-2">Hubungi Bantuan</a>.
        </p>
    </div>
</div>
