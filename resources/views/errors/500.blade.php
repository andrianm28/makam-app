{{--
    resources/views/errors/500.blade.php

    Same treatment as `errors/404.blade.php` — read that file's doc block
    for the full regression writeup (why `@vite` on an error-boundary view
    turned every incidental `abort(404)`/`abort(500)` anywhere in the app
    into a 500 during this branch's own CI run, and why `$this->withoutVite()`
    in individual tests cannot fix that: it only helps the tests that call
    it, not the ~90 unrelated tests that merely trigger one of these as a
    side effect). Same fix here: zero dependency on `@vite`, `public/build/
    manifest.json`, or the Vite facade — a minimal inline `<style>` block
    using literal colour values copied verbatim from `resources/css/
    tokens.css` (documented, narrow exception to "never hardcode a value" —
    an error boundary must render even when the build pipeline itself is
    broken, which is exactly the kind of failure a 500 page exists for).

    A 500 is exactly the case where the app may be unhealthy (DB down,
    queue worker crashed, an unexpected exception) — this view MUST NOT
    add any dependency (DB read, named route, auth check, Vite/build
    pipeline) beyond what 404's already-justified-minimal page uses, or
    the error page itself could throw while rendering.

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
    <style>
        /* Literal values copied from resources/css/tokens.css — see
           errors/404.blade.php's doc block for the exact source lines. */
        body {
            margin: 0;
            min-height: 100vh;
            background: #F7F8F8;
            color: #444B4B;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            -webkit-font-smoothing: antialiased;
        }
        main.errpage {
            box-sizing: border-box;
            margin: 0 auto;
            min-height: 100vh;
            max-width: 40rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            padding: 3rem 1rem;
            text-align: center;
        }
        .errpage a.errpage-logo { display: inline-flex; align-items: center; gap: 0.5rem; margin-bottom: 1rem; text-decoration: none; }
        .errpage h1 { font-size: 1.125rem; font-weight: 600; color: #2D3333; margin: 0; }
        .errpage p { max-width: 42rem; font-size: 1rem; color: #576060; margin: 0; }
        .errpage .errpage-actions { display: flex; flex-wrap: wrap; align-items: center; justify-content: center; gap: 0.75rem; padding-top: 0.5rem; }
        .errpage .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 2.75rem;
            padding: 0 1rem;
            border-radius: 0.5rem;
            font-weight: 500;
            text-decoration: none;
        }
        .errpage .btn-primary { background: #563B26; color: #FFFFFF; }
        .errpage .btn-primary:hover { background: #47311F; }
        .errpage .btn-secondary { background: #FFFFFF; color: #563B26; border: 1px solid #563B26; }
        .errpage .btn-secondary:hover { background: #F7F8F8; }
    </style>
</head>
<body>
    <main id="main" class="errpage">
        <a href="/" class="errpage-logo" aria-label="makam.co.id — beranda">
            <x-mk.logo :size="32" />
        </a>

        <h1>Terjadi kesalahan</h1>

        <p>
            Terjadi kesalahan pada sistem kami. Silakan coba lagi beberapa saat lagi.
        </p>

        <div class="errpage-actions">
            <a href="{{ url()->current() }}" class="btn btn-primary">Coba lagi</a>
            <a href="/" class="btn btn-secondary">Kembali ke beranda</a>
        </div>
    </main>
</body>
</html>
