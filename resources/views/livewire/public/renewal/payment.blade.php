{{--
    resources/views/livewire/public/renewal/payment.blade.php

    App\Livewire\Public\Renewal\RenewalPayment's view — `/perpanjangan/pembayaran`,
    screen PUB-033 (`docs/product/screen-inventory.md`: "payment | manual
    coordination or online payment"). L8 Task 5, AC8; online checkout landed
    in the 2026-08-25 online-payment-gateway lane's Task 3.

    ===========================================================================
    AC8 — both halves are real now
    ===========================================================================
    `$paymentState` (computed server-side by `RenewalPayment::resolveState()`
    from `GuardRenewalPaymentOpening`) drives three real branches below:
    `'denied'` (a fixed refusal), `'manual'` (the coordination card — allowed,
    but `G-PAY-01` is closed), and `'online'` (a real "Bayar Sekarang" button
    that opens a hosted-checkout session via `RenewalPayment::payOnline()` ->
    `App\Platform\Payment\Actions\OpenPaymentSession::authorizeRenewal()`).
    The manual-coordination path is unchanged from before this lane and is
    never removed when the gate is closed — design-system.md §6.9: "Step 8 is
    never removed." The renewal analogue is step 5.
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
                @if ($paymentState === 'online')
                    {{--
                        AC8's online half — a real hosted-checkout session,
                        opened by `RenewalPayment::payOnline()`. Styled after
                        `App\Livewire\Public\Booking\BookingWizard`'s own
                        online-payment card (`resources/views/livewire/
                        public/booking/wizard.blade.php`), trimmed to what
                        this screen actually needs: no webhook-driven session
                        state card, because this component does not persist
                        an opened session's id across requests the way the
                        booking wizard's PHP-session-bound draft does.
                    --}}
                    <x-mk.card>
                        <div class="flex flex-col gap-3">
                            <h3 class="text-base font-semibold text-neutral-900">
                                Pembayaran Online
                            </h3>
                            <p class="text-sm text-neutral-600">
                                Anda akan diarahkan ke halaman pembayaran untuk
                                menyelesaikan perpanjangan masa sewa makam.
                            </p>
                        </div>

                        {{--
                            ADR-0035 item 1's mitigation: "unmissable
                            payment-step labelling ... before any redirect to
                            the sandbox," mirrored verbatim from the booking
                            wizard's own card — the same sandbox provider
                            settles this checkout too.
                        --}}
                        @if ($isSandboxPayment)
                            <x-mk.alert
                                intent="urgent"
                                icon="exclamation-triangle"
                                title="ANDA TIDAK AKAN MENGIRIM UANG SUNGGUHAN"
                                live="off"
                                class="mt-3"
                            >
                                <p class="text-sm">
                                    Makam.co.id masih dalam masa uji coba publik (beta). Halaman
                                    pembayaran di bawah ini adalah <strong>simulasi (sandbox)</strong>
                                    milik penyedia pembayaran &mdash; tidak ada transaksi finansial
                                    nyata yang terjadi, berapa pun nominal yang tertera. Perpanjangan
                                    Anda tetap tercatat, dan tim kami akan menghubungi Anda secara
                                    langsung apabila diperlukan.
                                </p>
                            </x-mk.alert>
                        @endif

                        @if ($checkoutError !== null)
                            <x-mk.alert
                                intent="danger"
                                title="Pembayaran online belum dapat diproses"
                                live="assertive"
                                class="mt-3"
                            >
                                <p class="text-sm">{{ $checkoutError }}</p>
                                <x-slot name="action">
                                    <x-mk.button variant="secondary" size="sm" href="/bantuan">
                                        Butuh bantuan?
                                    </x-mk.button>
                                </x-slot>
                            </x-mk.alert>
                        @endif

                        <div class="mt-3 flex flex-wrap items-center gap-3">
                            <x-mk.button
                                variant="primary"
                                wire:click="payOnline"
                                wire:loading.attr="disabled"
                                wire:target="payOnline"
                            >
                                Bayar Sekarang
                            </x-mk.button>
                            <span wire:loading wire:target="payOnline" role="status" class="flex items-center gap-2 text-sm text-neutral-600">
                                <x-mk.spinner class="size-4" aria-hidden="true" />
                                Membuka halaman pembayaran&hellip;
                            </span>
                        </div>
                    </x-mk.card>
                @else
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
                @endif
            </div>
        @endif

        {{-- §6.10 support escape hatch --}}
        <p class="mt-10 text-center text-sm text-neutral-600">
            Butuh bantuan dengan pembayaran?
            <a href="/bantuan" class="font-medium underline underline-offset-2">Hubungi Bantuan</a>.
        </p>
    </div>
</div>
