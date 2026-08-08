{{--
    resources/views/components/icon/check-badge.blade.php

    <x-dynamic-component :component="'icon.' . $icon" ... /> — the glyph
    design-system.md §3.7 assigns to `SELESAI` in BOTH families (order
    lifecycle "Terminal success" and vendor processing), intent `success`
    in both. Reached through App\Support\Design\StatusIntent::icon(),
    never by a component switching on the enum itself (§3.7, §9.2 MUST
    #5).

    This glyph is reserved for terminal completion and must stay visually
    distinct from icon/banknote.blade.php (`DIBAYAR`) and
    icon/check-circle.blade.php (`DISETUJUI_PEMESAN` /
    `DITERIMA_VENDOR`): §3.7, marketplace-catalog.md and AGENTS.md all
    state "Paid does not mean completed", and §3.7 requires payment and
    fulfilment to read as two distinct indicators, never one merged
    "done" badge.

    Provenance: the real, unmodified Heroicons v2 outline
    "CheckBadgeIcon" (heroicons 2.2.0, `24/outline/check-badge.svg` —
    MIT-licensed, 24x24 viewBox, stroke-width 1.5), i.e. OQ-05's own
    documented assumed default ("Outline, 1.5 px" — design-system.md
    §9.1) and the same source the two pre-existing icon files were built
    from. Not a custom drawing.

    Batch P-1 scope, identical in kind to finding N-15 (sprint-plan.md)
    and icon/bars-3.blade.php's own doc block: this file exists because a
    real caller crashes without it (`InvalidArgumentException: Unable to
    locate a class or view for component [icon.check-badge]`), NOT as a
    resolution of OQ-05 ("Which icon set?"), which remains open.

    No default classes — every icon.* caller supplies its own
    class="size-*" and aria-hidden="true" via $attributes.
--}}
<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" {{ $attributes }}>
    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 0 1-1.043 3.296 3.745 3.745 0 0 1-3.296 1.043A3.745 3.745 0 0 1 12 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 0 1-3.296-1.043 3.745 3.745 0 0 1-1.043-3.296A3.745 3.745 0 0 1 3 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 0 1 1.043-3.296 3.746 3.746 0 0 1 3.296-1.043A3.746 3.746 0 0 1 12 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 0 1 3.296 1.043 3.746 3.746 0 0 1 1.043 3.296A3.745 3.745 0 0 1 21 12Z" />
</svg>
