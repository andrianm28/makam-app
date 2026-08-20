<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\OrderWorkflow;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\Models\OrderParty;
use App\Domain\OrderWorkflow\OrderPartyRole;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 1 of `docs/superpowers/plans/2026-08-20-akun-pesanan` (PR 3 of the
 * `/akun` account area). Proves `Order::forUser()` — the `#[Scope]`-attributed
 * filter `/akun/pesanan` will use to list a customer's own orders — returns
 * exactly the orders where the querying user has a `PEMESAN` party row, and
 * nothing else.
 */
final class OrderForUserScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_order_with_a_matching_pemesan_party_is_returned(): void
    {
        $user = User::factory()->create();
        $order = $this->makeOrder();
        $this->makeParty($order, $user->getKey());

        $results = Order::forUser($user->getKey())->get();

        self::assertCount(1, $results);
        self::assertSame($order->getKey(), $results->first()->getKey());
    }

    public function test_an_order_belonging_to_a_different_user_is_not_returned(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $order = $this->makeOrder();
        $this->makeParty($order, $owner->getKey());

        $results = Order::forUser($otherUser->getKey())->get();

        self::assertCount(0, $results);
    }

    public function test_an_order_with_no_parties_at_all_is_not_returned_and_does_not_error(): void
    {
        $user = User::factory()->create();
        $this->makeOrder();

        $results = Order::forUser($user->getKey())->get();

        self::assertCount(0, $results);
    }

    /**
     * `Order::forUser()` bakes in `->orderByDesc('created_at')` — a mutation
     * check confirmed deleting that clause left every other test in this
     * file green, so this test pins the ordering directly.
     */
    public function test_orders_are_returned_most_recently_created_first(): void
    {
        $user = User::factory()->create();

        $olderOrder = $this->makeOrder();
        $this->makeParty($olderOrder, $user->getKey());
        Order::query()->where('id', $olderOrder->getKey())->update(['created_at' => now()->subDay()]);

        $newerOrder = $this->makeOrder();
        $this->makeParty($newerOrder, $user->getKey());

        $references = Order::forUser($user->getKey())->get()->pluck('reference')->all();

        self::assertSame([$newerOrder->reference, $olderOrder->reference], $references);
    }

    public function test_parties_relation_returns_the_correct_order_party_rows(): void
    {
        $user = User::factory()->create();
        $order = $this->makeOrder();
        $party = $this->makeParty($order, $user->getKey());

        $otherOrder = $this->makeOrder();
        $this->makeParty($otherOrder, $user->getKey());

        $parties = $order->parties;

        self::assertCount(1, $parties);
        self::assertSame($party->getKey(), $parties->first()->getKey());
    }

    private function makeOrder(): Order
    {
        return Order::query()->create([
            'reference' => 'MK-2026-TEST-'.uniqid(),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::MASUK->value,
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
