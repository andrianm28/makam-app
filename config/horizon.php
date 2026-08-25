<?php

declare(strict_types=1);

use App\Platform\Outbox\OutboxQueueName;
use Illuminate\Support\Str;

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
        // Final-review re-check (round 2): supervisor-batch/reports moved
        // to the `redis_batch` connection (see the connection-choice
        // comment above), but these three keys still said `redis:` —
        // Horizon composes wait-time lookup keys as `connection:queue`
        // (RedisSupervisorRepository) and does a direct config() lookup
        // on that exact string (MonitorWaitTimes), so a mismatched
        // connection prefix here means these three thresholds are
        // silently dead and the `?? 60` default applies instead —
        // exactly the alerting regression this comment now prevents.
        'redis_batch:'.OutboxQueueName::Imports->value => 300,
        'redis_batch:'.OutboxQueueName::Media->value => 300,
        'redis_batch:'.OutboxQueueName::Reports->value => 600,
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
    | Dev environment convention: This array intentionally has no
    | `development`/`dev*` key. Dev's real queue mechanism is a plain
    | `queue:work` worker (Compose `dev-worker` profile), not Horizon. If
    | Horizon is ever run manually on dev for debugging, it MUST be started
    | with `php artisan horizon --environment=local` (never bare
    | `php artisan horizon`, which silently deploys zero supervisors since
    | APP_ENV=development matches no key in this array). Do NOT add a
    | persistent `development` block here — dev and beta share a live Redis
    | keyspace by explicit accepted decision (ADR-0035 item 12), and a real
    | persistent `development` Horizon block would start supervisors that
    | consume beta's live queues too.
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
            // Final-review I1: 'redis_batch', not 'redis' — see
            // config/queue.php's own comment on that connection. This
            // supervisor's 900s timeout exceeds the 'redis' connection's
            // 90s retry_after, which would otherwise let a second worker
            // pick up (and duplicate-execute) a still-running batch job.
            'connection' => 'redis_batch',
            'queue' => [OutboxQueueName::Imports->value, OutboxQueueName::Media->value],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            // Release-gates Task 7 rehearsal (24 Aug 2026): this was
            // `minProcesses => 0`, intended as true zero-idle capacity, but
            // that value is invalid — Laravel\Horizon\ProvisioningPlan::
            // convert() throws unconditionally when any environment's
            // minProcesses is set below 1, and it validates EVERY
            // environment block eagerly regardless of which one is being
            // deployed — so `php artisan horizon` could not start in ANY
            // of production/staging/local. Staging did not avoid this:
            // its own supervisor-batch/supervisor-reports entries were
            // `false` (see the 'supervisor-normal' comment below for why
            // that also throws), so staging would have hit that crash
            // instead, at the same shared construction step, the moment
            // it was reached. SupervisorOptions itself already defaults
            // minProcesses to 1 when unset, so true zero-idle was never
            // achievable in this Horizon version regardless — 1 is the
            // real floor, not a design compromise.
            'minProcesses' => 1,
            'maxProcesses' => 3,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 1,
            'timeout' => 900,
            'nice' => 0,
        ],
        'supervisor-reports' => [
            // Final-review I1: 'redis_batch', not 'redis' — same reasoning
            // as supervisor-batch above.
            'connection' => 'redis_batch',
            'queue' => [OutboxQueueName::Reports->value],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            // Same minProcesses correction as supervisor-batch above —
            // see that comment for the full explanation.
            'minProcesses' => 1,
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
            // minProcesses corrected from 0 to 1 (24 Aug 2026) — see the
            // matching 'defaults' comment above; 0 is invalid and would
            // have crashed `php artisan horizon` in production the first
            // time it was actually started.
            'supervisor-batch' => ['minProcesses' => 1, 'maxProcesses' => 3],
            'supervisor-reports' => ['minProcesses' => 1, 'maxProcesses' => 2],
            // Release-gates Task 7 rehearsal (24 Aug 2026): this was
            // `'supervisor-normal' => false`, meant to disable the
            // supervisor entirely for this environment — but that also
            // crashes `php artisan horizon` before it ever starts.
            // Laravel\Horizon\ProvisioningPlan::toSupervisorOptions()
            // eagerly calls convert() on EVERY supervisor entry of EVERY
            // environment (not just the one actually being deployed), and
            // convert() assumes its $options argument is an array;
            // array_replace_recursive() replaces a nested default array
            // with a bare scalar `false` rather than merging into it, so
            // convert() ends up building an options array with no
            // 'connection' key, and SupervisorOptions::fromArray()
            // unconditionally reads $array['connection'] — an
            // "Undefined array key" ErrorException. The real, working
            // mechanism for "declared but not started" is
            // `['maxProcesses' => 0]`: it merges cleanly with 'defaults'
            // (stays a real array), and ProvisioningPlan::deploy()'s own
            // gate (`if ($options->maxProcesses > 0)`) already skips
            // spawning it. Do not reach for `false` here again — the same
            // correction applies to every other `=> false` entry below.
            'supervisor-normal' => ['maxProcesses' => 0],
        ],

        // ADR-0027 condition 4: "Staging runs a maximum of two normal
        // Horizon worker processes; development and batch workers run on
        // demand." A literal 2-process cap across 4 queues needs ONE
        // supervisor covering all 4, not 4 separate supervisors each
        // capped individually (Horizon has no shared-pool primitive
        // across supervisors) — supervisor-normal (declared in 'defaults'
        // above) is that single supervisor. The 4 baseline supervisors
        // and both batch/report supervisors are disabled entirely in
        // staging (`maxProcesses => 0` — see the 'production' block's
        // comment above for why plain `false` cannot be used here) —
        // batch/report work runs via the dev-worker/stg-batch-worker
        // Compose services' on-demand profiles instead, not as
        // Horizon-managed processes here.
        'staging' => [
            'supervisor-critical' => ['maxProcesses' => 0],
            'supervisor-urgent' => ['maxProcesses' => 0],
            'supervisor-notify' => ['maxProcesses' => 0],
            'supervisor-default' => ['maxProcesses' => 0],
            'supervisor-batch' => ['maxProcesses' => 0],
            'supervisor-reports' => ['maxProcesses' => 0],
            'supervisor-normal' => ['minProcesses' => 1, 'maxProcesses' => 2],
        ],

        'local' => [
            'supervisor-critical' => ['maxProcesses' => 1],
            'supervisor-urgent' => ['maxProcesses' => 1],
            'supervisor-notify' => ['maxProcesses' => 1],
            'supervisor-default' => ['maxProcesses' => 1],
            'supervisor-batch' => ['maxProcesses' => 1],
            'supervisor-reports' => ['maxProcesses' => 1],
            'supervisor-normal' => ['maxProcesses' => 0],
        ],
    ],

];
