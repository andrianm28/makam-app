<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\IdentityAccess\Panel;

use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Panel\AdminPanelAccessPolicy;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Tests\TestCase;

/**
 * AC4's mechanism, exercised for the one real implementation this batch
 * ships. See the policy class's own doc block for the role rule.
 *
 * ---------------------------------------------------------------------------
 * Why the roles are asserted one-by-one rather than in a loop
 * ---------------------------------------------------------------------------
 * Task 10 of the L9 `admin-operations` lane restricted `/admin` panel
 * membership to `admin`, `restricted_admin`, `operator`, and `finance` (the
 * roles the gate's doc block names). The three actors that must be DENIED —
 * a guest, a roleless authenticated user, and a `customer`-role user — are
 * asserted individually so the reader can see each reason. The four allowed
 * roles are likewise asserted individually rather than via a data provider:
 * the test file reads as the named list the policy is supposed to admit, and
 * a future widening shows up as a visible, reviewable one-line addition
 * here rather than as a loop iteration.
 */
final class AdminPanelAccessPolicyTest extends TestCase
{
    private AdminPanelAccessPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new AdminPanelAccessPolicy;
    }

    public function test_denies_a_guest_actor(): void
    {
        $this->assertFalse($this->policy->allows(ActorContext::guest()));
    }

    public function test_denies_an_authenticated_actor_with_no_roles(): void
    {
        $this->assertFalse($this->policy->allows(new ActorContext(identityReference: 1)));
    }

    public function test_denies_a_customer_role_actor(): void
    {
        $this->assertFalse($this->policy->allows(new ActorContext(
            identityReference: 1,
            roles: [ActorRole::CUSTOMER],
        )));
    }

    public function test_allows_an_admin_role_actor(): void
    {
        $this->assertTrue($this->policy->allows(new ActorContext(
            identityReference: 1,
            roles: [ActorRole::ADMIN],
        )));
    }

    public function test_allows_a_restricted_admin_role_actor(): void
    {
        $this->assertTrue($this->policy->allows(new ActorContext(
            identityReference: 1,
            roles: [ActorRole::RESTRICTED_ADMIN],
        )));
    }

    public function test_allows_an_operator_role_actor(): void
    {
        $this->assertTrue($this->policy->allows(new ActorContext(
            identityReference: 1,
            roles: [ActorRole::OPERATOR],
        )));
    }

    public function test_allows_a_finance_role_actor(): void
    {
        $this->assertTrue($this->policy->allows(new ActorContext(
            identityReference: 1,
            roles: [ActorRole::FINANCE],
        )));
    }
}
