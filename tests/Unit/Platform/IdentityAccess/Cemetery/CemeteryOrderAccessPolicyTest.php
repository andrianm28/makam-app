<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\IdentityAccess\Cemetery;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Models\User;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Cemetery\CemeteryOrderAccessPolicy;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * The `/operator` orders resource's own access gate. Deliberately NOT
 * `MasterDataAdminAuthorizer`: that authorizer performs no record scoping at
 * all (its own doc block: "there is no record scope to check"), so admitting
 * `cemetery_operator` there would answer "yes" for every cemetery's orders.
 * Both conditions are required and neither substitutes for the other — the
 * same argument `CemeteryOperatorPanelAccessPolicy` makes at the panel
 * boundary.
 */
final class CemeteryOrderAccessPolicyTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    private function actor(): ActorContext
    {
        $this->app->forgetScopedInstances();

        return app(ActorContext::class);
    }

    public function test_a_guest_is_refused(): void
    {
        $this->assertFalse(app(CemeteryOrderAccessPolicy::class)->allows($this->actor()));
    }

    public function test_a_cemetery_operator_without_any_grant_is_refused(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::CEMETERY_OPERATOR);
        $this->actingAs($user);

        $this->assertFalse(app(CemeteryOrderAccessPolicy::class)->allows($this->actor()));
    }

    public function test_a_grant_without_the_role_is_refused(): void
    {
        $cemetery = Cemetery::factory()->create();
        $user = User::factory()->create();
        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $user->id,
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => (string) $cemetery->id,
        ]);
        $this->actingAs($user);

        $this->assertFalse(app(CemeteryOrderAccessPolicy::class)->allows($this->actor()));
    }

    public function test_an_admin_is_refused_because_this_gate_is_not_the_admin_gate(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);

        $this->assertFalse(app(CemeteryOrderAccessPolicy::class)->allows($this->actor()));
    }

    public function test_a_cemetery_operator_with_a_grant_is_admitted(): void
    {
        $cemetery = Cemetery::factory()->create();
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::CEMETERY_OPERATOR);
        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $user->id,
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => (string) $cemetery->id,
        ]);
        $this->actingAs($user);

        $this->assertTrue(app(CemeteryOrderAccessPolicy::class)->allows($this->actor()));
    }
}
