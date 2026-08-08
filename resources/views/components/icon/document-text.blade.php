{{--
    resources/views/components/icon/document-text.blade.php

    <x-dynamic-component :component="'icon.' . $icon" ... /> — the glyph
    design-system.md §3.7 assigns to order-lifecycle status
    `PENAWARAN_TERKIRIM` (intent `info`, "Action available to customer").
    Reached through App\Support\Design\StatusIntent::icon(), never by a
    component switching on the enum itself (§3.7, §9.2 MUST #5).

    Provenance: the real, unmodified Heroicons v2 outline
    "DocumentTextIcon" (heroicons 2.2.0, `24/outline/document-text.svg` —
    MIT-licensed, 24x24 viewBox, stroke-width 1.5), i.e. OQ-05's own
    documented assumed default ("Outline, 1.5 px" — design-system.md
    §9.1) and the same source the two pre-existing icon files were built
    from. Not a custom drawing.

    Batch P-1 scope, identical in kind to finding N-15 (sprint-plan.md)
    and icon/bars-3.blade.php's own doc block: this file exists because a
    real caller crashes without it (`InvalidArgumentException: Unable to
    locate a class or view for component [icon.document-text]`), NOT as a
    resolution of OQ-05 ("Which icon set?"), which remains open.

    No default classes — every icon.* caller supplies its own
    class="size-*" and aria-hidden="true" via $attributes.
--}}
<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" {{ $attributes }}>
    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
</svg>
