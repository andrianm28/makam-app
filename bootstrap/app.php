<?php

use App\Http\Middleware\AssignCorrelationId;
use App\Http\Middleware\BetaNoindexTag;
use App\Http\Middleware\ReportContentSecurityPolicy;
use App\Platform\DocumentVault\Jobs\ReconcileDocumentStorageCleanupJob;
use App\Platform\Outbox\OutboxQueueName;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // Added by the platform-payment-adapter lane (Task 3): the application
        // had no API surface until `POST /api/payments/webhook/{merchant}`.
        // Registering the group is what keeps that route OUT of the `web`
        // group — a provider callback must not carry session, cookie, or CSRF
        // middleware, and adding a CSRF exception for it would have been the
        // alternative. `withExceptions()` below already anticipated an
        // `api/*` prefix.
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->job(
            new ReconcileDocumentStorageCleanupJob,
            OutboxQueueName::Media->value,
        )
            ->name('document-vault:reconcile-storage-cleanups')
            ->everyFiveMinutes()
            ->withoutOverlapping(10)
            ->onOneServer();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        // Every environment in deployment.md §2/§3 puts a reverse proxy in
        // front of the app (host nginx for dev/stg, LB/CDN for production);
        // there is no topology where Laravel talks to the internet directly.
        // Without this, $request->isSecure()/secure_url() see plain HTTP
        // even behind an HTTPS-terminating proxy, breaking SESSION_SECURE_COOKIE
        // and generated URLs. `at: '*'` (trust any upstream) is safe here
        // specifically because the app port is never bound beyond 127.0.0.1
        // (ci/verify-infra.sh GATE I7) — only the host's own reverse proxy can
        // ever reach it to set these headers in the first place.
        $middleware->trustProxies(at: '*');

        // Public-beta readiness (finding N-2/OQ-11): global, not a group
        // append — must cover the Filament /admin and /vendor panels too,
        // and both declare their own middleware arrays outside the `web`
        // group entirely (see the comment on AssignCorrelationId below).
        // See ReportContentSecurityPolicy's own doc block for why this
        // ships report-only.
        $middleware->append(ReportContentSecurityPolicy::class);

        // Public-beta readiness (Lane C3): same global-not-group-append
        // reasoning as ReportContentSecurityPolicy immediately above — see
        // BetaNoindexTag's own doc block for why this is app-level rather
        // than only an nginx vhost header, and why it defaults to a no-op.
        $middleware->append(BetaNoindexTag::class);

        // S3-T10 (platform-audit AC10 / platform-outbox AC13): the
        // request-boundary origin point for correlation-id propagation.
        // appendToGroup() puts this after the framework's own default
        // `web` group middleware (cookies/session/CSRF/etc.) — that is
        // still before every route handler, controller, and Livewire
        // component, which is all "downstream" actually requires: a bound
        // CorrelationContext by the time anything might call
        // Audit::record(). See AssignCorrelationId's own doc block for the
        // full behavior and security rationale. The Filament `/admin`
        // panel does not go through this `web` group at all
        // (AdminPanelProvider declares its own explicit
        // ->middleware([...]) array) — it is wired there separately, and
        // placed FIRST in that array specifically because
        // AuthenticateSession's session-recording path sits later in that
        // same array (see AdminPanelProvider's comment).
        $middleware->appendToGroup('web', AssignCorrelationId::class);

        // Public-beta readiness: every public journey is unthrottled and
        // anonymous today — see the `public-guest` limiter's own doc block
        // in `AppServiceProvider::boot()` for why the `web` group is the
        // right (and only reachable) attachment point for a Livewire-heavy
        // application, and why an authenticated request is exempt.
        $middleware->appendToGroup('web', 'throttle:public-guest');

        // Same origin point for the `api` group. `platform-audit` design.md
        // requires a correlation id to originate at the request boundary and
        // propagate into outbox events and queue jobs; the payment webhook
        // receiver writes audit rows and dispatches a queued job, so without
        // this the whole webhook path would be the one request flow in the
        // application with no correlation id at all.
        $middleware->appendToGroup('api', AssignCorrelationId::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
