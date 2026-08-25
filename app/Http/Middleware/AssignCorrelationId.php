<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Correlation\CorrelationContext;
use App\Platform\Correlation\CorrelationId;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Request-boundary origin point for `correlation_id` propagation —
 * `platform-audit` design.md line 36: "`correlation_id` originates at the
 * request boundary and is propagated into outbox events, queue jobs,
 * provider calls, and notifications." This middleware is that origin
 * point: every request gets exactly one bound `CorrelationId` before
 * anything downstream (a controller, a Livewire component, a Filament
 * page, an `Audit::record()` call) runs.
 *
 * ---------------------------------------------------------------------------
 * Behavior
 * ---------------------------------------------------------------------------
 * - A non-empty, well-formed inbound `X-Correlation-Id` header is reused
 *   as-is (so a caller that already has an id from an upstream system can
 *   keep it — the whole point of propagation).
 * - Anything else (no header, empty header, or a header that fails the
 *   safe-character allowlist) gets a freshly generated UUID v4.
 * - The resulting id is bound into `CorrelationContext` (this request's
 *   `scoped()` instance — see that class's doc block) for every downstream
 *   consumer to read, and echoed back as an `X-Correlation-Id` response
 *   header, so a client/support engineer can correlate their own log entry
 *   with this server's.
 *
 * ---------------------------------------------------------------------------
 * Why inbound header input is validated rather than trusted (header
 * injection into an audit row / log line)
 * ---------------------------------------------------------------------------
 * `X-Correlation-Id` is fully attacker-controlled — anyone who can send an
 * HTTP request can set it to any string. That string is on a direct path
 * into `audit_events.correlation_id` (a real database column another spec
 * queries and reports against), into this codebase's application logs, and
 * back out as a response header. Trusting it unvalidated would let a
 * caller inject newlines (log-line/header injection), oversized payloads,
 * or arbitrary content into all three sinks under a column/field that
 * looks like an innocuous opaque id. `CorrelationId::isValid()` enforces a
 * conservative allowlist (`^[A-Za-z0-9_-]{1,128}$`, anchored so a trailing
 * newline cannot sneak past PCRE's default `$` semantics — see that
 * class's doc block) before any inbound value is accepted; anything that
 * fails is discarded outright and replaced with a generated id, not
 * sanitized/escaped and reused. Reject-and-replace is deliberately chosen
 * over sanitize-and-keep: a "cleaned" version of an attacker-supplied
 * string is still attacker-influenced content in an audit column, and
 * silently altering the value the caller sent could itself confuse
 * correlation (they would see a different id than what they sent, with no
 * signal that it was changed).
 */
final class AssignCorrelationId
{
    private const string HEADER = 'X-Correlation-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $inbound = $request->header(self::HEADER);

        $id = (is_string($inbound) && CorrelationId::isValid($inbound))
            ? CorrelationId::fromString($inbound)
            : CorrelationId::generate();

        // Resolved fresh here, at the point of use, rather than injected
        // and cached on a constructor property — this class has no
        // constructor at all, precisely to avoid the Eloquent-boot-style
        // "resolved once, frozen for the process" gotcha this codebase has
        // already hit twice (see the batch brief), even though a plain
        // middleware is not itself Eloquent.
        app(CorrelationContext::class)->set($id);

        $response = $next($request);

        $response->headers->set(self::HEADER, $id->value);

        return $response;
    }
}
