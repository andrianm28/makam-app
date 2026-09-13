<?php

declare(strict_types=1);

namespace App\Platform\Observability;

use App\Platform\Correlation\CorrelationContext;
use Illuminate\Database\QueryException;
use ReflectionProperty;
use Sentry\Event;
use Throwable;

/**
 * `config/sentry.php`'s `before_send` hook, referenced there as the array
 * callable `[self::class, 'scrub']` rather than an inline closure.
 *
 * Final-review C2: a closure inside `config/sentry.php`'s returned array
 * cannot survive `php artisan config:cache` — Laravel's config cache
 * `var_export()`s the file, and `Closure::__set_state()` does not exist.
 * An array callable (`[ClassName::class, 'method']`) is a plain string
 * array, so it survives `var_export()`/`config:cache` intact — this is
 * also the exact pattern Sentry's own Laravel filtering docs show for
 * `before_send` (a `[App\Exceptions\Sentry::class, 'beforeSend']` style
 * reference), not something invented for this fix.
 *
 * A different fix was considered and rejected: moving this logic into a
 * service provider's `boot()` and setting it at runtime via
 * `config(['sentry.before_send' => ...])`. `sentry-laravel`'s own
 * `ServiceProvider::boot()` resolves `HubInterface` — which reads
 * `config('sentry.before_send')` once, eagerly, to build the client — so a
 * different provider's `boot()` setting that key later would only win if
 * it is guaranteed to run before Sentry's own provider boots, which is not
 * something this codebase controls or should rely on. Keeping the
 * callable reference in the config file sidesteps that ordering risk
 * entirely; Pulse's `authorize` and Horizon's `viewHorizon` gate (both
 * `Gate::define()` calls, consulted lazily at check-time) have no such
 * ordering hazard, so those two did move into
 * `Providers\ObservabilityServiceProvider` as originally planned.
 *
 * Behavior is unchanged from the closure this replaces — same NIK/KK and
 * signed-URL scrubbing, same correlation id / image digest tagging. See
 * `config/sentry.php`'s own doc block for why this scrubbing exists
 * (AGENTS.md §Observability).
 *
 * ---------------------------------------------------------------------------
 * FIXED 5 Sep 2026 — the scrubber never ran on the real exception path
 * ---------------------------------------------------------------------------
 * `$event->getMessage()` is populated only for a manually logged Sentry
 * message; the standard `report()`/`captureException()` path used
 * throughout this app never sets it (confirmed against
 * `vendor/sentry/sentry/src/Client.php`) — the exception's text lives in
 * `$event->getExceptions()` instead, which this class never read. So every
 * real captured exception reached Sentry with an unscrubbed message, and
 * with its stack frames' local variables (`Frame::getVars()`) untouched.
 *
 * Separately, `Sentry\Integration\RequestIntegration::processEvent()`
 * unconditionally sets `$requestData['url']` / `['query_string']` from the
 * live request URI — that assignment is not inside its
 * `shouldSendDefaultPii()` branch (only `cookies`/`headers`/`env` are), and
 * it runs before `before_send`. A request URL or query string carrying a
 * NIK/KK or a signed DocumentVault URL therefore reached Sentry regardless
 * of `config('sentry.send_default_pii')`.
 *
 * Fixed by also scrubbing every exception's message and stack-frame vars,
 * and the request's `url`/`query_string`, through the same two patterns.
 *
 * ---------------------------------------------------------------------------
 * FIXED 7 Sep 2026 (OBS-02, security) — the signed-URL pattern matched a
 * shape the app never generates
 * ---------------------------------------------------------------------------
 * See the inline comment on `$signedUrlPattern` below for the full account.
 * In short: the old pattern targeted a `/vault/...?...signature=...` shape
 * that does not exist anywhere in this codebase's routes; the one real
 * signed download URL is a path-segment token at
 * `/internal/documents/{uuid}/download/{token}` with no query string, so
 * every real token reached Sentry unredacted. `SentryEventScrubberTest` was
 * rewritten to construct a REAL URL via `IssueSignedUrl::temporaryUrl()`
 * rather than a hand-typed fixture matching the new regex, so the test
 * cannot pass by construction alone.
 *
 * ---------------------------------------------------------------------------
 * FIXED 7 Sep 2026 (OBS-07, PII leak) — `QueryException` interpolates every
 * SQL binding into its own message
 * ---------------------------------------------------------------------------
 * `Illuminate\Database\QueryException::formatMessage()` builds its message
 * as `$previous->getMessage() . ' (Connection: ... , SQL: ' .
 * Str::replaceArray('?', $bindings, $sql) . ')'` — every bound value,
 * verbatim, baked into the exception's OWN `->getMessage()` at construction
 * time (confirmed against `vendor/laravel/framework/.../QueryException.php`).
 * A failed `booking_drafts` write carries deceased-person names and dates as
 * bindings, so a constraint violation on that table put real personal data
 * into the message before this class ever saw it. Some native drivers (the
 * PostgreSQL PDO driver among them) independently echo a real value into
 * `$previous->getMessage()` itself on a unique-constraint violation (a
 * `DETAIL:  Key (...)=(...) already exists.` line) — a SECOND, driver-level
 * leak that stripping only the `, SQL: ...)` tail would not close.
 *
 * `redactQueryExceptionMessages()` is the PRIMARY fix, wired as an early
 * `reportable()` callback in `bootstrap/app.php` (registered BEFORE
 * `Sentry\Laravel\Integration::handles()`, which registers its OWN
 * `reportable()` callback that is what actually calls
 * `captureException()`). It mutates the exception object'S OWN `message`
 * property in place, for every `QueryException` anywhere in the `getPrevious()`
 * chain, BEFORE either the default log write (`Handler::reportThrowable()`
 * calls `$logger->{$level}($e->getMessage(), ...)` — the SAME, now-mutated,
 * string) or Sentry's capture reads it — ONE fix point that covers BOTH
 * sinks, rather than two independent scrub implementations that could drift.
 * It discards `$previous->getMessage()` (the potentially driver-leaking raw
 * text) ENTIRELY rather than trying to selectively strip values out of it,
 * and rebuilds a message from only the placeholder SQL (`getSql()`, which
 * Laravel never interpolates — see the source above) and the binding COUNT,
 * never the binding values themselves.
 *
 * `scrub()`'s own `redactQueryExceptionValueFallback()` branch below is
 * defense-in-depth for the one path the reportable hook cannot reach: an
 * exception captured directly via `\Sentry\captureException()` without ever
 * going through `Handler::report()`. Nothing in this codebase does that
 * today, but `scrub()` is the LAST gate before anything leaves the process,
 * so it does not assume that stays true.
 */
