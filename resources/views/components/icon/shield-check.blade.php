{{--
    resources/views/components/icon/shield-check.blade.php

    <x-dynamic-component :component="'icon.' . $icon" ... /> — the glyph
    design-system.md §3.7 assigns to order-lifecycle status `DIVERIFIKASI`
    (intent `info`, "Progressing"). Reached through
    App\Support\Design\StatusIntent::icon(), never by a component
    switching on the enum itself (§3.7, §9.2 MUST #5).

    Provenance: the real, unmodified Heroicons v2 outline
    "ShieldCheckIcon" (heroicons 2.2.0, `24/outline/shield-check.svg` —
    MIT-licensed, 24x24 viewBox, stroke-width 1.5), i.e. OQ-05's own
    documented assumed default ("Outline, 1.5 px" — design-system.md
    §9.1) and the same source the two pre-existing icon files were built
    from. Not a custom drawing.

    Batch P-1 scope, identical in kind to finding N-15 (sprint-plan.md)
    and icon/bars-3.blade.php's own doc block: this file exists because a
    real caller crashes without it (`InvalidArgumentException: Unable to
    locate a class or view for component [icon.shield-check]`), NOT as a
    resolution of OQ-05 ("Which icon set?"), which remains open.

    No default classes — every icon.* caller supplies its own
    class="size-*" and aria-hidden="true" via $attributes.
--}}
<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" {{ $attributes }}>
    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
</svg>
