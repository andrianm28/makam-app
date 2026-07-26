{{--
    resources/views/livewire/public/coming-soon.blade.php

    Shared view for the three "not yet built this sprint" stub routes
    (`/pemesanan-makam`, `/marketplace`, `/perpanjangan`) — requirements.md
    AC6 ("display an explanatory state instead of a dead link or a generic
    404"), read expansively per this batch's own brief: AC6's literal text
    names FEATURE-GATED destinations specifically, but the same
    "explanatory page, never a bare 404" principle applies here for an
    honestly DIFFERENT reason (not built yet this sprint, not gated by any
    of the 17 documented gates) — see each `App\Livewire\Public\
    ComingSoon\*` component's own doc block and this batch's final report.

    --- Deliberately NOT <x-mk.gate-closed-page> ---
    That component's own doc block scopes its copy to a real CLOSED
    FEATURE GATE: "the capability exists but is not yet available" + "the
    documented fallback path" (its own words — read in full before this
    decision was made). There is no fallback path for "not built yet"
    (unlike, say, `PaymentMode::ManualCoordination`'s real manual-payment
    fallback), and no `G-*` gate governs whether any of these three routes
    exist. Using that component here would misrepresent WHY the page is
    unavailable — exactly the "do NOT claim it is gated by a feature flag
    if the real reason is simply not built yet" distinction this batch was
    told to preserve. This view instead reuses design-system.md §6.2's
    empty-state LAYOUT recipe (`flex flex-col items-center gap-3 py-12
    text-center`, title `text-lg font-semibold text-neutral-800`, body
    `max-w-prose text-base text-neutral-600`) for visual consistency with
    every other "nothing to show here, here is why" screen in this
    codebase, without borrowing `gate-closed-page`'s gate-specific framing
    or its `fallback`/`support` slot names.

    The header (four menus, IA §2) still renders above this via
    `layouts/app.blade.php` — a visitor landing here is never stuck; every
    other part of the site remains one tap away. Hand-written buttons, not
    `<x-mk.button>` — this file lives under `resources/views/livewire/**`,
    same N-14 convention as every other Livewire full-page view in this
    repo (see `home-page.blade.php`'s own doc block for the full citation).

    Contact line below uses `App\Support\ContactInfo` — see that class's
    own doc block for why this is clearly-plausible placeholder data,
    explicitly authorized by the user for full public display on
    dev.makam.co.id, not a real support line.
--}}
@php
    use App\Support\ContactInfo;
@endphp
<div class="flex flex-col items-center gap-3 px-4 py-12 text-center">
    <h1 class="text-lg font-semibold text-neutral-800">{{ $heading }}</h1>
    <p class="max-w-prose text-base text-neutral-600">{{ $body }}</p>
    <p class="text-sm text-neutral-600">{{ ContactInfo::PHONE }} · {{ ContactInfo::EMAIL }}</p>
    <div class="mt-2 flex flex-wrap justify-center gap-3">
        <a
            href="/"
            class="inline-flex h-11 select-none items-center justify-center gap-2 rounded-md border border-primary-600 bg-neutral-0 px-4 text-base font-medium text-primary-700 transition-[color,background-color,border-color,box-shadow] duration-fast ease-standard hover:bg-primary-50 active:bg-primary-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 focus-visible:ring-offset-2"
        >
            Kembali ke Beranda
        </a>
        <a
            href="/bantuan"
            class="inline-flex h-11 select-none items-center justify-center gap-2 rounded-md border border-transparent bg-primary-600 px-4 text-base font-medium text-neutral-0 transition-[color,background-color,border-color,box-shadow] duration-fast ease-standard hover:bg-primary-700 active:bg-primary-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 focus-visible:ring-offset-2"
        >
            Hubungi Bantuan
        </a>
    </div>
</div>
