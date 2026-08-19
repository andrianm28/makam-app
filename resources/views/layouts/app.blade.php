{{--
    resources/views/layouts/app.blade.php

    Minimal public-site layout — did not exist anywhere in this repository
    before this batch (confirmed: no `resources/views/layouts/*` file, same
    "component-only, nothing routes to it yet" state
    gate-closed-page.blade.php's own doc block described for itself).
    Sprint 4 S4-T2 (`public-faq`) needs a real `<html>` document to render
    `/faq` and its two sibling routes into, so this file is that minimal
    document — not a speculative full site shell. It is currently used ONLY
    by the two Livewire FAQ components (`app/Livewire/Public/Faq/**`), which
    is why `<x-mk.header>`'s `active` state below defaults to `'faq'` rather
    than being derived generically; a later batch building another public
    page should generalise this default rather than assume it, and pass its
    own `active` value through `->layout('layouts.app', ['active' => ...])`.

    `lang="id"` — design-system.md §7 / AGENTS.md mobile-first requirement.
    No other page in this repo sets it yet, so this is the first real place
    it is asserted.

    §6.10 support escape hatch: `<x-mk.header>` already renders a persistent
    "Bantuan" action (§3.10) in both its mobile and desktop bars, and the
    footer below repeats a contextual support link — plain `<a href>`, no
    JS dependency, works with JS disabled per §6.10's own requirement.
    `/bantuan` is not yet built in this repository (routes/web.php has no
    such route) — same honest forward-reference `<x-mk.header>` itself
    already makes for its own `bantuanHref` default; not fabricated here.

    Livewire's `->layout('layouts.app', [...])` mechanism injects the
    component's rendered HTML as `$slot` and merges any extra array passed
    as the second argument (`title`, `active`) into this view's own data —
    both are read defensively with `??` so this layout never hard-fails if
    a future caller omits either.

    ---------------------------------------------------------------------------
    UPDATED 17 Aug 2026 (brand identity adoption, Task 4) — real favicon +
    footer brand row
    ---------------------------------------------------------------------------
    `<head>` now carries `<link rel="icon">` / `<link rel="apple-touch-icon">`
    pointing at Task 3's committed `public/favicon.ico` and
    `public/apple-touch-icon.png` (previously this document had no favicon
    at all). The footer gained a brand row — a linked, inverse-variant
    `<x-mk.logo>` — as the FIRST child of the footer's inner flex column,
    above the existing "Tautan footer" nav. This is a pure ADDITION: IA §3
    item 9's footer content (privacy, terms, contact, inverse surface) is
    unchanged, just preceded by the brand mark.

    ---------------------------------------------------------------------------
    UPDATED 26 Jul 2026 (Sprint 4 S4-T3 `public-home-and-navigation`) —
    footer upgraded to the inverse-surface treatment IA §3 item 9 and
    design-system.md's primitives table specify for the homepage
    ---------------------------------------------------------------------------
    design-system.md §4.1's page-shell diagram draws the footer as a
    page-shell element (same level as the header), not per-page content, and
    §4.5's homepage section 9 is "Footer — privacy, terms, contact,
    inverse surface (--mk-surface-inverse / --color-primary-900), white
    text verified 14.40:1". Rather than stack a SECOND, homepage-specific
    footer under `livewire/public/home-page.blade.php`'s own content
    (duplicating this markup, and leaving every other public page, e.g.
    `/faq`, on the old lighter footer), this one shared footer was upgraded
    in place — it now applies to every page that uses this layout, which is
    a coherent site-wide improvement, not a homepage-only skin.
    `bg-primary-900 text-neutral-0` are both direct Tailwind utilities for
    already-`@theme`-registered primitives (`--color-primary-900`,
    `--color-neutral-0`) — no `var(--mk-*)` bracket syntax needed, since
    those primitives already have generated utility names.

    Two links below, `/privasi` and `/syarat-ketentuan`, are still NOT in
    information-architecture.md §1's route tree (unlike `/bantuan`, which
    IS documented there even though unbuilt) — IA only says the footer
    needs "privacy, terms, contact" without naming their paths. This batch
    picked plausible Indonesian path names, consistent with this site's
    existing convention (`/pemesanan-makam`, `/perpanjangan`, `/bantuan`).
    That naming-authority gap is still real (see this batch's final
    report) — a future product/legal decision may pick different paths,
    at which point `routes/web.php`'s `legal.privacy`/`legal.terms` route
    names are the one place to repoint.

    ---------------------------------------------------------------------------
    UPDATED 26 Jul 2026 (legal pages batch) — gap CLOSED, links now real routes
    ---------------------------------------------------------------------------
    The note above previously described these two links as an unresolved
    forward-reference — plain `<a href="/privasi">` pointing at nothing,
    same honest-gap pattern as `/bantuan`'s. That is no longer true: `App\
    Livewire\Public\Legal\PrivacyPolicy` and `TermsOfService` now back both
    paths (routes/web.php's `legal.privacy` / `legal.terms`), so the two
    links below were switched from raw hrefs to `route()` calls. `/bantuan`
    is intentionally UNCHANGED — it remains a real, honest forward-reference
    (no route exists for it yet), not touched by this batch.

    The muted company-info line below the nav is placeholder legal-entity
    data — a fictional PT name and a "Jl. Contoh ..." placeholder address
    until an operator configures a real one via the admin Site Settings
    page — the same honest-placeholder convention `2026_07_26_190300_seed_
    cemeteries_and_capability_profiles.php` already established in this
    codebase. Read from `App\Support\CompanyInfo::name()`/`::address()` —
    the one place this data is resolved, also used by `PrivacyPolicy`'s and
    `TermsOfService`'s own views — not hardcoded a second time here.

    ---------------------------------------------------------------------------
    UPDATED 19 Aug 2026 (public-beta-release plan, Lane C4) — persistent beta banner
    ---------------------------------------------------------------------------
    Plan's own words: "given the payment decision, this is the single
    cheapest risk-reducer on the whole list." A non-dismissible
    `<x-mk.alert>` at the very top of the page shell, ABOVE `<x-mk.header>`
    so it is the first thing on every page using this layout, not inside
    `<main>` (it is chrome, not page content, same category as the header/
    footer). `intent="pending"` (the calmer of the two warning-family
    intents) deliberately, not `urgent` — the louder `urgent` intent is
    reserved for `App\Livewire\Public\Booking\BookingWizard`'s own
    payment-step-specific warning (Step 8, right before the "Bayar
    Sekarang" redirect), which needs to read as more severe than this
    ambient, every-page notice. `live="off"`: present on initial load, not
    a dynamic update, per the same convention `alert.blade.php`'s own file
    header documents for ambient/pre-existing notices — actually `off`
    specifically (not `polite`) because this is permanent page chrome, not
    a state that changes during the visit; nothing here needs a live-region
    announcement at all. Never conditional on route/gate state: it applies
    uniformly whether `G-PAY-01` is open or closed, because the beta status
    itself — not just the payment mode — is what customers need to know.

    Deliberately NO `<a href="/bantuan">` inside this banner (plain text
    "Bantuan" instead): `<x-mk.header>`'s own "Lewati ke konten utama" skip
    link (header.blade.php) must be the FIRST focusable element in the DOM
    so a keyboard user's very first Tab reaches it — that is the entire
    point of a skip link. This banner sits before `<x-mk.header>` in markup
    order (see above for why), so any real link inside it would land ahead
    of the skip link in tab order and silently defeat it. The header's own
    persistent "Bantuan" action (§3.10, already present in both its mobile
    and desktop bars) is the real, reachable link — this banner does not
    need to duplicate it.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Makam.co.id' }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-[var(--mk-surface-page)] text-base text-[var(--mk-text-default)] antialiased">
    <div class="px-4 pt-4 md:px-6 lg:px-8">
        <div class="mx-auto max-w-content">
            <x-mk.alert intent="pending" title="Makam.co.id versi Beta" live="off" :dismissible="false">
                <p class="text-sm">
                    Anda sedang menggunakan versi <strong>beta publik</strong>. Sebagian fitur masih dalam
                    tahap uji coba dan dapat berubah sewaktu-waktu, termasuk pembayaran online yang saat ini
                    berjalan dalam mode simulasi (sandbox) &mdash; tidak ada transaksi finansial nyata yang
                    terjadi melaluinya. Hubungi tim Bantuan jika Anda menemukan kendala.
                </p>
            </x-mk.alert>
        </div>
    </div>

    <x-mk.header :active="$active ?? null" />

    <main id="main">
        {{ $slot }}
    </main>

    <footer class="bg-primary-900 px-4 py-8 text-neutral-0 md:px-6 lg:px-8">
        <div class="mx-auto flex max-w-content flex-col items-center gap-4 text-center">
            <a href="/" class="inline-flex items-center gap-2" aria-label="makam.co.id — beranda">
                <x-mk.logo variant="inverse" :size="28" />
            </a>
            <nav aria-label="Tautan footer" class="flex flex-wrap justify-center gap-x-6 gap-y-2 text-sm">
                <a href="{{ route('legal.privacy') }}" class="underline underline-offset-2 hover:text-neutral-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-300 focus-visible:ring-offset-2">Kebijakan Privasi</a>
                <a href="{{ route('legal.terms') }}" class="underline underline-offset-2 hover:text-neutral-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-300 focus-visible:ring-offset-2">Syarat &amp; Ketentuan</a>
                <a href="/bantuan" class="underline underline-offset-2 hover:text-neutral-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-300 focus-visible:ring-offset-2">Bantuan / Kontak</a>
            </nav>
            <p class="text-sm">&copy; {{ date('Y') }} Makam.co.id</p>
            <p class="text-xs text-primary-200">{{ \App\Support\CompanyInfo::name() }} &middot; {{ \App\Support\CompanyInfo::address() }}</p>
        </div>
    </footer>
</body>
</html>
