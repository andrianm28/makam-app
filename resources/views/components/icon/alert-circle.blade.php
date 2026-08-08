{{--
    resources/views/components/icon/alert-circle.blade.php

    <x-dynamic-component :component="'icon.alert-circle'" ... /> — the
    glyph <x-mk.field> renders beside a field-level validation error
    (resources/views/components/mk/field.blade.php, both the `@if ($error)`
    branches). design-system.md §7.5 requires an error to pair colour with
    an icon and Indonesian text, so this is not decorative.

    NAME SUBSTITUTION — read before changing this file.
    Requested name: `alert-circle` (the literal string field.blade.php
    passes to <x-dynamic-component>).
    Glyph actually used: Heroicons v2 outline **"ExclamationCircleIcon"**
    (`24/outline/exclamation-circle.svg`).
    Why: Heroicons v2 has no icon called `alert-circle`. Verified against
    heroicons 2.2.0 — `24/outline/alert-circle.svg` returns 404, while
    `24/outline/exclamation-circle.svg` exists and is the same glyph
    concept (a filled-stroke circle enclosing "!"). It is also the exact
    circular counterpart of the `exclamation-triangle` this repo already
    uses for alert-level warnings, so the two read as one family.
    `alert-circle` IS a real name in Lucide (checked: Lucide serves both
    `alert-circle` and its current name `circle-alert`), which is the same
    cross-namespace mix already noted in icon/banknote.blade.php and
    icon/clock-x.blade.php — the icon names in this codebase are not all
    from one library. Reported as a P-1 finding; not fixed here, because
    renaming the reference belongs in mk/field.blade.php (owned by another
    batch) or in an OQ-05 decision, not in this file.

    The FILE keeps the requested name `alert-circle` deliberately: it is
    what the existing caller asks for, and this component exists to stop
    that caller crashing, not to renegotiate its vocabulary.

    Provenance: the real, unmodified Heroicons v2 outline
    "ExclamationCircleIcon" (heroicons 2.2.0,
    `24/outline/exclamation-circle.svg` — MIT-licensed, 24x24 viewBox,
    stroke-width 1.5), i.e. OQ-05's own documented assumed default
    ("Outline, 1.5 px" — design-system.md §9.1). Not a custom drawing and
    not a hand-edited variant of the triangle.

    Batch P-1 scope: added because a real caller crashes without it, NOT
    as a resolution of OQ-05 ("Which icon set?"), which remains open.

    No default classes — every icon.* caller supplies its own
    class="size-*" and aria-hidden="true" via $attributes.
--}}
<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" {{ $attributes }}>
    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
</svg>
