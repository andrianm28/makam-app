{{--
    resources/views/livewire/public/home-page.blade.php

    App\Livewire\Public\HomePage's view — `/`. Nine-section order below is
    NORMATIVE (information-architecture.md §3, design-system.md §4.5 — do
    not reorder, do not drop a section silently):
      1. Header/navigation      -> rendered by layouts/app.blade.php, not here
      2. Hero + CTA "Pesan Makam"
      3. Four service cards, stakeholder order (AC1)
      4. Cara kerja singkat
      5. TPU/TPS unggulan       -> see below, REVERSED 26 Jul 2026
      6. Trust/safety
      7. FAQ highlights
      8. Customer-service CTA
      9. Footer                 -> rendered by layouts/app.blade.php, not here

    --- Section 5 now renders — reversed from its original "deliberately
    absent" state ---
    App\Livewire\Public\HomePage::render()'s own doc block has the full
    reasoning trail. Short version: the ten Cemetery rows here were
    fictional seed fixtures with NULL price/photo/coordinates when this
    section was first built, so showing them as "featured" would have
    misrepresented fabricated content as real — design-system.md §6.2's
    required-states table's "hide the section entirely" row governed that
    state. The user has since explicitly authorized clearly-fictional
    DUMMY price/photo/coordinate data for full public display on
    dev.makam.co.id (see `App\Support\ContactInfo`'s own doc block for the
    identical authorization trail, and `2026_07_26_210000_backfill_dummy_
    map_price_and_photo_for_seeded_cemeteries.php` for the data itself), so
    that "hide entirely" state is no longer the true one — it remains coded
    below for the case where every cemetery is later unpublished, but is
    not reachable against today's seed data. Cards are intentionally NOT
    links: `cemetery-directory-and-availability` (S4-T6) — the spec that
    would build a real detail/directory page to link to — has not been
    built yet, and inventing an unrouted `href` would trade one honesty gap
    for another.

    --- Section 9 (footer) is NOT re-rendered here ---
    design-system.md §4.1's page-shell diagram places the footer as a
    page-shell element (same level as the header), not per-page content —
    `layouts/app.blade.php` already renders ONE footer for every public
    page. Rather than stack a second, homepage-specific footer under this
    view's own content (duplicating "Kebijakan Privasi / Syarat & Ketentuan
    / Bantuan" markup a second time), this batch upgraded that shared
    footer in place to the inverse-surface treatment and privacy/terms/
    contact links IA §3 item 9 and design-system.md's primitives table ask
    for. See layouts/app.blade.php's own doc block for that change and the
    honest-forward-reference note on the two links that do not resolve yet.

    --- Urgent banner placement vs. design-system.md's ASCII diagram ---
    §4.1's page-shell diagram draws the gated-fallback mode banner (§6.9)
    as a full-bleed strip BETWEEN the header and `<main>`. This Livewire
    view only ever renders INSIDE `<main>` (`layouts/app.blade.php`'s
    `{{ $slot }}`), so this file cannot place anything literally above
    `<main>` without changing that shared layout's header/main structure
    for every public page — out of scope here. `<x-mk.alert>`'s own real,
    already-approved recipe (read in full before use) is a contained,
    rounded, padded box, not an edge-to-edge coloured strip either — so a
    literal full-bleed treatment would mean overriding that primitive's
    actual markup, not just its position. Instead the banner below is the
    FIRST thing this view renders (immediately after the header, visually
    "directly below" it) using `<x-mk.alert>` exactly as built. Substance
    (first, prominent, truthful, never dismissible) over a pixel-identical
    match to a schematic diagram.

    --- Hand-written primary CTA button, not <x-mk.button> ---
    See docs/planning/sprint-plan.md finding N-14 and
    resources/views/livewire/public/faq/index.blade.php's own doc comment
    for the full history. N-14's root cause is fixed, but this batch's own
    brief is explicit: every current Livewire full-page view in this repo
    still hand-writes button markup for consistency, and reverting is
    optional future cleanup, not this batch's job. The classes below are
    button.blade.php's own literal `size=lg` + `variant=primary` recipe,
    copied verbatim (base + `$sizes['lg']` + `$variants['primary']`) — no
    new design value introduced.
--}}
@php
    use App\Support\ContactInfo;
