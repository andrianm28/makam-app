<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
