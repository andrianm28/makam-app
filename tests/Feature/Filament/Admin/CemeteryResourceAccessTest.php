<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin;

use App\Filament\Admin\Resources\CemeteryResource;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * Proves the master-data role gate on the cemetery admin resource: the four
 * back-office roles (admin, restricted_admin, operator, finance) pass,
 * everyone else — including a bare authenticated customer and a guest —
 * fails closed.
 *
 * The gate is `App\Platform\IdentityAccess\MasterData\Contracts
 * \MasterDataAdminAuthorizerContract` (the shared master-data authorizer,
 * merged by the `masterdata-authorizer` lane), surfaced through
 * `CemeteryResource::canAccess()` exactly like `FinanceReports::canAccess()`
 * does for its own authorizer.
 *
 * ---------------------------------------------------------------------------
 * Why grants go through `GrantsActorRoles`, not fabricated `ActorContext`s
 * ---------------------------------------------------------------------------
 * Same reasoning as `tests/Support/GrantsActorRoles.php`'s own doc block:
 * a test that grants for real proves the whole chain — grant row ->
 * `Roles\ActorRoleReader` -> `Adapters\LocalUsersTableIdentityAccessAdapter`
 * -> `ActorContext::$roles` -> the authorizer — whereas a hand-constructed
 * context only proves `in_array()` works. The four-role positive test is
 * exactly the chain the denial test must not fake.
 */
final class CemeteryResourceAccessTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_a_guest_cannot_access_the_cemetery_resource(): void
    {
        $this->assertFalse(CemeteryResource::canAccess());
    }

    public function test_an_authenticated_customer_without_a_back_office_role_cannot_access_the_cemetery_resource(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->assertFalse(CemeteryResource::canAccess());
    }

    public function test_the_four_back_office_roles_can_access_the_cemetery_resource(): void
    {
        foreach ([
            ActorRole::ADMIN,
            ActorRole::RESTRICTED_ADMIN,
            ActorRole::OPERATOR,
            ActorRole::FINANCE,
        ] as $role) {
            $user = User::factory()->create();
            $this->grantRoleTo($user, $role);
            $this->actingAs($user);

            $this->assertTrue(CemeteryResource::canAccess(), "Expected role [{$role}] to access the cemetery resource.");

            // Each iteration is a fresh user; drop the resolved context so
            // the next actor's roles are not cached from this one.
            $this->forgetResolvedActorContext();
        }
    }
}
