<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\IdentityAccess\Panel;

use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Panel\VendorPanelAccessPolicy;
use App\Platform\IdentityAccess\Roles\ActorRole;
use PHPUnit\Framework\TestCase;

/**
 * `VendorPanelAccessPolicy` in isolation — the AC4 access check for `/vendor`.
 *
 * A plain `PHPUnit\Framework\TestCase` with hand-built `ActorContext` values,
 * matching `AdminPanelAccessPolicyTest`: the policy is a pure predicate over an
 * already-resolved actor context, so nothing here needs a database or a booted
 * application. The end-to-end wiring through `User::canAccessPanel()` and a
 * real HTTP request is covered separately by
 * `Tests\Feature\Filament\Vendor\VendorPanelAccessTest`.
 */
final class VendorPanelAccessPolicyTest extends TestCase
{
    private VendorPanelAccessPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new VendorPanelAccessPolicy;
    }

    public function test_guest_is_refused(): void
    {
        $this->assertFalse($this->policy->allows(ActorContext::guest()));
    }

    public function test_vendor_role_with_an_active_vendor_grant_is_admitted(): void
    {
        $this->assertTrue($this->policy->allows(new ActorContext(
            identityReference: 1,
            roles: [ActorRole::VENDOR],
            scopes: ['vendor:0198f2b6-1c2d-7000-8000-000000000001'],
        )));
    }

    public function test_vendor_role_without_any_scope_grant_is_refused(): void
    {
        // The panel would render nothing but empty tables for this actor —
        // every surface inside scopes on the same (empty) grant list. Refusing
        // at the boundary is the honest answer.
        $this->assertFalse($this->policy->allows(new ActorContext(
            identityReference: 1,
            roles: [ActorRole::VENDOR],
            scopes: [],
        )));
    }

    public function test_vendor_grant_without_the_vendor_role_is_refused(): void
    {
        // A customer or operator who holds a vendor-entity grant for some
        // unrelated reason must not reach a vendor's commercial and payout
        // surfaces on the strength of that grant alone.
        $this->assertFalse($this->policy->allows(new ActorContext(
            identityReference: 1,
            roles: [ActorRole::CUSTOMER],
            scopes: ['vendor:0198f2b6-1c2d-7000-8000-000000000001'],
        )));
    }

    public function test_a_non_vendor_scope_grant_does_not_satisfy_the_scope_condition(): void
    {
        // Guards the prefix match: 'cemetery:...' must not be read as a vendor
        // grant, and neither must an entity type that merely ends in 'vendor'.
        $this->assertFalse($this->policy->allows(new ActorContext(
            identityReference: 1,
            roles: [ActorRole::VENDOR],
            scopes: ['cemetery:1', 'order:9'],
        )));
    }

    public function test_admin_role_alone_does_not_open_the_vendor_panel(): void
    {
        // /admin's roles are not a superset of /vendor's. An admin who needs to
        // see a vendor's records uses the admin surfaces, which are built for
        // cross-vendor visibility; letting them in here would put them inside a
        // panel whose every query is scoped to grants they do not hold.
        $this->assertFalse($this->policy->allows(new ActorContext(
            identityReference: 1,
            roles: [ActorRole::ADMIN],
            scopes: ['vendor:0198f2b6-1c2d-7000-8000-000000000001'],
        )));
    }
}
