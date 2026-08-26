{{--
    resources/views/livewire/public/marketplace/order-tracking.blade.php

    PUB-024 — order tracking. Two labelled indicator rows: "Pembayaran"
    (PaymentState) and "Proses vendor" (VendorProcessingStatus). Both resolve
    through StatusIntent — never a `match` on a status in this file. They are
    visually and structurally distinct; a paid order is never shown as
    fulfilment-complete (AC12). Tokens only.

    The itemized breakdown below follows checkout.blade.php's own
    "Ringkasan pesanan" pattern exactly (`<x-mk.table>` + `Money::format()`)
    rather than inventing a new shape — `$order->items` is the frozen
    `MarketplaceOrderItem` snapshot (already eager-loaded with
    `listing.product` by `MarketplaceOrderQuery::findForCustomer()`), the
    same data `MarketplaceOrderInfolist` already shows staff.
--}}
@php
    use App\Domain\Marketplace\Models\MarketplaceOrderItem;
    use App\Platform\FinancialLedger\Money;
    use App\Support\Design\StatusIntent;
@endphp

<div class="py-8 md:py-12">
    <div class="mx-auto max-w-content px-4 md:px-6 lg:px-8">
        <header class="mb-6">
            <h1 class="text-2xl font-semibold text-neutral-900">Status Pesanan</h1>
        </header>

        @if ($order === null)
            <x-mk.card>
                <div class="py-10 text-center">
                    <p class="text-lg font-medium text-neutral-900">Pesanan tidak ditemukan</p>
                    <p class="mt-2 text-base text-neutral-600">Periksa kembali nomor pesanan Anda.</p>
                    <a
                        href="{{ route('marketplace.index') }}"
                        class="touch-target mt-6 inline-flex items-center text-base text-primary-700 underline underline-offset-2 hover:text-primary-800"
                    >
                        Lihat katalog
                    </a>
                </div>
            </x-mk.card>
        @else
            <x-mk.card class="mb-6">
                <dl class="grid gap-4 text-base md:grid-cols-2">
                    <div>
                        <dt class="text-neutral-600">Nomor pesanan</dt>
                        <dd class="font-semibold tabular-nums text-neutral-900">{{ $order->order_number }}</dd>
                    </div>
                    <div>
                        <dt class="text-neutral-600">Total</dt>
                        <dd class="font-bold tabular-nums text-neutral-900">{{ $order->total()->format() }}</dd>
                    </div>
                </dl>
            </x-mk.card>

            <section aria-labelledby="order-items-heading" class="mb-6">
                <h2 id="order-items-heading" class="text-lg font-semibold text-neutral-900">Rincian pesanan</h2>

                <x-mk.table
                    caption="Rincian item pesanan"
                    :headers="[
                        ['key' => 'product', 'label' => 'Produk'],
                        ['key' => 'price', 'label' => 'Harga', 'numeric' => true],
                        ['key' => 'quantity', 'label' => 'Jml'],
                        ['key' => 'lineTotal', 'label' => 'Subtotal', 'numeric' => true],
                    ]"
                    :rows="$items->map(fn (MarketplaceOrderItem $item): array => [
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
                        <span class="tabular-nums text-neutral-900">{{ (new Money((int) $order->subtotal_minor))->format() }}</span>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <span class="text-neutral-600">Ongkos kirim</span>
                        <span class="tabular-nums text-neutral-900">{{ (new Money((int) $order->delivery_fee_minor))->format() }}</span>
                    </div>
                    <div class="flex items-center justify-between gap-4 border-t border-neutral-200 pt-2">
                        <span class="font-semibold text-neutral-900">Total</span>
                        <span class="font-bold tabular-nums text-neutral-900">{{ $order->total()->format() }}</span>
                    </div>
                </div>
            </section>

            <section aria-labelledby="order-status-heading" class="mt-6">
                <h2 id="order-status-heading" class="text-lg font-semibold text-neutral-900">Status pesanan Anda</h2>

                {{-- TWO distinct indicator rows — payment and fulfilment never
                     merge into one badge (AC12). Each resolves through
                     StatusIntent. --}}
                <div class="mt-4 space-y-4">
                    <div class="flex items-center justify-between gap-4">
                        <span class="text-base font-medium text-neutral-700">Pembayaran</span>
                        <x-mk.badge
                            :intent="StatusIntent::intent($order->payment_state, StatusIntent::FAMILY_MARKETPLACE_PAYMENT)"
                            :icon="StatusIntent::icon($order->payment_state, StatusIntent::FAMILY_MARKETPLACE_PAYMENT)"
                        >
                            {{ StatusIntent::label($order->payment_state, StatusIntent::FAMILY_MARKETPLACE_PAYMENT) }}
                        </x-mk.badge>
                    </div>

                    <div class="flex items-center justify-between gap-4">
                        <span class="text-base font-medium text-neutral-700">Proses vendor</span>
                        @if ($vendorStatus !== null)
                            <x-mk.badge
                                :intent="StatusIntent::intent($vendorStatus, StatusIntent::FAMILY_VENDOR_PROCESSING)"
                                :icon="StatusIntent::icon($vendorStatus, StatusIntent::FAMILY_VENDOR_PROCESSING)"
                            >
                                {{ StatusIntent::label($vendorStatus, StatusIntent::FAMILY_VENDOR_PROCESSING) }}
                            </x-mk.badge>
                        @else
                            <span class="text-base text-neutral-600">Menunggu vendor</span>
                        @endif
                    </div>
                </div>
            </section>

            @if ($complaintSubmitted)
                <x-mk.alert intent="success" title="Komplain terkirim" class="mt-6" live="polite">
                    <p class="text-base">
                        Komplain Anda telah diterima dan diteruskan ke vendor. Tim kami akan menghubungi Anda
                        jika diperlukan informasi tambahan.
                    </p>
                </x-mk.alert>
            @elseif ($canFileComplaint)
                <section aria-labelledby="complaint-heading" class="mt-6">
                    <h2 id="complaint-heading" class="text-lg font-semibold text-neutral-900">Ajukan komplain</h2>
                    <p class="mt-1 text-base text-neutral-600">
                        Ada masalah dengan pesanan ini? Jelaskan keluhan Anda dan kami akan meneruskannya ke vendor.
                    </p>

                    <form wire:submit="fileComplaint" class="mt-4 space-y-4">
                        <x-mk.field
                            label="Keluhan Anda"
                            name="complaintReason"
                            type="textarea"
                            wire:model="complaintReason"
                            :error="$errors->first('complaintReason')"
                            hint="Jelaskan apa yang tidak sesuai dengan pesanan ini (minimal 10 karakter)."
                            required
                        />

                        @if ($complaintError !== null)
                            <x-mk.alert intent="danger" title="Komplain belum dapat diajukan" live="assertive">
                                <p class="text-base">{{ $complaintError }}</p>
                            </x-mk.alert>
                        @endif

                        <x-mk.button variant="danger" type="submit">
                            Ajukan komplain
                        </x-mk.button>
                    </form>
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
        @endif
    </div>
</div>
