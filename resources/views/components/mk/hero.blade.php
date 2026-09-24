{{--
    resources/views/components/mk/hero.blade.php

    <x-mk.hero> — REDESIGNED 24 Sep 2026 (ADR-0045, resolves OQ-K5). Pairs a
    real photo (design-system.md §2.2: cemeteries/gardens, daylight, no
    people in grief) with the page's primary heading and one CTA, now
    rendered as ONE unified full-bleed photo layer with the heading/CTA
    positioned over it behind --mk-hero-scrim, instead of the previous two
    stacked blocks (a photo band, then a separate bg-primary-50 text panel
    below it).

    NOT the reference site's own real technique — see ADR-0045 and
    tokens.css's own --mk-hero-scrim comment for the full accounting. The
    reference composes its photo so the text-bearing side is naturally
    bright and uses dark text directly on it, no dark overlay at all. This
    repo doesn't have photography with that bright-side property yet
    (OQ-K3/OQ-K4) — --mk-hero-scrim is a CSS-guaranteed alternative that
    works with any photo, verified for real by
    docs/design/verify-hero-scrim-contrast.py, not a re-derivation of the
    reference's exact method.

    Convention matches button.blade.php/card.blade.php: @props([...]) with
    defaults, classes composed once in a single PHP block, one
    $attributes->merge() on the root element.

    Props:
      image   (string, required) — path to a real cemetery/garden photo.
              Required for the same reason `heading` is: a scrim with no
              photo underneath makes no sense for this component's whole
              purpose, so there is no two-block fallback for a missing
              image anymore (unlike the pre-redesign version, which
              rendered the text panel alone when `image` was omitted).
      heading (string, required) — the page's primary heading text.
      cta     (array, required) — ['label' => string, 'href' => string],
              rendered as a single primary <x-mk.button>. design-system.md
              §2.3 DO: exactly one primary action per view.

    OBSOLETED BY THIS REDESIGN: the mobile CTA-reorder mechanism (Task C1,
    13 Sep 2026, kamboja plan §2.7/§7 option M2 — `order-last`/`md:order-none`
    flipping the photo below the text panel on mobile only, to lift the CTA
    above the fold). That mechanism existed only because the old two-block
    layout put a 256px photo band ABOVE the CTA-bearing text panel. With
    the CTA now rendered ON TOP of the photo from the very first paint, on
    every breakpoint, there is no "CTA buried below the fold" problem left
    to fix — the reorder logic, and its own dedicated regression test, are
    removed as dead complexity, not merely left inert.

    Heading typography is text-4xl (mobile) / lg:text-5xl (desktop) with
    font-display and tracking-tight, matching design-system.md §1.4's
    typography scale table verbatim — unchanged by this redesign. Heading
    colour changes from text-neutral-900 (dark-on-light, correct for the
    old bg-primary-50 panel) to text-neutral-0 (white, correct for
    dark-scrim-on-photo) — this is a real, deliberate change this redesign
    makes, not an oversight.

    The image remains deliberately decorative (empty alt) — it sets
    atmosphere, never conveys information the heading doesn't already
    carry, matching this repo's existing decorative-image convention.

    Responsive derivatives (DS-01, unchanged by this redesign): `image` is
    expected to name a JPEG at `{name}.jpg` with sibling AVIF/WebP
    derivatives at `{name}-640.avif`, `{name}-960.avif`, `{name}-1440.avif`
    and the matching `.webp` files, all in the same directory.
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

    if ($image === null) {
        throw new InvalidArgumentException('<x-mk.hero> requires an image.');
    }

    $classes = 'relative overflow-hidden rounded-lg';

    // White heading/CTA on the dark end of the scrim — text-neutral-0, not
    // the old text-neutral-900 (that colour was correct for the old
    // bg-primary-50 panel; it would be unreadable directly on a photo).
    $headingClasses = 'font-display text-4xl font-semibold tracking-tight text-neutral-0 lg:text-5xl';

    $imageDir = rtrim(pathinfo($image, PATHINFO_DIRNAME), '/');
    $imageName = pathinfo($image, PATHINFO_FILENAME);
    $widths = [640, 960, 1440];
    $srcset = fn (string $ext) => collect($widths)
        ->map(fn (int $w) => "{$imageDir}/{$imageName}-{$w}.{$ext} {$w}w")
        ->implode(', ');
    $avifSrcset = $srcset('avif');
    $webpSrcset = $srcset('webp');
    // Matches resources/css/tokens.css's --container-content (80rem /
    // 1280px page shell) minus the lg:px-8 gutters home-page.blade.php
    // applies around the hero.
    $imageSizes = '(min-width: 1024px) 1216px, 100vw';
@endphp

<div {{ $attributes->merge(['class' => $classes]) }}>
    {{-- The photo is now the component's root visual layer — absolutely
         positioned to fill the container, sized taller than the old
         h-64/md:h-96 photo BAND since it must now hold the full hero
         (photo + overlaid text), not just itself. --}}
    <picture>
        <source type="image/avif" srcset="{{ $avifSrcset }}" sizes="{{ $imageSizes }}" />
        <source type="image/webp" srcset="{{ $webpSrcset }}" sizes="{{ $imageSizes }}" />
        <img
            src="{{ $image }}"
            alt=""
            width="960"
            height="624"
            fetchpriority="high"
            class="h-80 w-full object-cover md:h-96"
        />
    </picture>

    {{-- The scrim sits between the photo and the text, covering the whole
         frame — its gradient (transparent top, --mk-hero-scrim's dark
         stop at/near the bottom) is what --mk-hero-scrim's own comment
         and docs/design/verify-hero-scrim-contrast.py's worst-case check
         both describe; see tokens.css for the exact stops. `inset-0`
         + `absolute` layers it directly over the photo without adding to
         document flow. --}}
    <div class="absolute inset-0 bg-[image:var(--mk-hero-scrim)]" aria-hidden="true"></div>

    {{-- Text layer — absolutely positioned over the scrim, bottom-aligned
         (design-system.md §2.4's "panel weight" finding: the message
         needs to read as the hero, not a caption under it — bottom
         alignment over a full-bleed photo, with the scrim darkest right
         where this text sits, is what the redesign uses to get there
         instead of the old panel's padding-only weight axis). --}}
    <div class="absolute inset-x-0 bottom-0 flex flex-col gap-4 p-8 md:p-12">
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
