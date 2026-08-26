{{--
    resources/views/errors/404.blade.php

    Laravel's default error-view lookup renders `resources/views/errors/
    {status}.blade.php` for an unhandled `NotFoundHttpException` (any
    `abort(404)`, e.g. `App\Livewire\Public\Invoices\InvoiceReceiptPage`
    for an unmatched `/kwitansi/{reference}`, or an unmatched route).
    `bootstrap/app.php`'s `withExceptions()` closure does not override
    rendering for this status, so simply having this file is what turns
    the framework's raw, unbranded default 404 page into this one — no
    other wiring is needed.

    Deliberately NOT `->layout('layouts.app', [...])` / `@extends`. That
    layout's footer resolves `App\Support\CompanyInfo::name()`/`address()`
    through `SettingsService` → `site_settings` (a real DB read), and its
    header computes `auth()->check()` and `route('akun.index')`. An error
    boundary is exactly the place those extra dependencies are least safe
    to add — a 404 must render even when something else nearby is
    unhealthy. This file is therefore a minimal, self-contained HTML
    document: same design tokens (`@vite(['resources/css/app.css'])` pulls
    in `resources/css/tokens.css` per that file's own `@import`), same
    `<x-mk.logo>` brand mark (asset-path only, no DB query — see that
    component's own doc block), same empty-state recipe
    `gate-closed-page.blade.php` already established (`flex flex-col
    items-center gap-3 py-12 text-center`, title `text-lg font-semibold
    text-neutral-800`, body `text-base text-neutral-600 max-w-prose`) —
    but no header nav, no footer legal links, no auth-aware account link.

    Copy follows design-system.md §2.1/§2.3: calm, plain Indonesian, no
    apology theatrics, no dead-end — a way back to the homepage, same
    "honest empty state" shape as the marketplace order-tracking page's
    "Pesanan tidak ditemukan" card (`resources/views/livewire/public/
    marketplace/order-tracking.blade.php`).

    `href="/"` (not `route('home')`) — same plain-anchor convention this
    layout's own footer uses for its home link, deliberate here too: an
    error boundary should not depend on the route resolver having
    succeeded for whatever named route it would otherwise reach for.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Halaman tidak ditemukan - Makam.co.id</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-[var(--mk-surface-page)] text-base text-[var(--mk-text-default)] antialiased">
    <main id="main" class="mx-auto flex min-h-screen max-w-content flex-col items-center justify-center gap-3 px-4 py-12 text-center">
        <a href="/" class="mb-4 inline-flex items-center gap-2" aria-label="makam.co.id — beranda">
            <x-mk.logo :size="32" />
        </a>

        <h1 class="text-lg font-semibold text-neutral-800">Halaman tidak ditemukan</h1>

        <p class="max-w-prose text-base text-neutral-600">
            Tautan yang Anda buka mungkin salah ketik atau sudah tidak berlaku.
        </p>

        <div class="pt-2">
            <x-mk.button href="/" variant="primary">Kembali ke beranda</x-mk.button>
        </div>
    </main>
</body>
</html>
