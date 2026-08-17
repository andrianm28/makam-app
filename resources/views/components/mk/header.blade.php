{{--
    resources/views/components/mk/header.blade.php

    <x-mk.header> — design-system.md §3.10, information-architecture.md §2.

    Menu labels are a PRODUCT CONTRACT (IA §2, AGENTS.md navigation
    invariants) — hardcoded below, not exposed as props, so nothing can
    reword, reorder, or hide any of the four without a deliberate code
    change to this file:
        Pemesanan Makam · Layanan Pemakaman · Perpanjangan Makam · FAQ
    Identical on mobile and desktop (IA §2: "menu labels tetap sama dengan
    desktop"). All four are visible without login (IA §4) — none of this
    component's markup gates them behind an `authenticated` check.

    Routes default to the exact paths documented in
    information-architecture.md §1 (`/pemesanan-makam`, `/marketplace` for
    Layanan Pemakaman, `/perpanjangan`, `/faq`, `/akun`, `/bantuan`) and are
    overridable via props for a consumer with a named-route helper — no path
    beyond what §1 documents is introduced.

    Two structurally different layouts, same reasoning as stepper.blade.php:
      - Mobile (< lg): hamburger + logo + persistent Bantuan action.
      - Desktop (lg+): full horizontal nav, all items visible, no hamburger.
    Class composition follows button.blade.php: one PHP block, a lookup
    table for active/inactive nav classes, `neutral-0` instead of Tailwind's
    `white`. The Bantuan action reuses <x-mk.button> rather than
    re-implementing button styling here.

    FIXED 13 Aug 2026 — hamburger toggle now works via vanilla JS in
    resources/js/app.js (the behaviour layer this markup was prepared for).
    No Alpine dependency was added (none is installed); the handler toggles
    the panel's `hidden` class and keeps `aria-expanded` in sync for every
    `header [aria-controls]` button. The mobile panel's accessible name is
    "Menu utama (seluler)" — distinct from the desktop nav's "Menu utama"
    so the two landmarks do not collide on axe `landmark-unique`, while the
    visible labels stay identical per IA §2.

    UPDATED 17 Aug 2026 (brand identity adoption, Task 4) — the hardcoded
    "Makam.co.id" text node after each `<x-mk.logo>` call was deleted at
    both call sites (mobile and desktop bars). `<x-mk.logo>` now renders
    its own live-text wordmark (font-display, lowercase "makam.co.id") as
    part of the component itself, so repeating it here would have
    duplicated the accessible name. The anchors' own `text-lg`/`text-xl`
    font-size classes are kept — the wordmark span inherits size from its
    ancestor; its own weight/colour come from the component.
--}}
@props([
    'active' => null,          // 'pemesanan' | 'layanan' | 'perpanjangan' | 'faq' | null
    'authenticated' => false,
    'accountLabel' => null,        // override for the Masuk/Akun text
    'pemesananHref' => '/pemesanan-makam',
    'layananHref' => '/marketplace',
    'perpanjanganHref' => '/perpanjangan',
    'faqHref' => '/faq',
    'akunHref' => null,            // null = account area not built yet (see below); pass a real URL once it ships
    'bantuanHref' => '/bantuan',
    'logoHref' => '/',
    'id' => 'mk-header',
])

@php
    // Canonical, ordered per IA §2 — do not reorder.
    $navItems = [
        'pemesanan'     => ['label' => 'Pemesanan Makam', 'href' => $pemesananHref],
        'layanan'       => ['label' => 'Layanan Pemakaman', 'href' => $layananHref],
        'perpanjangan'  => ['label' => 'Perpanjangan Makam', 'href' => $perpanjanganHref],
        'faq'           => ['label' => 'FAQ', 'href' => $faqHref],
    ];

    $accountText = $accountLabel ?? ($authenticated ? 'Akun' : 'Masuk/Akun');

    // The customer account area (information-architecture.md §1's /akun
    // tree — /akun/draft, /akun/pesanan, /akun/perpanjangan, /akun/dokumen)
    // has no route registered anywhere in routes/web.php; it has not been
    // built. Until a caller passes a real $akunHref, this renders as an
    // honest disabled control instead of a link that 404s — same "markup
    // is correct, behaviour layer not written yet" pattern this file
    // already uses for the mobile hamburger (see KNOWN GAP above).
    $akunAvailable = filled($akunHref);
    $mobileMenuId = $id . '-mobile-menu';

    // §3.10: active item = text-primary-700 font-medium + 2px primary-600
    // bottom border + aria-current="page". Inactive keeps a transparent
    // border of the same width so the active state never shifts layout.
    $activeClasses = 'text-primary-700 font-medium border-b-2 border-primary-600';
    $inactiveClasses = 'text-neutral-700 border-b-2 border-transparent hover:text-primary-700 hover:border-primary-300';
@endphp

<header {{ $attributes->merge(['class' => 'relative']) }}>
    {{-- Skip link — first focusable element (§3.10, §7.2). Hidden until
         focused, then pinned top-left at z-skiplink. `focus-visible:outline-*`
         overrides the global focus ring colour because this control sits on
         a primary-600 fill once focused, where the default ring colour would
         be invisible — §7.2 names --mk-focus-color-inverse (primary-300) for
         exactly this case. --}}
    <a
        href="#main"
        class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-skiplink focus:inline-flex focus:items-center focus:rounded-md focus:bg-primary-600 focus:px-4 focus:py-2 focus:text-base focus:font-medium focus:text-neutral-0 focus-visible:outline-primary-300"
    >
        Lewati ke konten utama
    </a>

    {{-- Mobile bar (< lg) — 56px, sticky, safe-area top inset. --}}
    <div class="lg:hidden sticky top-0 z-header flex h-[var(--mk-header-h)] items-center justify-between gap-3 border-b border-neutral-200 bg-neutral-0 px-4 pt-[var(--mk-safe-top)]">
        <button
            type="button"
            aria-expanded="false"
            aria-controls="{{ $mobileMenuId }}"
            class="touch-target inline-flex shrink-0 items-center justify-center rounded-md text-neutral-700 hover:bg-neutral-100"
        >
            {{-- Same icon.* dynamic-component convention as button.blade.php;
                 the icon set itself is not yet built (design-system.md OQ-05). --}}
            <x-dynamic-component component="icon.bars-3" class="size-6" aria-hidden="true" />
            <span class="sr-only">Buka menu navigasi</span>
        </button>

        <a href="{{ $logoHref }}" class="flex min-w-0 flex-1 items-center justify-center gap-2 text-lg font-semibold text-neutral-900">
            <x-mk.logo :size="28" />
        </a>

        {{-- Persistent Bantuan action — mandatory, never collapsed into the
             hamburger menu (IA §2, §3.10). Default (md, 44px) size: §3.1 is
             explicit that `sm` (36px) is "forbidden on any public/mobile
             surface" and exists only for dense Filament table rows. Someone
             reaching for this button is often doing so under real stress —
             it does not get the smallest touch target in the header.

             FIXED 26 Jul 2026 — first real CI run of Sprint 4's public-faq
             batch: hand-written, not <x-mk.button>, for the same reason
             resources/views/livewire/public/faq/index.blade.php's own doc
             comment explains at length (every <x-mk.button> usage rendered
             through a real Livewire full-page component fails with
             "Undefined variable $loading" — this component is the shared
             header, rendered on every page via layouts/app.blade.php, so it
             hits the same bug the FAQ views' own buttons did). See
             docs/planning/sprint-plan.md finding N-14.

             CORRECTED 08 Aug 2026 (Batch 0, P-3) — root-caused and fixed; see
             faq/index.blade.php's own doc comment for the mechanism. New
             Livewire views MAY use <x-mk.button> directly. This header's own
             hand-written Bantuan buttons are correct, working code, rendered
             on every page; migrating them is optional cleanup, not
             required. --}}
        <a
            href="{{ $bantuanHref }}"
            class="shrink-0 inline-flex h-11 select-none items-center justify-center gap-2 rounded-md border border-primary-600 bg-neutral-0 px-4 text-base font-medium text-primary-700 transition-[color,background-color,border-color,box-shadow] duration-fast ease-standard hover:bg-primary-50 active:bg-primary-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 focus-visible:ring-offset-2"
        >
            Bantuan
        </a>
    </div>

    {{-- Mobile menu panel — the hamburger's target. Stays `hidden` until a
         behaviour layer toggles it; see the file-header comment. `lg:hidden`
         is defensive: even if that layer removes the `hidden` class, the
         panel must never show once the desktop nav below takes over. --}}
    <nav id="{{ $mobileMenuId }}" aria-label="Menu utama (seluler)" class="hidden lg:hidden border-b border-neutral-200 bg-neutral-0 px-4 py-2">
        <ul class="flex flex-col">
            @foreach ($navItems as $key => $item)
                <li>
                    <a
                        href="{{ $item['href'] }}"
                        @if ($active === $key) aria-current="page" @endif
                        class="touch-target flex items-center text-base {{ $active === $key ? 'text-primary-700 font-medium' : 'text-neutral-700' }}"
                    >
                        {{ $item['label'] }}
                    </a>
                </li>
            @endforeach
            <li>
                @if ($akunAvailable)
                    <a href="{{ $akunHref }}" class="touch-target flex items-center text-base text-neutral-700">
                        {{ $accountText }}
                    </a>
                @else
                    <span
                        class="touch-target flex items-center text-base text-neutral-400 cursor-not-allowed"
                        aria-disabled="true"
                        title="Segera hadir"
                    >
                        {{ $accountText }}
                    </span>
                @endif
            </li>
        </ul>
    </nav>

    {{-- Desktop bar (lg+) — 72px, full horizontal nav, no hamburger. --}}
    <div class="hidden lg:flex sticky top-0 z-header h-[var(--mk-header-h-lg)] items-center justify-between gap-6 border-b border-neutral-200 bg-neutral-0 px-8">
        <a href="{{ $logoHref }}" class="flex shrink-0 items-center gap-2 text-xl font-semibold text-neutral-900">
            <x-mk.logo :size="32" />
        </a>

        <nav aria-label="Menu utama" class="flex h-full items-center gap-6">
            @foreach ($navItems as $key => $item)
                <a
                    href="{{ $item['href'] }}"
                    @if ($active === $key) aria-current="page" @endif
                    class="inline-flex h-full items-center text-base {{ $active === $key ? $activeClasses : $inactiveClasses }}"
                >
                    {{ $item['label'] }}
                </a>
            @endforeach
        </nav>

        <div class="flex shrink-0 items-center gap-4">
            @if ($akunAvailable)
                <a href="{{ $akunHref }}" class="text-base text-neutral-700 hover:text-primary-700">
                    {{ $accountText }}
                </a>
            @else
                <span
                    class="text-base text-neutral-400 cursor-not-allowed"
                    aria-disabled="true"
                    title="Segera hadir"
                >
                    {{ $accountText }}
                </span>
            @endif
            {{-- Same md (default) size as the mobile bar's Bantuan button —
                 see that comment. `sm` is reserved for dense Filament table
                 rows, not a public header. Hand-written, not <x-mk.button> —
                 see the mobile bar's own Bantuan button comment above. --}}
            <a
                href="{{ $bantuanHref }}"
                class="inline-flex h-11 select-none items-center justify-center gap-2 rounded-md border border-primary-600 bg-neutral-0 px-4 text-base font-medium text-primary-700 transition-[color,background-color,border-color,box-shadow] duration-fast ease-standard hover:bg-primary-50 active:bg-primary-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 focus-visible:ring-offset-2"
            >
                Bantuan
            </a>
        </div>
    </div>
</header>
