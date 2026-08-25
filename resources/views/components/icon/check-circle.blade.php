{{--
    resources/views/components/icon/check-circle.blade.php

    <x-dynamic-component :component="'icon.' . $icon" ... /> — the glyph
    design-system.md §3.7 assigns to `DISETUJUI_PEMESAN` (order
    lifecycle, intent `info`, "Progressing, not paid") and
    `DITERIMA_VENDOR` (vendor processing, intent `info`). Reached through
    App\Support\Design\StatusIntent::icon(), never by a component
    switching on the enum itself (§3.7, §9.2 MUST #5).

    Not to be confused with icon/check-badge.blade.php, which §3.7
    reserves for the terminal `SELESAI` success state — "Paid does not
    mean completed" (AGENTS.md, marketplace-catalog.md), and neither does
    "approved".

    Provenance: the real, unmodified Heroicons v2 outline
    "CheckCircleIcon" (heroicons 2.2.0, `24/outline/check-circle.svg` —
    MIT-licensed, 24x24 viewBox, stroke-width 1.5), i.e. OQ-05's own
    documented assumed default ("Outline, 1.5 px" — design-system.md
    §9.1) and the same source the two pre-existing icon files were built
    from. Not a custom drawing.

    Batch P-1 scope, identical in kind to finding N-15 (sprint-plan.md)
    and icon/bars-3.blade.php's own doc block: this file exists because a
    real caller crashes without it (`InvalidArgumentException: Unable to
    locate a class or view for component [icon.check-circle]`), NOT as a
    resolution of OQ-05 ("Which icon set?"), which remains open.

    No default classes — every icon.* caller supplies its own
    class="size-*" and aria-hidden="true" via $attributes.
--}}
<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" {{ $attributes }}>
    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
</svg>
