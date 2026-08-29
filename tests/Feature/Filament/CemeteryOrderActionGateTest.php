<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Filament\Support\CemeteryOrderActionGate;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * `CemeteryOrderActionGate` — the shared actor gate extracted from
 * `ReservePlotAction::roleAllowed()` and
 * `PlotReservationLifecycleActions::roleAllowed()`, which were
 * byte-for-byte identical before this extraction. Both action classes now
 * delegate here, so this file pins the one implementation both of them
 * share; `ReservePlotActionCemeteryOperatorTest` and
 * `PlotReservationLifecycleCemeteryOperatorTest` continue to exercise it
 * from their own call sites.
 *
 * The dual-role case below was previously untested at this layer — see the
 * final review's M-4 finding. `auditRoleFor()`'s dual-role precedence is
 * tested elsewhere (Task 8); this asserts the *authorization* precedence:
 * the platform-wide path is unconditional, so it must not be starved by an
 * absent (or foreign) cemetery grant on the narrower role.
 */
final class CemeteryOrderActionGateTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    private function orderFor(Cemetery $cemetery): Order
    {
        $draft = BookingDraft::query()->create(['cemetery_id' => $cemetery->id]);

        return Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::DIVERIFIKASI->value,
            'booking_draft_id' => $draft->id,
        ]);
    }

    public function test_an_actor_holding_both_admin_and_cemetery_operator_is_admitted_for_an_order_in_a_cemetery_they_hold_no_grant_for(): void
    {
        $ungrantedCemetery = Cemetery::factory()->create();
        $grantedCemetery = Cemetery::factory()->create();

        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->grantRoleTo($user, ActorRole::CEMETERY_OPERATOR);

        // Deliberately grants the narrower role a DIFFERENT cemetery than
        // the one under test, so the assertion below cannot be satisfied by
        // the cemetery_operator path — only the unconditional admin-tier
        // path can admit it.
        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $user->id,
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => (string) $grantedCemetery->id,
        ]);

        $this->actingAs($user);
        $this->app->forgetScopedInstances();

        $this->assertTrue(CemeteryOrderActionGate::allows($this->orderFor($ungrantedCemetery)));
    }

    public function test_an_actor_holding_both_admin_and_cemetery_operator_is_admitted_even_with_no_cemetery_grant_at_all(): void
    {
        $cemetery = Cemetery::factory()->create();

        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->grantRoleTo($user, ActorRole::CEMETERY_OPERATOR);

        $this->actingAs($user);
        $this->app->forgetScopedInstances();

        $this->assertTrue(CemeteryOrderActionGate::allows($this->orderFor($cemetery)));
    }
}
