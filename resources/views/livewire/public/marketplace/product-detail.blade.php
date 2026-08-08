{{--
    resources/views/livewire/public/marketplace/product-detail.blade.php

    App\Livewire\Public\Marketplace\ProductDetail's view —
    `/marketplace/produk/{productCode}`, PUB-021. Read that class's doc block
    first: it carries the "routed by code, not by an invented slug"
    reasoning, the 404 discipline, and why variants are displayed rather than
    selected.

    --- What PUB-021 asks for that this page does NOT render, and why ---
    screen-inventory.md lists PUB-021's key states as "variant, schedule,
    area unavailable", and marketplace-catalog.md §"Required product data"
    (AC2) additionally names stock/availability, service area, schedule,
    delivery fee rule, production lead time, cancellation policy, and
    evidence requirement.

    NONE of those are columns on `products` or `product_variants`.
    `2026_07_26_180000_create_products_table.php`'s own doc block calls the
    absence of stock/schedule/service-area columns deliberate and still open
    ("future vendor-listing-table concerns"), and no later migration adds
    them. So this page cannot render a schedule, a delivery fee, or a real
    §6.2 "area unavailable" state — there is no data behind any of them.
    Rendering a plausible-looking one would fabricate a commercial fact the
    repository does not hold, which is the exact failure
    `2026_07_26_180200_seed_marketplace_products_and_variants.php` avoided by
    seeding `base_price_idr` NULL rather than guessing. The page instead says
    plainly, once, that schedule and service area are confirmed with the
    vendor and are not shown here. AC2 therefore remains PARTIAL after this
    batch; it is reported as such, not ticked.

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

                @if ($product->vendor_name)
                    <p class="text-base text-neutral-600">
                        Vendor: <span class="text-neutral-800">{{ $product->vendor_name }}</span>
                    </p>
                @endif

                <p class="text-2xl font-semibold text-neutral-900">
                    @if ($product->base_price_idr !== null)
                        Mulai Rp {{ number_format((float) $product->base_price_idr, 0, ',', '.') }}
                    @else
                        Harga belum tersedia
                    @endif
                </p>

                <p class="max-w-prose text-base text-neutral-700">{{ $product->description }}</p>
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

        {{-- §6.7 pending — what is being waited on, who acts next, and the
             channel. Styled `pending`, never `success`. This is where AC4's
             one-vendor-per-checkout constraint is stated as the NOTE this
             batch's brief asked for: no cart, no vendor grouping, and no
             enforcement mechanism exists to build it into.

             Deliberately NOT <x-mk.gate-closed-banner>: design-system.md
             §6.9 gate banners describe a real closed FEATURE GATE read
             through `ModeResolver`, and no `G-*` gate governs whether
             checkout exists. Checkout is simply not built yet — the same
             distinction `coming-soon.blade.php`'s own doc block draws. --}}
        <section aria-labelledby="ordering-status-heading" class="mt-10">
            <h2 id="ordering-status-heading" class="sr-only">Status pemesanan produk</h2>
            <x-mk.alert intent="pending" title="Pemesanan online belum tersedia" icon="clock" live="off">
                <p>
                    Halaman ini menampilkan katalog produk saja. Keranjang, checkout, dan pembayaran untuk produk marketplace sedang kami siapkan dan belum dapat digunakan. Untuk memesan produk ini sekarang, hubungi customer service kami dan tim kami akan membantu prosesnya.
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
        </section>
    </div>
</div>
