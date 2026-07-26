{{--
    resources/views/components/icon/bars-3.blade.php

    <x-dynamic-component component="icon.bars-3" ... /> — the hamburger-menu
    glyph <x-mk.header> renders unconditionally in its mobile bar
    (resources/views/components/mk/header.blade.php), so it is the one icon
    every real page render actually reaches; that made it the first `icon.*`
    reference to surface design-system.md OQ-05 ("Which icon set?") as a
    real CI failure (`Unable to locate a class or view for component
    [icon.bars-3]`) rather than a still-open design question.

    This is deliberately scoped to ONLY this one icon, not the full set —
    OQ-05 itself is still open, and every other `icon.*` reference in this
    codebase (badge/alert/button/field/stepper/gate-closed-page) is
    conditional on a caller-supplied `$icon`/`$iconTrailing` prop that no
    current caller actually passes, so nothing else is blocking real
    renders yet. Built to OQ-05's own documented assumed default ("Outline,
    1.5 px" — design-system.md §9.1) rather than guessed: this is the real,
    unmodified Heroicons v2 outline "Bars3Icon" glyph (MIT-licensed,
    24x24 viewBox, stroke-width 1.5) — not a custom drawing.

    No default classes here (unlike mk.* primitives) — every `icon.*`
    caller already supplies its own `class="size-*"` via $attributes, and
    an icon has no sensible default size of its own.
--}}
<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" {{ $attributes }}>
    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
</svg>
