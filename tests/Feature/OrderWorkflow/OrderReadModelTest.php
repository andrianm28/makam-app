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

    public function test_manual_fallback_is_available_for_pre_payment_non_terminal_statuses(): void
    {
        $prePaymentStatuses = [
            OrderStatus::MASUK,
            OrderStatus::DIVERIFIKASI,
            OrderStatus::MENUNGGU_KETERSEDIAAN,
            OrderStatus::PENAWARAN_TERKIRIM,
            OrderStatus::DISETUJUI_PEMESAN,
            OrderStatus::MENUNGGU_PEMBAYARAN,
            OrderStatus::MENUNGGU_VERIFIKASI_PEMBAYARAN,
        ];

        foreach ($prePaymentStatuses as $status) {
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
        $terminalStatuses = [
            OrderStatus::SELESAI,
            OrderStatus::DITOLAK,
            OrderStatus::DIBATALKAN,
            OrderStatus::KEDALUWARSA,
        ];

        foreach ($terminalStatuses as $status) {
            $order = $this->makeOrder($status);
            $view = OrderReadModel::forOrder($order);

            self::assertNull($view->nextAction);
        }
    }

    public function test_next_action_is_present_for_non_terminal_statuses(): void
    {
        $nonTerminalStatuses = [
            OrderStatus::MASUK,
            OrderStatus::DIVERIFIKASI,
            OrderStatus::MENUNGGU_KETERSEDIAAN,
            OrderStatus::PENAWARAN_TERKIRIM,
            OrderStatus::DISETUJUI_PEMESAN,
            OrderStatus::MENUNGGU_PEMBAYARAN,
            OrderStatus::MENUNGGU_VERIFIKASI_PEMBAYARAN,
            OrderStatus::DIBAYAR,
            OrderStatus::DIPROSES,
        ];

        foreach ($nonTerminalStatuses as $status) {
            $order = $this->makeOrder($status);
            $view = OrderReadModel::forOrder($order);

            self::assertNotNull(
                $view->nextAction,
                "Status {$status->value} should have a next action"
            );
        }
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
