<?php

declare(strict_types=1);
use App\Platform\Observability\SentryEventScrubber;

return [
    'dsn' => env('SENTRY_LARAVEL_DSN'),

    // AGENTS.md §Observability: "Never place restricted data in logs,
    // Pulse, Horizon tags, or error trackers." This is the flag that
    // makes that true for Sentry's own default PII collection — must
    // stay false; the before_send scrubber below is a second layer,
    // not a substitute for this.
    'send_default_pii' => false,

    'traces_sample_rate' => (float) env('SENTRY_TRACES_SAMPLE_RATE', 0.0),

    'environment' => env('APP_ENV'),

    'release' => env('SENTRY_RELEASE'),

    /*
    |--------------------------------------------------------------------------
    | before_send scrubber
    |--------------------------------------------------------------------------
    |
    | Per docs/superpowers/plans/2026-08-18-public-beta-release.md Lane E2's
    | original spec (observability-stack.md §3/§5): scrub NIK/KK (Indonesian
    | national/family ID numbers) and signed document-vault URLs before
    | transmission — AGENTS.md §Observability's "never place restricted data
    | in... error trackers" applies to this exact surface. Also attaches the
    | existing correlation ID as a tag so a Sentry error links back to its
    | outbox event / journal entry via the same id used everywhere else in
    | this codebase's tracing (AssignCorrelationId middleware).
    |
    | Final-review C2: an inline closure here breaks `php artisan
    | config:cache` (var_export() can't serialize a Closure). An array
    | callable is a plain string array, so it survives var_export() intact
    | — this is also the exact pattern Sentry's own Laravel filtering docs
    | use for before_send. See SentryEventScrubber's own class-level doc
    | block for why this stayed a config-file callable reference rather
    | than moving into a service provider's boot().
    |
    */
    'before_send' => [SentryEventScrubber::class, 'scrub'],
];
