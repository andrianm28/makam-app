<?php

declare(strict_types=1);

namespace Tests\Unit\Platform;

use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Reauthentication\Exceptions\ReauthenticationRequiredException;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationGuard;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class ReauthenticationGuardTest extends TestCase
{
    private function actor(?CarbonImmutable $lastAuth): ActorContext
    {
        return new ActorContext(
            identityReference: 'user:1',
            roles: [ActorRole::FINANCE],
            scopes: [],
            mfaState: ActorContext::MFA_STATE_ENROLLED,
            lastAuthenticatedAt: $lastAuth,
        );
    }

    public function test_null_last_authentication_fails_closed(): void
    {
        $this->expectException(ReauthenticationRequiredException::class);
        app(ReauthenticationGuard::class)->assertFresh($this->actor(null));
    }

    public function test_recent_authentication_passes(): void
    {
        app(ReauthenticationGuard::class)->assertFresh($this->actor(CarbonImmutable::now()->subMinutes(2)));
        $this->assertTrue(true);
    }

    public function test_stale_authentication_fails(): void
    {
        $this->expectException(ReauthenticationRequiredException::class);
        app(ReauthenticationGuard::class)->assertFresh($this->actor(CarbonImmutable::now()->subMinutes(30)));
    }

    public function test_boundary_of_freshness_window_passes(): void
    {
        $window = (int) config('reauthentication.freshness_seconds', 900);
        app(ReauthenticationGuard::class)->assertFresh($this->actor(CarbonImmutable::now()->subSeconds($window - 1)));
        $this->assertTrue(true);
    }
}
