<?php

declare(strict_types=1);

/**
 * `public_guest_disabled` exists for exactly one caller: CI's Playwright
 * browser-test job (`.github/workflows/ci.yml`, `browser-test`), which
 * serves the app and drives the test browser from the SAME host. Every
 * test in that suite therefore shares one IP/guest bucket of
 * `RateLimiter::for('public-guest')` (`AppServiceProvider::boot()`) — 26
 * tests' combined page loads and Livewire round trips blow through
 * 60/minute within the run, producing real `429`s that read as broad,
 * unrelated test failures (confirmed 20 Aug 2026: a draft-article 404
 * check received 429, nav-link checks found zero elements because the
 * 429'd homepage rendered no nav).
 *
 * Deliberately NOT gated on `app()->environment('testing')` — that value
 * is ALSO what `phpunit.xml` sets for every ordinary PHPUnit run
 * (`<env name="APP_ENV" value="testing"/>`), including
 * `Tests\Feature\RateLimiting\PublicGuestThrottleTest`, which exists
 * specifically to prove this limiter actually throttles. An
 * `environment('testing')` gate would silently disable the limiter for
 * that whole suite too — confirmed the hard way in this PR's own second
 * CI run, which broke 4 of that test class's assertions. This dedicated,
 * default-false env flag is scoped to nothing but the one CI step that
 * explicitly opts in.
 */
return [
    'public_guest_disabled' => (bool) env('THROTTLE_PUBLIC_GUEST_DISABLED', false),
];
