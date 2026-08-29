<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Filament\Admin\Resources\BookingOrders\Actions\ReservePlotAction;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * `ReservePlotAction`'s actor gate, now that Phase C has made it
 * operator-aware.
 *
 * Phase A shipped this file asserting the opposite — that a
 * `cemetery_operator` was refused end to end — because `roleAllowed()`
 * composed the admin-only `BookingOrderResource::canAccess()` first. That
 * was a deliberate, documented intermediate state (see that plan's "Known,
 * deliberate incompleteness carried into Phase C", item 1), and this file
 * is its designed replacement, not a regression.
 *
 * The load-bearing assertion here is the cross-cemetery denial. Unlike
 * `TransitionOrderAction`, which routes through the cemetery-aware
 * `OrderTransitionAuthorizerContract`, this action has NO domain authorizer
 * — all of its authorization lives in `roleAllowed()`. Admitting the role
 * without the per-order cemetery check would let an operator granted
 * cemetery A reserve a plot against cemetery B's order.
 */
final class ReservePlotActionCemeteryOperatorTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    private Cemetery $cemeteryA;

    private Cemetery $cemeteryB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->cemeteryA = Cemetery::factory()->create();
        $this->cemeteryB = Cemetery::factory()->create();
    }

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

    private function actingAsCemeteryOperatorGrantedTo(?Cemetery $cemetery): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::CEMETERY_OPERATOR);

        if ($cemetery !== null) {
            ScopeAssignment::query()->create([
                'actor_identifier' => (string) $user->id,
                'entity_type' => ScopeEntityType::CEMETERY,
                'entity_id' => (string) $cemetery->id,
            ]);
        }

        $this->actingAs($user);
        $this->app->forgetScopedInstances();
    }

    public function test_a_cemetery_operator_may_reserve_against_their_own_cemeterys_order(): void
    {
        $this->actingAsCemeteryOperatorGrantedTo($this->cemeteryA);

        $this->assertTrue(ReservePlotAction::make($this->orderFor($this->cemeteryA))->isAuthorized());
    }

    public function test_a_cemetery_operator_may_not_reserve_against_another_cemeterys_order(): void
    {
        $this->actingAsCemeteryOperatorGrantedTo($this->cemeteryA);

        $this->assertFalse(ReservePlotAction::make($this->orderFor($this->cemeteryB))->isAuthorized());
    }

    public function test_a_cemetery_operator_holding_no_grant_at_all_is_refused(): void
    {
        $this->actingAsCemeteryOperatorGrantedTo(null);

        $this->assertFalse(ReservePlotAction::make($this->orderFor($this->cemeteryA))->isAuthorized());
    }

    public function test_a_cemetery_operator_is_refused_for_an_order_with_no_booking_draft(): void
    {
        $this->actingAsCemeteryOperatorGrantedTo($this->cemeteryA);

        $draftless = Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::DIVERIFIKASI->value,
        ]);

        $this->assertFalse(ReservePlotAction::make($draftless)->isAuthorized());
    }

    public function test_the_platform_wide_roles_are_unaffected_and_still_cross_cemetery(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);
        $this->app->forgetScopedInstances();

        // A platform-wide operator holds no cemetery grants at all, so
        // applying the cemetery check to them would deny every order. Both
        // cemeteries must stay reachable.
        $this->assertTrue(ReservePlotAction::make($this->orderFor($this->cemeteryA))->isAuthorized());
        $this->assertTrue(ReservePlotAction::make($this->orderFor($this->cemeteryB))->isAuthorized());
    }

    public function test_finance_is_still_refused(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::FINANCE);
        $this->actingAs($user);
        $this->app->forgetScopedInstances();

        $this->assertFalse(ReservePlotAction::make($this->orderFor($this->cemeteryA))->isAuthorized());
    }
}
