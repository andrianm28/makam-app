{{--
    resources/views/livewire/public/marketplace/product-detail.blade.php

    App\Livewire\Public\Marketplace\ProductDetail's view —
    `/marketplace/produk/{productCode}`, PUB-021. Read that class's doc block
    first: it carries the "routed by code, not by an invented slug"
    reasoning, the 404 discipline, why variants are displayed rather than
    selected, and how the add-to-cart affordance picks its listing.

    --- What PUB-021 asks for that this page does NOT render, and why ---
    screen-inventory.md lists PUB-021's key states as "variant, schedule,
    area unavailable", and marketplace-catalog.md §"Required product data"
    (AC2) additionally names stock/availability, service area, schedule,
    delivery fee rule, production lead time, cancellation policy, and
    evidence requirement.

    NONE of those were columns on `products` or `product_variants`.
    `2026_07_26_180000_create_products_table.php`'s own doc block calls the
    absence of stock/schedule/service-area columns deliberate and still open
    ("future vendor-listing-table concerns"). The `vendor_listings` table now
    EXISTS (L10) and carries most of them; the bootstrap seed ships one
    example listing per product, so a fresh install has an offer behind every
    product.

    UPDATE (this batch): availability mode, stock quantity (when
    `availability_mode` is STOCKED), production lead time, evidence
    requirement, and cancellation policy are now rendered from `$listing`,
    directly under the vendor line in the summary column above. This page
    still cannot render a real schedule (an actual delivery/service date) or
    a delivery fee — both genuinely depend on a service area the shopper has
    not yet chosen, and rendering either would fabricate a commercial fact
    the repository does not hold, which is the exact failure
    `2026_07_26_200200_seed_marketplace_products_and_variants.php` avoided by
    seeding `base_price_idr` NULL rather than guessing. The "ordering status"
    section below says plainly, once, that schedule and delivery fee are
    confirmed with the vendor and are not shown here. AC2 is therefore MORE
    COMPLETE after this batch (5 of 7 named fields now render) but still not
    FULL — schedule and delivery fee remain open — and is reported that way,
    not ticked as done.

    --- The two ordering states ---
    When the product has NO active `vendor_listings` row (deactivated or
    removed), the §6.7 pending alert below is exactly what the page shows:
    online ordering is genuinely unavailable for that product, with the
    customer-service escape hatch and the one-vendor-per-checkout note.

    When an active listing exists, the summary column renders the REAL offer
    — the listing's price (not the catalogue's dummy `base_price_idr`), the
    listing's vendor name, and (this batch) its availability mode, stock,
    production lead time, evidence requirement, and cancellation policy —
    with the "Tambah ke Keranjang" primary button, and the ordering section
    becomes a neutral note that schedule and delivery fee are confirmed with
    the vendor, carrying the same §6.10 customer-service escape hatch as the
    pending state. The dummy price's attribution line and the dummy
    `products.vendor_name` are suppressed in this state because they are
    fabricated values that would contradict the real offer standing right
    next to them; they remain, with their markers, when there is no listing
    to be truthful about. The one-vendor constraint sentence is stated in
    BOTH states (AC4).

    --- `preview_image_path` is deliberately not rendered ---
    The six seeded `product_variants` rows carry
    `marketplace/gravestone-variants/placeholder-*.jpg` paths. The seed
    migration's own doc block: *"`preview_image_path` values below are
    PLACEHOLDER STRINGS ONLY — no file exists at that path in any storage
    disk in this batch."* An `<img src>` pointing at them would render a
    broken image on every gravestone page. The product-level `photo_path`
    IS rendered, because `public/images/marketplace/*.svg` are real files
    that exist on disk.

    --- <x-mk.button> ---
    Same reasoning as `index.blade.php`'s own doc comment: N-14 is fixed and
    verified, so new pages use the primitive.
--}}
@php
    use App\Domain\Marketplace\MarketplaceProductCategory;
    use App\Livewire\Public\Marketplace\Support\MarketplacePresenter;
@endphp

