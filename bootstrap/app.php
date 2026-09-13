<?php

use App\Http\Middleware\AssignCorrelationId;
use App\Http\Middleware\BetaNoindexTag;
use App\Http\Middleware\ReportContentSecurityPolicy;
use App\Platform\DocumentVault\Jobs\ReconcileDocumentStorageCleanupJob;
use App\Platform\Observability\SentryEventScrubber;
use App\Platform\Outbox\OutboxQueueName;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;
use Sentry\Laravel\Integration;

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
        // and generated URLs.
        //
        // Finding SEC-N1 (13 Sep 2026). This line previously read
        // `trustProxies(at: '*')`, justified by the argument that "the app
        // port is never bound beyond 127.0.0.1 (ci/verify-infra.sh GATE I7),
        // so only the host's own reverse proxy can ever reach it to set these
        // headers in the first place." **That argument is wrong, and this
        // comment deliberately keeps the record of why** rather than deleting
        // it: the loopback binding controls who may open a SOCKET to the app,
        // not who may author its HEADERS. An internet client sends
        // `X-Forwarded-Host: evil.example`; the host nginx vhosts set `Host`,
        // `X-Real-IP`, `X-Forwarded-For` and `X-Forwarded-Proto` but never
        // touch `X-Forwarded-Host`, so the attacker's header arrives at the
        // app verbatim, carried in by the very proxy the binding argument
        // relied on. `at: '*'` then made that header authoritative. The
        // observed result was a genuine, app-sent password-reset email whose
        // link pointed at an attacker-controlled domain
        // (`tests/Feature/Http/TrustedProxiesAndHostsTest`).
        //
        // Two independent changes, both required — see that test's class doc
        // block for why neither alone is sufficient.
        //
        // 1. `at:` — the addresses that may speak for a client at all.
        //    Both application containers publish ONLY to loopback
        //    (`127.0.0.1:8081->8080` dev, `127.0.0.1:8083->8080` beta), so
        //    every packet reaches the container's nginx either from the
        //    host's own address on a docker bridge (observed: 172.19.0.1,
        //    the `makam-nonprod_egress` gateway) or from 127.0.0.1 (the
        //    image's own HEALTHCHECK, which curls http://127.0.0.1:8080/up
        //    from inside the container). The trusted set is therefore
        //    loopback plus docker's primary default address pool, NOT the
        //    single observed gateway address: docker allocates that subnet
        //    dynamically, and this host already holds ten bridge networks in
        //    172.17–172.27, so pinning 172.19.0.1 would silently stop
        //    matching the day the compose networks are recreated — and a
        //    no-longer-matching proxy address means `isSecure()` goes false
        //    behind TLS and every visitor collapses onto one rate-limit key.
        //    `10.0.0.0/8` is deliberately EXCLUDED: this host's own NIC sits
        //    on a 10.0.0.0/16 provider LAN it does not exclusively own, so
        //    trusting it would re-open header spoofing to anything else on
        //    that LAN.
        //
        // 2. `headers:` — which forwarded headers may be believed. Laravel's
        //    default bitmask is FOR|HOST|PORT|PROTO|PREFIX|AWS_ELB. Only the
        //    TWO the nginx vhosts actually set are kept, because a trusted
        //    header that nothing in our own infrastructure sets is pure
        //    attack surface with no consumer:
        //
        //    - `HOST` — the finding itself.
        //    - `PORT` — no vhost in this stack sets `X-Forwarded-Port`
        //      (grep the live configs: they set `Host`, `X-Real-IP`,
        //      `X-Forwarded-For` and `X-Forwarded-Proto`, and nothing else).
        //      Trusting it let a client choose the port in every generated
        //      absolute URL — `https://makam.co.id:1337/reset-password/…`.
        //      Lower severity than the HOST defect because the DOMAIN stays
        //      ours, so it is link-breakage rather than takeover, but it is
        //      the same shape of bug and it has no upside. Dropping it is
        //      provably safe rather than merely safer: with both PORT and
        //      HOST untrusted, `Request::getPort()` falls through to the
        //      `Host` header, which carries no port, and returns 443 from
        //      the scheme — which is the correct answer behind an
        //      HTTPS-terminating proxy. Covered by
        //      `test_a_spoofed_x_forwarded_port_does_not_reach_the_generated_url`.
        //    - `PREFIX` — same class of reason: `X-Forwarded-Prefix` rewrites
        //      the base path of every generated URL and nothing sets it.
        //    - `AWS_ELB` — there is no AWS anywhere in this project
        //      (CLAUDE.md §6).
        //
        //    If a future topology puts a load balancer in front that DOES
        //    set `X-Forwarded-Port` on a non-standard port, re-add PORT in
        //    the same commit that introduces it — not before.
        $middleware->trustProxies(
            at: [
                '127.0.0.1',
                '::1',
                '172.16.0.0/12',
            ],
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        // Finding SEC-N1, the positive half. Dropping `HEADER_X_FORWARDED_HOST`
        // above stops that header being believed; this stops a forged plain
        // `Host:` header being believed, which nothing else in the stack
        // checks — the host nginx vhosts pass `Host $host` through verbatim,
        // and with no trusted-host patterns registered Symfony applies only a
        // hostname-SYNTAX check, which `evil-attacker.example` passes.
        //
        // These strings are REGULAR EXPRESSIONS, not hostnames:
        // `Request::setTrustedHosts()` wraps each one as `{...}i` and they
        // are not anchored for you. A bare `'makam.co.id'` would match
        // `makamXcoZid.attacker.example`. Hence the explicit `^...$` and the
        // escaped dots.
        //
        // `subdomains: false` is deliberate, and is the reason the list is
        // enumerated rather than expressed as `^(.+\.)?makam\.co\.id$` (which
        // is what Laravel's own default and `subdomains: true` would give).
        // A wildcard would let any subdomain takeover be escalated straight
        // back into reset-link poisoning; the set of hostnames actually
        // served is small, known, and changes about once a year.
        //
        // The list is every `server_name` on the deployment host that
        // proxies to this application — `makam.co.id` and `www.makam.co.id`
        // (live, both -> beta-web), `dev.makam.co.id` (-> dev-web) — plus
        // `stg.makam.co.id`, which is a committed vhost example and a
        // documented runbook target (`docs/operations/runbooks/
        // deploy-stg-vhost.md`) not currently enabled; including it now
        // means enabling that vhost later cannot 400 the whole site.
        //
        // `127.0.0.1` and `localhost` are NOT cosmetic. The runtime image's
        // HEALTHCHECK requests `http://127.0.0.1:8080/up`, so its `Host` is
        // `127.0.0.1:8080` and `getHost()` strips the port before matching.
        // Omit that entry and the health probe takes a 400
        // SuspiciousOperationException, the container is marked unhealthy,
        // and a deploy rolls itself back — the exact "correct-looking change
        // takes the site down" failure this task was told to avoid. A forged
        // `Host: 127.0.0.1` is harmless in return: it yields a reset link
        // pointing at the recipient's own loopback.
        $middleware->trustHosts(at: [
            '^makam\.co\.id$',
            '^www\.makam\.co\.id$',
            '^dev\.makam\.co\.id$',
            '^stg\.makam\.co\.id$',
            '^127\.0\.0\.1$',
            '^localhost$',
        ], subdomains: false);

        // `/akun` account area, Task 2 of `.superpowers/sdd/
        // 2026-08-20-akun-shell-and-drafts/task-2-brief.md`: an
        // authenticated visitor hitting a `guest`-only route (`/masuk`,
        // `/daftar`, ...) should land on their account home, not the
        // framework's own default `/home`.
        $middleware->redirectUsersTo('/akun');

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

        // Finding SEC-04 (6 Sep 2026 audit): the three Filament panels
        // (Admin/Operator/Vendor) already register AuthenticateSession on
        // their own middleware arrays (see AdminPanelProvider's comment at
        // the AssignCorrelationId reference above), but the plain `web`
        // group used by the public /akun account area and /masuk, /daftar,
        // and the password-reset flow never did. Without it, a
        // pre-existing authenticated session survives a password reset
        // indefinitely — a stolen session cookie keeps authenticating even
        // after the account holder "secures" their account by changing
        // their password. This middleware compares the session's stored
        // password hash against the user's current one on every request
        // and logs out any session where they diverge — no code change
        // needed in ResetPasswordPage itself, since it already rotates the
        // password hash (and remember_token) on a successful reset; this
        // is the missing enforcement point that acts on that rotation.
        $middleware->appendToGroup('web', AuthenticateSession::class);

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

        // OBS-07 (PII leak, 7 Sep 2026): registered BEFORE
        // `Integration::handles($exceptions)` below, which registers
        // Sentry's OWN `reportable()` callback (the one that actually calls
        // `captureException()`). `Handler::reportThrowable()` runs every
        // registered `reportable()` callback, in registration order, before
        // its own default `$logger->{$level}($e->getMessage(), ...)` log
        // write — so this callback, running first, mutates the SAME
        // exception object both the log write and Sentry's capture read
        // `->getMessage()` from afterward. One fix point, both sinks. See
        // `SentryEventScrubber::redactQueryExceptionMessages()`'s own doc
        // block for why a `QueryException`'s message cannot be trusted
        // as-is: it interpolates every SQL binding verbatim (a failed
        // `booking_drafts` write carries deceased-person names and dates),
        // and some native drivers separately echo a real value into their
        // own error text on a constraint violation.
        $exceptions->reportable(function (Throwable $exception): void {
            SentryEventScrubber::redactQueryExceptionMessages($exception);
        });

        // Task 4 (observability-and-adr-fixes): registers Sentry's own
        // exception-reporting hook. Prepared alongside config/sentry.php,
        // whose send_default_pii=false + before_send scrubber govern what
        // this hook is allowed to transmit — this line just wires the
        // reporting path, it does not decide what leaves the app.
        //
        // Final-review C1: `sentry/sentry-laravel` is deliberately not
        // installed yet (composer.lock untouched per host build
        // restrictions — see CLAUDE.md), so `Integration` does not exist on
        // this branch today. `withExceptions()`'s closure resolves on every
        // HTTP request/console command, so an unguarded call fatal-errors
        // the whole app right now. Guarding on class_exists() makes this
        // self-activate the moment a human installs the package — no other
        // code change needed at that point.
        if (class_exists(Integration::class)) {
            Integration::handles($exceptions);
        }
    })->create();
