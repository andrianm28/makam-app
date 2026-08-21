{{--
    resources/views/components/mk/hero.blade.php

    <x-mk.hero> — Phase 1 of the brand visual refresh
    (docs/superpowers/specs/2026-08-21-brand-visual-refresh-design.md
    §4.2). Pairs a real photo (design-system.md §2.2: cemeteries/gardens,
    daylight, no people in grief) with the page's primary heading and one
    CTA. Not yet wired into any real page in this phase -- Phase 2 applies
    it to the homepage.

    Convention matches button.blade.php/card.blade.php: @props([...]) with
    defaults, classes composed once in a single PHP block, one
    $attributes->merge() on the root element.

    Props:
      image   (string, required) — path to a real cemetery/garden photo.
      heading (string, required) — the page's primary heading text.
      cta     (array, required) — ['label' => string, 'href' => string],
              rendered as a single primary <x-mk.button>. design-system.md
              §2.3 DO: exactly one primary action per view.

    Heading typography is text-4xl (mobile) / lg:text-5xl (desktop) with
    font-display (Poppins 600) and tracking-tight, matching
    design-system.md §1.4's typography scale table verbatim ("text-4xl |
    h1, hero (mobile)", "text-5xl | Hero (desktop, lg:)") and the
    font-display row ("h1/h2, hero, header wordmark only") -- larger than
    the text-2xl/md:text-3xl a plain page <h1> uses elsewhere (wizard/
    detail screens that are not heroes).

    NOTE (flagged in Task 4 review, 21 Aug 2026): home-page.blade.php's
    existing "Section 2: Hero" (id="hero-heading") already uses this same
    text-4xl/lg:text-5xl size scale but deliberately omits font-display --
    that section predates this primitive and was never updated to use it.
    This component adds font-display because §1.4's family table names it
    for heroes; that is a real, unresolved divergence from the one other
    hero in the codebase, not an absence of precedent. Reconciling the two
    (apply font-display to the existing section, or drop it here) is a
    Phase 2 decision when <x-mk.hero> is actually wired into the homepage
    -- left as-is here since this component is not yet used on any real
    page in this phase.

    tracking-tight and text-neutral-900 mirror every other <h1> in the
    codebase (e.g. faq/index.blade.php, booking/wizard.blade.php).

    The image is deliberately decorative (empty alt) -- it sets
    atmosphere, never conveys information the heading doesn't already
    carry, matching this repo's existing decorative-image convention.
--}}
@props([
    'image' => null,
    'heading' => null,
    'cta' => null,
])

@php
    if ($heading === null) {
        throw new InvalidArgumentException('<x-mk.hero> requires a heading.');
    }

    $classes = 'relative overflow-hidden rounded-lg';

    $headingClasses = 'font-display text-4xl font-semibold tracking-tight text-neutral-900 lg:text-5xl';
@endphp

<div {{ $attributes->merge(['class' => $classes]) }}>
    @if ($image)
        <img src="{{ $image }}" alt="" class="h-64 w-full object-cover md:h-96" />
    @endif

    <div class="flex flex-col gap-4 bg-primary-50 p-6 md:p-8">
        <h1 class="{{ $headingClasses }}">{{ $heading }}</h1>

        {{ $slot }}

        @if ($cta)
            <div>
                <x-mk.button variant="primary" size="lg" :href="$cta['href']">
                    {{ $cta['label'] }}
                </x-mk.button>
            </div>
        @endif
    </div>
</div>