<div class="py-8 md:py-12">
    <div class="mx-auto max-w-content px-4 md:px-6 lg:px-8">
        <nav aria-label="Navigasi kembali" class="mb-6">
            <a
                href="{{ route('marketplace.index', ['kategori' => $product->category]) }}"
                class="touch-target inline-flex items-center text-base text-primary-700 underline underline-offset-2 hover:text-primary-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 focus-visible:ring-offset-2"
            >
                Kembali ke {{ MarketplaceProductCategory::label($product->category) }}
            </a>
        </nav>

        <div class="grid grid-cols-1 gap-6 md:gap-8 lg:grid-cols-2">
            @if ($product->photo_path)
                <div class="overflow-hidden rounded-lg border border-neutral-200 bg-neutral-0">
                    {{-- Decorative: <h1> below names the product. --}}
                    <img
                        src="{{ asset($product->photo_path) }}"
                        alt=""
                        class="h-64 w-full object-cover md:h-80"
                    >
                </div>
            @endif

            <div class="space-y-4">
                <div>
                    <x-mk.badge intent="neutral">{{ $product->categoryLabel() }}</x-mk.badge>
                </div>

                <h1 class="text-3xl font-semibold tracking-tight text-neutral-900">{{ $product->name }}</h1>

                {{-- The fabricated `products.vendor_name` is only shown when
                     there is no real offer to tell the truth about — a real
                     listing renders its own vendor beside its own price
                     below, and a dummy name next to a real one would read as
                     two different sellers of the same product. The marker
                     stays on the dummy name whenever it IS shown. --}}
                @if ($listing === null && $product->vendor_name)
                    <p class="text-base text-neutral-600">
                        Vendor: <span class="text-neutral-800">{{ MarketplacePresenter::vendorLabel($product) }}</span>
                    </p>
                @endif

                @if ($listing !== null)
                    {{-- The REAL offer: the listing the add button below
                         adds, priced from `vendor_listings`, not the dummy
                         catalogue estimate. No attribution line is needed —
                         this figure is not "data contoh". --}}
                    <p class="text-2xl font-semibold tabular-nums text-neutral-900">
                        {{ $listing->priceMoney()->format() }}
                    </p>
                    <p class="text-sm text-neutral-600">
                        Ditawarkan oleh {{ $listing->vendor->name }}
                    </p>

                    <dl class="mt-3 space-y-2 text-sm text-neutral-700">
                        <div class="flex flex-wrap items-center gap-2">
                            <dt class="text-neutral-600">Ketersediaan</dt>
                            <dd>
                                <x-mk.badge :intent="match ($listing->availability_mode) {
                                    \App\Domain\Marketplace\AvailabilityMode::STOCKED => 'success',
                                    \App\Domain\Marketplace\AvailabilityMode::MADE_TO_ORDER => 'info',
                                    \App\Domain\Marketplace\AvailabilityMode::SCHEDULED => 'info',
                                    default => 'neutral',
                                }">
                                    {{ match ($listing->availability_mode) {
                                        \App\Domain\Marketplace\AvailabilityMode::STOCKED => 'Tersedia (stok)',
                                        \App\Domain\Marketplace\AvailabilityMode::MADE_TO_ORDER => 'Dibuat sesuai pesanan',
                                        \App\Domain\Marketplace\AvailabilityMode::SCHEDULED => 'Dijadwalkan',
                                        default => $listing->availability_mode,
                                    } }}
                                </x-mk.badge>
                            </dd>
                        </div>

                        @if ($listing->availability_mode === \App\Domain\Marketplace\AvailabilityMode::STOCKED && $listing->stock_quantity !== null)
                            <div class="flex flex-wrap items-center gap-2">
                                <dt class="text-neutral-600">Stok</dt>
                                <dd class="font-medium text-neutral-900">{{ $listing->stock_quantity }} unit</dd>
                            </div>
                        @endif

                        @if ($listing->production_lead_time_days !== null)
                            <div class="flex flex-wrap items-center gap-2">
                                <dt class="text-neutral-600">Waktu pengerjaan</dt>
                                <dd class="font-medium text-neutral-900">{{ $listing->production_lead_time_days }} hari</dd>
                            </div>
                        @endif

                        @if ($listing->evidence_requirement !== \App\Domain\Marketplace\EvidenceRequirement::NONE)
                            <div class="flex flex-wrap items-center gap-2">
                                <dt class="text-neutral-600">Bukti penyelesaian</dt>
                                <dd class="font-medium text-neutral-900">
                                    {{ $listing->evidence_requirement === \App\Domain\Marketplace\EvidenceRequirement::PHOTO ? 'Vendor mengunggah foto' : 'Vendor mengunggah dokumen' }}
                                </dd>
                            </div>
                        @endif

                        @if ($listing->cancellation_policy)
                            <div>
                                <dt class="text-neutral-600">Kebijakan pembatalan</dt>
                                <dd class="mt-1 text-neutral-700">{{ $listing->cancellation_policy }}</dd>
                            </div>
                        @endif
                    </dl>
                @else
                    @php
                        $price = MarketplacePresenter::priceAttribution($product);
                    @endphp
                    <p class="text-2xl font-semibold text-neutral-900">
                        @if ($price !== null)
                            Mulai {{ $price['amount'] }}
                        @else
                            Harga belum tersedia
                        @endif
                    </p>

                    @if ($price !== null)
                        <p class="text-sm text-neutral-600">{{ $price['source'] }}</p>
                    @endif
                @endif

                <p class="max-w-prose text-base text-neutral-700">{{ $product->description }}</p>

                {{-- The add affordance — only when there is an offer to add.
                     Primary CTA, full-width on mobile (thumb reach), loading
                     disabled via wire:loading (the button primitive's own
                     disabled state, §3.1). The note under it names the
                     vendor whose offer is being added and states AC4's
                     one-vendor constraint at the exact point of adding. --}}
                @if ($listing !== null)
                    <div class="flex flex-col gap-2 pt-2">
                        <x-mk.button
                            variant="primary"
                            size="lg"
                            type="button"
                            wire:click="addToCart"
                            wire:loading.attr="disabled"
                            full
                            class="md:w-auto"
                        >
                            Tambah ke Keranjang
                        </x-mk.button>
                        <p class="text-sm text-neutral-600">
                            Item ditambahkan sebagai penawaran dari {{ $listing->vendor->name }}.
                            Satu checkout hanya dapat memuat produk dari satu vendor.
                        </p>
                    </div>
                @endif
            </div>
        </div>

        {{-- Variants (AC2, partial). Three genuinely different situations,
             never collapsed into one message — see ProductDetail's own doc
             block. --}}
        <section aria-labelledby="product-variants-heading" class="mt-10">
            <h2 id="product-variants-heading" class="mb-4 text-2xl font-semibold text-neutral-900">
                Pilihan Varian
            </h2>

            @if ($variantsUnavailable)
                {{-- §6.5 provider unavailable, scoped to this panel only —
                     the product's own name, price, and support path above
                     have already rendered. --}}
                <x-mk.alert intent="pending" title="Pilihan varian sedang tidak dapat dimuat" icon="clock" live="polite">
                    Daftar varian untuk produk ini tidak dapat kami tampilkan saat ini. Detail produk lainnya di atas tetap berlaku. Silakan coba beberapa saat lagi, atau
                    <a href="/bantuan" class="underline underline-offset-2">hubungi customer service</a>.
                </x-mk.alert>
            @elseif (! $product->hasVariantAxes())
                {{-- Not an error and not an empty state: the catalogue names
                     variant attributes only under Batu Nisan, so this product
                     family genuinely has none. Saying "belum ada varian"
                     here would imply something is missing. --}}
                <x-mk.alert intent="neutral" title="Produk ini tidak memiliki pilihan varian" live="off">
                    {{ MarketplaceProductCategory::label($product->category) }} ditawarkan sebagai satu paket, tanpa pilihan ukuran atau bahan yang perlu Anda tentukan.
                </x-mk.alert>
            @elseif ($variants->isEmpty())
                {{-- §6.2 empty — this product family DOES carry variant axes,
                     so zero rows means they are not configured yet. What is
                     empty, why, what to do next. --}}
                <div class="flex flex-col items-start gap-3 py-8">
                    <h3 class="text-lg font-semibold text-neutral-800">Belum ada varian yang terdaftar.</h3>
                    <p class="max-w-prose text-base text-neutral-600">
                        Pilihan ukuran, bahan, dan warna untuk produk ini belum didaftarkan oleh vendor. Customer service kami dapat menanyakan ketersediaannya untuk Anda.
                    </p>
                    <x-mk.button variant="secondary" href="/bantuan">
                        Hubungi Customer Service
                    </x-mk.button>
                </div>
            @else
                <p class="sr-only" role="status" aria-live="polite">
                    {{ $variants->count() }} varian tersedia.
                </p>

                <ul class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:gap-6 lg:grid-cols-3" aria-label="Daftar varian produk">
                    @foreach ($variants as $variant)
                        <li wire:key="variant-{{ $variant->id }}">
                            <x-mk.card class="h-full">
                                {{-- A definition list, not a table: at 320px
                                     a table of six variant attributes either
                                     scrolls horizontally or crushes its
                                     columns, and design.md §4.3 calls
                                     horizontal scrolling on this kind of list
                                     a usability failure. --}}
                                <dl class="space-y-2 text-base">
                                    @if ($variant->size)
                                        <div class="flex flex-wrap gap-x-2">
                                            <dt class="text-neutral-600">Ukuran</dt>
                                            <dd class="font-medium text-neutral-900">{{ $variant->size }}</dd>
                                        </div>
                                    @endif
                                    @if ($variant->material)
                                        <div class="flex flex-wrap gap-x-2">
                                            <dt class="text-neutral-600">Bahan</dt>
                                            <dd class="font-medium text-neutral-900">{{ $variant->material }}</dd>
                                        </div>
                                    @endif
                                    @if ($variant->color)
                                        <div class="flex flex-wrap gap-x-2">
                                            <dt class="text-neutral-600">Warna</dt>
                                            <dd class="font-medium text-neutral-900">{{ $variant->color }}</dd>
                                        </div>
                                    @endif
                                    @if ($variant->calligraphy_style)
                                        <div class="flex flex-wrap gap-x-2">
                                            <dt class="text-neutral-600">Gaya kaligrafi</dt>
                                            <dd class="font-medium text-neutral-900">{{ $variant->calligraphy_style }}</dd>
                                        </div>
                                    @endif
                                    @if ($variant->inscription_text_example)
                                        <div class="space-y-1">
                                            <dt class="text-neutral-600">Contoh teks inskripsi</dt>
                                            <dd class="text-sm text-neutral-700">{{ $variant->inscription_text_example }}</dd>
                                        </div>
                                    @endif
                                </dl>
                            </x-mk.card>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- Ordering status — two honest states, never one that implies the
             other. With an active listing the add button above is live and
             this section only states what is confirmed later; without one,
             the §6.7 pending alert says online ordering is not available
             for THIS product and points at customer service. Both carry the
             one-vendor-per-checkout note (AC4) — the pending one inside the
             alert, the live one directly under the add button above.

             Deliberately NOT <x-mk.gate-closed-banner>: design-system.md
             §6.9 gate banners describe a real closed FEATURE GATE read
             through `ModeResolver`, and no `G-*` gate governs whether
             checkout exists. Checkout is a real route since this lane; what
             a listing-less product shows is an honest "no offer yet" state,
             not a gate. --}}
        <section aria-labelledby="ordering-status-heading" class="mt-10">
            <h2 id="ordering-status-heading" class="sr-only">Status pemesanan produk</h2>

            @if ($listing !== null)
                <x-mk.alert intent="neutral" title="Detail pesanan dikonfirmasi bersama vendor" live="off">
                    <p>
                        Jadwal pengiriman atau pengerjaan dan biaya pengiriman ke area Anda dikonfirmasi bersama vendor setelah pesanan dibuat.
                    </p>
                    {{-- §6.10 support escape hatch — same affordance as the
                         pending state below; online ordering being AVAILABLE
                         does not remove the support path. --}}
                    <x-slot:action>
                        <x-mk.button variant="secondary" href="/bantuan">
                            Hubungi Customer Service
                        </x-mk.button>
                    </x-slot:action>
                </x-mk.alert>
            @else
                <x-mk.alert intent="pending" title="Pemesanan online belum tersedia" icon="clock" live="off">
                    <p>
                        Halaman ini menampilkan katalog produk saja. Penawaran dari vendor untuk produk ini belum tersedia, sehingga belum dapat ditambahkan ke keranjang. Untuk memesan produk ini sekarang, hubungi customer service kami dan tim kami akan membantu prosesnya.
                    </p>
                    <p class="mt-2">
                        Jadwal pengiriman atau pengerjaan, area layanan, dan biaya pengiriman dikonfirmasi bersama vendor dan belum ditampilkan di halaman ini.
                    </p>
                    <p class="mt-2">
                        Catatan: satu checkout hanya dapat memuat produk dari satu vendor. Bila nanti Anda memesan produk dari vendor berbeda, pesanannya diselesaikan secara terpisah.
                    </p>
                    <x-slot:action>
                        <x-mk.button variant="primary" href="/bantuan">
                            Hubungi Customer Service
                        </x-mk.button>
                    </x-slot:action>
                </x-mk.alert>
            @endif
        </section>

        {{-- §3.4 single-vendor conflict modal — same copy, same actions, same
             state shape as the cart screen's (PUB-022), so the two surfaces
             state AC4 identically. Only "Ganti keranjang" clears anything. --}}
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
