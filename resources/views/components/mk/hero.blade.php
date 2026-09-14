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

    Responsive derivatives (DS-01, docs/superpowers/plans/
    2026-09-06-batch2g-cicd-hardening.md): `image` is expected to name a
    JPEG at `{name}.jpg` with sibling AVIF/WebP derivatives at
    `{name}-640.avif`, `{name}-960.avif`, `{name}-1440.avif` and the
    matching `.webp` files, all in the same directory -- exactly what
    public/images/hero/cemetery-garden-daylight.* provides. The `<img>`
    fallback keeps the plain `{name}.jpg` path (960px wide, ~230KB) so
    existing `assertSee('src="'.asset(...).'"')` tests keep matching
    unchanged; browsers that support AVIF or WebP never reach it. Only
    one call site exists today (home-page.blade.php); if a second image
    is ever passed that doesn't have these derivatives, generate them
    first (see the plan doc's DS-01 section for the cwebp/avifenc
    commands used) rather than passing a bare path through this prop.
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

    // MOBILE ORDER (Task C1, 13 Sep 2026 — kamboja design-language plan
    // §2.7/§7, option M2). Measured on 360x740, deviceScaleFactor 2,
    // mobile true: the `Pesan Makam` CTA sat at y = 1048 px, 1.4 screens
    // below the fold, because the 256 px photo band rendered above the
    // text panel that carries the CTA. M2 flips that order on mobile ONLY
    // -- text panel + CTA first, photo after -- which lifts the CTA by
    // exactly the photo band's height without shrinking the photo (M1) or
    // adding a persistent sticky bar (M3, deliberately not chosen here;
    // the booking wizard's --mk-z-sticky-cta precedent is NOT copied).
    //
    // Mechanism: the root becomes a flex column below `md` so that CSS
    // `order` applies, and reverts to `md:block` from `md` up. The
    // `md:block` reset restores the exact formatting context desktop has
    // today (block root, inline <picture>), which is why desktop is
    // unchanged rather than merely similar -- `md:order-none` on the
    // <picture> below is belt-and-braces for anyone who later drops the
    // `md:block`.
    //
    // Accessibility: this is a CSS-order/DOM-order divergence, which WCAG
    // 1.3.2 (Meaningful Sequence) only penalises when the reordered
    // content carries meaning or focus. The <picture> is decorative
    // (alt="", no focusable descendants), so reading order and tab order
    // are both unaffected -- the only focusable element in this component
    // is the CTA, and it is now reached sooner visually as well as in the
    // DOM order it already had.
    $classes = 'relative flex flex-col overflow-hidden rounded-lg md:block';

    $headingClasses = 'font-display text-4xl font-semibold tracking-tight text-neutral-900 lg:text-5xl';

    if ($image) {
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
    }
@endphp

<div {{ $attributes->merge(['class' => $classes]) }}>
    @if ($image)
        {{-- `order-last` is what puts the photo BELOW the text panel on
             mobile; see $classes above for why, and why the `md:` reset is
             expressed twice. --}}
        <picture class="order-last md:order-none">
            <source type="image/avif" srcset="{{ $avifSrcset }}" sizes="{{ $imageSizes }}" />
            <source type="image/webp" srcset="{{ $webpSrcset }}" sizes="{{ $imageSizes }}" />
            <img
                src="{{ $image }}"
                alt=""
                width="960"
                height="624"
                fetchpriority="high"
                class="h-64 w-full object-cover md:h-96"
            />
        </picture>
    @endif

    {{-- PANEL WEIGHT (Tahap 4 butir 5, kamboja plan §2.4). The measured
         complaint is not the panel's colour, it is its mass: the hero renders
         as "dua balok yang tidak pernah bersentuhan", a 384 px photo band
         above a 252 px text panel, so the photo reads as the hero and the
         message reads as a caption under it. The panel gains padding
         (24 -> 32 px mobile, 32 -> 48 px from `md`), which is the only
         weight axis available without touching the photo. Both values sit on
         tokens.css's 4 px `--spacing` scale; `bg-primary-50`, `gap-4`
         (= `--mk-stack-gap`) and the heading scale are unchanged, and NO
         scrim, overlay, or gradient is introduced — the unified hero (A6)
         is deliberately out of every stage until OQ-K5 is answered. --}}
    <div class="flex flex-col gap-4 bg-primary-50 p-8 md:p-12">
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
