{{--
    resources/views/livewire/public/marketplace/cart.blade.php

    PUB-022 — the cart screen. One vendor per checkout (requirement 4): the
    §3.4 conflict modal states the constraint in plain Indonesian, names both
    vendors, and offers BOTH resolutions — replace the cart, or finish the
    current order first. Items are never silently dropped.

    Prices: `tabular-nums` with `--font-mono`, total in `--font-weight-bold`.
    Tokens only — never a hardcoded hex/px/ms/shadow (ci/verify-docs.sh).
--}}
@php
    use App\Domain\Marketplace\Models\CartItem;

    // Built here, not inline in <x-mk.table :rows="...">, on purpose:
    // Blade's component-tag compiler finds the end of a double-quote-
    // delimited attribute (:rows="...") by scanning the raw template text
    // for the next literal `"` — it has no knowledge of PHP string
    // nesting. The 'actions' cell's HtmlString is a single-quoted PHP
    // string containing double quotes (`type="button"` etc.), which is
    // completely safe PHP but broke the Blade compiler: it treated the
    // FIRST `"` inside that HTML literal as the end of :rows, corrupting
    // the whole component tag. Confirmed live 20 Aug 2026 (Playwright
    // E2E-MKT suite): once the cart held any item, page.content() showed
    // the raw uncompiled Blade source leaking onto the page as literal
    // text instead of a real <table> — no product rows, no remove
    // control, cart unusable. Computing $rows in its own @php block (the
    // same pattern modal.blade.php already uses for $baseId/$titleId)
    // means the HTML-with-double-quotes string never has to live inside a
    // double-quote-delimited Blade attribute value again.
    $rows = $items->map(function (CartItem $item) use ($cart): array {
        $listing = $item->listing;

        return [
            'id' => $item->id,
            'product' => $listing->product->name.' — '.$listing->vendor->name,
            'price' => (new \App\Platform\FinancialLedger\Money((int) $item->unit_price_minor))->format(),
            'quantity' => $item->quantity,
            'lineTotal' => $item->lineTotal()->format(),
            'actions' => new \Illuminate\Support\HtmlString(
                '<button type="button" wire:click="removeItem('.$item->id.')" class="touch-target inline-flex items-center text-base text-danger-700 underline underline-offset-2 hover:text-danger-800">Hapus</button>'
            ),
        ];
    })->all();
@endphp

<div class="py-8 md:py-12">
    <div class="mx-auto max-w-content px-4 md:px-6 lg:px-8">
        <header class="mb-6 flex items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold text-neutral-900">Keranjang</h1>
                <p class="mt-1 text-base text-neutral-600">Satu vendor untuk satu pesanan.</p>
            </div>
            <a
                href="{{ route('bantuan.index') }}"
                class="touch-target inline-flex items-center text-base text-primary-700 underline underline-offset-2 hover:text-primary-800"
            >
                Butuh bantuan?
            </a>
        </header>

        @if ($items->isEmpty())
            <x-mk.card>
                <div class="py-10 text-center">
                    <p class="text-lg font-medium text-neutral-900">Keranjang Anda masih kosong</p>
                    <p class="mt-2 text-base text-neutral-600">
                        Belum ada produk yang Anda pilih. Lihat katalog untuk memulai.
                    </p>
                    <a
                        href="{{ route('marketplace.index') }}"
                        class="touch-target mt-6 inline-flex items-center text-base text-primary-700 underline underline-offset-2 hover:text-primary-800"
                    >
                        Lihat katalog
                    </a>
                </div>
            </x-mk.card>
        @else
            @if ($hasStalePricing)
                <x-mk.alert intent="info" title="Harga berubah" class="mb-6" live="polite">
                    <p class="text-base">Harga berubah sejak item ditambahkan. Tinjau harga baru sebelum melanjutkan ke pembayaran.</p>
                    <x-slot name="action">
                        <x-mk.button variant="primary" size="sm" wire:click="reconfirmPricing" type="button">
                            Konfirmasi harga baru
                        </x-mk.button>
                    </x-slot>
                </x-mk.alert>
            @endif

            <x-mk.table
                caption="Isi keranjang"
                :headers="[
                    ['key' => 'product', 'label' => 'Produk'],
                    ['key' => 'price', 'label' => 'Harga satuan', 'numeric' => true],
                    ['key' => 'quantity', 'label' => 'Jumlah'],
                    ['key' => 'lineTotal', 'label' => 'Subtotal', 'numeric' => true],
                    ['key' => 'actions', 'label' => '', 'srLabel' => 'Aksi'],
                ]"
                :rows="$rows"
            />

            <div class="mt-6 flex flex-col items-start gap-4 md:flex-row md:items-center md:justify-between">
                <p class="text-lg">
                    Total
                    <span class="font-bold tabular-nums text-neutral-900">{{ $cart->subtotal()->format() }}</span>
                </p>

                @if (! $hasStalePricing)
                    <x-mk.button
                        variant="primary"
                        size="lg"
                        full
                        href="{{ route('marketplace.checkout') }}"
                        class="md:w-auto"
                    >
                        Lanjut ke pembayaran
                    </x-mk.button>
                @else
                    <p class="text-base text-neutral-600">Konfirmasi harga baru untuk melanjutkan.</p>
                @endif
            </div>
        @endif

        @if ($conflictOpen && $conflict !== null)
            <x-mk.modal title="Hanya satu vendor per pesanan" size="md" :dismissible="true">
                <p class="text-base text-neutral-700">
                    Keranjang Anda saat ini berisi pesanan dari <strong>{{ $conflict['existingVendorName'] }}</strong>
                    ({{ $conflict['existingItemCount'] }} item). Keranjang hanya dapat menampung satu vendor
                    per pesanan.
                </p>
                <p class="mt-3 text-base text-neutral-700">
                    Anda mencoba menambahkan produk dari <strong>{{ $conflict['incomingVendorName'] }}</strong>.
                    Pilih salah satu:
                </p>

                <x-slot name="footer">
                    <x-mk.button variant="secondary" size="lg" wire:click="dismissConflict" type="button">
                        Selesaikan pesanan ini dulu
                    </x-mk.button>
                    <x-mk.button variant="primary" size="lg" wire:click="resolveConflictByReplacing" type="button">
                        Ganti keranjang
                    </x-mk.button>
                </x-slot>
            </x-mk.modal>
        @endif
    </div>
</div>
