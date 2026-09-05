<?php

declare(strict_types=1);

namespace App\Platform\Observability;

use App\Platform\Correlation\CorrelationContext;
use Sentry\Event;

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
 */
final class SentryEventScrubber
{
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

        // A DocumentVault signed URL — scrub the whole query string, not
        // just the signature param, since the path + expiry + signature
        // together are the sensitive artifact, not any one field alone.
        $signedUrlPattern = '#(https?://[^\s]+/vault/[^\s?]+)\?[^\s]+#';

        $scrub = static function (mixed $value) use ($nikKkPattern, $signedUrlPattern): mixed {
            if (! is_string($value)) {
                return $value;
            }

            $value = preg_replace($nikKkPattern, '[REDACTED-NIK-KK]', $value) ?? $value;
            $value = preg_replace($signedUrlPattern, '$1?[REDACTED-SIGNATURE]', $value) ?? $value;

            return $value;
        };

        if (($message = $event->getMessage()) !== null) {
            $event->setMessage($scrub($message));
        }

        foreach ($event->getExceptions() as $exception) {
            $exception->setValue($scrub($exception->getValue()));

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
