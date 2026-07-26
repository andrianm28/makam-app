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

    <footer class="border-t border-neutral-200 bg-neutral-0 px-4 py-8 text-sm text-neutral-600">
        <div class="mx-auto flex max-w-content flex-col items-center gap-2 text-center">
            <p>
                Butuh bantuan lebih lanjut?
                <a href="/bantuan" class="text-primary-600 underline underline-offset-2 hover:text-primary-700">Hubungi Customer Service</a>
            </p>
            <p>&copy; {{ date('Y') }} Makam.co.id</p>
        </div>
    </footer>
</body>
</html>
