{{--
    resources/views/errors/500.blade.php

    Same treatment as `errors/404.blade.php` (read that file's doc block
    for the full rationale — minimal self-contained document, tokens via
    `@vite(['resources/css/app.css'])`, `<x-mk.logo>`, `gate-closed-page`'s
    empty-state recipe, no DB-touching layout). Added alongside the 404
    page for the same "honest, on-brand empty state everywhere" reason:
    an unhandled server error should not fall through to Laravel's raw
    default 500 page either.

    A 500 is exactly the case where the app may be unhealthy (DB down,
    queue worker crashed, an unexpected exception) — this view MUST NOT
    add any dependency (DB read, named route, auth check) beyond what
    404's already-justified-minimal page uses, or the error page itself
    could throw while rendering.

    Copy is deliberately generic per the task brief ("something went
    wrong, try again") and design-system.md §2.3: no blame, no technical
    detail (never renders `$exception`), a "coba lagi" reload action
    alongside the same "back to homepage" escape hatch 404 offers.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Terjadi kesalahan - Makam.co.id</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-[var(--mk-surface-page)] text-base text-[var(--mk-text-default)] antialiased">
    <main id="main" class="mx-auto flex min-h-screen max-w-content flex-col items-center justify-center gap-3 px-4 py-12 text-center">
        <a href="/" class="mb-4 inline-flex items-center gap-2" aria-label="makam.co.id — beranda">
            <x-mk.logo :size="32" />
        </a>

        <h1 class="text-lg font-semibold text-neutral-800">Terjadi kesalahan</h1>

        <p class="max-w-prose text-base text-neutral-600">
            Terjadi kesalahan pada sistem kami. Silakan coba lagi beberapa saat lagi.
        </p>

        <div class="flex flex-wrap items-center justify-center gap-3 pt-2">
            <x-mk.button href="{{ url()->current() }}" variant="primary">Coba lagi</x-mk.button>
            <x-mk.button href="/" variant="secondary">Kembali ke beranda</x-mk.button>
        </div>
    </main>
</body>
</html>
