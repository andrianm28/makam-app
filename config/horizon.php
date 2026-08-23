<?php

declare(strict_types=1);

use App\Platform\Outbox\OutboxQueueName;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | ADR-0027 condition 3 requires development and staging to use different
    | Redis/Horizon prefixes — this must differ per environment via
    | HORIZON_PREFIX in .env.dev vs .env.stg, not hardcoded here.
    |
    */

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | Long-wait alert thresholds per queue, per docs/architecture/
    | queue-and-outbox.md §3's own "Suggested initial thresholds" table —
    | transcribed values, not invented ones.
    |
    */

    'waits' => [
        'redis:'.OutboxQueueName::Critical->value => 10,
        'redis:'.OutboxQueueName::Urgent->value => 15,
        'redis:'.OutboxQueueName::Notifications->value => 60,
        'redis:'.OutboxQueueName::Default->value => 90,
        'redis:'.OutboxQueueName::Imports->value => 300,
        'redis:'.OutboxQueueName::Media->value => 300,
        'redis:'.OutboxQueueName::Reports->value => 600,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    */

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    */

    'silenced' => [],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    */

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    */

    'memory_limit' => 128,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Supervisors
    |--------------------------------------------------------------------------
    |
    | Per-environment supervisor definitions. `production` and `local` (the
    | default Laravel environment names) hold the full baseline from
    | docs/architecture/queue-and-outbox.md §3. `staging` is deliberately
    | narrower per ADR-0027 condition 4 ("Staging runs a maximum of two
    | normal Horizon worker processes; development and batch workers run
    | on demand") — the 4 "normal" queues (critical/urgent/notifications/
    | default) share ONE supervisor capped at 2 total processes, and batch
    | queues (imports/media/reports) are NOT started at all in staging,
    | matching "development and batch workers run on demand" — the
    | dev-worker/stg-batch-worker Compose services (profiles: ["dev-worker"]/
    | ["batch"]) are the on-demand mechanism, not Horizon-managed supervisors.
    |
    */

    'defaults' => [
        'supervisor-critical' => [
            'connection' => 'redis',
            'queue' => [OutboxQueueName::Critical->value],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 4,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 3,
            'timeout' => 60,
            'nice' => 0,
        ],
        'supervisor-urgent' => [
            'connection' => 'redis',
            'queue' => [OutboxQueueName::Urgent->value],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 4,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 3,
            'timeout' => 60,
            'nice' => 0,
        ],
        'supervisor-notify' => [
            'connection' => 'redis',
            'queue' => [OutboxQueueName::Notifications->value],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 4,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 3,
            'timeout' => 90,
            'nice' => 0,
        ],
        'supervisor-default' => [
            'connection' => 'redis',
            'queue' => [OutboxQueueName::Default->value],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 4,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 3,
            'timeout' => 90,
            'nice' => 0,
        ],
        'supervisor-batch' => [
            'connection' => 'redis',
            'queue' => [OutboxQueueName::Imports->value, OutboxQueueName::Media->value],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses' => 0,
            'maxProcesses' => 3,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 1,
            'timeout' => 900,
            'nice' => 0,
        ],
        'supervisor-reports' => [
            'connection' => 'redis',
            'queue' => [OutboxQueueName::Reports->value],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses' => 0,
            'maxProcesses' => 2,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 1,
            'timeout' => 900,
            'nice' => 0,
        ],
        // Staging-only supervisor (see the 'staging' environment block
        // below) — declared here too, with a maxProcesses baseline, per
        // this file's own convention that every supervisor referenced in
        // 'environments' has a matching 'defaults' entry.
        'supervisor-normal' => [
            'connection' => 'redis',
            'queue' => [
                OutboxQueueName::Critical->value,
                OutboxQueueName::Urgent->value,
                OutboxQueueName::Notifications->value,
                OutboxQueueName::Default->value,
            ],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 4,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 3,
            'timeout' => 90,
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-critical' => ['minProcesses' => 1, 'maxProcesses' => 4],
            'supervisor-urgent' => ['minProcesses' => 1, 'maxProcesses' => 4],
            'supervisor-notify' => ['minProcesses' => 1, 'maxProcesses' => 4],
            'supervisor-default' => ['minProcesses' => 1, 'maxProcesses' => 4],
            'supervisor-batch' => ['minProcesses' => 0, 'maxProcesses' => 3],
            'supervisor-reports' => ['minProcesses' => 0, 'maxProcesses' => 2],
            'supervisor-normal' => false,
        ],

        // ADR-0027 condition 4: "Staging runs a maximum of two normal
        // Horizon worker processes; development and batch workers run on
        // demand." A literal 2-process cap across 4 queues needs ONE
        // supervisor covering all 4, not 4 separate supervisors each
        // capped individually (Horizon has no shared-pool primitive
        // across supervisors) — supervisor-normal (declared in 'defaults'
        // above) is that single supervisor. The 4 baseline supervisors
        // and both batch/report supervisors are disabled entirely in
        // staging ('false') — batch/report work runs via the
        // dev-worker/stg-batch-worker Compose services' on-demand
        // profiles instead, not as Horizon-managed processes here.
        'staging' => [
            'supervisor-critical' => false,
            'supervisor-urgent' => false,
            'supervisor-notify' => false,
            'supervisor-default' => false,
            'supervisor-batch' => false,
            'supervisor-reports' => false,
            'supervisor-normal' => ['minProcesses' => 1, 'maxProcesses' => 2],
        ],

        'local' => [
            'supervisor-critical' => ['maxProcesses' => 1],
            'supervisor-urgent' => ['maxProcesses' => 1],
            'supervisor-notify' => ['maxProcesses' => 1],
            'supervisor-default' => ['maxProcesses' => 1],
            'supervisor-batch' => ['maxProcesses' => 1],
            'supervisor-reports' => ['maxProcesses' => 1],
            'supervisor-normal' => false,
        ],
    ],

];
