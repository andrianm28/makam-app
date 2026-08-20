{{--
    resources/views/components/mk/card.blade.php

    <x-mk.card> — design-system.md §3.3. Convention matches <x-mk.button>
    (read that file first): @props([...]) with defaults, classes composed
    once in a single PHP block, one $attributes->merge() on the root
    element, `neutral-0` (not Tailwind's built-in `white`) because
    tokens.css defines --color-neutral-0 explicitly.

    Uses the bare `duration-fast` utility, matching button.blade.php — it is
    registered via `@utility duration-fast { ... }` in resources/css/app.css,
    independent of `--mk-duration-fast` living in the Layer 2 `:root` block.
    (Corrected 25 Jul 2026 from an earlier `duration-[var(--mk-duration-fast)]`
    written on the mistaken belief that the bare class compiled to nothing.)

    Slots: default (body), `header`, `footer`, `media` (all optional).
    `media` renders full-bleed above the padded body, clipped to the card's
    own rounded corners.
--}}
@props([
    'as' => 'div',
    'href' => null,
    'padding' => 'md',
    'interactive' => false,
    'intent' => null,
])

@php
    // Components must not switch on domain enum strings (§3.7) — this
    // component only ever receives an already-resolved intent. It does not
    // define `StatusIntent`; that resolution lives elsewhere and is not yet
    // built. An unrecognised value falls back to the plain default look,
    // same defensive pattern button.blade.php uses for unknown $variant.
    $validIntents = ['neutral', 'info', 'pending', 'success', 'danger', 'urgent'];
    $intent = in_array($intent, $validIntents, true) ? $intent : null;

    // A `<a>` with no real destination has no reliable affordance, so
    // `interactive` only promotes the tag to <a> when an href is actually
    // present — same "no href, no <a>" logic button.blade.php uses. This also
    // guarantees the interactive card contains exactly one focusable
    // anchor: the root element itself, wrapping everything (§3.3) — never
    // nest another <a> or <button> inside an interactive card's slot.
    $tag = (($as === 'a' || $interactive) && $href)
        ? 'a'
        : ($as === 'article' ? 'article' : 'div');

    $hasMedia = isset($media) && $media->isNotEmpty();

    // Base: `bg-neutral-0` per the button's established convention (§1.1 of
    // button.blade.php), not the doc's literal `bg-white` snippet wording —
    // consistency with the reference matters more here. `overflow-hidden`
    // only when there's media to clip to the card's own rounded corners;
    // added unconditionally it would silently clip legitimate overflowing
    // content (a popover opened from inside the card, for instance).
    $base = trim('block rounded-lg border shadow-sm' . ($hasMedia ? ' overflow-hidden' : ''));

    // §3.3: intent (when set) drives border + background from the
    // `--mk-intent-*` semantic tokens (§2.10) — these have no Tailwind
    // utility namespace, so `var(--mk-*)` inside brackets is the documented
    // exception, same as the badge recipe in §3.6.
    //
    // MUST be a static array of complete literal strings, one per intent —
    // never build the class name by interpolating $intent at request time
    // (e.g. "border-[var(--mk-intent-{$intent}-border)]"). Tailwind's
    // @source scanner reads file TEXT for literal class-shaped strings; it
    // cannot execute PHP, so an interpolated class is invisible to it and
    // silently generates no CSS. Found and fixed 25 Jul 2026 — an earlier
    // revision of this file did exactly that, so every `intent` card
    // rendered with zero border/background styling. badge.blade.php's
    // $intents array (§3.6) already gets this right; this mirrors it.
    $intentSurfaces = [
        'neutral' => 'border-[var(--mk-intent-neutral-border)] bg-[var(--mk-intent-neutral-bg)]',
        'info'    => 'border-[var(--mk-intent-info-border)] bg-[var(--mk-intent-info-bg)]',
        'pending' => 'border-[var(--mk-intent-pending-border)] bg-[var(--mk-intent-pending-bg)]',
        'success' => 'border-[var(--mk-intent-success-border)] bg-[var(--mk-intent-success-bg)]',
        'danger'  => 'border-[var(--mk-intent-danger-border)] bg-[var(--mk-intent-danger-bg)]',
        'urgent'  => 'border-[var(--mk-intent-urgent-border)] bg-[var(--mk-intent-urgent-bg)]',
    ];
    $surfaceClasses = $intent ? $intentSurfaces[$intent] : 'border-neutral-200 bg-neutral-0';

    $paddingMap = [
        'none' => '',
        'sm' => 'p-4',
        'md' => 'p-4 md:p-6',
        'lg' => 'p-6 md:p-8',
    ];
    $paddingClasses = $paddingMap[$padding] ?? $paddingMap['md'];

    // Interactive: whole card becomes a clickable link (§3.3). Border/shadow
    // change on hover, so both are transitioned, not just shadow as the
    // doc's literal snippet shows — the hover state changes both.
    //
    // Background-tint hover added (homepage visual refresh, 19 Aug 2026):
    // `hover:bg-primary-50` joins the transition, gated on `$intent === null`
    // — an intent card already owns its surface via `$intentSurfaces` above
    // (§3.3's cemetery/service-row variants), and this tint must never
    // compete with that. This is the site-wide "livelier" hover: colour-only,
    // no transform (design-system.md §5's interaction table states, for
    // hover, "Transform: none" — a lift/scale effect would need a change to
    // that normative table, not a component tweak), same `duration-fast`/
    // `ease-standard` budget as before.
    $interactiveClasses = $interactive
        ? 'transition-[border-color,box-shadow,background-color] duration-fast ease-standard
           hover:border-primary-300 hover:shadow-md'
           . ($intent === null ? ' hover:bg-primary-50' : '') . '
           focus-within:outline-none focus-within:ring-2 focus-within:ring-primary-600 focus-within:ring-offset-2'
        : '';

    $classes = trim("$base $surfaceClasses $interactiveClasses");
    $bodyClasses = trim("$paddingClasses flex flex-col gap-4");
@endphp

<{{ $tag }}
    @if ($tag === 'a') href="{{ $href }}" @endif
    {{ $attributes->merge(['class' => $classes]) }}
>
    @if ($hasMedia)
        {{ $media }}
    @endif

    <div class="{{ $bodyClasses }}">
        @isset($header)
            <div>{{ $header }}</div>
        @endisset

        {{ $slot }}

        @isset($footer)
            <div>{{ $footer }}</div>
        @endisset
    </div>
</{{ $tag }}>
