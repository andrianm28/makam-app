{{--
    resources/views/livewire/public/marketplace/index.blade.php

    App\Livewire\Public\Marketplace\MarketplaceIndex's view — `/marketplace`,
    PUB-020. Read that class's doc block first: it carries the reasoning for
    the "Layanan Pemakaman" heading, for `?kategori=<KEY>` being a query
    parameter on an existing route rather than the still-BLOCKED
    `/marketplace/kategori/{categorySlug}`, and for why an unknown key
    explains itself instead of 404ing.

    --- <x-mk.button>, not hand-written markup ---
    Earlier Livewire views in this repo (`faq/index.blade.php`,
    `coming-soon.blade.php`, `home-page.blade.php`) hand-write button markup
    because of finding N-14 (`Undefined variable $loading`). That bug is
    fixed and the fix was verified on 8 Aug 2026 against real Laravel
    13.22.0 + Livewire 4.3.3 — all three variants, including the `loading`
    state — so design-system.md §9.2 MUST #2 ("Use the `<x-mk.*>`
    primitives; extend them rather than forking them") applies again and
    this page uses the primitive. The older hand-written call sites are left
    alone; migrating them is not this batch's scope.

    --- NEVER NAME A BLADE DIRECTIVE LITERALLY IN THESE COMMENTS ---
    N-14's disclosed root cause (Batch 0 / P-3, 8 Aug 2026) is that
    `BladeCompiler::compileString()` calls `storeUncompiledBlocks()` before
    `compileComments()`. A leading doc comment whose prose contains the
    literal directive-opening token for a raw PHP block therefore pairs with
    the file's REAL closing token, and everything between the two is stored
    as an uncompiled block and vanishes from the output. This file has
    exactly that structural shape — a leading comment followed by a real raw
    PHP block — so it is in the danger zone by construction.

    This is not theoretical here. An earlier revision of THIS comment spelled
    that token out while warning against it, and silently destroyed the
    file's entire header: the root wrapper div, the h1, the intro paragraph
    and the `use` import were all absent from the compiled output. It still
    passed `php -l` and still reported COMPILE-OK, because what survives is
    valid PHP — just missing a third of the template.

    So: describe directives in prose (say "a raw PHP block", not the token),
    and never paste one into a comment. And note the verification lesson —
    a clean compile does NOT detect this. Only asserting that known source
    content survives into the compiled output does.

    --- Product labels are never restated here ---
    tasks.md §"Seeds and enums must derive from the catalogue": *"Do not
    restate the product list in a Livewire component, a Blade view, a
    Filament Resource, a validation rule, or a test fixture."* Every product
    name, description, vendor, price, and photo below comes from the seeded
    `products` row. The only closed list this file iterates is
    `MarketplaceProductCategory::KNOWN_KEYS`, passed in by the component,
    with its Indonesian text coming from that class's own `label()` — the
    single place the catalogue's three heading strings are written down.

    --- Prices ---
    `Rp {{ number_format(..., 0, ',', '.') }}` matches `home-page.blade.php`'s
    existing IDR presentation. Every seeded `base_price_idr` is CLEARLY-
    FICTIONAL placeholder data — see
    `2026_07_26_200100_add_dummy_vendor_pricing_and_photo_to_products.php`'s
    own doc block. Since the W-1 fix (09 Aug 2026) this view annotates every
    rendered price with the "Estimasi internal (data contoh)" source line and
    every rendered vendor name with the "(vendor contoh)" marker, via
    `App\Livewire\Public\Marketplace\Support\MarketplacePresenter` — the two
    figures a visitor could otherwise mistake for real ones now carry the
    repository's fabricated-data marker. The honest handling of a NULL price
    (a real possibility, since that column is nullable and the original seed
    left all nine NULL) is the "Harga belum tersedia" line below rather than a
    fabricated figure.
--}}
@php
    use App\Domain\Marketplace\MarketplaceProductCategory;
    use App\Livewire\Public\Marketplace\Support\MarketplacePresenter;
@endphp

