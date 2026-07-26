{{--
    resources/views/components/mk/logo.blade.php

    <x-mk.logo> — placeholder brand mark. design-system.md OQ-02 is explicit
    that no verified Makam.co.id brand identity (logo, colour, typeface)
    exists — the live `makam.co.id` site is a static 14 KB landing page,
    deliberately not treated as brand authority anywhere in this system.

    This mark is a plain wordmark-derived monogram ("M" in a rounded badge,
    primary-600), not a claim to a finished, approved identity — the same
    honesty framing this codebase already applies to prices, coordinates,
    and contact details it doesn't have real values for yet. It exists so
    the header isn't bare text while a real logo (OQ-02) is pending; a
    future batch swaps this one file's markup for the real asset, nothing
    else in the header changes.

    Tokens only: --color-primary-600 fill, --color-neutral-0 glyph — no new
    hue, no arbitrary values.
--}}
@props([
    'size' => 32,
])

<span class="inline-flex items-center gap-2">
    <svg
        width="{{ $size }}"
        height="{{ $size }}"
        viewBox="0 0 32 32"
        fill="none"
        xmlns="http://www.w3.org/2000/svg"
        aria-hidden="true"
        {{ $attributes->merge(['class' => 'shrink-0']) }}
    >
        <rect width="32" height="32" rx="8" class="fill-primary-600" />
        <path
            d="M9 22V10.5C9 10.2239 9.22386 10 9.5 10H11.2C11.393 10 11.5685 10.112 11.6515 10.2864L15.7 18.8C15.8548 19.1238 16.3199 19.1195 16.4688 18.7931L20.3252 10.2989C20.4064 10.1198 20.5851 10.0044 20.7818 10.0022L22.5 9.98315C22.7772 9.98008 23 10.2005 23 10.4778V22"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            class="stroke-neutral-0"
        />
    </svg>
</span>
