{{--
    resources/views/components/icon/exclamation-triangle.blade.php

    <x-dynamic-component component="icon.exclamation-triangle" ... /> —
    warning-triangle glyph. Added for S4-T3's homepage Urgent-availability
    banner (<x-mk.alert intent="urgent">), which design-system.md §7.5
    requires to pair colour with an icon and Indonesian text ("Every status
    uses colour + icon + Indonesian text") — a real, confirmed gap an
    adversarial review caught (colour + text only, no icon) before this
    file existed.

    Same deliberate scoping as icon/bars-3.blade.php (see that file's own
    doc block, finding N-15, sprint-plan.md): this is ONE more icon added
    because a real caller now needs it, not a resolution of the still-open
    OQ-05 ("which icon set?"). Built to OQ-05's own documented assumed
    default ("Outline, 1.5 px" — design-system.md §9.1): the real,
    unmodified Heroicons v2 outline "ExclamationTriangleIcon" glyph
    (MIT-licensed, 24x24 viewBox, stroke-width 1.5) — not a custom drawing.

    No default classes — every icon.* caller already supplies its own
    class="size-*" via $attributes.
--}}
<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" {{ $attributes }}>
    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
</svg>
