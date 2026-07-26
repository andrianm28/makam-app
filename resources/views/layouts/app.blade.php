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

    Two NEW links below, `/privasi` and `/syarat-ketentuan`, are NOT in
    information-architecture.md §1's route tree at all (unlike `/bantuan`,
    which IS documented there even though unbuilt) — IA only says the
    footer needs "privacy, terms, contact" without naming their paths. This
    batch picked plausible Indonesian path names, consistent with this
    site's existing convention (`/pemesanan-makam`, `/perpanjangan`,
    `/bantuan`), as an honest forward-reference in the exact same spirit as
    `/bantuan`'s own already-established pattern: a real `<a href>`, not a
    fabricated claim that the page exists today. This is a genuine, named
    spec gap — see this batch's final report — not a silent invention:
    a future product/legal decision may pick different paths, at which
    point this file is the one place to update.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Makam.co.id' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-[var(--mk-surface-page)] text-base text-[var(--mk-text-default)] antialiased">
    <x-mk.header :active="$active ?? null" />

    <main id="main">
        {{ $slot }}
    </main>

    <footer class="bg-primary-900 px-4 py-8 text-neutral-0 md:px-6 lg:px-8">
        <div class="mx-auto flex max-w-content flex-col items-center gap-4 text-center">
            <nav aria-label="Tautan footer" class="flex flex-wrap justify-center gap-x-6 gap-y-2 text-sm">
                <a href="/privasi" class="underline underline-offset-2 hover:text-neutral-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-300 focus-visible:ring-offset-2">Kebijakan Privasi</a>
                <a href="/syarat-ketentuan" class="underline underline-offset-2 hover:text-neutral-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-300 focus-visible:ring-offset-2">Syarat &amp; Ketentuan</a>
                <a href="/bantuan" class="underline underline-offset-2 hover:text-neutral-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-300 focus-visible:ring-offset-2">Bantuan / Kontak</a>
            </nav>
            <p class="text-sm">&copy; {{ date('Y') }} Makam.co.id</p>
        </div>
    </footer>
</body>
</html>
