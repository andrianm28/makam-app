{{--
    resources/views/components/icon/cog.blade.php

    <x-dynamic-component :component="'icon.' . $icon" ... /> — the glyph
    design-system.md §3.7 assigns to `DIPROSES` in BOTH families (order
    lifecycle "Fulfilment underway" and vendor processing), intent `info`
    in both. Reached through App\Support\Design\StatusIntent::icon(),
    never by a component switching on the enum itself (§3.7, §9.2 MUST
    #5).

    Provenance: the real, unmodified Heroicons v2 outline "CogIcon"
    (heroicons 2.2.0, `24/outline/cog.svg` — MIT-licensed, 24x24 viewBox,
    stroke-width 1.5), i.e. OQ-05's own documented assumed default
    ("Outline, 1.5 px" — design-system.md §9.1) and the same source the
    two pre-existing icon files were built from. Not a custom drawing.

    Worth stating because it is easy to "fix" wrongly: `cog` is a REAL
    Heroicons v2 icon, distinct from `cog-6-tooth` and `cog-8-tooth`
    (all three ship in the set). Verified against heroicons 2.2.0. Do not
    swap it for a tooth variant assuming v2 dropped the bare `cog` name —
    it did not, and §3.7 spells it `cog`.

    Batch P-1 scope, identical in kind to finding N-15 (sprint-plan.md)
    and icon/bars-3.blade.php's own doc block: this file exists because a
    real caller crashes without it (`InvalidArgumentException: Unable to
    locate a class or view for component [icon.cog]`), NOT as a
    resolution of OQ-05 ("Which icon set?"), which remains open.

    No default classes — every icon.* caller supplies its own
    class="size-*" and aria-hidden="true" via $attributes.
--}}
<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" {{ $attributes }}>
    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12a7.5 7.5 0 0 0 15 0m-15 0a7.5 7.5 0 1 1 15 0m-15 0H3m16.5 0H21m-1.5 0H12m-8.457 3.077 1.41-.513m14.095-5.13 1.41-.513M5.106 17.785l1.15-.964m11.49-9.642 1.149-.964M7.501 19.795l.75-1.3m7.5-12.99.75-1.3m-6.063 16.658.26-1.477m2.605-14.772.26-1.477m0 17.726-.26-1.477M10.698 4.614l-.26-1.477M16.5 19.794l-.75-1.299M7.5 4.205 12 12m6.894 5.785-1.149-.964M6.256 7.178l-1.15-.964m15.352 8.864-1.41-.513M4.954 9.435l-1.41-.514M12.002 12l-3.75 6.495" />
</svg>
