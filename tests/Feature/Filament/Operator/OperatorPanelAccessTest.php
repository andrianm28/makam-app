<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Operator;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Roles\Models\ActorRoleAssignment;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `CemeteryOperatorPanelAccessPolicy` reached the way a real request reaches
 * it: over HTTP, through Filament's `Authenticate` middleware and
 * `User::canAccessPanel()`. Mirrors `Tests\Feature\Filament\Vendor
 * \VendorPanelAccessTest` exactly — see that file's own doc block for why
 * this end-to-end wiring check is not redundant with the unit-level policy
 * test (Task 2): before a panel's `'operator'` match arm exists,
 * `canAccessPanel()` falls through to `default => false` and every actor is
 * refused, which no amount of unit-testing the policy in isolation reveals.
 */
final class OperatorPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Asset builds run in CI, never on a dev host (this repo's
        // CLAUDE.md) — same reasoning as VendorPanelAccessTest.
        $this->withoutVite();
    }

    public function test_an_operator_with_an_active_grant_reaches_the_panel(): void
    {
        $this->actingAs($this->operatorUser(withRole: true, withGrant: true));

        $this->get('/operator')->assertSuccessful();
    }

    public function test_a_cemetery_operator_role_without_a_grant_is_refused(): void
    {
        $this->actingAs($this->operatorUser(withRole: true, withGrant: false));

        $this->get('/operator')->assertForbidden();
    }

    public function test_a_grant_without_the_cemetery_operator_role_is_refused(): void
    {
        $this->actingAs($this->operatorUser(withRole: false, withGrant: true));

        $this->get('/operator')->assertForbidden();
    }

    public function test_a_user_with_neither_is_refused(): void
    {
        $this->actingAs($this->operatorUser(withRole: false, withGrant: false));

        $this->get('/operator')->assertForbidden();
    }

    public function test_a_guest_is_sent_to_the_operator_login_page(): void
    {
        $this->get('/operator')->assertRedirect('/operator/login');
    }

    private function operatorUser(bool $withRole, bool $withGrant): User
    {
        $user = User::factory()->create();

        if ($withRole) {
            ActorRoleAssignment::create([
                'actor_identifier' => (string) $user->id,
                'role' => ActorRole::CEMETERY_OPERATOR,
            ]);
        }

        if ($withGrant) {
            $cemetery = Cemetery::factory()->create();

            ScopeAssignment::query()->create([
                'actor_identifier' => (string) $user->id,
                'entity_type' => ScopeEntityType::CEMETERY,
                'entity_id' => (string) $cemetery->id,
            ]);
        }

        return $user;
    }
}
