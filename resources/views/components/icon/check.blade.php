{{--
    resources/views/components/icon/check.blade.php

    <x-dynamic-component component="icon.check" ... /> — the completed-step
    tick in <x-mk.stepper> (resources/views/components/mk/stepper.blade.php
    renders it for every dot whose `$state === 'complete'`).

    Unlike most icon.* references, this one is NOT behind a caller-supplied
    `$icon` prop: any stepper past its first step renders it
    unconditionally, so it blocked the booking wizard (Steps 1-9) and the
    renewal journey the same way `icon.bars-3` blocked every page render in
    finding N-15 (sprint-plan.md).

    Provenance: the real, unmodified Heroicons v2 outline "CheckIcon"
    (heroicons 2.2.0, `24/outline/check.svg` — MIT-licensed, 24x24
    viewBox, stroke-width 1.5), i.e. OQ-05's own documented assumed
    default ("Outline, 1.5 px" — design-system.md §9.1) and the same
    source the two pre-existing icon files were built from. Not a custom
    drawing. `check` is the genuine Heroicons name — no substitution.

    Distinct from icon/check-circle.blade.php and
    icon/check-badge.blade.php, which §3.7 reserves for status badges;
    this is the bare tick, correct inside a stepper dot that already
    supplies its own circular container.

    Batch P-1 scope: added because a real caller crashes without it, NOT
    as a resolution of OQ-05 ("Which icon set?"), which remains open.

    No default classes — every icon.* caller supplies its own
    class="size-*" and aria-hidden="true" via $attributes.
--}}
<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" {{ $attributes }}>
    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
</svg>
