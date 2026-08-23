<?php

declare(strict_types=1);

use App\Platform\IdentityAccess\Contracts\IdentityAccessAdapter;
use App\Platform\IdentityAccess\Panel\AdminPanelAccessPolicy;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Pulse\Recorders\CacheInteractions;
use Laravel\Pulse\Recorders\Queues;
use Laravel\Pulse\Recorders\SlowJobs;
use Laravel\Pulse\Recorders\SlowQueries;
use Laravel\Pulse\Recorders\SlowRequests;

return [
    'enabled' => env('PULSE_ENABLED', true),

    'domain' => env('PULSE_DOMAIN'),

    'path' => env('PULSE_PATH', 'pulse'),

    'storage' => [
        'driver' => env('PULSE_STORAGE_DRIVER', 'database'),
        'trim' => [
            'keep' => env('PULSE_STORAGE_TRIM_KEEP', '7 days'),
        ],
        'database' => [
            'connection' => env('PULSE_DB_CONNECTION'),
            'chunk' => 1000,
        ],
    ],

    'ingest' => [
        'driver' => env('PULSE_INGEST_DRIVER', 'storage'),
        'buffer' => env('PULSE_INGEST_BUFFER', 5000),
        'trim' => [
            'lottery' => [1, 1000],
        ],
        'redis' => [
            'connection' => env('PULSE_REDIS_CONNECTION'),
            'chunk' => 1000,
        ],
    ],

    // Per AGENTS.md §Observability's "access-controlled" requirement (also
    // named directly by release-gates.md §H's Pulse box) — the dashboard
    // route must stay behind real admin authorization, not Pulse's own
    // default gate. This reuses the same panel-access rule the `/admin`
    // Filament panel already enforces (`AdminPanelAccessPolicy`, checked via
    // `User::canAccessPanel()`) rather than inventing a second, parallel
    // "isAdmin" concept — the four PANEL_ROLES documented on that class
    // (admin, restricted_admin, operator, finance) are exactly who should
    // see operational metrics too.
    'authorize' => static function (?Authenticatable $user): bool {
        $actorContext = app(IdentityAccessAdapter::class)->resolveActorContext($user);

        return app(AdminPanelAccessPolicy::class)->allows($actorContext);
    },

    'servers' => [
        env('PULSE_SERVER_NAME', gethostname()),
    ],

    'recorders' => [
        CacheInteractions::class => [
            'enabled' => env('PULSE_CACHE_INTERACTIONS_ENABLED', true),
            'sample_rate' => env('PULSE_CACHE_INTERACTIONS_SAMPLE_RATE', 1),
        ],
        Queues::class => [
            'enabled' => env('PULSE_QUEUES_ENABLED', true),
            'sample_rate' => env('PULSE_QUEUES_SAMPLE_RATE', 1),
        ],
        SlowJobs::class => [
            'enabled' => env('PULSE_SLOW_JOBS_ENABLED', true),
            'sample_rate' => env('PULSE_SLOW_JOBS_SAMPLE_RATE', 1),
            'threshold' => env('PULSE_SLOW_JOBS_THRESHOLD', 1000),
        ],
        SlowQueries::class => [
            'enabled' => env('PULSE_SLOW_QUERIES_ENABLED', true),
            'sample_rate' => env('PULSE_SLOW_QUERIES_SAMPLE_RATE', 1),
            'threshold' => env('PULSE_SLOW_QUERIES_THRESHOLD', 1000),
            'location' => env('PULSE_SLOW_QUERIES_LOCATION', true),
        ],
        SlowRequests::class => [
            'enabled' => env('PULSE_SLOW_REQUESTS_ENABLED', true),
            'sample_rate' => env('PULSE_SLOW_REQUESTS_SAMPLE_RATE', 1),
            'threshold' => env('PULSE_SLOW_REQUESTS_THRESHOLD', 1000),
        ],
    ],
];
