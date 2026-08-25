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
    The fine block renders on `RenewalQuoteDraft::hasLateFine()`, which is true
    only when BOTH a figure and its written basis are present, and it prints
    `lateFineAsMoney()` — the fine — never the tariff total. An earlier revision
    keyed the block on either field alone and then printed the FULL TARIFF as
    the fine, under a fabricated "Informasi petugas" basis string. It was
    unreachable while the gate stays closed, which is exactly why it would have
    shipped: the first real fine data would have charged the family twice over
    against an invented basis.

    ===========================================================================
    AC14 — every grave field here comes off the projection
    ===========================================================================
    `$graveView` is a `GraveRecordProjection`, not a `GraveRecord`. A withheld
    field is absent from the object, not merely unprinted. Restricted records
    never reach the fee body at all; they get §6.2's privacy-limited state.

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
        @elseif ($privacyRestricted)
            {{-- design-system.md §6.2 privacy-limited state — AC14 --}}
            <div class="flex flex-col items-center gap-3 py-12 text-center">
                <x-dynamic-component component="icon.inbox" class="size-12 text-neutral-400" aria-hidden="true" />
                <h1 class="text-lg font-semibold text-neutral-800">
                    Data makam ini dibatasi.
                </h1>
                <p class="max-w-prose text-base text-neutral-600">
                    Data makam ini tercatat, namun tidak dapat ditampilkan secara
                    online. Silakan hubungi petugas kami untuk mengurus perpanjangan
                    makam ini.
                </p>
                <x-mk.button variant="primary" href="/bantuan" class="mt-2">
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
        @elseif ($graveView && $quote)
            <div class="mx-auto mb-8 max-w-prose space-y-2 text-center">
                <h1 class="text-3xl font-semibold tracking-tight text-neutral-900">
                    Biaya Perpanjangan
                </h1>
                <p class="text-base text-neutral-600">
                    Perpanjangan masa sewa makam
                    <span class="font-medium text-neutral-900">{{ $graveView->deceasedName }}</span>
                    di
                    <span class="font-medium text-neutral-900">{{ $graveView->cemeteryName ?? 'TPU' }}</span>.
                </p>
            </div>

            <div class="mx-auto mb-8 max-w-prose">
                <x-mk.card>
                    <div class="flex flex-col gap-6">
                        {{-- Grave record summary — projected fields only --}}
                        <div class="flex flex-col gap-2 border-b border-neutral-200 pb-4">
                            <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                                <span class="text-neutral-600">Nama almarhum</span>
                                <span class="font-medium text-neutral-900">{{ $graveView->deceasedName }}</span>

                                <span class="text-neutral-600">Blok</span>
                                <span class="font-medium text-neutral-900">{{ $graveView->block ?? '—' }}</span>

                                <span class="text-neutral-600">Jatuh tempo saat ini</span>
                                <span class="font-medium text-neutral-900">
                                    {{ $graveView->dueDate ?? '—' }}
                                </span>
                            </div>
                        </div>

                        {{-- AC6 — tariff amount --}}
                        <div class="flex flex-col gap-1">
                            <span class="text-sm text-neutral-600">Estimasi biaya perpanjangan</span>
                            <span class="font-mono text-3xl font-bold text-neutral-900">
                                {{ $quote->amountAsMoney()->format() }}
                            </span>
                        </div>

                        {{-- AC6 — tariff source and effective time --}}
                        <div class="flex flex-col gap-1 border-t border-neutral-200 pt-4">
                            <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                                <span class="text-neutral-600">Sumber tarif</span>
                                <span class="font-medium text-neutral-900">{{ $quote->tariffSource }}</span>

                                <span class="text-neutral-600">Terakhir diperbarui</span>
                                <span class="font-medium text-neutral-900">
                                    {{ $quote->tariffEffectiveAt?->format('d F Y') ?? '—' }}
                                </span>
                            </div>
                        </div>

                        {{-- AC7 — a fine renders only with its own figure AND its written basis --}}
                        @if ($quote->hasLateFine())
                            <div class="flex flex-col gap-1 border-t border-neutral-200 pt-4">
                                <span class="text-sm text-neutral-600">Denda keterlambatan</span>
                                <span class="font-mono text-lg font-semibold text-neutral-900">
                                    {{ $quote->lateFineAsMoney()->format() }}
                                </span>
                                <span class="text-sm text-neutral-500">
                                    Dasar: {{ $quote->lateFineBasis }}
                                </span>
                            </div>
                        @endif

                        {{-- Acceptance — a POST, and the only path that persists a renewal --}}
                        <div class="flex flex-col gap-2 border-t border-neutral-200 pt-4">
                            <x-mk.button
                                variant="primary"
                                type="button"
                                wire:click="terimaDanLanjutkan"
                                wire:loading.attr="disabled"
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
