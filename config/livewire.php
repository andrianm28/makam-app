<?php

declare(strict_types=1);

/**
 * Minimal override of `vendor/livewire/livewire/config/livewire.php` — only
 * `csp_safe` is set here; every other key falls back to the package's own
 * default via Laravel's standard `mergeConfigFrom()` package-config merge
 * (`LivewireServiceProvider::boot()`), so this file does not fork or
 * duplicate the rest of Livewire's configuration surface.
 *
 * ---------------------------------------------------------------------------
 * Why this exists — SEC-08's enforcing-CSP switch broke real clicks
 * ---------------------------------------------------------------------------
 * `App\Http\Middleware\ReportContentSecurityPolicy`'s `script-src` carries
 * no `unsafe-eval`, by design — that is the whole point of a nonce-scoped
 * policy. Livewire's REGULAR bundle (`livewire.js`/`livewire.min.js`, the
 * default when this key is `false`) parses `wire:click="method(args)"`-style
 * directive expressions — and the Alpine.js it bundles internally parses
 * `x-on:*`/`x-data` expressions — via `new Function()`, which real CSP
 * enforcement blocks outright. Under `Content-Security-Policy-Report-Only`
 * this was silently masked: report-only never blocks anything, so the eval
 * calls kept succeeding and the violation was only ever logged, never felt.
 * The instant SEC-08 (a same-day PR) flipped the header to genuinely
 * enforcing, every `wire:click` with an argument — for example the booking
 * wizard's city-selection buttons — stopped working: the click fired, the
 * browser silently blocked the eval Livewire needed to parse
 * `saveStep1('JAKARTA')` into a method name and argument list, and nothing
 * happened. Caught by `tests/browser/e2e-a11y-interaction.spec.ts`'s real
 * Chromium run in CI, which report-only-mode's own Playwright coverage
 * never exercised the same way (report-only doesn't stop the click from
 * working, so nothing there could have caught this).
 *
 * `csp_safe => true` switches Livewire's asset injector
 * (`FrontendAssets::isCspSafe()`) to serve `livewire.csp.js`/
 * `livewire.csp.min.js` instead — Livewire's own official answer to this
 * exact class of problem, built without `eval`/`new Function`, at the cost
 * of a more restricted directive grammar (no arbitrary JS expressions,
 * only plain method calls, literal arguments, simple assignments, and a
 * fixed set of supported magics like `$set`/`$dispatch`/`$toggle`).
 *
 * Verified compatible before flipping this, not assumed: every `wire:*`
 * directive in `resources/views/` was grepped and is a plain method call
 * or a literal-argument call (Blade/PHP interpolation resolves any
 * `{{ }}`/string-concat before the browser ever sees the attribute, so
 * `wire:click="goToStep({{ BookingWizardStep::LOCATION }})"` reaches the
 * browser as the literal `wire:click="goToStep(1)"`), and the only
 * hand-written Alpine usage in the app (`x-data="{ dismissed: false }"`,
 * `x-on:click="dismissed = true"`, `x-on:click="$dispatch('close-modal',
 * { id: '...' })"`) is exactly the simple-literal/simple-assignment/
 * supported-magic shape the CSP-safe Alpine evaluator handles. No
 * `$event`, `$refs`, ternaries, string concatenation, or template literals
 * appear in any directive anywhere in this codebase.
 */
return [
    'csp_safe' => true,
];
