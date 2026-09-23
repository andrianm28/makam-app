{{--
    resources/views/components/icon/user.blade.php

    <x-dynamic-component :component="'icon.' . $icon" ... /> — the glyph
    for <x-mk.bottom-nav>'s Akun tab (FFI full-visual-clone design doc
    §3, Stage 2 ticket 02).

    Provenance: real, unmodified Heroicons v2.2.0 outline "UserIcon"
    (24/outline/user.svg, fetched directly from
    github.com/tailwindlabs/heroicons at tag v2.2.0, MIT-licensed, 24x24
    viewBox, stroke-width 1.5) -- not a custom drawing, matching the
    provenance discipline icon/clock-x.blade.php's own file-header
    documents: a real glyph or nothing, never invented path data.

    No default classes -- every icon.* caller supplies its own
    class="size-*" and aria-hidden="true" via $attributes.
--}}
<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" {{ $attributes }}>
    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
</svg>
