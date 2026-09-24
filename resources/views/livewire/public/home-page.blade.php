{{--
    resources/views/livewire/public/home-page.blade.php

    App\Livewire\Public\HomePage's view — `/`. IA §3's nine-section list
    below is still the NORMATIVE floor (do not drop any of its content
    silently), but Stage 3 (design doc §4.1) restructured the body between
    Section 3 and Section 7 into FFI's card-grid treatment. Real,
    as-shipped order after Stage 3 tickets 03+04:
      1. Header/navigation      -> rendered by layouts/app.blade.php, not here
      2. Hero + CTA "Pesan Makam"
      3. Four service cards, stakeholder order (AC1), + 3 secondary CTAs (ticket 03)
      -. TPU/TPS dengan ketersediaan terbatas (ticket 03 — replaces old "Cara kerja singkat" position)
      -. TPU/TPS terbaru (ticket 03)
      -. TPU/TPS terverifikasi (ticket 04 — carries IA §3 item 6's trust/safety
         substance forward, plus the PRD's "lokasi terverifikasi"/"harga
         transparan" trust badges; replaces old "TPU/TPS unggulan" + "Trust/
         safety" positions)
      7. FAQ highlights (now also carries IA §3 item 4's "Cara kerja singkat"
         substance forward, condensed, per ticket 04)
      8. Customer-service CTA
      9. Footer                 -> rendered by layouts/app.blade.php, not here
    IA §3's original items 4 ("Cara kerja singkat") and 6 ("Trust/safety")
    are no longer their own sections — their substance is redistributed
    into FAQ highlights and the new verified section respectively (ticket
    04's own documented decision), not deleted. IA §3 item 5 ("TPU/TPS
    unggulan") is likewise redistributed across the three new TPU/TPS
    sections above.

    --- ADDED 5 Sep 2026: plot-availability preview, between Section 2 and
    Section 3 (not renumbered above) ---
    This is deliberately NOT a tenth entry in the NORMATIVE nine-section
    list above, for the same reason the "Kehangatan Keluarga" entry below
    is not: it is not part of information-architecture.md §3's contract, so
    nothing there needed to change. It renders `<livewire:public.home.plot-
    availability-preview />` directly under the hero, ahead of the four
    service cards, per docs/superpowers/specs/2026-09-05-marketing-hero-
    plot-preview-design.md. The component is read-only (see its own doc
    block — no wire:click/wire:model target, ever) and renders nothing when
    no configured showcase cemetery has real per-plot data yet or the read
    fails, so it never leaves a broken or empty-looking box on the
    highest-traffic page. See this section's own inline comment below for
    the placement reasoning.

    --- ADDED 26 Aug 2026: "Kehangatan Keluarga" supporting photo section
    (not renumbered above) ---
    This is deliberately NOT a tenth entry in the NORMATIVE nine-section
    list above — it is not part of information-architecture.md §3's
    contract, so nothing there needed to change. It is new, project-owner-
    approved homepage content: a second, different real photo (warm, joyful
    family) reinforcing the "lighter, younger, warmer" brand direction
    alongside the existing location-focused hero (Section 2, which stays
    untouched). Placed directly after the verified-cemeteries section
    (Stage 3 ticket 04, itself carrying forward the old Section 6
    "Trust/safety" this note originally referenced) because it
    continues the same reassurance beat with a human visual, right before
    the page moves into FAQ mode — see that section's own comment below for
    the full reasoning and image sourcing.

    --- Real Cemetery data renders on this page, using DUMMY price/photo/
    coordinate values — same authorization trail for all three real-data
    sections below (urgent-availability, newest-published, verified) ---
    The user has explicitly authorized clearly-fictional DUMMY price/
    photo/coordinate data for full public display on dev.makam.co.id (see
    `App\Support\ContactInfo`'s own doc block for the identical
    authorization trail, and `2026_07_26_210000_backfill_dummy_map_
    price_and_photo_for_seeded_cemeteries.php` for the data itself) — real
    Cemetery rows, dummy field values, not a fabrication risk. Each
    section's own empty/failure handling still governs the case where
    nothing qualifies (design-system.md §6.2's "hide the section
    entirely" row) or the query fails (§6.3 provider-unavailable).

    Cards in these sections are real links (`cemeteries.show`,
    resources/views/livewire/public/directory/index.blade.php renders the
    identical `<x-mk.card as="a" interactive>` pattern reused here) and
    price renders through `CemeteryPresenter::priceRange()`/
    `priceAttribution()` (same presenter the directory page uses), never a
    bare figure with no source — design-system.md §2.3's DO ("show the
    source and last-updated time on any fee figure"). The availability
    badge the directory page shows is deliberately still omitted here —
    it needs a per-row capability-profile lookup, not worth the
    homepage's query budget for six cards per section.

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
    (first, prominent, truthful) over a pixel-identical match to a
    schematic diagram.

    UPDATED 14 Sep 2026 (ADR-0040 D4, the plan's U7): this parenthetical
    used to end "…truthful, never dismissible". The banner is now
    dismissible, because §6.9's literal rule is "Dismissible ONLY for
    informational modes — never for one that changes how a user must pay",
    and a closed `G-OPS-01` changes what the platform can honestly claim
    about Urgent acceptance, not how anyone pays. Dismissibility is still
    NOT decided here: it comes from `UrgentMode::fallback()` on the server
    (see that enum's doc block for the full reversal record), and this view
    only renders `$urgentFallback->dismissible` — §6.9's "the UI must read
    the server value" is unchanged. The hotline number inside the banner
    also moved from an inline link to a `secondary` <x-mk.button>; every
    word of the copy is byte-identical, only weight and dismissibility
    changed, so no service promise was added (plan N10, `G-OPS-01` still
    closed). `secondary`, not `primary`: §2.3 allows exactly one primary
    action per view and that is `Pesan Makam`.

    --- UPDATED 19 Aug 2026 — hand-written buttons converted to <x-mk.button> ---
    Previously hand-written (see docs/planning/sprint-plan.md finding N-14
    and resources/views/livewire/public/faq/index.blade.php's own doc
    comment for the full history — N-14's root cause was fixed, but every
    Livewire full-page view kept hand-writing button markup anyway, with
    reverting flagged explicitly as "optional future cleanup, not this
    batch's job"). The homepage visual refresh is that cleanup, for this
    file only: the hero's primary CTA and the CS CTA button both became
    real `<x-mk.button>` instances. Verified byte-for-byte before
    converting: `<x-mk.button variant="primary" size="lg" href="...">`
    renders `href="{{ $href }}"` and the slot text verbatim, so
    `HomePageRouteTest::test_pemesanan_makam_is_the_primary_call_to_action`'s
    literal `assertSee('Pesan Makam')` / `assertSee('href="/pemesanan-makam"')`
    still pass — copy is unchanged, only the markup generating it changed.
--}}
@php
    use App\Livewire\Public\Directory\Support\CemeteryPresenter;
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

                {{-- U7 (ADR-0040 D4): the number moves into <x-mk.alert>'s own
                     `action` slot and gains button weight. The words are
                     UNCHANGED and in the same order — number, "atau", "hubungi
                     Bantuan", full stop — because N10 forbids adding any service
                     promise while `G-OPS-01` is closed; only the weight changed.
                     `variant="secondary"` (white fill, primary-700 label,
                     primary-600 border, 44px) and NOT `primary`: §2.3 allows one
                     primary action per view and that is the hero's "Pesan Makam".

                     The leading `+` is KEPT. Stripping it (as this line did until 8 Aug
                     2026) yields `tel:6281200001234`, which a handset reads as a
                     DOMESTIC number and dials wrongly; `+62…` is an unambiguous
                     international dial string. Found while building PUB-060, which
                     had already made the opposite call — see App\Livewire\Public\
                     Support\HelpCentre::telHref(). --}}
                <x-slot:action>
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                        <x-mk.button variant="secondary" :href="'tel:+' . preg_replace('/[^0-9]/', '', ContactInfo::phone())">{{ ContactInfo::phone() }}</x-mk.button>
                        <span>atau <a href="/bantuan" class="font-medium underline underline-offset-2">hubungi Bantuan</a>.</span>
                    </div>
                </x-slot:action>
            </x-mk.alert>
        </div>
    @endif

    {{-- Section 2: Hero — <x-mk.hero> (brand visual refresh Phase 2,
         docs/superpowers/specs/2026-08-21-brand-visual-refresh-design.md §4.2).
         Single primary CTA per §2.3; the prior secondary "Lihat TPU & TPS"
         button moved into Section 3's card grid area as a plain text link
         below the cards (see below) rather than competing with the hero's
         one sanctioned primary action. The eyebrow label and secondary-accent
         rule the old hand-written markup had are dropped, not relocated —
         <x-mk.hero> has no slot for them and design-system.md's hero
         typography row does not call for an eyebrow.

         `image` points to public/images/hero/cemetery-garden-daylight.jpg,
         a real Indonesian cemetery photo the project owner picked from a
         sourced/verified candidate set (aerial view, Tangerang, Banten;
         Pexels, photographer Tom Fisk, Pexels License, no attribution
         required — see this change's commit message for the source URL)
         — see docs/design/design-system.md §2.2 (real cemetery/garden,
         daylight, no people in grief) and the misattribution precedent in
         database/migrations/2026_08_24_100000_backfill_photo_and_maps_url_for_real_cemeteries.php's
         doc block (why the photo must not depict one specific, identifiable
         real cemetery). --}}
    <x-mk.hero
        image="{{ asset('images/hero/cemetery-garden-daylight.jpg') }}"
        heading="Urus Pemakaman dengan Tenang, dalam Satu Platform"
        :cta="['label' => 'Pesan Makam', 'href' => '/pemesanan-makam']"
    >
        <p class="text-base text-neutral-600 md:text-lg">
            Pesan makam, jelajahi layanan pemakaman, dan urus perpanjangan masa sewa makam secara online. Setiap
            langkah tercatat jelas, dari pemesanan hingga konfirmasi.
        </p>
    </x-mk.hero>

    {{-- Plot-availability preview — NEW section, not a tenth entry in IA §3's normative nine-section
         list (same non-renumbering precedent as "Kehangatan Keluarga" below, added 26 Aug 2026 —
         see that section's own comment and this file's top doc block). Sits directly under the
         hero, ahead of the four service cards, per
         docs/superpowers/specs/2026-09-05-marketing-hero-plot-preview-design.md. Renders nothing
         when no showcase cemetery has real per-plot data yet or the read fails (component's own
         try/catch) — never a broken or empty-looking box on the highest-traffic page. --}}
    <livewire:public.home.plot-availability-preview />

    {{-- Section 3: four service cards — AC1's exact order, from
         HomePage::PRIMARY_MENUS (see that class's own doc block for why
         this is a second hardcoded copy of header.blade.php's $navItems,
         and why that is not forbidden catalogue-data duplication). --}}
    <section aria-labelledby="services-heading" class="mx-auto max-w-content px-4 py-section md:px-6 lg:px-8 lg:py-section-lg">
        <h2 id="services-heading" class="sr-only">Layanan utama</h2>
        @php
            $serviceDescriptions = [
                'pemesanan' => 'Pesan makam baru atau makam tumpang, lengkap dengan pilihan lokasi dan jenis layanan.',
                'layanan' => 'Jelajahi paket dan produk layanan pemakaman dari vendor.',
                'perpanjangan' => 'Cari data makam dan ajukan perpanjangan masa sewa secara online.',
                'faq' => 'Temukan jawaban seputar pemesanan, dokumen, pembayaran, dan perpanjangan.',
            ];
            // Decorative only (icon-medallion.blade.php is always
            // aria-hidden) — the card's own <h3> text is what actually
            // labels each destination, so an unmapped $key just renders no
            // icon rather than failing.
            $serviceIcons = [
                'pemesanan' => 'document-text',
                'layanan' => 'truck',
                'perpanjangan' => 'clock',
                'faq' => 'question-mark-circle',
            ];
        @endphp
        <ul class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:gap-6 lg:grid-cols-4" aria-label="Layanan utama">
            @foreach ($primaryMenus as $key => $menu)
                @php
                    // A9/U8 — two-tone heading (kamboja plan §3.1 A9, §3.3 U8,
                    // Tahap 4). The label is SPLIT for rendering, never
                    // rewritten: same words, same order, so the four product
                    // labels §9.2 MUST-NOT 9 protects are byte-identical when
                    // read. `explode(..., 2)` keeps everything after the first
                    // space together, so "Pemesanan Makam" reads
                    // brand("Pemesanan") + near-black("Makam"), and a
                    // three-word label would keep its last two words together
                    // rather than fragmenting.
                    //
                    // Single-word labels ("FAQ") get NO brand line and render
                    // exactly as they did before this change. A two-tone device
                    // needs two parts; colouring a lone word brand would hand
                    // the LAST card in AC1's stakeholder order the loudest
                    // heading on the row, inverting the emphasis the order
                    // exists to express. The fallback is also today's markup,
                    // so the degenerate case degrades to the shipped state.
                    [$headingLead, $headingRest] = array_pad(explode(' ', $menu['label'], 2), 2, null);
                @endphp
                <li wire:key="service-card-{{ $key }}">
                    <x-mk.card as="a" interactive emphasis="strong" :href="$menu['route']" class="h-full touch-target">
                        <div class="space-y-3">
                            @if (isset($serviceIcons[$key]))
                                {{-- `size="xl"` (64px), Tahap 4 butir 2 — SIZE only;
                                     the rendered colour is unchanged from Tahap 2,
                                     but the prop value is renamed `earth` -> `primary`. --}}
                                <x-mk.icon-medallion :icon="$serviceIcons[$key]" tone="primary" size="xl" />
                            @endif
                            {{-- `text-primary-600` on the card's `bg-neutral-0`
                                 is `primary heading on surface-raised`, an
                                 EXISTING asserted pair in
                                 docs/design/verify-contrast.py — no new pair,
                                 no new token. --}}
                            <h3 class="text-lg font-semibold text-neutral-900">
                                @if ($headingRest !== null)
                                    <span class="block text-primary-600">{{ $headingLead }}</span>
                                    <span class="block">{{ $headingRest }}</span>
                                @else
                                    {{ $menu['label'] }}
                                @endif
                            </h3>
                            <p class="text-base text-neutral-600">{{ $serviceDescriptions[$key] ?? '' }}</p>
                        </div>
                    </x-mk.card>
                </li>
            @endforeach
        </ul>
        <p class="mt-4 text-center text-sm text-neutral-600">
            <a href="{{ route('cemeteries.index') }}" class="font-medium text-primary-700 underline underline-offset-2">
                Lihat semua TPU &amp; TPS
            </a>
        </p>
        {{-- Stage 3 ticket 03 — the three PRD-required secondary CTAs
             (design doc §4.2), same placement/visual-weight pattern as the
             "Lihat semua TPU & TPS" link directly above (plain text link,
             never inside <x-mk.hero>, which structurally supports no more
             than its one primary CTA).

             Wakaf Tanah has no real route anywhere in this codebase —
             confirmed by a repo-wide search before writing this, not
             assumed. Rather than invent a URL (forbidden by this ticket's
             own text) or silently drop the requirement, this follows
             header.blade.php's own established "honest disabled control"
             precedent for a destination that doesn't exist yet (see that
             file's $akunAvailable handling) — real, undecided gap, named
             here and in this ticket's PR, not fabricated. --}}
        <p class="mt-2 flex flex-wrap items-center justify-center gap-x-4 gap-y-1 text-center text-sm">
            <a href="{{ route('perpanjangan.index') }}" class="font-medium text-primary-700 underline underline-offset-2">
                Perpanjang Makam
            </a>
            <a href="{{ route('marketplace.index') }}" class="font-medium text-primary-700 underline underline-offset-2">
                Layanan Pemakaman
            </a>
            <span class="text-neutral-400" aria-disabled="true" title="Segera hadir">
                Wakaf Tanah
            </span>
        </p>
    </section>

    {{-- Stage 3 ticket 03 — "TPU & TPS dengan ketersediaan terbatas"
         (urgent-availability). Hidden entirely when nothing qualifies or
         the query fails — same §6.2 empty-state / §6.3 provider-
         unavailable discipline as every other real-data section on this
         page. Card partial mirrors the verified-cemeteries section
         below verbatim (same presenter calls, same badge/price
         block) — that section is this codebase's own already-FFI-restyled
         (Stage 1 palette + Stage 2 component library) card treatment, so
         reusing it here is fidelity, not a shortcut. Ticket 04 relocates
         "lokasi terverifikasi"/"harga transparan" into a later section;
         this one does not carry those badges. --}}
    @unless ($urgentAvailabilityCemeteriesUnavailable || $urgentAvailabilityCemeteries->isEmpty())
        <section aria-labelledby="urgent-availability-heading" class="mx-auto max-w-content px-4 py-section md:px-6 lg:px-8 lg:py-section-lg">
            <h2 id="urgent-availability-heading" class="mb-6 text-center text-2xl font-semibold text-neutral-900">
                TPU &amp; TPS dengan Ketersediaan Terbatas
            </h2>
            <ul class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:gap-6 lg:grid-cols-3" aria-label="TPU dan TPS dengan ketersediaan terbatas">
                @foreach ($urgentAvailabilityCemeteries as $cemetery)
                    @php
                        $priceRange = CemeteryPresenter::priceRange($cemetery);
                        $priceAttribution = CemeteryPresenter::priceAttribution($cemetery);
                        $photoUrl = CemeteryPresenter::photoUrl($cemetery);
                    @endphp
                    <li wire:key="urgent-cemetery-{{ $cemetery->id }}">
                        <x-mk.card
                            as="a"
                            interactive
                            :href="route('cemeteries.show', ['cemeterySlug' => $cemetery->slug])"
                            class="h-full touch-target"
                        >
                            <x-slot:media>
                                @if ($photoUrl)
                                    <img
                                        src="{{ $photoUrl }}"
                                        alt="Foto {{ $cemetery->name }}"
                                        loading="lazy"
                                        class="h-40 w-full object-cover"
                                    >
                                @else
                                    <div class="flex h-40 w-full items-center justify-center bg-neutral-100">
                                        <span class="text-sm text-neutral-600">Foto belum tersedia</span>
                                    </div>
                                @endif
                            </x-slot:media>
                            <div class="space-y-2">
                                <x-mk.badge intent="pending">Ketersediaan Terbatas</x-mk.badge>
                                <h3 class="text-lg font-semibold text-neutral-900">{{ $cemetery->name }}</h3>
                                <p class="text-sm text-neutral-600">{{ $cemetery->address }}</p>
                                @if ($priceRange !== null && $priceAttribution !== null)
                                    <p class="pt-1 text-base font-medium text-neutral-900">{{ $priceRange }}</p>
                                    <p class="text-sm text-[var(--mk-text-muted)]">
                                        Sumber: {{ $priceAttribution['source'] }}@if ($priceAttribution['effective']) &middot; per {{ $priceAttribution['effective'] }}@endif
                                    </p>
                                @endif
                            </div>
                        </x-mk.card>
                    </li>
                @endforeach
            </ul>
        </section>
    @endunless

    {{-- Stage 3 ticket 03 — "TPU & TPS terbaru" (newest published). Same
         empty/failure discipline and card partial as the section above. --}}
    @unless ($newestPublishedCemeteriesUnavailable || $newestPublishedCemeteries->isEmpty())
        <section aria-labelledby="newest-published-heading" class="mx-auto max-w-content px-4 py-section md:px-6 lg:px-8 lg:py-section-lg">
            <h2 id="newest-published-heading" class="mb-6 text-center text-2xl font-semibold text-neutral-900">
                TPU &amp; TPS Terbaru
            </h2>
            <ul class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:gap-6 lg:grid-cols-3" aria-label="TPU dan TPS terbaru">
                @foreach ($newestPublishedCemeteries as $cemetery)
                    @php
                        $priceRange = CemeteryPresenter::priceRange($cemetery);
                        $priceAttribution = CemeteryPresenter::priceAttribution($cemetery);
                        $photoUrl = CemeteryPresenter::photoUrl($cemetery);
                    @endphp
                    <li wire:key="newest-cemetery-{{ $cemetery->id }}">
                        <x-mk.card
                            as="a"
                            interactive
                            :href="route('cemeteries.show', ['cemeterySlug' => $cemetery->slug])"
                            class="h-full touch-target"
                        >
                            <x-slot:media>
                                @if ($photoUrl)
                                    <img
                                        src="{{ $photoUrl }}"
                                        alt="Foto {{ $cemetery->name }}"
                                        loading="lazy"
                                        class="h-40 w-full object-cover"
                                    >
                                @else
                                    <div class="flex h-40 w-full items-center justify-center bg-neutral-100">
                                        <span class="text-sm text-neutral-600">Foto belum tersedia</span>
                                    </div>
                                @endif
                            </x-slot:media>
                            <div class="space-y-2">
                                <x-mk.badge intent="neutral">{{ $cemetery->type }}</x-mk.badge>
                                <h3 class="text-lg font-semibold text-neutral-900">{{ $cemetery->name }}</h3>
                                <p class="text-sm text-neutral-600">{{ $cemetery->address }}</p>
                                @if ($priceRange !== null && $priceAttribution !== null)
                                    <p class="pt-1 text-base font-medium text-neutral-900">{{ $priceRange }}</p>
                                    <p class="text-sm text-[var(--mk-text-muted)]">
                                        Sumber: {{ $priceAttribution['source'] }}@if ($priceAttribution['effective']) &middot; per {{ $priceAttribution['effective'] }}@endif
                                    </p>
                                @endif
                            </div>
                        </x-mk.card>
                    </li>
                @endforeach
            </ul>
        </section>
    @endunless

    {{-- Stage 3 ticket 04 — "TPU & TPS Terverifikasi" (featured/verified).
         Replaces the old "Cara Kerja" (Section 4), "TPU/TPS Unggulan"
         (old Section 5), and "Trust/safety" (old Section 6) sections —
         their content is redistributed, not deleted: this section's intro
         paragraph carries the old trust/safety points' substance (privacy,
         verified payment, honesty about limitations); the FAQ highlights
         section below carries the old Cara Kerja steps' substance.

         Carries the PRD trust element into the first screenful (design
         doc §7): "lokasi terverifikasi" and "harga transparan". "Lokasi
         terverifikasi" is HomePage::render()'s own documented definition —
         a real, existing `registry_mode = AUTHORITATIVE` capability
         profile, not a new rule invented here. Against today's real seed
         data no cemetery currently qualifies (see that method's own doc
         comment) — but unlike the other real-data sections on this page,
         the section ITSELF (heading + reassurance intro) is NOT hidden in
         that case: this section replaces the old, always-visible
         "Trust/safety" section, and a homepage test already expects that
         reassurance band to always render (test_homepage_sections_
         alternate_surfaces_without_divider_lines). Only the CARD GRID
         below — the actual real-data list — follows the §6.2 empty-state
         / §6.3 provider-unavailable discipline and hides when nothing
         qualifies or the query fails. --}}
    <section aria-labelledby="verified-heading" class="surface-warm py-section lg:py-section-lg">
        <div class="mx-auto max-w-content px-4 md:px-6 lg:px-8">
            <h2 id="verified-heading" class="mb-2 text-center text-2xl font-semibold text-neutral-900">
                TPU &amp; TPS Terverifikasi
            </h2>
            {{-- Redistributed from the old "Trust/safety" section
                 (privacy, verified payment, honesty about
                 limitations) — condensed into one intro sentence
                 rather than the original 3-card grid, since this
                 section's own job is the card grid below. Always
                 renders, independent of the card grid's own data
                 availability (see this section's own top comment). --}}
            <p class="mx-auto mb-6 max-w-prose text-center text-base text-neutral-700">
                Dokumen Anda disimpan privat dan diperiksa sebelum diakses siapa pun, pembayaran baru dianggap
                lunas setelah benar-benar terverifikasi oleh tim kami, dan kami tidak mengarang data atau
                ketersediaan yang belum dapat kami pastikan.
            </p>
            @unless ($verifiedCemeteriesUnavailable || $verifiedCemeteries->isEmpty())
                <ul class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:gap-6 lg:grid-cols-3" aria-label="TPU dan TPS terverifikasi">
                    @foreach ($verifiedCemeteries as $cemetery)
                        @php
                            $priceRange = CemeteryPresenter::priceRange($cemetery);
                            $priceAttribution = CemeteryPresenter::priceAttribution($cemetery);
                            $photoUrl = CemeteryPresenter::photoUrl($cemetery);
                        @endphp
                        <li wire:key="verified-cemetery-{{ $cemetery->id }}">
                            <x-mk.card
                                as="a"
                                interactive
                                :href="route('cemeteries.show', ['cemeterySlug' => $cemetery->slug])"
                                class="h-full touch-target"
                            >
                                <x-slot:media>
                                    @if ($photoUrl)
                                        <img
                                            src="{{ $photoUrl }}"
                                            alt="Foto {{ $cemetery->name }}"
                                            loading="lazy"
                                            class="h-40 w-full object-cover"
                                        >
                                    @else
                                        <div class="flex h-40 w-full items-center justify-center bg-neutral-100">
                                            <span class="text-sm text-neutral-600">Foto belum tersedia</span>
                                        </div>
                                    @endif
                                </x-slot:media>
                                <div class="space-y-2">
                                    <x-mk.badge intent="success">Lokasi Terverifikasi</x-mk.badge>
                                    <h3 class="text-lg font-semibold text-neutral-900">{{ $cemetery->name }}</h3>
                                    <p class="text-sm text-neutral-600">{{ $cemetery->address }}</p>
                                    @if ($priceRange !== null && $priceAttribution !== null)
                                        <p class="pt-1 text-base font-medium text-neutral-900">{{ $priceRange }}</p>
                                        <p class="text-sm text-[var(--mk-text-muted)]">
                                            Harga Transparan &middot; Sumber: {{ $priceAttribution['source'] }}@if ($priceAttribution['effective']) &middot; per {{ $priceAttribution['effective'] }}@endif
                                        </p>
                                    @endif
                                </div>
                            </x-mk.card>
                        </li>
                    @endforeach
                </ul>
            @endunless
        </div>
    </section>

    {{-- "Kehangatan Keluarga" — supporting photo section, between the
         verified-cemeteries section above and FAQ highlights below (see
         this file's top doc block, "ADDED 26 Aug 2026", for why this is
         not a tenth NORMATIVE section). Reuses <x-mk.card>'s
         existing `media` + padded-body slot pattern (design-system.md
         §3.3, the same shape the verified-cemetery cards above already
         use) rather than inventing a new layout — design-system.md
         §9.2 MUST #2, "extend primitives rather than forking". Centered,
         single, non-interactive card (`padding="lg"`, no `href`, no
         `interactive`): informational only, nothing to click, so it reads
         as a smaller supporting note rather than competing with the hero.

         Deliberately does NOT reuse <x-mk.hero>: that component renders an
         <h1> (hero.blade.php's own doc block), and this page's Section 2
         hero already owns the page's one <h1> — a second <h1> here would
         break heading hierarchy. This section's heading is a plain <h2>,
         the same level every other homepage section below uses.

         image points to public/images/home/family-warmth.jpg — a real,
         candid, joyful family photo (Pexels, photographer RDNE Stock
         project, Pexels License, no attribution required; see this
         change's commit message for the source URL and this session's
         verification trail). design-system.md §2.2's Imagery row ("Real
         cemeteries/gardens, daylight, no people in grief") governs the
         hero/cemetery-card photography cage specifically — Section 2's
         hero above still fully honours it, untouched. This is a SECOND,
         different photo for a deliberately different purpose (warm family
         reassurance, not a location/facility image), which the project
         owner explicitly reviewed and approved separately from that cage,
         the same "additional, not a replacement" relationship this
         section's placement has to information-architecture.md §3's nine
         sections. `alt=""`: decorative, same convention the hero above
         uses — the heading and copy below already carry the message the
         image doesn't add information beyond. --}}
    <section aria-labelledby="family-warmth-heading" class="mx-auto max-w-content px-4 py-section md:px-6 lg:px-8 lg:py-section-lg">
        <x-mk.card class="mx-auto max-w-2xl" padding="lg">
            <x-slot:media>
                <img
                    src="{{ asset('images/home/family-warmth.jpg') }}"
                    alt=""
                    loading="lazy"
                    class="h-64 w-full object-cover md:h-80"
                >
            </x-slot:media>
            <div class="flex flex-col items-center gap-3 text-center">
                <h2 id="family-warmth-heading" class="text-2xl font-semibold text-neutral-900">
                    Didampingi dengan Hangat, Setiap Langkah
                </h2>
                <p class="text-base text-neutral-600">
                    Kami memahami setiap keluarga punya cerita masing-masing. Tim kami hadir membantu Anda mengurus
                    setiap kebutuhan dengan tenang, jelas, dan penuh perhatian — dari awal hingga selesai.
                </p>
            </div>
        </x-mk.card>
    </section>

    {{-- Section 7: FAQ highlights — §6.5 provider-unavailable degrades
         gracefully (HomePage::render()'s own try/catch); §6.2 empty state
         for the (rare, hard to trigger against real seed data) case where
         the query succeeds but returns nothing. --}}
    <section aria-labelledby="faq-highlights-heading" class="surface-quiet py-section lg:py-section-lg">
        <div class="mx-auto max-w-content px-4 md:px-6 lg:px-8">
            <h2 id="faq-highlights-heading" class="mb-2 text-center text-2xl font-semibold text-neutral-900">
                Pertanyaan yang Sering Diajukan
            </h2>
            {{-- Stage 3 ticket 04 — redistributed from the old "Cara
                 Kerja" section (pilih lokasi, lengkapi data, bayar, terima
                 konfirmasi) — condensed into one intro sentence rather
                 than the original 4-card breakdown, since this section's
                 own job is the FAQ list below. --}}
            <p class="mx-auto mb-6 max-w-prose text-center text-base text-neutral-700">
                Prosesnya singkat: pilih lokasi dan jenis layanan, lengkapi data dan dokumen secara privat, selesaikan
                pembayaran, lalu terima konfirmasi pesanan Anda.
            </p>

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
                            {{-- `emphasis="quiet"` (Tahap 4 butir 1): §2.3 of the
                                 kamboja plan measured these 600x130 answer rows
                                 rendering the same raised-card treatment as the
                                 286x242 service cards above. They keep the same
                                 border and the same interactive affordance; only
                                 the resting elevation drops to `shadow-none`, so
                                 a row reads as a row. --}}
                            <x-mk.card as="a" interactive emphasis="quiet" :href="route('faq.show', ['articleSlug' => $article->slug])" class="h-full touch-target">
                                <h3 class="text-base font-semibold text-neutral-900">{{ $article->title }}</h3>
                                <p class="text-sm text-neutral-600">{{ $article->summary }}</p>
                            </x-mk.card>
                        </li>
                    @endforeach
                </ul>
                <div class="mt-6 text-center">
                    <x-mk.button variant="secondary" :href="route('faq.index')">
                        Lihat semua FAQ
                    </x-mk.button>
                </div>
            @endif
        </div>
    </section>

    {{-- Section 8: Customer-service CTA — requirements.md AC5. Distinct
         from the Urgent banner above: this is a general "need help
         choosing" invitation, not a gate-state notice. --}}
    <section aria-labelledby="cs-cta-heading" class="mx-auto max-w-content px-4 py-section md:px-6 lg:px-8 lg:py-section-lg">
        <div class="mx-auto flex max-w-prose flex-col items-center gap-3 rounded-lg border border-primary-200 bg-primary-50 p-6 text-center md:p-8">
            {{-- tone `earth` -> `brand` 14 Sep 2026 (ADR-0040 D3): a real
                 primary-600 field, not another primary-100 tint on the
                 primary-50 card it already sits on. `size="lg"` is unchanged —
                 medallion SIZING on service cards is Tahap 4 of the kamboja
                 plan, not this change. --}}
            <x-mk.icon-medallion icon="question-mark-circle" tone="brand" size="lg" />
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
            <x-mk.button variant="secondary" href="/bantuan" class="mt-2">
                Hubungi Bantuan
            </x-mk.button>
        </div>
    </section>
</div>
