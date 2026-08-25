{{--
    resources/views/components/icon/question-mark-circle.blade.php

    <x-dynamic-component :component="'icon.' . $icon" ... /> — NOT a
    §3.7 table entry. This is App\Support\Design\StatusIntent's
    `FALLBACK_ICON`: what renders when a status is unrecognised, or when
    the same status resolves to conflicting intents across families and
    StatusIntent refuses to guess (it logs a warning and degrades to
    `neutral` + this icon rather than throwing).

    That makes this the single most load-bearing file in the set. Every
    other icon here covers a mapped status; this one covers the unmapped
    ones — including the whole families StatusIntent's own doc block
    lists as deliberately NOT mapped yet (funeral-case-management,
    recurring-care-subscriptions, pre-need-contracting). Without it, the
    defensive fallback that exists specifically so an unknown status
    "must not crash a table render" would itself crash the render, which
    is exactly the failure it was written to prevent.

    Provenance: the real, unmodified Heroicons v2 outline
    "QuestionMarkCircleIcon" (heroicons 2.2.0,
    `24/outline/question-mark-circle.svg` — MIT-licensed, 24x24 viewBox,
    stroke-width 1.5), i.e. OQ-05's own documented assumed default
    ("Outline, 1.5 px" — design-system.md §9.1) and the same source the
    two pre-existing icon files were built from. Not a custom drawing.

    Batch P-1 scope, identical in kind to finding N-15 (sprint-plan.md)
    and icon/bars-3.blade.php's own doc block: this file exists because a
    real caller crashes without it (`InvalidArgumentException: Unable to
    locate a class or view for component [icon.question-mark-circle]`),
    NOT as a resolution of OQ-05 ("Which icon set?"), which remains open.

    No default classes — every icon.* caller supplies its own
    class="size-*" and aria-hidden="true" via $attributes.
--}}
<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" {{ $attributes }}>
    <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z" />
</svg>
