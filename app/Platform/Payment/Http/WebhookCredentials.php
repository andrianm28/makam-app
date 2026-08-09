<?php

declare(strict_types=1);

namespace App\Platform\Payment\Http;

use JsonSerializable;
use Stringable;

/**
 * The credential-bearing headers of one inbound webhook, lifted off the
 * `Illuminate\Http\Request` by `Middleware\RedactProviderPayload` and carried
 * to `Providers\SumoPodWebhookSignature` in a container that refuses to say
 * what it holds.
 *
 * ---------------------------------------------------------------------------
 * Why the values are moved off the Request rather than merely "not logged"
 * ---------------------------------------------------------------------------
 * AC14 / `AGENTS.md` §Observability: credentials must not appear in logs or
 * error trackers. A `Request` is the single most-dumped object in a Laravel
 * application — exception context, `dd($request)`, a debug page, a future
 * error-tracker integration, `Log::info('…', ['headers' => $request->headers->all()])`.
 * Asking every present and future call site not to dump it is a convention,
 * and conventions are not controls. Removing the header from the request
 * object means a dump of the request CANNOT contain the value, because it is
 * no longer there.
 *
 * This object is what remains, and it is deliberately hostile to disclosure:
 * `__debugInfo()`, `__toString()`, `jsonSerialize()`, and `toArray()` all
 * report presence, never value. `var_dump()`, `dd()`, `json_encode()`, string
 * interpolation, and Laravel's own exception renderer all route through one of
 * those four. Reading a value requires naming the property explicitly, which
 * only the signature verifier does.
 *
 * Not `Serializable`/queue-safe on purpose: nothing here may ever be
 * serialized onto a queue payload. `Jobs\ProcessProviderEventJob` takes a row
 * id and nothing else.
 */
final readonly class WebhookCredentials implements JsonSerializable, Stringable
{
    public function __construct(
        /** ADR-0033: the `svix-signature` header — `v1,<base64>` entries, space-separated. */
        public ?string $svixSignature = null,
        /** ADR-0033: the shared `X-Webhook-Token` header (`whtok_…`). */
        public ?string $sharedToken = null,
    ) {}

    public function hasAnyCredential(): bool
    {
        return $this->svixSignature !== null || $this->sharedToken !== null;
    }

    /**
     * Presence only — never a value, never a prefix, never a length (a length
     * is a distinguisher between two rotating secrets).
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'svix_signature' => $this->svixSignature === null ? 'absent' : '[REDACTED]',
            'shared_token' => $this->sharedToken === null ? 'absent' : '[REDACTED]',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return $this->toArray();
    }

    /**
     * @return array<string, string>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function __toString(): string
    {
        return 'WebhookCredentials[REDACTED]';
    }
}
