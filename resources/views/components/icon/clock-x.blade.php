{{--
    resources/views/components/icon/clock-x.blade.php

    <x-dynamic-component :component="'icon.' . $icon" ... /> — the glyph
    design-system.md §3.7 assigns to order-lifecycle status
    `KEDALUWARSA` (intent `neutral`, "Terminal, expiry is factual not
    alarming"). Reached through App\Support\Design\StatusIntent::icon(),
    never by a component switching on the enum itself (§3.7, §9.2 MUST
    #5).

    NAME SUBSTITUTION — read before changing this file.
    Requested name: `clock-x` (design-system.md §3.7 order-lifecycle
    table, transcribed verbatim into StatusIntent::MAP).
    Glyph actually used: Heroicons v2 outline **"ClockIcon"**
    (`24/outline/clock.svg`) — the plain clock, with no X modifier.
    Why: Heroicons v2 has no `clock-x`, and no clock variant of any kind
    beyond the plain `clock`. Verified against heroicons 2.2.0:
    `24/outline/clock-x.svg` returns 404, and the complete 324-icon
    outline set contains exactly one clock (`clock`) and no hourglass.
    Nor is it a name borrowed from the other set OQ-05 names: Lucide has
    no `clock-x` either (checked — it ships `clock` and `clock-alert`).
    So `clock-x` reads as descriptive shorthand written into §3.7 by
    hand, not as any real library's icon name. Drawing an X onto the
    clock myself would be inventing path data, and P-1's brief forbids
    that; the honest options were the closest real glyph or nothing.

    Why `clock` and not a "cancelled" glyph: §3.7's own rationale column
    is the tie-breaker — "expiry is factual not alarming", intent
    `neutral`. `x-circle` and `no-symbol` both read as error/prohibition
    and are already spoken for by `DITOLAK` (`danger`) and the general
    prohibition sense; the plain clock keeps the temporal meaning §3.7
    asked for and keeps the tone neutral, which is the property the
    rationale actually pins down.

    KNOWN CONSEQUENCE, not a silent one: `KEDALUWARSA` now renders the
    same glyph as the three `pending` waiting states (icon/clock —
    `MENUNGGU_*`). Colour and Indonesian label still differ (neutral vs.
    pending), so §7.5's "colour + icon + Indonesian text" rule is still
    satisfied and no state is conveyed by colour alone (WCAG 1.4.1) — but
    the ICON alone no longer distinguishes expired from waiting. That is
    a real design regression against §3.7's evident intent, and resolving
    it means either picking a distinct glyph (a design decision that
    belongs in a §3.7 update, not in this file) or resolving OQ-05 onto a
    set that has `clock-x`. Surfaced as a P-1 finding; deliberately not
    decided here.

    Provenance: the real, unmodified Heroicons v2 outline "ClockIcon"
    (heroicons 2.2.0, `24/outline/clock.svg` — MIT-licensed, 24x24
    viewBox, stroke-width 1.5), byte-identical to the path data in
    icon/clock.blade.php. Not a custom drawing, not a hand-edited variant.

    Batch P-1 scope, identical in kind to finding N-15 (sprint-plan.md)
    and icon/bars-3.blade.php's own doc block: this file exists because a
    real caller crashes without it (`InvalidArgumentException: Unable to
    locate a class or view for component [icon.clock-x]`), NOT as a
    resolution of OQ-05 ("Which icon set?"), which remains open.

    No default classes — every icon.* caller supplies its own
    class="size-*" and aria-hidden="true" via $attributes.
--}}
<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" {{ $attributes }}>
    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
</svg>