final class SentryEventScrubber
{
    /**
     * The single fix point for OBS-07 — see the class doc block. Mutates
     * every `QueryException` found in `$exception`'s own `getPrevious()`
     * chain IN PLACE, so every later reader of the SAME exception object
     * (the default log write, Sentry's own `reportable()` capture) sees the
     * redacted message rather than the original interpolated one.
     *
     * Deliberately walks the WHOLE chain, not just `$exception` itself: a
     * domain-level exception routinely wraps a `QueryException` as its
     * `$previous`, and that inner exception is exactly as reportable/
     * loggable as the outer one.
     */
    public static function redactQueryExceptionMessages(Throwable $exception): void
    {
        $current = $exception;

        while ($current !== null) {
            if ($current instanceof QueryException) {
                self::redactQueryException($current);
            }

            $current = $current->getPrevious();
        }
    }

    /**
     * `Exception::$message` has no public setter, and `QueryException`
     * builds its message once, in its own constructor, from data this class
     * has no other way to overwrite. `ReflectionProperty` on the base
     * `Exception` class (not `QueryException`, which declares no `$message`
     * property of its own) is the only way to replace it after construction
     * — matching the same "mutate the real object, not a copy" requirement
     * `redactQueryExceptionMessages()`'s doc block explains.
     */
    private static function redactQueryException(QueryException $exception): void
    {
        $sqlState = is_array($exception->errorInfo ?? null)
            ? (string) ($exception->errorInfo[0] ?? 'UNKNOWN')
            : 'UNKNOWN';

        $redacted = sprintf(
            'Query failed [SQLSTATE %s] on connection "%s". SQL: %s (%d binding value(s) redacted — see AGENTS.md §Observability).',
            $sqlState,
            $exception->getConnectionName(),
            $exception->getSql(),
            count($exception->getBindings()),
        );

        $property = new ReflectionProperty(\Exception::class, 'message');
        $property->setAccessible(true);
        $property->setValue($exception, $redacted);
    }

    /**
     * Defense-in-depth fallback for the Sentry payload specifically — see
     * the class doc block for why this is secondary, not the real fix.
     * Regex-based because, by the time `scrub()` runs, `ExceptionDataBag`
     * exposes only the already-stringified `getValue()`/`getType()`, never
     * the original exception object `redactQueryException()` above needs.
     */
    private static function redactQueryExceptionValueFallback(string $value): string
    {
        $value = preg_replace('/, SQL: .*\)\s*$/s', ', SQL: [REDACTED-QUERY-BINDINGS])', $value) ?? $value;
        $value = preg_replace('/(DETAIL:\s*Key \([^)]*\)=\()[^)]*(\))/i', '$1[REDACTED]$2', $value) ?? $value;

        return $value;
    }

