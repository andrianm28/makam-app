<?php

declare(strict_types=1);

namespace Tests\Feature\OrderWorkflow;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderReadModel;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\OrderTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OrderReadModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_admin_fallback_stays_reachable_while_an_operator_has_not_responded(): void
    {
        $order = $this->makeOrder(OrderStatus::MENUNGGU_KETERSEDIAAN);

        $view = OrderReadModel::forOrder($order);

        self::assertSame('pending', $view->statusIntent);
        self::assertNotNull($view->nextAction, 'Operator silence must leave an actionable next step');

        self::assertContains('PENAWARAN_TERKIRIM', OrderTransition::allowedFrom(OrderStatus::MENUNGGU_KETERSEDIAAN));
        self::assertTrue($view->manualFallbackAvailable);
    }

    public function test_status_intent_resolves_correctly_for_each_order_status(): void
    {
        $cases = [
            [OrderStatus::MASUK, 'neutral'],
            [OrderStatus::DIVERIFIKASI, 'info'],
            [OrderStatus::MENUNGGU_KETERSEDIAAN, 'pending'],
            [OrderStatus::PENAWARAN_TERKIRIM, 'info'],
            [OrderStatus::DISETUJUI_PEMESAN, 'info'],
            [OrderStatus::MENUNGGU_PEMBAYARAN, 'pending'],
            [OrderStatus::MENUNGGU_VERIFIKASI_PEMBAYARAN, 'pending'],
            [OrderStatus::DIBAYAR, 'success'],
            [OrderStatus::DIPROSES, 'info'],
            [OrderStatus::SELESAI, 'success'],
            [OrderStatus::DITOLAK, 'danger'],
            [OrderStatus::DIBATALKAN, 'neutral'],
            [OrderStatus::KEDALUWARSA, 'neutral'],
        ];

        foreach ($cases as [$status, $expectedIntent]) {
            $order = $this->makeOrder($status);
            $view = OrderReadModel::forOrder($order);

            self::assertSame(
                $expectedIntent,
                $view->statusIntent,
                "Status {$status->value} should resolve to intent '{$expectedIntent}'"
            );
        }
    }

    public function test_order_reference_is_exposed(): void
    {
        $order = $this->makeOrder(OrderStatus::MASUK);

        $view = OrderReadModel::forOrder($order);

        self::assertSame($order->reference, $view->orderReference);
    }

    public function test_invoice_state_is_read_model_only_and_indicates_no_invoice_while_fin_dec_02_is_tbd(): void
    {
        $order = $this->makeOrder(OrderStatus::MENUNGGU_PEMBAYARAN);

        $view = OrderReadModel::forOrder($order);

        self::assertSame('pending', $view->invoiceState);
    }

    public function test_manual_fallback_is_not_available_when_order_is_at_terminal_status(): void
    {
        $terminalStatuses = [
            OrderStatus::SELESAI,
            OrderStatus::DITOLAK,
            OrderStatus::DIBATALKAN,
            OrderStatus::KEDALUWARSA,
        ];

        foreach ($terminalStatuses as $status) {
            $order = $this->makeOrder($status);
            $view = OrderReadModel::forOrder($order);

            self::assertFalse($view->manualFallbackAvailable);
        }
    }

    /**
     * ------------------------------------------------------------------
     * Why these three tests derive their status lists instead of writing
     * them out
     * ------------------------------------------------------------------
     * They used to carry hand-written lists, and that is precisely why they
     * stayed green when the three pay-first statuses were added on 13 Sep
     * 2026 while `resolveNextAction()` silently returned `null` for two of
     * them — breaking the AC12 guarantee `OrderReadModel`'s own doc block
     * states, on a page a customer sees right after paying in full.
     *
     * A hand-written list cannot fail on the one change these tests exist to
     * guard: somebody adding a case to `OrderStatus`. Deriving the lists
     * from `OrderStatus::cases()`, partitioned by
     * `OrderTransition::isTerminal()` — the same authority the read model
     * itself branches on — means a new case is covered the moment it is
     * added, with nothing to remember.
     *
     * `StatusIntent` was caught by exactly this shape
     * (`OrderTransitionTest::test_every_status_is_renderable_through_status_intent`
     * iterates `cases()`); this read model was missed because it was not.
     * Do not convert these back to literal lists.
     */
    public function test_manual_fallback_is_available_for_every_non_terminal_status(): void
    {
        foreach ($this->nonTerminalStatuses() as $status) {
            $order = $this->makeOrder($status);
            $view = OrderReadModel::forOrder($order);

            self::assertTrue(
                $view->manualFallbackAvailable,
                "Status {$status->value} should allow manual fallback"
            );
        }
    }

    public function test_next_action_is_null_for_terminal_statuses(): void
    {
        foreach ($this->terminalStatuses() as $status) {
            $order = $this->makeOrder($status);
            $view = OrderReadModel::forOrder($order);

            self::assertNull(
                $view->nextAction,
                "Terminal status {$status->value} should have no next action"
            );
        }
    }

    public function test_next_action_is_present_for_non_terminal_statuses(): void
    {
        foreach ($this->nonTerminalStatuses() as $status) {
            $order = $this->makeOrder($status);
            $view = OrderReadModel::forOrder($order);

            self::assertNotNull(
                $view->nextAction,
                "Status {$status->value} should have a next action"
            );
        }
    }

    /**
     * A status whose money has arrived must never read as though the payment
     * is still outstanding — the second half of the same miss.
     * `DITOLAK_SETELAH_BAYAR` is included: the order was refused, but the
     * money did arrive and is owed back, so `pending` would be a false
     * statement about the payment.
     */
    public function test_channel_delivery_is_not_pending_once_money_has_arrived(): void
    {
        foreach (OrderStatus::cases() as $status) {
            if (! $status->isPaidOrLater()) {
                continue;
            }

            $order = $this->makeOrder($status);
            $view = OrderReadModel::forOrder($order);

            self::assertNotSame(
                'pending',
                $view->channelDeliveryState,
                "Status {$status->value} reads as an unpaid order on the customer's own detail page"
            );
        }
    }

    /**
     * @return list<OrderStatus>
     */
    private function nonTerminalStatuses(): array
    {
        return array_values(array_filter(
            OrderStatus::cases(),
            static fn (OrderStatus $status): bool => ! OrderTransition::isTerminal($status),
        ));
    }

    /**
     * @return list<OrderStatus>
     */
    private function terminalStatuses(): array
    {
        return array_values(array_filter(
            OrderStatus::cases(),
            static fn (OrderStatus $status): bool => OrderTransition::isTerminal($status),
        ));
    }

    private function makeOrder(OrderStatus $status): Order
    {
        return Order::query()->create([
            'reference' => 'MK-2026-TEST-'.uniqid(),
            'product_type' => 'AT_NEED_SERVICE_ORDER',
            'status' => $status->value,
        ]);
    }
}
