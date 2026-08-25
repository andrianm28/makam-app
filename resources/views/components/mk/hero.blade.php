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

    NOTE (corrected post Task 4 review, 21 Aug 2026): resources/css/app.css's
    base layer already applies font-display, tracking-tight, and the strong
    text colour to every <h1> in the codebase globally (the `h1, h2 {
    font-family: var(--font-display); letter-spacing: var(--tracking-tight);
    }` and `h1, h2, h3, h4 { color: var(--mk-text-strong); ... }` rules), so
    home-page.blade.php's existing "Section 2: Hero" <h1> already renders
    with font-display -- there is no divergence to reconcile. The only thing
    $headingClasses adds beyond the base layer is the text-4xl/lg:text-5xl
    size scale (design-system.md §1.4's hero typography row). font-display,
    font-semibold, tracking-tight, and text-neutral-900 are restated
    explicitly here anyway for this component's own self-containment/
    clarity, even though the base <h1> rule already applies them.

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