<div class="py-8 md:py-12">
    <div class="mx-auto max-w-content px-4 md:px-6 lg:px-8">
        <div class="mx-auto mb-8 max-w-prose space-y-2 text-center">
            <h1 class="text-3xl font-semibold tracking-tight text-neutral-900">
                @if ($activeCategory)
                    {{ MarketplaceProductCategory::label($activeCategory) }}
                @else
                    Layanan Pemakaman
                @endif
            </h1>
            <p class="text-base text-neutral-600">
                Karangan bunga, batu nisan, dan perawatan makam dari vendor rekanan kami.
            </p>
        </div>

        {{-- Category filter chips, via <x-mk.filter-chip> (design-system.md
             §3.6a).

             MIGRATED 8 Aug 2026, same day as shipping. This page originally
             hand-wrote the active chip, copying `faq/index.blade.php`'s
             literal recipe because `<x-mk.badge>`'s six intents are the
             closed status vocabulary and have no `primary`, while
             `$attributes->merge()` only appends — so passing the chip colours
             through the badge left two competing colour declarations on one
             element. S4-T6 hit the identical wall independently; the two
             batches raised it jointly rather than shipping two workarounds,
             and the primitive is the resolution. Three hand-written call
             sites (FAQ, directory, here) collapse into it.

             That makes the hand-written version a FORK of a primitive, which
             §9.2 MUST #2 forbids — so it is gone. The primitive owns the
             wrapper anchor, `aria-current="page"`, both colour recipes, and
             the active state's check icon, which is the non-colour selection
             cue (WCAG 1.4.1): without it, active and inactive differ by hue
             alone, since every other property — layout, radius, border,
             padding, text size — is identical.

             `MarketplaceIndexRouteTest` still asserts that icon's real SVG
             path data and that exactly one chip in this nav carries
             aria-current, so a regression inside the primitive fails a test
             here, not just in the FAQ's. Both assertions are deliberately
             behavioural rather than class-name-based, so they survived this
             migration unchanged — which is the useful proof that it was a
             refactor and not a rewrite. --}}
        <nav aria-label="Filter kategori produk" class="mb-8 flex flex-wrap justify-center gap-2">
            <x-mk.filter-chip :href="route('marketplace.index')" :active="! $activeCategory">
                Semua Kategori
            </x-mk.filter-chip>

            @foreach ($categoryKeys as $categoryKey)
                <x-mk.filter-chip
                    :href="route('marketplace.index', ['kategori' => $categoryKey])"
                    :active="$activeCategory === $categoryKey"
                >
                    {{ MarketplaceProductCategory::label($categoryKey) }}
                </x-mk.filter-chip>
            @endforeach
        </nav>

        {{-- §6.3 validation error — the only genuinely reachable one on a
             page with no form: a `?kategori=` value that is not one of the
             three known keys. The requested value is deliberately NOT echoed
             back; the chips above already show every category that exists,
             and echoing an arbitrary query string adds nothing a visitor can
             act on. --}}
        @if ($unknownCategoryRequested)
            <div class="mb-6">
                <x-mk.alert intent="danger" title="Kategori tidak dikenali" icon="alert-circle" live="assertive">
                    Alamat yang Anda buka menyebut kategori yang tidak ada dalam katalog kami, jadi kami menampilkan seluruh produk di bawah ini. Pilih salah satu kategori di atas untuk mempersempit daftar.
                </x-mk.alert>
            </div>
        @endif

        {{-- §6.5 provider unavailable — the catalogue read itself threw.
             Never a dead end: the header, the category chips, and the
             customer-service path below all still work. --}}
        @if ($catalogueUnavailable)
            <div class="mb-6">
                <x-mk.alert intent="pending" title="Katalog sedang tidak dapat dimuat" icon="clock" live="polite">
                    Daftar produk tidak dapat kami tampilkan saat ini. Silakan muat ulang halaman ini beberapa saat lagi, atau
                    <a href="/bantuan" class="underline underline-offset-2">hubungi customer service</a>
                    bila Anda membutuhkan bantuan sekarang.
                </x-mk.alert>
            </div>
        @endif

        {{-- No `wire:loading` skeleton here, deliberately — see
             MarketplaceIndex's own doc block. Every category chip above is a
             plain link, so this page makes no Livewire round trip and a
             skeleton would be markup that can never render. --}}
        @unless ($catalogueUnavailable)
            <p class="sr-only" role="status" aria-live="polite">
                {{ $products->count() }} produk ditampilkan.
            </p>

            @if ($products->isEmpty())
                {{-- §6.2 empty — what is empty, why, and what to do
                     next. Reachable in practice by deactivating every
                     product in one category (`products.is_active`), which
                     is exactly what this state's test does. --}}
                <div class="flex flex-col items-center gap-3 py-12 text-center">
                    <h2 class="text-lg font-semibold text-neutral-800">
                        @if ($activeCategory)
                            Belum ada produk di kategori ini.
                        @else
                            Belum ada produk yang tersedia.
                        @endif
                    </h2>
                    <p class="max-w-prose text-base text-neutral-600">
                        @if ($activeCategory)
                            Produk untuk kategori ini sedang tidak tersedia dari vendor rekanan kami. Anda dapat melihat kategori lain, atau menghubungi customer service untuk menanyakan ketersediaannya.
                        @else
                            Belum ada produk yang dapat kami tampilkan dari vendor rekanan kami saat ini. Silakan hubungi customer service untuk menanyakan ketersediaannya.
                        @endif
                    </p>
                    <div class="mt-2 flex flex-wrap justify-center gap-3">
                        {{-- Only offered when a category is actually
                             narrowing the list — on the unfiltered page it
                             would link to the page the visitor is already
                             on, which is a dead end dressed as an action. --}}
                        @if ($activeCategory)
                            <x-mk.button variant="secondary" :href="route('marketplace.index')">
                                Lihat kategori lain
                            </x-mk.button>
                        @endif
                        <x-mk.button variant="primary" href="/bantuan">
                            Hubungi Customer Service
                        </x-mk.button>
                    </div>
                </div>
            @else
                <ul class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:gap-6 lg:grid-cols-3 xl:grid-cols-4" aria-label="Daftar produk">
                    @foreach ($products as $product)
                        @php
                            $price = MarketplacePresenter::priceAttribution($product);
                        @endphp
                        <li wire:key="product-{{ $product->code }}">
                            {{-- One focusable anchor per card: the card
                                 root itself (card.blade.php §3.3 — never
                                 nest another <a> or <button> inside an
                                 interactive card). --}}
                            <x-mk.card
                                as="a"
                                interactive
                                :href="route('marketplace.product', ['productCode' => $product->code])"
                                class="h-full touch-target"
                            >
                                @if ($product->photo_path)
                                    <x-slot:media>
                                        {{-- Decorative: the product name
                                             is the adjacent heading, so
                                             an alt repeating it would be
                                             announced twice. --}}
                                        <img
                                            src="{{ asset($product->photo_path) }}"
                                            alt=""
                                            class="h-40 w-full object-cover"
                                            loading="lazy"
                                        >
                                    </x-slot:media>
                                @endif

                                <div>
                                    <x-mk.badge intent="neutral">{{ $product->categoryLabel() }}</x-mk.badge>
                                </div>

                                <h2 class="text-lg font-semibold text-neutral-900">{{ $product->name }}</h2>

                                @if ($product->vendor_name)
                                    <p class="text-sm text-neutral-600">{{ MarketplacePresenter::vendorLabel($product) }}</p>
                                @endif

                                <p class="text-base font-medium text-neutral-800">
                                    @if ($price !== null)
                                        Mulai {{ $price['amount'] }}
                                    @else
                                        Harga belum tersedia
                                    @endif
                                </p>

                                @if ($price !== null)
                                    <p class="text-xs text-neutral-500">{{ $price['source'] }}</p>
                                @endif
                            </x-mk.card>
                        </li>
                    @endforeach
                </ul>
            @endif
        @endunless

        {{-- §6.10 support escape hatch — required on every screen, and
             the destination the empty and provider-unavailable states
             above both point at. --}}
        <div class="mt-10 flex flex-col items-center gap-3 border-t border-neutral-200 pt-8 text-center">
            <p class="max-w-prose text-sm text-neutral-600">
                Tidak menemukan produk yang Anda cari, atau butuh bantuan memilih? Tim kami dapat membantu.
            </p>
            <x-mk.button variant="secondary" href="/bantuan">
                Hubungi Customer Service
            </x-mk.button>
        </div>
    </div>
</div>
