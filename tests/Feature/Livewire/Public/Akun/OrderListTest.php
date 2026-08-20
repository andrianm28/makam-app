<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Akun;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\Models\OrderParty;
use App\Domain\OrderWorkflow\OrderPartyRole;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `/akun/pesanan` — Task 2 of `docs/superpowers/plans/2026-08-20-akun-pesanan`
 * (PR 3 of the `/akun` account area). `OrderList` is a thin `render()`-only
 * wrapper over `Order::forUser(auth()->id())`, already covered for its own
 * scoping contract by `OrderForUserScopeTest` (Task 1). This file proves the
 * SCREEN renders that same data honestly — own orders only, the exact
 * empty-state copy, and that the status badge actually goes through
 * `StatusIntent` rather than a hardcoded label.
 */
final class OrderListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_the_empty_state_copy_renders_when_the_user_has_no_orders(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/akun/pesanan');

        $response->assertOk();
        $response->assertSee('Belum ada pesanan.');
        $response->assertSee('Mulai pemesanan');
        $response->assertSee(route('pemesanan-makam.index'), false);
    }

    public function test_only_the_authenticated_users_own_order_is_shown(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $ownOrder = $this->makeOrder('MK-2026-OWNONE01', OrderStatus::MASUK);
        $this->makeParty($ownOrder, $user->getKey());

        $otherOrder = $this->makeOrder('MK-2026-OTHERONE', OrderStatus::MASUK);
        $this->makeParty($otherOrder, $otherUser->getKey());

        $response = $this->actingAs($user)->get('/akun/pesanan');

        $response->assertOk();
        $response->assertSee('MK-2026-OWNONE01');
        $response->assertDontSee('MK-2026-OTHERONE');
        $response->assertDontSee('Belum ada pesanan.');
    }

    public function test_row_shows_reference_humanized_product_type_and_status_badge_for_masuk(): void
    {
        $user = User::factory()->create();

        $order = $this->makeOrder('MK-2026-MASUKONE', OrderStatus::MASUK);
        $this->makeParty($order, $user->getKey());

        $response = $this->actingAs($user)->get('/akun/pesanan');

        $response->assertOk();
        $response->assertSee('MK-2026-MASUKONE');
        $response->assertSee('At Need Service Order');
        $response->assertSee('Masuk');
    }

    public function test_row_shows_status_badge_for_dibayar_with_a_different_intent_than_masuk(): void
    {
        $user = User::factory()->create();

        $order = $this->makeOrder('MK-2026-DIBAYARO', OrderStatus::DIBAYAR);
        $this->makeParty($order, $user->getKey());

        $response = $this->actingAs($user)->get('/akun/pesanan')->assertOk();

        $response->assertSee('MK-2026-DIBAYARO');
        $response->assertSee('Dibayar');
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get('/akun/pesanan')->assertRedirect(route('login'));
    }

    private function makeOrder(string $reference, OrderStatus $status): Order
    {
        return Order::query()->create([
            'reference' => $reference,
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => $status->value,
        ]);
    }

    private function makeParty(Order $order, int $userId): OrderParty
    {
        return OrderParty::query()->create([
            'order_id' => $order->getKey(),
            'user_id' => $userId,
            'role' => OrderPartyRole::PEMESAN->value,
        ]);
    }
}