    public static function scrub(Event $event): ?Event
    {
        $correlationId = app(CorrelationContext::class)->current();

        if ($correlationId !== null) {
            $event->setTag('correlation_id', $correlationId->value);
        }

        $event->setTag('image_digest', (string) config('sentry.image_digest', 'unknown'));

        // NIK: 16 consecutive digits. KK: same format, same scrub — this
        // codebase does not distinguish the two at the string-pattern
        // level, matching how docs/operations/observability-stack.md §5
        // itself does not either.
        $nikKkPattern = '/\b\d{16}\b/';

        // OBS-02 (7 Sep 2026): the pattern this replaced —
        // `#(https?://[^\s]+/vault/[^\s]+)\?[^\s]+#` — matched a URL SHAPE
        // this app never generates. The one real signed-URL route this
        // codebase issues is `IssueSignedUrl::temporaryUrl()`
        // (`app/Platform/DocumentVault/Actions/IssueSignedUrl.php`), which
        // builds `URL::to("/internal/documents/{$grant->document_id}/download/{$grant->token}")`
        // — confirmed against `routes/web.php`'s
        // `Route::get('/internal/documents/{document}/download/{token}', ...)`.
        // That is neither a `/vault/` path NOR a query-string token: the
        // 64-hex-character token (`bin2hex(random_bytes(32))`) is the LAST
        // PATH SEGMENT, with no query string at all. The old pattern could
        // never match it, so a live, valid download token reaching an
        // exception's context (message, stack-frame var, or request
        // url/query_string) went to Sentry completely unredacted. Fixed to
        // match the real path shape and redact only the token segment,
        // keeping the document id (a UUID, not a secret) visible for
        // triage.
        $signedUrlPattern = '#((?:https?://[^\s/]+)?/internal/documents/[0-9a-f-]{36}/download/)[0-9a-f]+#i';

        // Defense-in-depth for any FUTURE `URL::temporarySignedRoute()` use
        // elsewhere in the app: a generic scrub of `signature=`/`expires=`
        // query parameters, wherever they appear, independent of the host
        // or path they are attached to.
        $signedQueryParamPattern = '#([?&](?:signature|expires)=)[^&\s]+#i';

        $scrub = static function (mixed $value) use ($nikKkPattern, $signedUrlPattern, $signedQueryParamPattern): mixed {
            if (! is_string($value)) {
                return $value;
            }

            $value = preg_replace($nikKkPattern, '[REDACTED-NIK-KK]', $value) ?? $value;
            $value = preg_replace($signedUrlPattern, '$1[REDACTED-TOKEN]', $value) ?? $value;
            $value = preg_replace($signedQueryParamPattern, '$1[REDACTED-TOKEN]', $value) ?? $value;

            return $value;
        };

        if (($message = $event->getMessage()) !== null) {
            $event->setMessage($scrub($message));
        }

        foreach ($event->getExceptions() as $exception) {
            $value = $exception->getValue();

            if (is_a($exception->getType(), QueryException::class, true)) {
                $value = self::redactQueryExceptionValueFallback($value);
            }

            $exception->setValue($scrub($value));

            $stacktrace = $exception->getStacktrace();

            if ($stacktrace === null) {
                continue;
            }

            foreach ($stacktrace->getFrames() as $frame) {
                $frame->setVars(self::scrubVars($frame->getVars(), $scrub));
            }
        }

        $request = $event->getRequest();

        foreach (['url', 'query_string'] as $key) {
            if (isset($request[$key]) && is_string($request[$key])) {
                $request[$key] = $scrub($request[$key]);
            }
        }

        $event->setRequest($request);

        return $event;
    }

    /**
     * Recurses into nested arrays (e.g. a frame-local variable holding an
     * array of request params); scalars are passed through `$scrub`
     * unchanged if they're not strings, matching `$scrub`'s own contract.
     *
     * @param  array<array-key, mixed>  $vars
     * @return array<array-key, mixed>
     */
    private static function scrubVars(array $vars, callable $scrub): array
    {
        foreach ($vars as $key => $value) {
            $vars[$key] = is_array($value) ? self::scrubVars($value, $scrub) : $scrub($value);
        }

        return $vars;
    }
}
