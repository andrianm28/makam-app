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
    unhealthy.

    REGRESSION FIXED HERE (found via this branch's own CI run, PHP job
    32939718221): the first version of this file used `@vite(['resources/
    css/app.css'])` for styling. `abort(404)` can fire from ANY route in
    the app (a common, everyday occurrence — not just this file's own
    "unmatched route" case), and `Illuminate\Foundation\Exceptions\
    Handler::renderHttpException()` renders THIS view for every one of
    them. That handler wraps the render in try/catch, but its catch block
    is `config('app.debug') && throw $t;` (vendor/laravel/framework/src/
    Illuminate/Foundation/Exceptions/Handler.php) — it only swallows
    quietly when `app.debug` is OFF. This repo's `.env.example` ships
    `APP_DEBUG=true`, and CI's "Prepare test environment" step
    (`.github/workflows/ci.yml`, `php` job) does `cp .env.example .env`
    with nothing overriding that value, and `phpunit.xml` does not set
    `APP_DEBUG` either (PHPUnit's own env vars are set before Dotenv
    loads and are never overwritten by it, but only for vars PHPUnit
    actually sets — `APP_DEBUG` isn't one of them). So `app.debug` is TRUE
    in that job, the catch block RE-THROWS, and the exception propagates
    uncaught. The `php` CI job also never runs `npm run build` (that's a
    separate `frontend` job) — `public/build/manifest.json` genuinely does
    not exist there — so `@vite(...)` threw `ViteManifestNotFoundException`
    on every single `abort(404)` anywhere in the app, turning it into a
    500. Confirmed via ~90 unrelated test failures
    (DocumentVault\DownloadDocumentTest, Payment\VerifyManualPaymentRouteTest,
    etc.) all expecting a clean 404 and getting a 500 instead. The
    established in-repo pattern for real full-page `@vite` renders under
    test (`$this->withoutVite()`, see e.g. `HomePageRouteTest`,
    `BrandIdentityTest`) only helps a test that calls it — it cannot be
    retrofitted onto every test in the suite that merely triggers an
    incidental `abort(404)` as a side effect, so the previous
    implementation's dependency on the asset pipeline was the actual bug,
    not a test-setup gap.

    FIX: this view has zero dependency on `@vite`, `public/build/
    manifest.json`, or the Vite facade — an error boundary must render
    successfully even when the build pipeline is broken or missing, in
    ANY environment, not only this CI job. Styling is a small inline
    `<style>` block using literal colour values instead of Tailwind
    utilities. Per design-system.md's "never hardcode a value" rule this
    would normally be prohibited — this file is a deliberate, narrow,
    documented exception: an error page must survive asset-pipeline
    failure, so it cannot read `resources/css/tokens.css` through the
    normal `@theme`/Tailwind pipeline. Every literal below is copied
    verbatim from `resources/css/tokens.css` (line numbers as of this
    writing) and MUST be kept in sync by hand if that file's values ever
    change:
      --color-neutral-50  #F7F8F8  (tokens.css:81  → --mk-surface-page)
      --color-neutral-0   #FFFFFF  (tokens.css:80)
      --color-neutral-700 #444B4B  (tokens.css:89  → --mk-text-default)
      --color-neutral-800 #2D3333  (tokens.css:90  → heading)
      --color-neutral-600 #576060  (tokens.css:88  → --mk-text-muted)
      --color-primary-600 #563B26  (tokens.css:47  → primary button bg)
      --color-primary-700 #47311F  (tokens.css:48  → primary button hover)
      --radius-md          0.5rem  (tokens.css:219 → button/input radius)
    The font stack is the SAME non-brand fallback chain `--font-sans`
    already ends in (tokens.css:170-171) — no `Poppins`/`Inter var`
    (self-hosted, loaded only through the built stylesheet), just system
    fonts, so there is no dependency on any font file existing either.

    Same `<x-mk.logo>` brand mark as before (asset-path only via
    `asset()`, no DB query, no Vite — see that component's own doc
    block) — kept because `tests/Feature/View/ErrorPagesTest.php` asserts
    on `brand/mark-96.png` appearing in the response, and because it is
    still the right brand mark to show even unstyled. Its own Tailwind
    utility class names (`font-display`, `text-primary-800`, …) are
    inert without the compiled stylesheet — harmless, just unstyled — so
    they are left as-is rather than stripped, to keep the component's
    normal (Vite-available) rendering unchanged everywhere else it's used.

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
    <style>
        /* Literal values copied from resources/css/tokens.css — see this
           file's own doc block above for why and the exact source lines. */
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
        .errpage .errpage-actions { padding-top: 0.5rem; }
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
    </style>
</head>
<body>
    <main id="main" class="errpage">
        <a href="/" class="errpage-logo" aria-label="makam.co.id — beranda">
            <x-mk.logo :size="32" />
        </a>

        <h1>Halaman tidak ditemukan</h1>

        <p>
            Tautan yang Anda buka mungkin salah ketik atau sudah tidak berlaku.
        </p>

        <div class="errpage-actions">
            <a href="/" class="btn btn-primary">Kembali ke beranda</a>
        </div>
    </main>
</body>
</html>
