{{--
    resources/views/components/mk/badge.blade.php

    <x-mk.badge> — design-system.md §3.6. Compact status/tag marker used
    wherever an order or vendor state needs a small visual label.

    §3.7 is the normative status → intent table and requires that
    resolution to happen in ONE place: the planned
    `app/Support/Design/StatusIntent.php`. That class does not exist in
    this repo yet (confirmed absent — not fabricated here). This component
    deliberately does not switch on any status enum itself; it only knows
    how to render whatever `intent` string it is handed. Callers resolve
    the status → intent mapping themselves until `StatusIntent` lands.

    --- The dynamic-intent-class vs Tailwind-JIT-scanning problem (§3.6) ---
    §3.6's colour recipe is `bg-[var(--mk-intent-{intent}-bg)]
    text-[var(--mk-intent-{intent}-fg)] border-[var(--mk-intent-{intent}-border)]`.
    Building that string at request time via PHP interpolation
    (`"bg-[var(--mk-intent-{$intent}-bg)]"`) would never appear as literal
    text anywhere in this file — Tailwind 4's `@source`-driven JIT scanner
    (resources/css/app.css) only generates a utility for class names it can
    find as literal text in scanned files, so an interpolated class is
    silently dropped and the badge renders with no colour at all.

    The fix: $intents below is a STATIC PHP ARRAY where every value is a
    complete, already-written class string, one per intent. All six
    strings are literal text in this file, so the scanner finds every one
    of them regardless of which branch PHP takes at render time. Do not
    "simplify" this back into interpolation.

    `--mk-intent-*` (tokens.css §2.10) has no `--color-*` utility
    namespace of its own, so the `var()` arbitrary-value form is the
    permitted exception under design-system.md §9.2 MUST-NOT #2.

    §3.6 gives one base recipe (`px-2 py-0.5 text-xs`), not a per-size
    table the way §3.1 (button) does. `text-xs` (12px) is the documented
    badge type size at §1.4 regardless of `size` — so `size` here only
    adjusts horizontal padding; font-size never shrinks below the
    documented floor.

    Mandatory per §3.6 and the §2.3 DON'T list: a badge always carries
    **text** (the slot is required content — there is no icon-only or
    empty-label mode) and carries an **icon** whenever it communicates
    status, because colour alone never conveys state (WCAG 1.4.1). `dot`
    is a purely decorative accent — `aria-hidden`, coloured with
    `bg-current` so it always matches the intent text colour — and is
    never a substitute for the text label or the `icon` prop.

    Icons follow <x-mk.button>'s `x-dynamic-component :component="'icon.'
    . $icon"` convention. FLAG: no `icon.*` components exist in this repo
    yet (same pre-existing gap already present in button.blade.php); this
    file does not fabricate them.
--}}
@props([
    'intent' => 'neutral',
    'size' => 'md',
    'icon' => null,
    'dot' => false,
])

@php
    $base = 'inline-flex items-center gap-1 rounded-sm font-medium border';

    $sizes = [
        'sm' => 'px-1.5 py-0.5 text-xs',
        'md' => 'px-2 py-0.5 text-xs',
    ];

    // Complete, literal per-intent strings — see file header. Every value
    // MUST stay fully written out; refactoring this into
    // "bg-[var(--mk-intent-{$intent}-bg)]" interpolation looks equivalent
    // but is invisible to Tailwind's scanner (no CSS would be generated).
    $intents = [
        'neutral' => 'bg-[var(--mk-intent-neutral-bg)] text-[var(--mk-intent-neutral-fg)] border-[var(--mk-intent-neutral-border)]',
        'info'    => 'bg-[var(--mk-intent-info-bg)] text-[var(--mk-intent-info-fg)] border-[var(--mk-intent-info-border)]',
        'pending' => 'bg-[var(--mk-intent-pending-bg)] text-[var(--mk-intent-pending-fg)] border-[var(--mk-intent-pending-border)]',
        'success' => 'bg-[var(--mk-intent-success-bg)] text-[var(--mk-intent-success-fg)] border-[var(--mk-intent-success-border)]',
        'danger'  => 'bg-[var(--mk-intent-danger-bg)] text-[var(--mk-intent-danger-fg)] border-[var(--mk-intent-danger-border)]',
        'urgent'  => 'bg-[var(--mk-intent-urgent-bg)] text-[var(--mk-intent-urgent-fg)] border-[var(--mk-intent-urgent-border)]',
    ];

    $sizeClasses = $sizes[$size] ?? $sizes['md'];
    $intentClasses = $intents[$intent] ?? $intents['neutral'];

    $classes = trim("$base $sizeClasses $intentClasses");
@endphp

<span {{ $attributes->merge(['class' => $classes]) }}>
    {{-- Decorative only — bg-current inherits the intent text colour set
         above, so it never introduces a colour the scanner didn't already
         capture via $intents, and it never stands in for the text label. --}}
    @if ($dot)
        <span class="size-1.5 shrink-0 rounded-full bg-current" aria-hidden="true"></span>
    @endif

    @if ($icon)
        <x-dynamic-component :component="'icon.' . $icon" class="size-3.5 shrink-0" aria-hidden="true" />
    @endif

    <span>{{ $slot }}</span>
</span>
