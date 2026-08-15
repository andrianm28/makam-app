<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Filament\Admin\Resources\BookingOrders\Actions\TransitionOrderAction;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
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

    public function test_finance_money_transition_requires_fresh_authentication(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::FINANCE);
        $this->actingAs($user);

        $order = $this->order(OrderStatus::DISETUJUI_PEMESAN);
        $action = TransitionOrderAction::make(OrderStatus::MENUNGGU_PEMBAYARAN, $order);

        $this->assertTrue($action->isAuthorized());
    }
}
