<?php

declare(strict_types=1);

namespace App\Platform\Payment\Http;

use Carbon\CarbonImmutable;

/**
 * Everything `ReceiveWebhook` needs about one HTTP delivery, assembled by
 * `Controllers\WebhookController` so the Action never touches an
 * `Illuminate\Http\Request` (`AGENTS.md` §Architecture: "Keep domain logic
 * outside controllers", and the converse — keep the framework request out of
 * the domain, so the receiver is exercisable without an HTTP kernel).
 *
 * The credential values live behind `WebhookCredentials`, which reports
 * presence rather than value on every dump/serialize path. `$rawBody` is the
 * verbatim bytes the signature was computed over and is never re-encoded.
 */
final readonly class InboundWebhook
{
    public function __construct(
        public string $provider,
        /** The `{merchant}` route segment — AC13's merchant-scoped endpoint. */
        public string $merchantRef,
        public string $rawBody,
        public WebhookCredentials $credentials,
        public ?string $svixId = null,
        public ?string $svixTimestamp = null,
        public ?CarbonImmutable $receivedAt = null,
        public ?string $correlationId = null,
    ) {}

    public function receivedAt(): CarbonImmutable
    {
        return $this->receivedAt ?? CarbonImmutable::now();
    }
}
