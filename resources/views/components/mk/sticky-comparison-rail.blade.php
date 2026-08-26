{{--
    resources/views/components/mk/sticky-comparison-rail.blade.php

    <x-mk.sticky-comparison-rail> — design-system.md §3.3e. A right-rail
    widget combining a condensed multi-tier price comparison, the page's one
    primary CTA, an optional trust/review slot, and a compact related-links
    list — all in one persistent element.

    ORIGIN: a competitive read of kamboja.co.id's pricing-tier page found
    this exact combination (compare / convert / explore-more, in one sticky
    element) a strong pattern worth adopting. Per explicit project-owner
    instruction, ONLY the layout pattern is adopted from that benchmark —
    every visual treatment (colour, shape, type, spacing) below comes
    exclusively from this repo's own tokens.css / design-system.md and the
    existing <x-mk.card>/<x-mk.button>/<x-mk.badge> primitives. In
    particular: no pill-shaped buttons (§1.5 forbids them site-wide) and no
    borrowed palette — <x-mk.button variant="primary"> is reused verbatim.

    NO REAL PAGE CONSUMES THIS YET. It is built ahead of content: both
    cemetery package pricing and care-subscription tier pricing are
    separate, in-progress workstreams that will need exactly this
    comparison UI once their tier data exists. Until then this ships as an
    isolated, tested primitive with fixture-only coverage (see
    tests/Feature/View/Components/MkStickyComparisonRailTest.php).

    Convention matches button.blade.php/card.blade.php/hero.blade.php: a
    single @props([...]) block, classes composed once in PHP, one
    $attributes->merge() on the root element. The visual chrome (border,
    radius, shadow, padding) is NOT reinvented here — it is delegated to
    <x-mk.card padding="lg"> (§3.3), same "extend, don't fork" reasoning
    §3.6a documents for filter-chip.blade.php. This component only adds:
    (a) the <aside> sticky-positioning wrapper, and (b) the tier/CTA/trust/
    links content structure inside the card.

    -----------------------------------------------------------------------
    Sticky positioning precedent: design-system.md §4.3's own wizard
    "form + sticky summary" example —
      <aside class="md:sticky md:top-24 md:self-start">
    — is reused here VERBATIM rather than inventing a new breakpoint or a
    new top offset. Below `md` the <aside> is a plain, non-sticky block in
    normal document flow (mobile-first — matches <x-mk.table>'s own
    md:-gated collapse, §3.5). No new z-index layer is introduced: this is
    a sticky element positioned WITHIN the page's normal content column
    (like the wizard summary), not a viewport-pinned bar, so none of the
    §1.5 `--mk-z-*` layers apply — same reasoning the wizard aside example
    already relies on with no z-index utility of its own.

    -----------------------------------------------------------------------
    Props
      heading (string|null, default null) — optional visible rail title
              (e.g. "Bandingkan paket"). This component does not invent
              default marketing copy; when omitted, no heading renders and
              the <aside> falls back to the generic `label` below for its
              accessible name.
      label   (string, default 'Perbandingan paket') — accessible name for
              the <aside> landmark when `heading` is not supplied. Static
              UI chrome text, not product content — same category as
              table.blade.php's own component-owned "Pilih semua baris".
      tiers   (array, default []) — one entry per plan/tier:
                'label'       => string, required — plan/tier name
                'price'       => string|null — a PRE-FORMATTED price string
                                 (e.g. "Rp 4.000.000/tahun"), following this
                                 codebase's CemeteryPresenter::priceRange()
                                 convention of formatting upstream, never
                                 inside a view. `null` renders the same
                                 honest "Belum tersedia" fallback
                                 CemeteryPresenter's callers already use for
                                 a missing price — never a fabricated
                                 example figure.
                'priceSource' => string|null — attribution shown as
                                 "Sumber: {priceSource}" under the price,
                                 mirroring detail.blade.php's §2.3 pattern
                                 ("show the source ... on any fee figure").
                                 Ignored when 'price' is null.
                'indicative'  => bool, default false — when true (and a
                                 price is present), renders the same
                                 <x-mk.badge intent="neutral" icon="clock">
                                 Perlu konfirmasi</x-mk.badge> the cemetery
                                 directory already uses for an indicative
                                 price — neutral, never success (§2.3).
                'description' => string|null — one short feature/description
                                 line under the tier.
      cta     (array, required) — ['label' => string, 'href' => string],
              rendered as the ONE <x-mk.button variant="primary" size="lg">
              in this component, matching §2.3's "exactly one primary
              action per view" and <x-mk.hero>'s identical CTA contract.
              Omitting it throws InvalidArgumentException at render time,
              same fail-loudly convention <x-mk.hero> uses for `heading` —
              a comparison rail with no primary action defeats its purpose.
      links   (array, default []) — related/explore-more links, each
              ['label' => string, 'href' => string]. Rendered as
              <x-mk.button variant="link"> per §3.1's "link: inline in
              prose" convention — no new link-list primitive invented.

    Slots
      trust (named, optional) — the documented place for design-system.md
            §3.3d's reserved <x-mk.trust-badge-strip> once it actually ships
            (§3.3d landed on trunk mid-authoring of this component; it
            reserves that component's name/shape as DOCUMENTATION ONLY —
            "do not build the Blade component in this phase" — because no
            real partner/review/certification content exists yet to
            populate it). This slot renders whatever the caller passes, with
            NO built-in fallback content: this component does not build
            <x-mk.trust-badge-strip> itself and does not invent placeholder
            trust content, matching §3.3d's own hard constraint. Future
            usage once that component ships:
              <x-slot:trust>
                  <x-mk.trust-badge-strip ... />
              </x-slot:trust>

    -----------------------------------------------------------------------
    NOT IMPLEMENTED — no real page passes real data through this yet (see
    header above); every render in this repo today is fixture-driven.
--}}
@props([
    'heading' => null,
    'label' => 'Perbandingan paket',
    'tiers' => [],
    'cta' => null,
    'links' => [],
])

