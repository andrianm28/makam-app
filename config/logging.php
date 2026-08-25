<?php

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\NullHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\PsrLogMessageProcessor;

/*
|--------------------------------------------------------------------------
| Restricted data — what IS and IS NOT redacted here
|--------------------------------------------------------------------------
|
| S2-T10 (docs/planning/agent-execution-plan.md §4). AGENTS.md §Observability:
| "Never place restricted data in logs, Pulse, Horizon tags, or error
| trackers." This file cannot enforce that rule; it only decides where log
| lines go and what shape they are in. What actually keeps restricted data
| out of a log line is the calling code choosing not to write it there.
|
| IS covered by a Laravel default, verified against docs/errors.md and
| docs/validation.md (13.x) 25 Jul 2026 — see docs/operations/observability.md
| §"Structured logging" for the verification note:
|   - When a ValidationException redirects back with old input flashed to
|     the session, Laravel's base exception handler excludes a small
|     hard-coded field list — password, password_confirmation,
|     current_password — from that flash. This is a session-flash
|     protection for form repopulation, not a log-output protection, and it
|     is NOT configurable from this file.
|
| NOT covered by any Laravel default (i.e. still the developer's
| responsibility on every call site):
|   - Log::info()/warning()/error()/etc. context arrays — anything passed
|     here is written verbatim (JSON-encoded on the "json" channel below).
|   - Exception::context() / the global Exceptions::context() closure in
|     bootstrap/app.php.
|   - Job/queue payloads, HTTP client request/response logging, webhook
|     payload logging, Horizon tags, Pulse entries.
|   - Full documents, signed URLs, payment credentials, identity numbers —
|     see docs/operations/observability-stack.md §3's explicit "never log"
|     list, which this project already treats as binding.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that is utilized to write
    | messages to your logs. The value provided here should match one of
    | the channels present in the list of "channels" configured below.
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Laravel
    | utilizes the Monolog PHP logging library, which includes a variety
    | of powerful log handlers and formatters that you're free to use.
    |
    | Available drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog", "custom", "stack"
    |
    */

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', (string) env('LOG_STACK', 'single')),
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
        ],

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => env('LOG_SLACK_USERNAME', env('APP_NAME', 'Laravel')),
            'emoji' => env('LOG_SLACK_EMOJI', ':boom:'),
            'level' => env('LOG_LEVEL', 'critical'),
            'replace_placeholders' => true,
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stderr',
            ],
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'processors' => [PsrLogMessageProcessor::class],
        ],

        /*
        |----------------------------------------------------------------
        | Structured (JSON) channel — S2-T10
        |----------------------------------------------------------------
        |
        | Not wired into the default 'stack' channel below, so existing
        | deployments and their LOG_STACK=single default are unchanged by
        | adding this. Opt in per-environment with either:
        |   LOG_CHANNEL=json                (json only), or
        |   LOG_STACK=single,json           (both, via the stack channel)
        |
        | Rotates on the same LOG_DAILY_DAYS retention as the 'daily'
        | channel (default 14) so this doesn't grow the disk footprint
        | envelope already assumed for the 4 GB host in
        | dev-staging-environment.md §6.
        |
        | Field shape is documented, not enforced, by this config —
        | Monolog's JsonFormatter emits whatever is in the record
        | (message/context/extra/level/channel/datetime). Matching the
        | field list in docs/operations/observability-stack.md §3
        | (request_id, actor_type, domain_reference, etc.) is up to the
        | code calling Log::withContext()/Log::info(), not this file.
        */
        'json' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => RotatingFileHandler::class,
            'handler_with' => [
                'filename' => storage_path('logs/laravel-json.log'),
                'maxFiles' => env('LOG_DAILY_DAYS', 14),
            ],
            'formatter' => JsonFormatter::class,
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => env('LOG_SYSLOG_FACILITY', LOG_USER),
            'replace_placeholders' => true,
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

    ],

];
