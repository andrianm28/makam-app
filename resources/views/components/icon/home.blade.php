{{--
    resources/views/components/icon/home.blade.php

    <x-dynamic-component :component="'icon.' . $icon" ... /> — the glyph
    for <x-mk.bottom-nav>'s Beranda tab (FFI full-visual-clone design
    doc §3, Stage 2 ticket 02).

    Provenance: real, unmodified Heroicons v2.2.0 outline "HomeIcon"
    (24/outline/home.svg, fetched directly from
    github.com/tailwindlabs/heroicons at tag v2.2.0, MIT-licensed, 24x24
    viewBox, stroke-width 1.5) -- not a custom drawing, matching the
    provenance discipline icon/clock-x.blade.php's own file-header
    documents: a real glyph or nothing, never invented path data.

    No default classes -- every icon.* caller supplies its own
    class="size-*" and aria-hidden="true" via $attributes.
--}}
<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" {{ $attributes }}>
    <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
</svg>
