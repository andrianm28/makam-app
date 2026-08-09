<?php

declare(strict_types=1);

namespace App\Platform\Payment\Http\Middleware;

use App\Platform\Payment\Http\WebhookCredentials;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AC14: "THE SYSTEM SHALL source provider credentials from secret management,
 * scoped per environment. THE SYSTEM SHALL NOT let credentials appear in logs
 * or error trackers." `AGENTS.md` §Observability: "Never place restricted data
 * in logs, Pulse, Horizon tags, or error trackers."
 *
 * ---------------------------------------------------------------------------
 * This middleware REMOVES rather than filters, and that is the whole design
 * ---------------------------------------------------------------------------
 * The usual shape of a redaction layer is a list of keys some logger promises
 * to mask. That only protects the sinks that remember to ask. A Laravel
 * `Request` reaches many sinks that never ask: the exception renderer, a
 * `report()` context, a debug page, a future error-tracker integration, a
 * `dd($request)` left in during an incident, `$request->headers->all()` in a
 * hand-written log line.
 *
 * So the credential-bearing headers are lifted off the request object entirely,
 * before any route handler runs, and handed to the one collaborator that needs
 * them inside a `WebhookCredentials` that reports presence rather than value on
 * every dump, cast, and serialize path. After this middleware, a full dump of
 * the request CANNOT contain a credential, because the request no longer holds
 * one. `$request->server` is scrubbed alongside `$request->headers` because
 * Symfony rebuilds header values from the server bag, so removing only the
 * header would leave the value reachable through `$request->server->all()`.
 *
 * The verbatim body is deliberately NOT altered: the signature is computed over
 * the exact bytes received, and `provider_events.raw_payload` must stay
 * re-verifiable (it is encrypted at rest instead — see that migration). What
 * `redact()` below exists for is any place a payload STRUCTURE is about to be
 * logged; the receiver uses it for nothing else today, and no payload is logged
 * on the happy path at all.
 *
 * ---------------------------------------------------------------------------
 * What this does not do
 * ---------------------------------------------------------------------------
 * It cannot un-log something a caller already logged upstream of it, and it
 * does not touch the web server's own access log (nginx logs request lines and
 * configured headers; none of these headers is in that configuration — verified
 * as a deployment property, not enforced by this file).
 */
final readonly class RedactProviderPayload
{
    /**
     * Headers removed from the request before any handler sees it.
     *
     * `svix-signature` and `x-webhook-token` are ADR-0033's two verification
     * credentials. `x-api-key` is the OUTBOUND credential (`SUMODOP_SANDBOX_API_KEY`)
     * — it has no business arriving on an inbound webhook, and stripping it
     * means a caller cannot get this application to echo or log a value it was
     * fishing for. `authorization` is stripped for the same reason.
     *
     * @var list<string>
     */
    public const array CREDENTIAL_HEADERS = [
        'svix-signature',
        'x-webhook-token',
        'x-api-key',
        'authorization',
    ];

    /**
     * Payload keys masked by `redact()`. Matched case-insensitively as a
     * substring, so `merchant_api_key` and `X-Signature` are covered too.
     *
     * @var list<string>
     */
    public const array SENSITIVE_KEY_FRAGMENTS = [
        'secret', 'token', 'signature', 'api_key', 'apikey', 'authorization',
        'password', 'credential', 'card', 'pan', 'cvv', 'cvc', 'ktp', 'nik',
    ];

    public const string MASK = '[REDACTED]';

    public function __construct(private Application $app) {}

    public function handle(Request $request, Closure $next): Response
    {
        $credentials = new WebhookCredentials(
            svixSignature: $this->headerOrNull($request, 'svix-signature'),
            sharedToken: $this->headerOrNull($request, 'x-webhook-token'),
        );

        foreach (self::CREDENTIAL_HEADERS as $header) {
            $request->headers->remove($header);
            $request->server->remove('HTTP_'.strtoupper(str_replace('-', '_', $header)));
        }

        // Basic-auth credentials Symfony derives from an Authorization header
        // are stored separately and would survive the removal above.
        $request->server->remove('PHP_AUTH_USER');
        $request->server->remove('PHP_AUTH_PW');

        // Scoped to this request: `instance()` on the request-lifetime
        // container, never a singleton that could outlive the delivery and be
        // read by a later one.
        $this->app->instance(WebhookCredentials::class, $credentials);

        return $next($request);
    }

    /**
     * Mask credential-shaped values in a payload structure before it is
     * logged. Recursive, key-based, and it never inspects values — a masker
     * that pattern-matched values would itself have to hold them.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public static function redact(array $payload): array
    {
        $redacted = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                $redacted[$key] = self::MASK;

                continue;
            }

            $redacted[$key] = is_array($value) ? self::redact($value) : $value;
        }

        return $redacted;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);

        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function headerOrNull(Request $request, string $header): ?string
    {
        $value = $request->header($header);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
