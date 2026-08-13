{{--
    resources/views/livewire/public/renewal/payment.blade.php

    App\Livewire\Public\Renewal\RenewalPayment's view — `/perpanjangan/pembayaran`,
    screen PUB-033 (`docs/product/screen-inventory.md`: "payment | manual
    coordination or online payment"). L8 Task 5, AC8.

    ===========================================================================
    AC8 — NEVER the online path (BLOCKED upstream)
    ===========================================================================
    `PaymentSession::create()` throws by design (Wave 1b ruling 1b-L3-01).
    Ruling A (Option A): when all conditions hold, render the manual
    coordination path. The online path is BLOCKED (upstream deny-only) and is
    never claimed as PASS. The step is never removed — design-system.md §6.9:
    "Step 8 is never removed." The renewal analogue is step 5.

    The screen therefore always renders the manual coordination path when
    allowed, and a denial message when denied.
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
        @elseif ($paymentState === 'denied')
            {{--
                One fixed refusal for every denial reason. The guard's specific
                reason is deliberately NOT printed here — see the component's
                doc block: on an anonymous page it would distinguish "no such
                renewal" from "restricted grave" from "stale quote" for anyone
                iterating UUIDs.
            --}}
            <div class="flex flex-col items-center gap-3 py-12 text-center">
                <x-dynamic-component component="icon.inbox" class="size-12 text-neutral-400" aria-hidden="true" />
                <h1 class="text-lg font-semibold text-neutral-800">
                    Pembayaran tidak dapat diproses
                </h1>
                <p class="max-w-prose text-base text-neutral-600">
                    Perpanjangan ini belum dapat dilanjutkan ke pembayaran.
                    Silakan hubungi petugas kami untuk memeriksa statusnya.
                </p>
                <x-mk.button variant="primary" href="/bantuan" class="mt-2">
                    Hubungi Bantuan
                </x-mk.button>
            </div>
        @else
            <div class="mx-auto mb-8 max-w-prose space-y-2 text-center">
                <h1 class="text-3xl font-semibold tracking-tight text-neutral-900">
                    Pembayaran Perpanjangan
                </h1>
                <p class="text-base text-neutral-600">
                    Perpanjangan masa sewa makam.
                </p>
            </div>

            <div class="mx-auto mb-8 max-w-prose">
                <x-mk.card>
                    <div class="flex flex-col gap-4">
                        {{-- AC8: manual coordination path — step is never removed --}}
                        <div class="flex flex-col gap-3">
                            <div class="flex items-start gap-3">
                                <x-dynamic-component
                                    component="icon.alert-circle"
                                    class="size-6 text-blue-600 flex-shrink-0 mt-0.5"
                                    aria-hidden="true"
                                />
                                <div class="flex flex-col gap-1">
                                    <p class="font-medium text-neutral-900">
                                        Koordinasi manual diperlukan
                                    </p>
                                    <p class="text-sm text-neutral-600">
                                        Pembayaran online untuk perpanjangan makam saat ini
                                        belum tersedia. Silakan hubungi petugas kami untuk
                                        koordinasi manual.
                                    </p>
                                </div>
                            </div>
                        </div>

                        {{-- Proceed to confirmation --}}
                        <div class="flex flex-col gap-2 border-t border-neutral-200 pt-4">
                            <x-mk.button
                                variant="primary"
                                href="/perpanjangan/konfirmasi?perpanjangan={{ $perpanjangan }}"
                                class="w-full justify-center"
                            >
                                Lanjutkan ke Konfirmasi
                            </x-mk.button>
                            <p class="text-center text-sm text-neutral-500">
                                Dengan melanjutkan, Anda akan menyelesaikan proses
                                perpanjangan setelah pembayaran dilakukan.
                            </p>
                        </div>
                    </div>
                </x-mk.card>
            </div>
        @endif

        {{-- §6.10 support escape hatch --}}
        <p class="mt-10 text-center text-sm text-neutral-600">
            Butuh bantuan dengan pembayaran?
            <a href="/bantuan" class="font-medium underline underline-offset-2">Hubungi Bantuan</a>.
        </p>
    </div>
</div>
