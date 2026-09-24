{{--
    resources/views/components/icon/magnifying-glass.blade.php

    <x-dynamic-component :component="'icon.' . $icon" ... /> — the search
    icon rendered inside the header's search bar (FFI-clone pixel-fidelity
    ticket 01). FFI's own real header search input uses a hand-copied,
    slightly older/simplified variant of this glyph (confirmed by reading
    DesktopHeader.tsx directly); this file uses the CURRENT real Heroicons
    v2.2.0 path instead, per this project's own established
    icon-provenance discipline (verify against the real upstream source,
    never trust a secondary copy, even FFI's own).

    Provenance: the real, unmodified Heroicons v2.2.0 outline
    "MagnifyingGlassIcon" (`24/outline/magnifying-glass.svg`, fetched
    directly from raw.githubusercontent.com/tailwindlabs/heroicons at the
    v2.2.0 tag — MIT-licensed, 24x24 viewBox, stroke-width 1.5). Not a
    custom drawing, not FFI's own copy.

    No default classes — every icon.* caller supplies its own
    class="size-*" and aria-hidden="true" via $attributes.
--}}
<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" {{ $attributes }}>
    <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
</svg>
