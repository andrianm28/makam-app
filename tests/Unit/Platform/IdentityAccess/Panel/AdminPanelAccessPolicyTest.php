<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\IdentityAccess\Panel;

use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Panel\AdminPanelAccessPolicy;
use Tests\TestCase;

/**
 * AC4's mechanism, exercised for the one real implementation this batch
 * ships. See the policy class's own doc block for why the predicate is
 * "authenticated" rather than a role check today.
 */
final class AdminPanelAccessPolicyTest extends TestCase
{
    public function test_denies_a_guest_actor(): void
    {
        $policy = new AdminPanelAccessPolicy;

        $this->assertFalse($policy->allows(ActorContext::guest()));
    }

    public function test_allows_an_authenticated_actor(): void
    {
        $policy = new AdminPanelAccessPolicy;

        $this->assertTrue($policy->allows(new ActorContext(identityReference: 1)));
    }
}
