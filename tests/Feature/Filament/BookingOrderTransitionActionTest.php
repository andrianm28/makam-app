<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\Models\OrderStatusEvent;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Filament\Admin\Resources\BookingOrders\Actions\TransitionOrderAction;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

final class BookingOrderTransitionActionTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function order(OrderStatus $status): Order
    {
        return Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => $status->value,
        ]);
    }

    public function test_operator_can_invoke_verify_transition(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);

        $order = $this->order(OrderStatus::MASUK);
        TransitionOrderAction::make(OrderStatus::DIVERIFIKASI, $order)->call();

        $this->assertSame(OrderStatus::DIVERIFIKASI, $order->fresh()->status());
    }

    public function test_operator_cannot_invoke_money_transition(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);

        $order = $this->order(OrderStatus::DISETUJUI_PEMESAN);
        $action = TransitionOrderAction::make(OrderStatus::MENUNGGU_PEMBAYARAN, $order);

        $this->assertFalse($action->isAuthorized());
    }

    public function test_finance_money_transition_is_authorized(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::FINANCE);
        $this->actingAs($user);

        $order = $this->order(OrderStatus::DISETUJUI_PEMESAN);
        $action = TransitionOrderAction::make(OrderStatus::MENUNGGU_PEMBAYARAN, $order);

        $this->assertTrue($action->isAuthorized());
    }

    public function test_a_cemetery_operator_cannot_transition_another_cemeterys_order(): void
    {
        $cemeteryA = Cemetery::factory()->create();
        $cemeteryB = Cemetery::factory()->create();
        $draft = BookingDraft::query()->create(['cemetery_id' => $cemeteryB->id]);
        $order = Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::MASUK->value,
            'booking_draft_id' => $draft->id,
        ]);

        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::CEMETERY_OPERATOR);
        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $user->id,
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => (string) $cemeteryA->id,
        ]);
        $this->actingAs($user);

        $action = TransitionOrderAction::make(OrderStatus::DIVERIFIKASI, $order);

        $this->assertFalse($action->isAuthorized());
    }

    public function test_a_cemetery_operator_can_transition_their_own_cemeterys_order(): void
    {
        $cemeteryA = Cemetery::factory()->create();
        $draft = BookingDraft::query()->create(['cemetery_id' => $cemeteryA->id]);
        $order = Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::MASUK->value,
            'booking_draft_id' => $draft->id,
        ]);

        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::CEMETERY_OPERATOR);
        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $user->id,
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => (string) $cemeteryA->id,
        ]);
        $this->actingAs($user);

        $action = TransitionOrderAction::make(OrderStatus::DIVERIFIKASI, $order);
        $action->call();

        $this->assertSame(OrderStatus::DIVERIFIKASI, $order->fresh()->status());

        // Known-wrong, Phase-C-deferred behaviour (final review, Important
        // #2): `BookingOrderResource::auditRoleFor()` does not recognise
        // `cemetery_operator`, so this transition's audit trail misattributes
        // the actor to the generic 'authenticated_actor' fallback instead of
        // the real role. This assertion pins that CURRENT value on purpose —
        // it must start FAILING the moment Phase C teaches `auditRoleFor()`
        // about `cemetery_operator`, which is the intended tripwire.
        $event = OrderStatusEvent::query()
            ->where('order_id', $order->getKey())
            ->where('to_status', OrderStatus::DIVERIFIKASI->value)
            ->sole();
        $this->assertSame('authenticated_actor', $event->actor_role);
    }
}
