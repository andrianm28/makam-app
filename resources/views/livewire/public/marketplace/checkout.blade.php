{{--
    resources/views/livewire/public/marketplace/checkout.blade.php

    PUB-023 — checkout. The online branch renders a §6.9 gate-closed banner
    (never hidden, never faked); the manual-transfer path is the live submit.
    Order summary uses tabular numerals; fields carry 44px targets and
    aria-invalid on error. Tokens only (ci/verify-docs.sh).
--}}
@php
    use App\Domain\Marketplace\Models\CartItem;
    use App\Platform\FinancialLedger\Money;
@endphp

<div class="py-8 md:py-12">
    <div class="mx-auto max-w-content px-4 md:px-6 lg:px-8">
        <header class="mb-6">
            <h1 class="text-2xl font-semibold text-neutral-900">Checkout</h1>
            <p class="mt-1 text-base text-neutral-600">Satu pesanan hanya dapat berasal dari satu vendor.</p>
        </header>

        @if ($orderPlaced && $placedOrderNumber !== null)
            <x-mk.alert intent="success" title="Pesanan diterima" class="mb-6" live="polite">
                <p class="text-base">
                    Pesanan <strong class="tabular-nums">{{ $placedOrderNumber }}</strong> berhasil dibuat dan
                    menunggu konfirmasi vendor. Ikuti langkah transfer manual di bawah untuk melanjutkan pembayaran.
                </p>
                <x-slot name="action">
                    <x-mk.button
                        variant="secondary"
                        size="sm"
                        href="{{ route('marketplace.order.tracking', ['orderNumber' => $placedOrderNumber]) }}"
                    >
                        Lacak pesanan
                    </x-mk.button>
                </x-slot>
            </x-mk.alert>

            @if ($onlinePaymentAllowed)
                <x-mk.card class="mb-6">
                    <div class="flex flex-col gap-2">
                        <h2 class="text-base font-semibold text-neutral-900">Pembayaran Online</h2>
                        <p class="text-sm text-neutral-600">
                            Anda akan diarahkan ke halaman pembayaran untuk menyelesaikan transaksi.
                        </p>
                    </div>

                    {{-- ADR-0035 item 1's mitigation — mirrors
                         `wizard.blade.php`'s identical booking-side warning:
                         unmissable labelling before any redirect to the
                         sandbox, on the button's own card, not only in a
                         dismissible site-wide banner. --}}
                    @if ($isSandboxPayment)
                        <x-mk.alert
                            intent="urgent"
                            icon="exclamation-triangle"
                            title="ANDA TIDAK AKAN MENGIRIM UANG SUNGGUHAN"
                            live="off"
                            class="mt-3"
                        >
                            <p class="text-sm">
                                Makam.co.id masih dalam masa uji coba publik (beta). Halaman pembayaran
                                di bawah ini adalah <strong>simulasi (sandbox)</strong> milik penyedia
                                pembayaran &mdash; tidak ada transaksi finansial nyata yang terjadi,
                                berapa pun nominal yang tertera. Pesanan Anda tetap tercatat, dan tim
                                kami akan menghubungi Anda secara langsung untuk menyelesaikan
                                pembayaran sesungguhnya secara manual.
                            </p>
                        </x-mk.alert>
                    @endif

                    @if ($onlinePaymentError !== null)
                        <x-mk.alert
                            intent="danger"
                            title="Pembayaran online belum dapat diproses"
                            live="assertive"
                            class="mt-3"
                        >
                            <p class="text-base">{{ $onlinePaymentError }}</p>
                            <x-slot name="action">
                                <x-mk.button
                                    variant="secondary"
                                    size="sm"
                                    href="{{ route('bantuan.index') }}"
                                >
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
                            Bayar Online
                        </x-mk.button>
                        <span wire:loading wire:target="payOnline" role="status" class="flex items-center gap-2 text-sm text-neutral-600">
                            <x-mk.spinner class="size-4" aria-hidden="true" />
                            Membuka halaman pembayaran&hellip;
                        </span>
                    </div>
                </x-mk.card>
            @endif
        @endif

        @if (! $onlinePaymentAllowed)
            <x-mk.gate-closed-banner intent="info" class="mb-6">
                <p class="text-base">
                    Pembayaran online belum tersedia. Gunakan <strong>transfer manual</strong> sebagai gantinya —
                    instruksinya ada di bagian bawah halaman ini.
                </p>
            </x-mk.gate-closed-banner>
        @endif

        @error('checkout')
            <x-mk.alert intent="danger" title="Checkout belum dapat diproses" class="mb-6" live="assertive">
                <p class="text-base">{{ $message }} Silakan hubungi dukungan melalui
                    <a href="{{ route('bantuan.index') }}" class="underline underline-offset-2">Butuh bantuan?</a>
                </p>
            </x-mk.alert>
        @enderror

        <div class="grid gap-8 md:grid-cols-2">
            <section aria-labelledby="order-summary-heading">
                <h2 id="order-summary-heading" class="text-lg font-semibold text-neutral-900">Ringkasan pesanan</h2>

                @if ($items->isEmpty())
                    <x-mk.card class="mt-4">
                        <p class="text-base text-neutral-600">Keranjang Anda kosong.</p>
                        <a
                            href="{{ route('marketplace.index') }}"
                            class="touch-target mt-4 inline-flex items-center text-base text-primary-700 underline underline-offset-2 hover:text-primary-800"
                        >
                            Lihat katalog
                        </a>
                    </x-mk.card>
                @else
                    <x-mk.table
                        caption="Ringkasan pesanan"
                        :headers="[
                            ['key' => 'product', 'label' => 'Produk'],
                            ['key' => 'price', 'label' => 'Harga', 'numeric' => true],
                            ['key' => 'quantity', 'label' => 'Jml'],
                            ['key' => 'lineTotal', 'label' => 'Subtotal', 'numeric' => true],
                        ]"
                        :rows="$items->map(fn (CartItem $item): array => [
                            'id' => $item->id,
                            'product' => $item->listing->product->name,
                            'price' => (new Money((int) $item->unit_price_minor))->format(),
                            'quantity' => $item->quantity,
                            'lineTotal' => $item->lineTotal()->format(),
                        ])->all()"
                        class="mt-4"
                    />

                    <div class="mt-6 space-y-2 text-base">
                        <div class="flex items-center justify-between gap-4">
                            <span class="text-neutral-600">Subtotal</span>
                            <span class="tabular-nums text-neutral-900">{{ $cart->subtotal()->format() }}</span>
                        </div>
                        @if ($selectedAreaCode !== '' && ($deliveryFee = $deliveryFeeByCode->get($selectedAreaCode)) !== null)
                            <div class="flex items-center justify-between gap-4">
                                <span class="text-neutral-600">Ongkos kirim ({{ $deliveryFee['area_label'] }})</span>
                                <span class="tabular-nums text-neutral-900">{{ (new Money((int) $deliveryFee['delivery_fee_minor']))->format() }}</span>
                            </div>
                            <div class="flex items-center justify-between gap-4 border-t border-neutral-200 pt-2">
                                <span class="font-semibold text-neutral-900">Total</span>
                                <span class="font-bold tabular-nums text-neutral-900">
                                    {{ (new Money((int) $cart->subtotal()->toMinorInt() + (int) $deliveryFee['delivery_fee_minor']))->format() }}
                                </span>
                            </div>
                        @endif
                    </div>
                @endif
            </section>

            <section aria-labelledby="recipient-heading">
                <h2 id="recipient-heading" class="text-lg font-semibold text-neutral-900">Data penerima</h2>

                <form wire:submit="placeOrder" class="mt-4 space-y-4">
                    <x-mk.field
                        label="Nama penerima"
                        name="recipientName"
                        wire:model="recipientName"
                        :error="$errors->first('recipientName')"
                        autocomplete="name"
                        required
                    />
                    <x-mk.field
                        label="Nomor HP penerima"
                        name="recipientPhone"
                        wire:model="recipientPhone"
                        type="tel"
                        inputmode="tel"
                        autocomplete="tel"
                        :error="$errors->first('recipientPhone')"
                        required
                    />
                    <x-mk.field
                        label="Email penerima"
                        name="recipientEmail"
                        wire:model="recipientEmail"
                        type="email"
                        inputmode="email"
                        autocomplete="email"
                        :error="$errors->first('recipientEmail')"
                        required
                    />

                    <x-mk.field
                        label="Area layanan"
                        name="selectedAreaCode"
                        wire:model="selectedAreaCode"
                        type="select"
                        :error="$errors->first('selectedAreaCode')"
                        required
                    >
                        @foreach ($serviceAreas as $area)
                            <option value="{{ $area['area_code'] }}" @selected($selectedAreaCode === $area['area_code'])>
                                {{ $area['area_label'] }}
                            </option>
                        @endforeach
                    </x-mk.field>

                    <x-mk.field
                        label="Tanggal pelaksanaan (opsional)"
                        name="scheduledFor"
                        wire:model="scheduledFor"
                        type="date"
                        :error="$errors->first('scheduledFor')"
                        optional
                    />

                    @if ($errors->isNotEmpty())
                        <x-mk.alert intent="danger" title="Periksa kembali isian Anda" live="assertive">
                            <p class="text-base">Ada data yang belum lengkap atau tidak sesuai. Silakan periksa kolom yang ditandai.</p>
                        </x-mk.alert>
                    @endif

                    @if (! $orderPlaced)
                        <x-mk.button
                            variant="primary"
                            size="lg"
                            full
                            type="submit"
                            :disabled="$items->isEmpty() || $hasStalePricing"
                        >
                            Buat pesanan
                        </x-mk.button>
                    @endif

                    @if ($hasStalePricing)
                        <p class="text-base text-neutral-600">
                            Harga berubah sejak item ditambahkan. Kembali ke keranjang untuk konfirmasi harga baru.
                        </p>
                        <x-mk.button variant="secondary" size="lg" full href="{{ route('marketplace.cart') }}">
                            Kembali ke keranjang
                        </x-mk.button>
                    @endif
                </form>
            </section>
        </div>

        @if ($orderPlaced && $placedOrderNumber !== null)
            <section aria-labelledby="manual-payment-heading" class="mt-10">
                <h2 id="manual-payment-heading" class="text-lg font-semibold text-neutral-900">Pembayaran transfer manual</h2>
                <x-mk.card class="mt-4">
                    <p class="text-base text-neutral-700">
                        Transfer sejumlah total pesanan, lalu masukkan nomor referensi transfer Anda di bawah.
                        Pesanan Anda akan dikonfirmasi setelah pembayaran diverifikasi.
                    </p>

                    <form wire:submit="submitManualProof" class="mt-4 space-y-4">
                        <x-mk.field
                            label="Nomor referensi transfer"
                            name="manualPaymentReference"
                            wire:model="manualPaymentReference"
                            :error="$manualSubmissionError"
                            required
                        />

                        @if ($manualSubmissionError)
                            <x-mk.alert intent="danger" title="Bukti transfer belum terkirim" live="assertive">
                                <p class="text-base">{{ $manualSubmissionError }}</p>
                            </x-mk.alert>
                        @endif

                        <x-mk.button variant="primary" size="lg" full type="submit">
                            Kirim bukti transfer
                        </x-mk.button>
                    </form>
                </x-mk.card>
            </section>
        @endif

        <p class="mt-10 text-base text-neutral-600">
            Butuh bantuan?{" "}
            <a
                href="{{ route('bantuan.index') }}"
                class="touch-target inline-flex items-center text-base text-primary-700 underline underline-offset-2 hover:text-primary-800"
            >
                Hubungi Customer Service
            </a>
        </p>
    </div>
</div>
