{{--
    resources/views/components/mk/filter-chip.blade.php

    <x-mk.filter-chip> — design-system.md §3.6a. A navigation/selection
    control (a link that toggles which filter is active), not a status
    marker — that distinction is why this is its own primitive rather than
    a new <x-mk.badge> intent.

    ---------------------------------------------------------------------------
    Why this exists — a real gap found independently by two Sprint 4
    batches (S4-T6 cemetery directory, S4-T8 marketplace), 8 Aug 2026
    ---------------------------------------------------------------------------
    `<x-mk.badge>`'s six intents (§3.6/§3.7) are the status vocabulary —
    neutral/info/pending/success/danger/urgent — and its own doc block is
    explicit that `$attributes->merge()` only APPENDS a caller's classes
    after its already-complete intent string; it never replaces them. So an
    "active filter chip" styled primary-100/primary-800 (the colour pair
    `resources/views/livewire/public/faq/index.blade.php` established first,
    already an asserted WCAG AA pair — verify-contrast.py — so no tokens.css
    change or §9.4 ADR was needed here) cannot go through `<x-mk.badge>`
    without leaving two competing colour declarations on one element. Three
    call sites (FAQ, cemetery directory, marketplace) independently
    hand-wrote the same badge-shaped recipe to work around it — the point at
    which a repeated workaround should become a primitive (§9.2 MUST #2:
    "Use the `<x-mk.*>` primitives. Extend them rather than forking.").

    Rejected: adding a `primary` value to badge.blade.php's `$intents` map.
    §3.7 is a closed status→intent vocabulary; a selection state is not a
    status, and stretching `intent` to cover both blurs a mapping every
    other part of this codebase (StatusIntent, Filament tables) treats as
    closed. See the sprint-plan.md finding this fix is recorded under.

    ---------------------------------------------------------------------------
    The accessibility gap this also closes
    ---------------------------------------------------------------------------
    All three hand-written call sites signalled the active chip by colour
    ALONE — same layout, radius, border width, padding, text size, and
    weight as the inactive `<x-mk.badge intent="neutral">` state; only the
    hue differed. `aria-current="page"` on the wrapping `<a>` serves
    assistive tech, but a sighted user with reduced colour discrimination
    had nothing else to go on within the chip group itself — exactly what
    §3.6's "a badge carries an icon whenever it communicates status" rule
    (WCAG 1.4.1) exists to prevent for the badge primitive; the hand-written
    chip that bypassed it silently lost the same protection. Not a page-level
    1.4.1 failure on the pages that also update a heading/URL on selection
    (redundant non-colour cue elsewhere on the page) — but worth fixing at
    the control itself rather than relying on that redundancy holding
    everywhere this primitive is ever used.

    Fix: the active state adds a real, non-colour cue — a check icon — not
    just a darker colour pair. `font-semibold` vs `font-medium` is a second,
    weaker cue (weight alone is not sufficient alone, hence the icon).

    ---------------------------------------------------------------------------
    Structure — same conventions as every other mk.* primitive
    ---------------------------------------------------------------------------
    One `@props` block, one PHP block with complete literal class strings
    (never interpolate a class name — Tailwind's JIT scanner cannot see
    it), `neutral-0`-family tokens instead of Tailwind's own `white`/plain
    greys. This comment deliberately avoids writing the PHP-block directive
    itself as prose before that block appears for real (finding N-14 — a
    doc comment containing that literal directive text corrupts Blade
    compilation of the WHOLE file — this exact file tripped on it once
    while this very paragraph was first drafted, caught by rendering it
    through the real compiler before commit, not by inspection).
--}}
@props([
    'href' => null,
    'active' => false,
])

@php
    $wrapperClasses = 'touch-target inline-flex items-center justify-center rounded-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 focus-visible:ring-offset-2';

    $chipBase = 'inline-flex items-center gap-1 rounded-sm border px-2 py-0.5 text-xs';

    // Two complete, literal strings — see file header. Do not merge these
    // into one interpolated expression.
    $activeChip = 'border-primary-300 bg-primary-100 font-semibold text-primary-800';
    $inactiveChip = 'border-[var(--mk-intent-neutral-border)] bg-[var(--mk-intent-neutral-bg)] font-medium text-[var(--mk-intent-neutral-fg)]';

    $chipClasses = trim($chipBase.' '.($active ? $activeChip : $inactiveChip));
@endphp

<a
    href="{{ $href }}"
    @if ($active) aria-current="page" @endif
    {{ $attributes->merge(['class' => $wrapperClasses]) }}
>
    <span class="{{ $chipClasses }}">
        {{-- The non-colour selection cue — see file header. aria-hidden
             because aria-current on the wrapping <a> already carries the
             state to assistive tech; this icon is a sighted-user cue only. --}}
        @if ($active)
            <x-dynamic-component component="icon.check" class="size-3 shrink-0" aria-hidden="true" />
        @endif
        {{ $slot }}
    </span>
</a>