@endphp
<div>
    @php
        $urgentFallback = $urgentMode->fallback();
    @endphp

    @if ($urgentFallback)
        <div class="mx-auto max-w-content px-4 pt-4 md:px-6 lg:px-8">
            {{-- requirements.md AC5 — truthful, server-driven, never
                 dismissible (see UrgentMode::fallback()'s own doc block). --}}
            <x-mk.alert
                :intent="$urgentFallback->intent"
                icon="exclamation-triangle"
                title="Ketersediaan Urgent Belum Dapat Dipastikan Otomatis"
                :dismissible="$urgentFallback->dismissible"
                live="polite"
            >
                Jam operasional dan cakupan layanan Urgent (termasuk Pemakaman Hari Ini) berbeda-beda di setiap TPU/TPS,
                dan diperiksa langsung pada saat Anda mengajukan permintaan. Kami belum dapat menjamin penerimaan
                otomatis di luar kapasitas yang tersedia saat ini — hotline di bawah ini dapat dihubungi kapan pun
                untuk menanyakan ketersediaan.
                {{-- The leading `+` is KEPT. Stripping it (as this line did until 8 Aug
                     2026) yields `tel:6281200001234`, which a handset reads as a
                     DOMESTIC number and dials wrongly; `+62…` is an unambiguous
                     international dial string. Found while building PUB-060, which
                     had already made the opposite call — see App\Livewire\Public\
                     Support\HelpCentre::telHref(). --}}
                <a href="tel:+{{ preg_replace('/[^0-9]/', '', ContactInfo::phone()) }}" class="font-medium underline underline-offset-2">{{ ContactInfo::phone() }}</a>
                atau
                <a href="/bantuan" class="font-medium underline underline-offset-2">hubungi Bantuan</a>.
            </x-mk.alert>
        </div>
    @endif

    {{-- Section 2: Hero. --}}
    <section aria-labelledby="hero-heading" class="mx-auto max-w-content px-4 py-5 md:px-6 lg:px-8 lg:py-8">
        <div class="mx-auto max-w-prose space-y-4 text-center">
            <h1 id="hero-heading" class="text-3xl font-semibold tracking-tight text-neutral-900 md:text-4xl">
                Urus Pemakaman dengan Tenang, dalam Satu Platform
            </h1>
            <p class="text-base text-neutral-600 md:text-lg">
                Pesan makam, jelajahi layanan pemakaman, dan urus perpanjangan masa sewa makam secara online. Setiap
                langkah tercatat jelas, dari pemesanan hingga konfirmasi.
            </p>
            <div class="flex justify-center pt-2">
                <a
                    href="/pemesanan-makam"
                    class="inline-flex h-13 select-none items-center justify-center gap-2 rounded-md border border-transparent bg-primary-600 px-6 text-base font-medium text-neutral-0 transition-[color,background-color,border-color,box-shadow] duration-fast ease-standard hover:bg-primary-700 active:bg-primary-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 focus-visible:ring-offset-2"
                >
                    <span>Pesan Makam</span>
                </a>
            </div>
        </div>
    </section>

    {{-- Section 3: four service cards — AC1's exact order, from
         HomePage::PRIMARY_MENUS (see that class's own doc block for why
         this is a second hardcoded copy of header.blade.php's $navItems,
         and why that is not forbidden catalogue-data duplication). --}}
    <section aria-labelledby="services-heading" class="mx-auto max-w-content px-4 py-5 md:px-6 lg:px-8 lg:py-8">
        <h2 id="services-heading" class="sr-only">Layanan utama</h2>
        @php
            $serviceDescriptions = [
                'pemesanan' => 'Pesan makam baru atau makam tumpang, lengkap dengan pilihan lokasi dan jenis layanan.',
                'layanan' => 'Jelajahi paket dan produk layanan pemakaman dari vendor.',
                'perpanjangan' => 'Cari data makam dan ajukan perpanjangan masa sewa secara online.',
                'faq' => 'Temukan jawaban seputar pemesanan, dokumen, pembayaran, dan perpanjangan.',
            ];
        @endphp
        <ul class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:gap-6 lg:grid-cols-4" aria-label="Layanan utama">
            @foreach ($primaryMenus as $key => $menu)
                <li wire:key="service-card-{{ $key }}">
                    <x-mk.card as="a" interactive :href="$menu['route']" class="h-full touch-target">
                        <h3 class="text-lg font-semibold text-neutral-900">{{ $menu['label'] }}</h3>
                        <p class="text-base text-neutral-600">{{ $serviceDescriptions[$key] ?? '' }}</p>
                    </x-mk.card>
                </li>
            @endforeach
        </ul>
    </section>

    {{-- Section 4: Cara kerja singkat — a simplified summary of
         mvp-scope.md §2's real nine booking steps, not an invented flow. --}}
    <section aria-labelledby="how-it-works-heading" class="mx-auto max-w-content px-4 py-5 md:px-6 lg:px-8 lg:py-8">
        <h2 id="how-it-works-heading" class="mb-6 text-center text-2xl font-semibold text-neutral-900">
            Cara Kerja
        </h2>
        <ol class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:gap-6 lg:grid-cols-4">
            @foreach ([
                ['title' => 'Pilih lokasi & jenis layanan', 'body' => 'Pilih kota, TPU/TPS, dan jenis layanan: makam baru, makam tumpang, Urgent, atau Pre-Need.'],
                ['title' => 'Lengkapi data & dokumen', 'body' => 'Isi data pemesan dan almarhum, unggah dokumen yang diperlukan secara privat dan aman.'],
                ['title' => 'Selesaikan pembayaran', 'body' => 'Bayar online bila tersedia, atau ikuti instruksi pembayaran manual.'],
                ['title' => 'Terima konfirmasi', 'body' => 'Dapatkan nomor pesanan, status, dan langkah selanjutnya.'],
            ] as $index => $step)
                <li wire:key="how-it-works-{{ $index }}" class="flex flex-col gap-2 rounded-lg border border-neutral-200 bg-neutral-0 p-4 md:p-6">
                    <span class="text-sm font-semibold text-primary-700" aria-hidden="true">{{ $index + 1 }}</span>
                    <h3 class="text-base font-semibold text-neutral-900">{{ $step['title'] }}</h3>
                    <p class="text-sm text-neutral-600">{{ $step['body'] }}</p>
                </li>
            @endforeach
        </ol>
    </section>

    {{-- Section 5: TPU/TPS unggulan — HomePage::render()'s own doc block
         has the full "why this now renders" reasoning. §6.2 provider-
         unavailable / truly-empty degrade the same way FAQ highlights does
         below: hide the section entirely rather than an empty shell, per
         design-system.md §6.2's own required-states row for this section.
         Cards show name/city/address/price range/photo — deliberately not
         wrapped in <a>, since no cemetery detail/directory route exists
         yet (S4-T6, not built this batch). --}}
    @unless ($featuredCemeteriesUnavailable || $featuredCemeteries->isEmpty())
        <section aria-labelledby="featured-cemeteries-heading" class="mx-auto max-w-content px-4 py-5 md:px-6 lg:px-8 lg:py-8">
            <h2 id="featured-cemeteries-heading" class="mb-6 text-center text-2xl font-semibold text-neutral-900">
                TPU &amp; TPS Unggulan
            </h2>
            <ul class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:gap-6 lg:grid-cols-3" aria-label="TPU dan TPS tersedia">
                @foreach ($featuredCemeteries as $cemetery)
                    <li wire:key="featured-cemetery-{{ $cemetery->id }}">
                        <x-mk.card class="h-full">
                            <x-slot:media>
                                <img
                                    src="{{ asset($cemetery->primary_photo_path) }}"
                                    alt=""
                                    class="h-40 w-full object-cover"
                                    loading="lazy"
                                >
                            </x-slot:media>
                            <h3 class="text-lg font-semibold text-neutral-900">{{ $cemetery->name }}</h3>
                            <p class="text-sm text-neutral-600">{{ $cemetery->address }}</p>
                            @if ($cemetery->price_min !== null && $cemetery->price_max !== null)
                                <p class="mt-2 text-base font-medium text-neutral-800">
                                    Rp {{ number_format((float) $cemetery->price_min, 0, ',', '.') }}
                                    &ndash;
                                    Rp {{ number_format((float) $cemetery->price_max, 0, ',', '.') }}
                                </p>
                            @endif
                        </x-mk.card>
                    </li>
                @endforeach
            </ul>
        </section>
    @endunless

    {{-- Section 6: Trust/safety — surface-warm (--mk-surface-warm ->
         --color-secondary-50 -> bg-secondary-50, already a generated
         Tailwind utility, no arbitrary value needed). Full-bleed tint with
         a contained inner wrapper, the same pattern the footer below uses. --}}
    <section aria-labelledby="trust-heading" class="bg-secondary-50 py-5 lg:py-8">
        <div class="mx-auto max-w-content px-4 md:px-6 lg:px-8">
            <h2 id="trust-heading" class="mb-6 text-center text-2xl font-semibold text-neutral-900">
                Kenapa Makam.co.id
            </h2>
            <ul class="grid grid-cols-1 gap-4 sm:grid-cols-3 md:gap-6">
                @foreach ([
                    ['title' => 'Dokumen privat & aman', 'body' => 'Dokumen seperti KTP, KK, dan surat keterangan kematian disimpan secara privat dan diperiksa sebelum dapat diakses siapa pun, termasuk tim kami.'],
                    ['title' => 'Pembayaran diverifikasi', 'body' => 'Status pesanan hanya berubah menjadi lunas setelah pembayaran benar-benar terverifikasi oleh tim kami — bukan otomatis saat Anda kembali dari halaman pembayaran.'],
                    ['title' => 'Jujur soal keterbatasan', 'body' => 'Kami tidak mengarang data, tarif, atau ketersediaan yang belum dapat kami pastikan. Kami akan menyatakannya dengan jelas dan mengarahkan Anda ke customer service.'],
                ] as $index => $point)
                    <li wire:key="trust-point-{{ $index }}" class="space-y-1 text-center sm:text-left">
                        <h3 class="text-base font-semibold text-neutral-900">{{ $point['title'] }}</h3>
                        <p class="text-sm text-neutral-700">{{ $point['body'] }}</p>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    {{-- Section 7: FAQ highlights — §6.5 provider-unavailable degrades
         gracefully (HomePage::render()'s own try/catch); §6.2 empty state
         for the (rare, hard to trigger against real seed data) case where
         the query succeeds but returns nothing. --}}
    <section aria-labelledby="faq-highlights-heading" class="mx-auto max-w-content px-4 py-5 md:px-6 lg:px-8 lg:py-8">
        <h2 id="faq-highlights-heading" class="mb-6 text-center text-2xl font-semibold text-neutral-900">
            Pertanyaan yang Sering Diajukan
        </h2>

        @if ($faqHighlightsUnavailable)
            <x-mk.alert intent="pending" title="Pertanyaan populer sedang tidak tersedia" live="polite">
                Anda tetap dapat menjelajahi seluruh FAQ kami.
                <a href="{{ route('faq.index') }}" class="underline underline-offset-2">Lihat semua FAQ</a>.
            </x-mk.alert>
        @elseif ($faqHighlights->isEmpty())
            <div class="flex flex-col items-center gap-3 py-8 text-center">
                <p class="text-base text-neutral-600">Belum ada pertanyaan unggulan saat ini.</p>
                <a href="{{ route('faq.index') }}" class="text-base font-medium text-primary-700 underline underline-offset-2">
                    Lihat semua FAQ
                </a>
            </div>
        @else
            <ul class="grid grid-cols-1 gap-4 md:grid-cols-2" aria-label="Pertanyaan unggulan">
                @foreach ($faqHighlights as $article)
                    <li wire:key="faq-highlight-{{ $article->id }}">
                        <x-mk.card as="a" interactive :href="route('faq.show', ['articleSlug' => $article->slug])" class="h-full touch-target">
                            <h3 class="text-base font-semibold text-neutral-900">{{ $article->title }}</h3>
                            <p class="text-sm text-neutral-600">{{ $article->summary }}</p>
                        </x-mk.card>
                    </li>
                @endforeach
            </ul>
            <div class="mt-6 text-center">
                <a href="{{ route('faq.index') }}" class="text-base font-medium text-primary-700 underline underline-offset-2">
                    Lihat semua FAQ
                </a>
            </div>
        @endif
    </section>

    {{-- Section 8: Customer-service CTA — requirements.md AC5. Distinct
         from the Urgent banner above: this is a general "need help
         choosing" invitation, not a gate-state notice. --}}
    <section aria-labelledby="cs-cta-heading" class="mx-auto max-w-content px-4 py-5 md:px-6 lg:px-8 lg:py-8">
        <div class="mx-auto flex max-w-prose flex-col items-center gap-3 rounded-lg border border-neutral-200 bg-neutral-0 p-6 text-center md:p-8">
            <h2 id="cs-cta-heading" class="text-xl font-semibold text-neutral-900">
                Butuh Bantuan Memilih Layanan?
            </h2>
            <p class="text-base text-neutral-600">
                Tim customer service kami siap membantu Anda menentukan langkah terbaik, kapan pun Anda membutuhkannya.
            </p>
            <p class="text-sm text-neutral-600">
                {{ ContactInfo::phone() }} (telepon/WhatsApp) · {{ ContactInfo::email() }}<br>
                {{ ContactInfo::businessHours() }}
            </p>
            <a
                href="/bantuan"
                class="mt-2 inline-flex h-11 select-none items-center justify-center gap-2 rounded-md border border-primary-600 bg-neutral-0 px-4 text-base font-medium text-primary-700 transition-[color,background-color,border-color,box-shadow] duration-fast ease-standard hover:bg-primary-50 active:bg-primary-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 focus-visible:ring-offset-2"
            >
                Hubungi Bantuan
            </a>
        </div>
    </section>
</div>
