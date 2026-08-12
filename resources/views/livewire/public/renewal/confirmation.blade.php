{{--
    resources/views/livewire/public/renewal/confirmation.blade.php

    App\Livewire\Public\Renewal\RenewalConfirmation's view — `/perpanjangan/konfirmasi`,
    screen PUB-034 (`docs/product/screen-inventory.md`: "confirmation | reference,
    status, invoice state, resulting due date when available"). L8 Task 6, AC9.

    ===========================================================================
    AC9 — quiet success, no confetti or exclamation marks
    ===========================================================================
    design-system.md §6.8: "Success is quiet." The confirmation step is the
    moment the user has completed their journey — no celebration, just clarity
    on what happened and what comes next.

    Due date is shown only when settled (AC9: "when available"). An unpaid
    renewal has not moved the due date; showing a projected one would be a
    fabricated figure.
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
        @else
            <div class="mx-auto mb-8 max-w-prose space-y-2 text-center">
                <h1 class="text-3xl font-semibold tracking-tight text-neutral-900">
                    Konfirmasi Perpanjangan
                </h1>
                <p class="text-base text-neutral-600">
                    Perpanjangan masa sewa makam Anda telah tercatat.
                </p>
            </div>

            <div class="mx-auto mb-8 max-w-prose">
                <x-mk.card>
                    <div class="flex flex-col gap-4">

                        {{-- Reference (AC9: in font-mono) --}}
                        <div class="flex flex-col gap-1">
                            <span class="text-sm font-medium text-neutral-600">Nomor Referensi</span>
                            <span class="font-mono text-lg font-bold text-neutral-900">{{ $renewal->reference }}</span>
                        </div>

                        {{-- Status (AC9: invoice state) --}}
                        <div class="flex flex-col gap-1">
                            <span class="text-sm font-medium text-neutral-600">Status</span>
                            <span class="text-base font-medium text-neutral-900">
                                @if ($renewal->status === \App\Domain\Renewal\RenewalStatus::DIBAYAR)
                                    Sudah Dibayar
                                @elseif ($renewal->status === \App\Domain\Renewal\RenewalStatus::MENUNGGU_PEMBAYARAN)
                                    Menunggu pembayaran
                                @else
                                    {{ $renewal->status }}
                                @endif
                            </span>
                        </div>

                        {{-- Due date shown only when settled (AC9: when available) --}}
                        @if ($renewal->isSettled())
                            <div class="flex flex-col gap-1">
                                <span class="text-sm font-medium text-neutral-600">Jatuh Tempo Baru</span>
                                <span class="text-base font-medium text-neutral-900">{{ $renewal->target_due_period->format('d M Y') }}</span>
                            </div>
                        @endif

                        {{-- Settlement info when paid --}}
                        @if ($renewal->isSettled() && $renewal->settled_at)
                            <div class="flex flex-col gap-1 border-t border-neutral-200 pt-4">
                                <span class="text-sm font-medium text-neutral-600">Tanggal Pembayaran</span>
                                <span class="text-base text-neutral-700">{{ $renewal->settled_at->format('d M Y') }}</span>
                            </div>
                        @endif

                        {{-- Next steps when unpaid --}}
                        @if (!$renewal->isSettled())
                            <div class="flex flex-col gap-2 border-t border-neutral-200 pt-4">
                                <p class="text-sm text-neutral-600">
                                    Silakan lakukan pembayaran sesuai informasi yang tertera pada
                                    langkah sebelumnya. Setelah pembayaran dilakukan, silakan
                                    hubungi petugas untuk mengaktifkan perpanjangan.
                                </p>
                            </div>
                        @endif
                    </div>
                </x-mk.card>
            </div>
        @endif

        {{-- §6.10 support escape hatch --}}
        <p class="mt-10 text-center text-sm text-neutral-600">
            Butuh bantuan?
            <a href="/bantuan" class="font-medium underline underline-offset-2">Hubungi Bantuan</a>.
        </p>
    </div>
</div>
