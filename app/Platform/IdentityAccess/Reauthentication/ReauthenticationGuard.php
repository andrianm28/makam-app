<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Reauthentication;

use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Reauthentication\Exceptions\ReauthenticationRequiredException;
use Carbon\CarbonImmutable;

final class ReauthenticationGuard
{
    public function assertFresh(ActorContext $actor): void
    {
        $lastAuthenticatedAt = $actor->lastAuthenticatedAt;

        if ($lastAuthenticatedAt === null) {
            throw ReauthenticationRequiredException::forActor();
        }

        $freshness = (int) config('reauthentication.freshness_seconds', 900);

        if ($lastAuthenticatedAt->lt(CarbonImmutable::now()->subSeconds($freshness))) {
            throw ReauthenticationRequiredException::forActor();
        }
    }
}
