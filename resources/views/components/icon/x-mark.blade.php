{{--
    resources/views/components/icon/x-mark.blade.php

    <x-dynamic-component component="icon.x-mark" ... /> — the Heroicons
    close/dismiss glyph.

    NO LIVE CALLER TODAY — deliberately stated, because it is easy to
    assume otherwise. Unlike every other file in this directory, this one
    was NOT added to stop an active crash. Both places that would use it
    currently draw their own inline SVG X instead:

      - mk/modal.blade.php's close button (an inline <svg class="size-5">,
        never an icon.* reference), and
      - mk/alert.blade.php's dismiss button, which DID reference
        `icon.x-mark` unconditionally and crashed every `dismissible`
        alert until 26 Jul 2026, when it was switched to an inline SVG
        for exactly the reason this directory exists (see that file's own
        comment).

    Both of those comments say the same thing: "once OQ-05 resolves, both
    this and modal.blade.php's close button can switch to the real icon
    component together, in one pass." This file is that component, made
    available ahead of the switch. The switch itself is NOT done here —
    editing mk/* is another batch's ownership, and consolidating the two
    inline SVGs is part of the OQ-05 pass, not of P-1's crash fix.

    Provenance: the real, unmodified Heroicons v2 outline "XMarkIcon"
    (heroicons 2.2.0, `24/outline/x-mark.svg` — MIT-licensed, 24x24
    viewBox, stroke-width 1.5), i.e. OQ-05's own documented assumed
    default ("Outline, 1.5 px" — design-system.md §9.1) and the same
    source the two pre-existing icon files were built from. Not a custom
    drawing. `x-mark` is the genuine Heroicons v2 name (v1 called it
    `x`) — no substitution.

    Distinct from icon/x-circle.blade.php, which §3.7 reserves for the
    `danger` rejection statuses (`DITOLAK`, `DITOLAK_VENDOR`). A close
    control is not a status; do not swap them.

    Accessibility note for whoever does wire this up: a close button is an
    interactive control, so the accessible name belongs on the BUTTON
    (aria-label or visually-hidden text) with this svg left
    aria-hidden="true". This file hardcodes neither — it merges whatever
    the caller passes through $attributes, exactly like the two
    pre-existing icon files.

    No default classes — every icon.* caller supplies its own
    class="size-*" and aria-hidden="true" via $attributes.
--}}
<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" {{ $attributes }}>
    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
</svg>
