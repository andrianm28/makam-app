<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\IdentityAccess\Panel;

use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Panel\CemeteryOperatorPanelAccessPolicy;
use App\Platform\IdentityAccess\Roles\ActorRole;
use PHPUnit\Framework\TestCase;

/**
 * `CemeteryOperatorPanelAccessPolicy` in isolation — the AC4 access check for
 * `/operator`, mirroring `VendorPanelAccessPolicyTest` exactly.
 *
 * A plain `PHPUnit\Framework\TestCase` with hand-built `ActorContext` values:
 * the policy is a pure predicate over an already-resolved actor context, so
 * nothing here needs a database or a booted application. The end-to-end
 * wiring through `User::canAccessPanel()` and a real HTTP request is covered
 * separately by `Tests\Feature\Filament\Operator\OperatorPanelAccessTest`.
 */
final class CemeteryOperatorPanelAccessPolicyTest extends TestCase
{
    private CemeteryOperatorPanelAccessPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new CemeteryOperatorPanelAccessPolicy;
    }

    public function test_guest_is_refused(): void
    {
        $this->assertFalse($this->policy->allows(ActorContext::guest()));
    }

    public function test_cemetery_operator_role_with_an_active_cemetery_grant_is_admitted(): void
    {
        $this->assertTrue($this->policy->allows(new ActorContext(
            identityReference: 1,
            roles: [ActorRole::CEMETERY_OPERATOR],
            scopes: ['cemetery:0198f2b6-1c2d-7000-8000-000000000001'],
        )));
    }

    public function test_cemetery_operator_role_without_any_scope_grant_is_refused(): void
    {
        // The panel would render nothing but empty tables for this actor —
        // every surface inside scopes on the same (empty) grant list. Refusing
        // at the boundary is the honest answer.
        $this->assertFalse($this->policy->allows(new ActorContext(
            identityReference: 1,
            roles: [ActorRole::CEMETERY_OPERATOR],
            scopes: [],
        )));
    }

    public function test_cemetery_grant_without_the_cemetery_operator_role_is_refused(): void
    {
        // A customer or vendor who holds a cemetery-entity grant for some
        // unrelated reason must not reach an operator's own surfaces on the
        // strength of that grant alone.
        $this->assertFalse($this->policy->allows(new ActorContext(
            identityReference: 1,
            roles: [ActorRole::CUSTOMER],
            scopes: ['cemetery:0198f2b6-1c2d-7000-8000-000000000001'],
        )));
    }

    public function test_a_non_cemetery_scope_grant_does_not_satisfy_the_scope_condition(): void
    {
        // Guards the prefix match: 'vendor:...' must not be read as a
        // cemetery grant.
        $this->assertFalse($this->policy->allows(new ActorContext(
            identityReference: 1,
            roles: [ActorRole::CEMETERY_OPERATOR],
            scopes: ['vendor:1', 'order:9'],
        )));
    }

    public function test_admin_role_alone_does_not_open_the_operator_panel(): void
    {
        // /admin's roles are not a superset of /operator's. An admin who
        // needs to see a cemetery's records uses the admin surfaces, which
        // are built for cross-cemetery visibility; letting them in here
        // would put them inside a panel whose every query is scoped to
        // grants they do not hold.
        $this->assertFalse($this->policy->allows(new ActorContext(
            identityReference: 1,
            roles: [ActorRole::ADMIN],
            scopes: ['cemetery:0198f2b6-1c2d-7000-8000-000000000001'],
        )));
    }
}
