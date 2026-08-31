{{--
    resources/views/livewire/public/renewal/payment.blade.php

    App\Livewire\Public\Renewal\RenewalPayment's view — `/perpanjangan/
    pembayaran`, Screen 2 "Biaya & Bayar" of the consolidated renewal
    journey (journey steps 4-5: Biaya, Pembayaran). Merge of the former
    payment.blade.php (step 5) and fee.blade.php (step 4) — every state each
    one rendered is preserved verbatim below. `$mode` (`'not_found'`|
    `'fee'`|`'payment'`) drives which half renders; within `'payment'` mode
    the pre-existing `$paymentState` (`'denied'`|`'manual'`|`'online'`)
    still drives the three payment branches exactly as before.
--}}
<div class="py-8 md:py-12">
    <div class="mx-auto max-w-content px-4">

        <x-mk.stepper
            :labels="$stepLabels"
            :step="$currentStep"
            aria-label="Progres perpanjangan makam"
            class="mb-8"
        />

        @if ($mode === 'not_found')
            {{-- This state is reachable with nothing at all to act on: no
                 `?perpanjangan=`, and no session-held grave selection —
                 including AFTER a successful acceptance, since
                 `terimaDanLanjutkan()` forgets the selection and
                 `$perpanjangan` is `#[Url(history: true)]`, so a browser
                 Back can land here with both gone. A support link alone
                 would be a dead end, so the search itself is offered as the
                 primary way out; Bantuan stays, one step down. --}}
            <div class="flex flex-col items-center gap-3 py-12 text-center">
                <x-dynamic-component component="icon.inbox" class="size-12 text-neutral-400" aria-hidden="true" />
                <h1 class="text-lg font-semibold text-neutral-800">
                    {{ $errorMessage }}
                </h1>
                <p class="max-w-prose text-base text-neutral-600">
                    Anda dapat mencari makam yang ingin diperpanjang dari awal, atau
                    hubungi petugas kami untuk dibantu langsung.
                </p>
                <div class="mt-2 flex flex-wrap items-center justify-center gap-3">
                    <x-mk.button variant="primary" href="/perpanjangan">
                        Cari makam lain
                    </x-mk.button>
                    <x-mk.button variant="secondary" href="/bantuan">
                        Hubungi Bantuan
                    </x-mk.button>
                </div>
            </div>

        @elseif ($mode === 'fee')
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

                            <div class="flex flex-col gap-1">
                                <span class="text-sm text-neutral-600">Estimasi biaya perpanjangan</span>
                                <span class="font-mono text-3xl font-bold text-neutral-900">
                                    {{ $quote->amountAsMoney()->format() }}
                                </span>
                            </div>

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

                            <div class="flex flex-col gap-2 border-t border-neutral-200 pt-4">
                                <x-mk.button
                                    variant="primary"
                                    type="button"
                                    wire:click="terimaDanLanjutkan"
                                    wire:loading.attr="disabled"
                                    class="w-full justify-center"
                                >
                                    Terima Tarif &mdash; Lanjut ke Pembayaran
                                </x-mk.button>
                                <p class="text-center text-sm text-neutral-500">
                                    Dengan melanjutkan, Anda menyetujui estimasi biaya di atas.
                                </p>
                            </div>
                        </div>
                    </x-mk.card>
                </div>
            @endif

        @else
            {{-- $mode === 'payment' — unchanged from the pre-merge payment.blade.php --}}
            @if ($paymentState === 'denied')
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
        @endif

        <p class="mt-10 text-center text-sm text-neutral-600">
            Butuh bantuan dengan pembayaran?
            <a href="/bantuan" class="font-medium underline underline-offset-2">Hubungi Bantuan</a>.
        </p>
    </div>
</div>