@php
    if ($cta === null || ($cta['label'] ?? null) === null || ($cta['href'] ?? null) === null) {
        throw new InvalidArgumentException(
            '<x-mk.sticky-comparison-rail> requires a cta with a label and href.'
        );
    }

    // §4.3's wizard-aside example verbatim — see header comment above for
    // why no z-index layer is added on top of it.
    $rootClasses = 'md:sticky md:top-24 md:self-start';

    $accessibleLabel = $heading !== null && $heading !== '' ? $heading : $label;
@endphp

<aside {{ $attributes->merge(['class' => $rootClasses]) }} aria-label="{{ $accessibleLabel }}">
    <x-mk.card padding="lg" class="flex flex-col gap-6">
        @if ($heading)
            <h2 class="text-xl font-semibold text-neutral-900">{{ $heading }}</h2>
        @endif

        @if (count($tiers) > 0)
            <div class="flex flex-col gap-4">
                @foreach ($tiers as $tier)
                    @php
                        $tierLabel = $tier['label'] ?? '';
                        $tierPrice = $tier['price'] ?? null;
                        $tierSource = $tier['priceSource'] ?? null;
                        $tierIndicative = $tier['indicative'] ?? false;
                        $tierDescription = $tier['description'] ?? null;
                    @endphp

                    <div class="flex flex-col gap-1 border-b border-neutral-200 pb-4 last:border-b-0 last:pb-0">
                        <div class="flex items-baseline justify-between gap-2">
                            <span class="text-base font-semibold text-neutral-900">{{ $tierLabel }}</span>

                            @if ($tierPrice !== null && $tierPrice !== '')
                                <span class="shrink-0 text-lg font-semibold tabular-nums text-neutral-900">
                                    {{ $tierPrice }}
                                </span>
                            @else
                                {{-- Honest empty state — CemeteryPresenter's
                                     own convention for a missing price:
                                     "showing nothing is honest; showing a
                                     number with an invented source would not
                                     be." No fabricated example price. --}}
                                <span class="shrink-0 text-sm font-medium text-neutral-600">
                                    Belum tersedia
                                </span>
                            @endif
                        </div>

                        @if ($tierPrice !== null && $tierPrice !== '')
                            @if ($tierSource)
                                <p class="text-sm text-[var(--mk-text-muted)]">
                                    Sumber: {{ $tierSource }}
                                </p>
                            @endif

                            @if ($tierIndicative)
                                <div>
                                    <x-mk.badge intent="neutral" icon="clock">
                                        Perlu konfirmasi
                                    </x-mk.badge>
                                </div>
                            @endif
                        @endif

                        @if ($tierDescription)
                            <p class="text-sm text-neutral-600">{{ $tierDescription }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        <x-mk.button variant="primary" size="lg" full :href="$cta['href']">
            {{ $cta['label'] }}
        </x-mk.button>

        {{-- Optional trust/review slot — see header comment. Deliberately no
             fallback content when the caller omits it. --}}
        @isset($trust)
            <div>{{ $trust }}</div>
        @endisset

        @if (count($links) > 0)
            <nav aria-label="Tautan terkait">
                <ul class="flex flex-col gap-2">
                    @foreach ($links as $link)
                        <li>
                            <x-mk.button variant="link" :href="$link['href'] ?? null">
                                {{ $link['label'] ?? '' }}
                            </x-mk.button>
                        </li>
                    @endforeach
                </ul>
            </nav>
        @endif
    </x-mk.card>
</aside>
