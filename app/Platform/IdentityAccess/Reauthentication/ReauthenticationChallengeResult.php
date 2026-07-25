<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Reauthentication;

use App\Platform\IdentityAccess\Reauthentication\Models\ReauthenticationEvent;

/**
 * Outcome of `ReauthenticationService::challenge()`. Distinguishes whether
 * this particular call actually wrote a `reauthentication_events` row (and
 * paired `audit_events` row) from one that was throttled by the rate
 * limiter — see `ReauthenticationService`'s class-level doc block for why
 * throttling here bounds WRITE volume rather than blocking the security
 * behaviour itself (the middleware's redirect happens regardless of this
 * result).
 */
final class ReauthenticationChallengeResult
{
    private function __construct(
        public readonly bool $recorded,
        public readonly bool $rateLimited,
        public readonly ?ReauthenticationEvent $event,
    ) {}

    public static function recorded(ReauthenticationEvent $event): self
    {
        return new self(recorded: true, rateLimited: false, event: $event);
    }

    public static function rateLimited(): self
    {
        return new self(recorded: false, rateLimited: true, event: null);
    }
}
